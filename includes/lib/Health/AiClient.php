<?php
namespace lib\Health;

class AiClient
{
    const MAX_RESPONSE_BYTES = 1048576;
    const MAX_REQUEST_BYTES = 524288;

    private $config;
    private $lastError;

    public function __construct($config = [])
    {
        $this->config = $config;
    }

    public function getLastError()
    {
        return $this->lastError;
    }

    public function completeJson($systemPrompt, $userPayload, $maxTokens = null)
    {
        $started = microtime(true);
        $secrets = self::loadSecretEnvironment();
        $apiKey = isset($secrets['HEALTH_AI_API_KEY']) ? $secrets['HEALTH_AI_API_KEY'] : getenv('HEALTH_AI_API_KEY');
        $allowed = isset($secrets['HEALTH_AI_ALLOWED_HOSTS']) ? $secrets['HEALTH_AI_ALLOWED_HOSTS'] : getenv('HEALTH_AI_ALLOWED_HOSTS');
        $allowedModels = isset($secrets['HEALTH_AI_ALLOWED_MODELS']) ? $secrets['HEALTH_AI_ALLOWED_MODELS'] : getenv('HEALTH_AI_ALLOWED_MODELS');
        $pinnedIps = isset($secrets['HEALTH_AI_PINNED_IPV4']) ? $secrets['HEALTH_AI_PINNED_IPV4'] : getenv('HEALTH_AI_PINNED_IPV4');
        $baseUrl = trim(isset($this->config['base_url']) ? (string)$this->config['base_url'] : '');
        $model = trim(isset($this->config['model']) ? (string)$this->config['model'] : '');
        if($apiKey === '' || $apiKey === false) return $this->failure('AI API key is not configured', $started);
        if($model === '') return $this->failure('AI model is not configured', $started);
        if(!$this->modelAllowed($model, $allowedModels)) return $this->failure('AI model is not allowlisted', $started);
        if(!is_array($userPayload)) return $this->failure('AI user payload must be an object', $started);

        try {
            $endpoint = $this->buildEndpoint($baseUrl, $allowed);
            $endpointHost = strtolower((string)parse_url($endpoint, PHP_URL_HOST));
            $endpointIp = $this->resolvePublicIpv4($endpointHost, $pinnedIps);
        } catch(\Exception $e){
            return $this->failure($e->getMessage(), $started);
        }

        $payload = [
            'model'=>$model,
            'temperature'=>0.1,
            'max_tokens'=>$this->boundedInt($maxTokens === null ? (isset($this->config['max_tokens']) ? $this->config['max_tokens'] : 1800) : $maxTokens, 300, 5000),
            'response_format'=>['type'=>'json_object'],
            'messages'=>[
                ['role'=>'system', 'content'=>$this->boundedText($systemPrompt, 12000)],
                ['role'=>'user', 'content'=>json_encode($userPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ],
        ];
        $stream = !empty($this->config['stream']);
        if($stream){
            $payload['stream'] = true;
            $payload['stream_options'] = ['include_usage'=>true];
        }
        if($payload['messages'][1]['content'] === false) return $this->failure('AI request JSON encoding failed', $started);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if($body === false) return $this->failure('AI request JSON encoding failed', $started);
        if(strlen($body) > self::MAX_REQUEST_BYTES) return $this->failure('AI request exceeded size limit', $started);
        $requestSha256 = hash('sha256', $body);

        try {
            $transport = $this->performTransport($endpoint, $endpointHost, $endpointIp, $body, $apiKey, $stream);
        } catch(\Throwable $e){
            return $this->failure('AI transport initialization failed', $started, $requestSha256);
        }
        $response = $transport['response'];
        $responseSha256 = hash('sha256', $response);
        if($transport['overflow'] || strlen($response) > self::MAX_RESPONSE_BYTES) return $this->failure('AI response exceeded size limit', $started, $requestSha256, $responseSha256);
        if(!$transport['ok']) return $this->failure('AI transport failed: '.$this->safeError($transport['error']), $started, $requestSha256, $responseSha256);
        if($transport['http_code'] < 200 || $transport['http_code'] >= 300) return $this->failure($this->httpFailure($transport), $started, $requestSha256, $responseSha256);

        $decoded = $this->decodeCompletionResponse($response, $stream);
        if(empty($decoded['ok'])) return $this->failure($decoded['error'], $started, $requestSha256, $responseSha256);
        $finishReason = $decoded['finish_reason'];
        if($finishReason === 'length') return $this->failure('AI response was truncated at token limit', $started, $requestSha256, $responseSha256);
        $content = $decoded['content'];
        $result = json_decode($this->stripJsonFence($content), true);
        if(!is_array($result)) return $this->failure('AI content is not valid JSON', $started, $requestSha256, $responseSha256);
        $usage = isset($decoded['usage']) && is_array($decoded['usage']) ? $decoded['usage'] : [];
        $providerUsage = isset($usage['prompt_tokens']) && isset($usage['completion_tokens']);
        $this->lastError = null;
        return [
            'ok'=>true,
            'duration_ms'=>(int)round((microtime(true) - $started) * 1000),
            'first_byte_ms'=>isset($transport['first_byte_ms']) ? $transport['first_byte_ms'] : null,
            'stream_events'=>isset($decoded['events']) ? $decoded['events'] : 0,
            'request_sha256'=>$requestSha256,
            'response_sha256'=>$responseSha256,
            'request_bytes'=>strlen($body),
            'response_bytes'=>strlen($response),
            'input_tokens'=>$providerUsage ? intval($usage['prompt_tokens']) : $this->estimateTokens($payload['messages'][0]['content']."\n".$payload['messages'][1]['content']),
            'output_tokens'=>$providerUsage ? intval($usage['completion_tokens']) : $this->estimateTokens($content),
            'token_source'=>$providerUsage ? 'provider' : 'estimated',
            'data'=>$result,
        ];
    }

    public function analyze($metrics, $rules)
    {
        $started = microtime(true);
        $secrets = self::loadSecretEnvironment();
        $apiKey = isset($secrets['HEALTH_AI_API_KEY']) ? $secrets['HEALTH_AI_API_KEY'] : getenv('HEALTH_AI_API_KEY');
        $allowed = isset($secrets['HEALTH_AI_ALLOWED_HOSTS']) ? $secrets['HEALTH_AI_ALLOWED_HOSTS'] : getenv('HEALTH_AI_ALLOWED_HOSTS');
        $allowedModels = isset($secrets['HEALTH_AI_ALLOWED_MODELS']) ? $secrets['HEALTH_AI_ALLOWED_MODELS'] : getenv('HEALTH_AI_ALLOWED_MODELS');
        $pinnedIps = isset($secrets['HEALTH_AI_PINNED_IPV4']) ? $secrets['HEALTH_AI_PINNED_IPV4'] : getenv('HEALTH_AI_PINNED_IPV4');
        $baseUrl = trim(isset($this->config['base_url']) ? (string)$this->config['base_url'] : '');
        $model = trim(isset($this->config['model']) ? (string)$this->config['model'] : '');
        if($apiKey === '' || $apiKey === false) return $this->failure('AI API key is not configured', $started);
        if($model === '') return $this->failure('AI model is not configured', $started);
        if(!$this->modelAllowed($model, $allowedModels)) return $this->failure('AI model is not allowlisted', $started);

        $minSample = $this->boundedInt(isset($this->config['min_sample']) ? $this->config['min_sample'] : 10, 5, 1000);
        if(intval(isset($metrics['platform']['total_orders']) ? $metrics['platform']['total_orders'] : 0) < $minSample){
            return $this->failure('Local order sample is below the configured AI threshold', $started);
        }
        $allowedScopes = [];
        foreach(isset($metrics['channels']) && is_array($metrics['channels']) ? $metrics['channels'] : [] as $id => $channel){
            if(intval(isset($channel['total_orders']) ? $channel['total_orders'] : 0) >= $minSample) $allowedScopes['channel:'.intval($id)] = true;
        }
        $sanitizedRules = $this->sanitizeRules($rules, $allowedScopes);
        $allowedFindings = $this->allowedFindings($rules, $allowedScopes);
        if(!$allowedFindings) return $this->failure('No local rule issue is available for grounded AI advice', $started);

        try {
            $endpoint = $this->buildEndpoint($baseUrl, $allowed);
            $endpointHost = strtolower((string)parse_url($endpoint, PHP_URL_HOST));
            $endpointIp = $this->resolvePublicIpv4($endpointHost, $pinnedIps);
        } catch(\Exception $e){
            return $this->failure($e->getMessage(), $started);
        }

        $payload = [
            'model'=>$model,
            'temperature'=>0.1,
            'max_tokens'=>$this->boundedInt(isset($this->config['max_tokens']) ? $this->config['max_tokens'] : 1200, 300, 3000),
            'response_format'=>['type'=>'json_object'],
            'messages'=>[
                ['role'=>'system', 'content'=>$this->systemPrompt()],
                ['role'=>'user', 'content'=>json_encode($this->buildUserPayload($sanitizedRules), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if($body === false) return $this->failure('AI request JSON encoding failed', $started);
        if(strlen($body) > self::MAX_REQUEST_BYTES) return $this->failure('AI request exceeded size limit', $started);
        $requestSha256 = hash('sha256', $body);

        try {
            $transport = $this->performTransport($endpoint, $endpointHost, $endpointIp, $body, $apiKey, false);
        } catch(\Throwable $e){
            return $this->failure('AI transport initialization failed', $started, $requestSha256);
        }
        $response = $transport['response'];
        $responseSha256 = hash('sha256', $response);
        if($transport['overflow'] || strlen($response) > self::MAX_RESPONSE_BYTES){
            return $this->failure('AI response exceeded size limit', $started, $requestSha256, $responseSha256);
        }
        if(!$transport['ok']){
            return $this->failure('AI transport failed: '.$this->safeError($transport['error']), $started, $requestSha256, $responseSha256);
        }
        if($transport['http_code'] < 200 || $transport['http_code'] >= 300){
            return $this->failure($this->httpFailure($transport), $started, $requestSha256, $responseSha256);
        }

        $outer = json_decode($response, true);
        if(!is_array($outer)) return $this->failure('AI response is not valid JSON', $started, $requestSha256, $responseSha256);
        $finishReason = isset($outer['choices'][0]['finish_reason']) ? (string)$outer['choices'][0]['finish_reason'] : '';
        if($finishReason === 'length') return $this->failure('AI response was truncated at token limit', $started, $requestSha256, $responseSha256);
        $content = isset($outer['choices'][0]['message']['content']) ? $outer['choices'][0]['message']['content'] : null;
        if(!is_string($content)) return $this->failure('AI response content is missing', $started, $requestSha256, $responseSha256);
        $content = $this->stripJsonFence($content);
        $result = json_decode($content, true);
        if(!is_array($result)) return $this->failure('AI content is not valid JSON', $started, $requestSha256, $responseSha256);
        try {
            $result = $this->validateResult($result, $allowedFindings);
        } catch(\Exception $e){
            return $this->failure($e->getMessage(), $started, $requestSha256, $responseSha256);
        }

        $this->lastError = null;
        return ['ok'=>true, 'duration_ms'=>(int)round((microtime(true) - $started) * 1000),
            'request_sha256'=>$requestSha256, 'response_sha256'=>$responseSha256, 'data'=>$result];
    }

    private function decodeCompletionResponse($response, $stream)
    {
        if($stream){
            $streamResult = $this->decodeEventStream($response);
            if(!empty($streamResult['ok'])) return $streamResult;
            $trimmed = ltrim((string)$response);
            if($trimmed === '' || $trimmed[0] !== '{') return $streamResult;
        }

        $outer = json_decode($response, true);
        if(!is_array($outer)) return ['ok'=>false, 'error'=>'AI response is not valid JSON'];
        $content = isset($outer['choices'][0]['message']['content']) ? $outer['choices'][0]['message']['content'] : null;
        if(!is_string($content)) return ['ok'=>false, 'error'=>'AI response content is missing'];
        return [
            'ok'=>true,
            'content'=>$content,
            'finish_reason'=>isset($outer['choices'][0]['finish_reason']) ? (string)$outer['choices'][0]['finish_reason'] : '',
            'events'=>0,
            'usage'=>isset($outer['usage']) && is_array($outer['usage']) ? $outer['usage'] : [],
        ];
    }

    private function decodeEventStream($response)
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", (string)$response);
        $content = '';
        $finishReason = '';
        $events = 0;
        $done = false;
        $usage = [];
        foreach(preg_split('/\n\n+/', $normalized) as $block){
            $dataLines = [];
            foreach(explode("\n", $block) as $line){
                if(strpos($line, 'data:') !== 0) continue;
                $dataLines[] = ltrim(substr($line, 5));
            }
            if(!$dataLines) continue;
            $data = implode("\n", $dataLines);
            if($data === '[DONE]'){
                $done = true;
                continue;
            }
            $event = json_decode($data, true);
            if(!is_array($event)) continue;
            if(isset($event['usage']) && is_array($event['usage'])) $usage = $event['usage'];
            if(!isset($event['choices'][0]) || !is_array($event['choices'][0])) continue;
            $events++;
            $choice = $event['choices'][0];
            if(isset($choice['delta']['content']) && is_string($choice['delta']['content'])) $content .= $choice['delta']['content'];
            elseif(isset($choice['message']['content']) && is_string($choice['message']['content'])) $content .= $choice['message']['content'];
            if(isset($choice['finish_reason']) && $choice['finish_reason'] !== null) $finishReason = (string)$choice['finish_reason'];
        }
        if($events === 0) return ['ok'=>false, 'error'=>'AI event stream contained no valid events'];
        if(!$done && $finishReason === '') return ['ok'=>false, 'error'=>'AI event stream ended before completion'];
        if($finishReason === 'length') return ['ok'=>true, 'content'=>$content, 'finish_reason'=>$finishReason, 'events'=>$events, 'done'=>$done, 'usage'=>$usage];
        if($content === '') return ['ok'=>false, 'error'=>'AI response content is missing'];
        return ['ok'=>true, 'content'=>$content, 'finish_reason'=>$finishReason, 'events'=>$events, 'done'=>$done, 'usage'=>$usage];
    }

    private function estimateTokens($value)
    {
        $value = (string)$value;
        if($value === '') return 0;
        $characters = mb_strlen($value, 'UTF-8');
        preg_match_all('/[\x00-\x7F]/', $value, $matches);
        $ascii = count($matches[0]);
        return (int)ceil($ascii / 4 + max(0, $characters - $ascii));
    }

    private function httpFailure($transport)
    {
        $code = intval(isset($transport['http_code']) ? $transport['http_code'] : 0);
        $message = 'AI HTTP '.$code;
        $headers = isset($transport['headers']) && is_array($transport['headers']) ? $transport['headers'] : [];
        if($code === 429 && isset($headers['retry-after'])){
            $retryAfter = preg_replace('/[^A-Za-z0-9 ,:+-]/', '', (string)$headers['retry-after']);
            if($retryAfter !== '') $message .= ' retry-after='.mb_substr($retryAfter, 0, 60, 'UTF-8');
        }
        if(isset($headers['cf-ray']) && preg_match('/^[A-Za-z0-9-]{6,80}$/D', (string)$headers['cf-ray'])){
            $message .= ' cf-ray='.$headers['cf-ray'];
        }
        return $message;
    }

    private function performTransport($endpoint, $endpointHost, $endpointIp, $body, $apiKey, $stream = false)
    {
        if(isset($this->config['transport'])){
            if(!is_callable($this->config['transport'])) throw new \RuntimeException('AI test transport is invalid');
            $result = call_user_func($this->config['transport'], [
                'endpoint'=>$endpoint,
                'endpoint_host'=>$endpointHost,
                'endpoint_ip'=>$endpointIp,
                'body'=>$body,
                'stream'=>$stream,
            ]);
            if(!is_array($result) || !array_key_exists('ok', $result) || !array_key_exists('http_code', $result)
                || !array_key_exists('response', $result) || !array_key_exists('error', $result)){
                throw new \RuntimeException('AI test transport returned an invalid result');
            }
            return [
                'ok'=>$result['ok'] === true,
                'http_code'=>intval($result['http_code']),
                'response'=>(string)$result['response'],
                'error'=>(string)$result['error'],
                'overflow'=>!empty($result['overflow']),
                'headers'=>isset($result['headers']) && is_array($result['headers']) ? $result['headers'] : [],
                'first_byte_ms'=>isset($result['first_byte_ms']) ? intval($result['first_byte_ms']) : null,
            ];
        }

        $response = '';
        $overflow = false;
        $headers = [];
        $started = microtime(true);
        $firstByteAt = null;
        $lastActivityAt = $started;
        $abortReason = null;
        $absoluteTimeout = $this->boundedInt(isset($this->config['timeout']) ? $this->config['timeout'] : 600, 60, 600);
        $firstByteTimeout = min($absoluteTimeout, $this->boundedInt(isset($this->config['first_byte_timeout']) ? $this->config['first_byte_timeout'] : 300, 30, 600));
        $idleTimeout = min($absoluteTimeout, $this->boundedInt(isset($this->config['idle_timeout']) ? $this->config['idle_timeout'] : 120, 30, 300));
        $ch = curl_init($endpoint);
        if($ch === false) throw new \RuntimeException('cURL initialization failed');
        curl_setopt_array($ch, [
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$body,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'Accept: '.($stream ? 'text/event-stream' : 'application/json'), 'Authorization: Bearer '.$apiKey],
            CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_MAXREDIRS=>0,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>$absoluteTimeout,
            CURLOPT_NOPROGRESS=>false,
            CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,
            CURLOPT_RESOLVE=>[$endpointHost.':443:'.$endpointIp],
            CURLOPT_PROXY=>'',
            CURLOPT_NOPROXY=>'*',
            CURLOPT_HEADERFUNCTION=>function($curl, $line) use (&$headers){
                $position = strpos($line, ':');
                if($position !== false){
                    $name = strtolower(trim(substr($line, 0, $position)));
                    if(in_array($name, ['retry-after','x-ratelimit-limit-requests','x-ratelimit-remaining-requests','x-ratelimit-reset-requests','cf-ray'], true)){
                        $headers[$name] = mb_substr(trim(substr($line, $position + 1)), 0, 120, 'UTF-8');
                    }
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION=>function($curl, $chunk) use (&$response, &$overflow, &$firstByteAt, &$lastActivityAt){
                $now = microtime(true);
                if($firstByteAt === null) $firstByteAt = $now;
                $lastActivityAt = $now;
                if(strlen($response) + strlen($chunk) > self::MAX_RESPONSE_BYTES){
                    $overflow = true;
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_XFERINFOFUNCTION=>function($curl, $downloadTotal, $downloaded, $uploadTotal, $uploaded) use (&$firstByteAt, &$lastActivityAt, &$abortReason, $started, $firstByteTimeout, $idleTimeout, $stream){
                $now = microtime(true);
                if($firstByteAt === null && ($now - $started) > $firstByteTimeout){
                    $abortReason = 'first response byte timeout after '.$firstByteTimeout.' seconds';
                    return 1;
                }
                if($stream && $firstByteAt !== null && ($now - $lastActivityAt) > $idleTimeout){
                    $abortReason = 'stream idle timeout after '.$idleTimeout.' seconds';
                    return 1;
                }
                return 0;
            },
        ]);
        $ok = curl_exec($ch);
        $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $curlError = $abortReason !== null ? $abortReason : curl_error($ch);
        $firstByteMs = $firstByteAt === null ? null : (int)round(($firstByteAt - $started) * 1000);
        if(PHP_VERSION_ID < 80500) curl_close($ch);
        return ['ok'=>$ok !== false, 'http_code'=>$httpCode, 'response'=>$response, 'error'=>$curlError, 'overflow'=>$overflow,
            'headers'=>$headers, 'first_byte_ms'=>$firstByteMs];
    }

    public static function loadSecretEnvironment($file = null)
    {
        if($file === null){
            $configuredFile = getenv('HEALTH_AI_ENV_FILE');
            $file = $configuredFile !== false && trim($configuredFile) !== '' ? trim($configuredFile) : '/etc/epay/ai-health.env';
        }
        if(!is_readable($file)) return [];
        $result = [];
        foreach(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line){
            $line = trim($line);
            if($line === '' || $line[0] === '#') continue;
            if(!preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches)) continue;
            $value = trim($matches[2]);
            if(strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))){
                $value = substr($value, 1, -1);
            }
            $result[$matches[1]] = $value;
        }
        return $result;
    }

    private function buildEndpoint($baseUrl, $allowedHosts)
    {
        $parts = parse_url($baseUrl);
        if(!is_array($parts) || strtolower(isset($parts['scheme']) ? $parts['scheme'] : '') !== 'https') throw new \InvalidArgumentException('AI base URL must use HTTPS');
        if(empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query'])) throw new \InvalidArgumentException('AI base URL is invalid');
        if(isset($parts['port']) && intval($parts['port']) !== 443) throw new \InvalidArgumentException('AI base URL must use port 443');
        $host = strtolower(rtrim($parts['host'], '.'));
        $hosts = array_filter(array_map(function($item){ return strtolower(rtrim(trim($item), '.')); }, explode(',', (string)$allowedHosts)));
        if(!$hosts || !in_array($host, $hosts, true)) throw new \InvalidArgumentException('AI host is not allowlisted');
        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
        if($path !== '' && !preg_match('#^/[A-Za-z0-9._~/-]*$#D', $path)) throw new \InvalidArgumentException('AI base URL path is invalid');
        if(substr($path, -17) !== '/chat/completions') $path .= '/chat/completions';
        return 'https://'.$host.$path;
    }

    private function resolvePublicIpv4($host, $pinnedConfig = '')
    {
        foreach(array_filter(array_map('trim', explode(',', (string)$pinnedConfig))) as $entry){
            $parts = explode('=', $entry, 2);
            if(count($parts) !== 2) throw new \RuntimeException('AI pinned IP configuration is invalid');
            $pinnedHost = strtolower(rtrim(trim($parts[0]), '.'));
            $pinnedIp = trim($parts[1]);
            if($pinnedHost === strtolower(rtrim($host, '.'))){
                if(!$this->isGloballyRoutableIpv4($pinnedIp)) throw new \RuntimeException('AI pinned IP is not globally routable');
                return $pinnedIp;
            }
        }
        if(filter_var($host, FILTER_VALIDATE_IP)){
            $ips = [$host];
        }else{
            $ips = gethostbynamel($host);
        }
        if(!is_array($ips) || !$ips) throw new \RuntimeException('AI host DNS resolution failed');
        foreach($ips as $ip) if($this->isGloballyRoutableIpv4($ip)) return $ip;
        throw new \RuntimeException('AI host resolved to a non-public address');
    }

    private function buildUserPayload($sanitizedRules)
    {
        return ['rule_result'=>$sanitizedRules];
    }

    private function sanitizeRules($rules, $allowedChannelScopes)
    {
        $result = ['level'=>isset($rules['level']) ? $rules['level'] : 'unknown', 'issues'=>[]];
        foreach(array_slice(isset($rules['issues']) ? $rules['issues'] : [], 0, 20) as $issue){
            $copy = array_intersect_key($issue, array_flip(['level','code','scope']));
            if(isset($copy['scope']) && strpos($copy['scope'], 'channel:') === 0 && empty($allowedChannelScopes[$copy['scope']])) continue;
            if(isset($copy['scope'])) $copy['scope'] = preg_replace('/channel:(\d+)/', 'C$1', $copy['scope']);
            $result['issues'][] = $copy;
        }
        return $result;
    }

    private function allowedFindings($rules, $allowedChannelScopes = [])
    {
        $allowed = [];
        foreach(isset($rules['issues']) && is_array($rules['issues']) ? $rules['issues'] : [] as $issue){
            if(!is_array($issue) || empty($issue['code']) || empty($issue['scope'])) continue;
            $scope = (string)$issue['scope'];
            if(strpos($scope, 'channel:') === 0 && empty($allowedChannelScopes[$scope])) continue;
            $externalScope = preg_replace('/channel:(\d+)/', 'C$1', $scope);
            $copy = $issue;
            $copy['scope'] = $externalScope;
            $allowed[$issue['code'].'|'.$externalScope] = $copy;
        }
        return $allowed;
    }

    private function validateResult($result, $allowedFindings)
    {
        if(!array_key_exists('findings', $result) || !is_array($result['findings'])) throw new \UnexpectedValueException('AI result schema is incomplete');
        $clean = [
            'headline'=>'基于本地规则的人工调查建议',
            'summary'=>'规则事实和证据由本地系统生成，AI 只提供人工调查步骤。',
            'findings'=>[],
        ];
        $seen = [];
        foreach(array_slice($result['findings'], 0, 10) as $finding){
            if(!is_array($finding)) continue;
            $code = isset($finding['rule_code']) ? trim((string)$finding['rule_code']) : '';
            $scope = isset($finding['scope']) ? trim((string)$finding['scope']) : '';
            $key = $code.'|'.$scope;
            if($code === '' || $scope === '' || empty($allowedFindings[$key]) || isset($seen[$key])) continue;
            $issue = $allowedFindings[$key];
            $seen[$key] = true;
            $clean['findings'][] = [
                'severity'=>isset($issue['level']) && in_array($issue['level'], ['info','attention','warning','critical'], true) ? $issue['level'] : 'info',
                'rule_code'=>$code,
                'scope'=>$scope,
                'title'=>$this->boundedText(isset($issue['message']) ? $issue['message'] : $code, 160),
                'evidence'=>$this->formatEvidence(isset($issue['evidence']) ? $issue['evidence'] : []),
                'action'=>$this->playbookFor($code),
            ];
            if(count($clean['findings']) >= 5) break;
        }
        if(!$clean['findings']) throw new \UnexpectedValueException('AI returned no advice grounded in a local rule');
        return $clean;
    }

    private function systemPrompt()
    {
        return '你是支付系统只读规则排序助手。只能从输入的本地规则中选择最值得人工优先复核的项目，不得生成事实、数字、建议或操作指令。输出严格JSON且只包含findings数组；每项只能包含输入中原样存在的rule_code与scope。';
    }

    private function playbookFor($code)
    {
        $playbooks = [
            'data_incomplete'=>'核对小时快照覆盖范围、统计任务退出码与数据库查询日志。',
            'baseline_missing'=>'核对最近7天小时快照覆盖范围，基线完整前不要判断趋势恢复。',
            'no_traffic'=>'核对订单入口访问量、下单接口日志与统计窗口是否正确。',
            'sample_insufficient'=>'核对统计窗口与订单入口流量，等待样本达到阈值后再判断趋势。',
            'notify_failed'=>'核对商户通知队列、响应码与重试日志，并保持回调处理幂等。',
            'notify_pending'=>'核对通知队列积压、最早待重试时间与商户端响应耗时。',
            'success_rate_drop'=>'按通道、金额区间和浏览器环境对照下单至支付页漏斗。',
            'very_low_conversion'=>'抽查未支付订单的入口访问、上游建单结果与支付页跳转日志。',
            'failure_streak'=>'核对通道上游可达性、最近成功订单与连续未支付订单日志。',
            'stale_success'=>'核对通道状态、最近成功订单及上游接口可达性。',
            'high_refund_rate'=>'核对退款订单明细、退款发起来源与对应支付成功记录。',
            'frozen_orders'=>'核对冻结订单明细、冻结原因及相关通道状态。',
        ];
        return isset($playbooks[$code]) ? $playbooks[$code] : '按本地规则证据核对对应服务日志和监控，不执行自动处置。';
    }

    private function stripJsonFence($value)
    {
        $value = trim($value);
        if(preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $value, $matches)) return trim($matches[1]);
        return $value;
    }

    private function modelAllowed($model, $allowedModels)
    {
        if(!preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $model)) return false;
        $models = array_values(array_filter(array_map('trim', explode(',', (string)$allowedModels)), 'strlen'));
        return $models && in_array($model, $models, true);
    }

    private function isGloballyRoutableIpv4($ip)
    {
        if(!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
        $value = sprintf('%u', ip2long($ip));
        $blocked = [
            ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8],
            ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24],
            ['192.88.99.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24],
            ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
        ];
        foreach($blocked as $range){
            $network = sprintf('%u', ip2long($range[0]));
            $size = pow(2, 32 - $range[1]);
            if((float)$value >= (float)$network && (float)$value < (float)$network + $size) return false;
        }
        return true;
    }

    private function boundedText($value, $limit)
    {
        $value = trim(strip_tags((string)$value));
        $value = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $value);
        return mb_substr($value, 0, $limit, 'UTF-8');
    }

    private function formatEvidence($evidence)
    {
        if(!is_array($evidence)) return $this->boundedText($evidence, 240);
        ksort($evidence);
        $parts = [];
        foreach($evidence as $key => $value){
            if(is_bool($value)) $value = $value ? 'true' : 'false';
            elseif($value === null) $value = 'null';
            elseif(!is_scalar($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $parts[] = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$key).'='.$value;
        }
        return $this->boundedText(implode(', ', $parts), 240);
    }

    private function boundedInt($value, $min, $max)
    {
        return max($min, min($max, intval($value)));
    }

    private function safeError($value)
    {
        $value = preg_replace('/https?:\/\/[^\s]+/i', '[url]', (string)$value);
        return mb_substr($value, 0, 180, 'UTF-8');
    }

    private function failure($message, $started, $requestSha256 = null, $responseSha256 = null)
    {
        $this->lastError = $message;
        return [
            'ok'=>false,
            'duration_ms'=>(int)round((microtime(true) - $started) * 1000),
            'error'=>$message,
            'request_sha256'=>$requestSha256,
            'response_sha256'=>$responseSha256,
        ];
    }
}
