<?php
namespace lib\Telegram;

class BotAPI
{
    private $token;
    private $apiUrl = 'https://api.telegram.org/bot';
    private $lastError;
    private $lastHttpCode = 0;
    private $lastCurlErrno = 0;
    private $deliveryUncertain = false;

    public function __construct($token)
    {
        $this->token = $token;
    }

    public function setToken($token)
    {
        $this->token = $token;
    }

    public function getLastError()
    {
        return $this->lastError;
    }

    public function isDeterministicFormatError()
    {
        return $this->lastHttpCode === 400 && is_string($this->lastError)
            && stripos($this->lastError, "can't parse entities") !== false;
    }

    public function isDeliveryUncertain()
    {
        return $this->deliveryUncertain;
    }

    private function request($method, $params = [])
    {
        $this->lastError = null;
        $this->lastHttpCode = 0;
        $this->lastCurlErrno = 0;
        $this->deliveryUncertain = false;
        if (empty($this->token)) {
            $this->lastError = 'Bot token is empty';
            return false;
        }

        $url = $this->apiUrl . $this->token . '/' . $method;
        
        $ch = curl_init();
        if($ch === false){
            $this->lastError = 'CURL initialization failed';
            return false;
        }
        
        $getMethods = ['getMe', 'getUpdates', 'getWebhookInfo', 'deleteWebhook'];
        $isPostMethod = !in_array($method, $getMethods, true);
        if (!$isPostMethod) {
            if (!empty($params)) {
                $url .= '?' . http_build_query($params);
            }
            curl_setopt($ch, CURLOPT_POST, false);
        } else {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        }
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        global $conf;
        $policy = $this->transportPolicy($method, $params, is_array($conf) ? $conf : []);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $policy['ssl_verifypeer']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $policy['ssl_verifyhost']);
        curl_setopt($ch, CURLOPT_TIMEOUT, $policy['timeout']);
        curl_setopt($ch, CURLOPT_IPRESOLVE, $policy['ipresolve']);
        curl_setopt($ch, CURLOPT_MAXREDIRS, $policy['maxredirs']);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $policy['followlocation']);
        curl_setopt($ch, CURLOPT_PROXY, '');
        curl_setopt($ch, CURLOPT_NOPROXY, '*');

        $proxyLabel = '';
        $proxyConfig = $policy['proxy'];
        if (isset($proxyConfig['error'])) {
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }
            $this->lastError = $proxyConfig['error'];
            return false;
        }
        if (!empty($proxyConfig)) {
            curl_setopt($ch, CURLOPT_PROXY, $proxyConfig['server']);
            curl_setopt($ch, CURLOPT_NOPROXY, '');
            curl_setopt($ch, CURLOPT_PROXYPORT, $proxyConfig['port']);
            curl_setopt($ch, CURLOPT_PROXYTYPE, $proxyConfig['type']);
            if ($proxyConfig['user'] !== '' && $proxyConfig['auth_value'] !== '') {
                curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyConfig['user'] . ':' . $proxyConfig['auth_value']);
            }
            $proxyLabel = $proxyConfig['label'];
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $error = curl_error($ch);
        $requestSize = intval(curl_getinfo($ch, CURLINFO_REQUEST_SIZE));
        $pretransferTime = (float)curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        $classified = $this->classifyTransportResult($isPostMethod, $response, intval($httpCode), intval($curlErrno), $error, $requestSize, $pretransferTime, $proxyLabel);
        return $classified['ok'] ? $classified['result'] : false;
    }

    private function transportPolicy($method, $params, $config)
    {
        $requestTimeout = 30;
        if ($method === 'getUpdates' && !empty($params['timeout'])) {
            $requestTimeout = max(30, intval($params['timeout']) + 10);
        }
        return [
            'ssl_verifypeer'=>true,
            'ssl_verifyhost'=>2,
            'timeout'=>$requestTimeout,
            'ipresolve'=>CURL_IPRESOLVE_V4,
            'maxredirs'=>0,
            'followlocation'=>false,
            'proxy'=>$this->getProxyConfig($config),
        ];
    }

    private function classifyTransportResult($isPostMethod, $response, $httpCode, $curlErrno, $error, $requestSize, $pretransferTime, $proxyLabel)
    {
        $this->lastError = null;
        $this->lastHttpCode = 0;
        $this->lastCurlErrno = 0;
        $this->deliveryUncertain = false;
        if ($error !== '') {
            $this->lastCurlErrno = intval($curlErrno);
            $this->deliveryUncertain = $this->curlFailureCouldHaveDelivered($isPostMethod, intval($curlErrno), $requestSize, $pretransferTime);
            $this->lastError = 'CURL Error: ' . $error . ($proxyLabel !== '' ? ' (' . $proxyLabel . ')' : ' (直连)');
            return ['ok'=>false, 'result'=>null];
        }
        if ($httpCode !== 200) {
            $this->lastHttpCode = intval($httpCode);
            $errorResult = json_decode((string)$response, true);
            $this->deliveryUncertain = $this->httpFailureCouldHaveDelivered($isPostMethod, intval($httpCode));
            $description = is_array($errorResult) && isset($errorResult['description'])
                ? preg_replace('/bot[0-9]+:[A-Za-z0-9_-]+/i', 'bot[redacted]', (string)$errorResult['description'])
                : '';
            $this->lastError = 'HTTP Error: ' . $httpCode . ($description !== '' ? ' - '.mb_substr($description, 0, 300, 'UTF-8') : '');
            return ['ok'=>false, 'result'=>null];
        }
        $result = json_decode((string)$response, true);
        if (!is_array($result) || !array_key_exists('ok', $result)) {
            $this->deliveryUncertain = (bool)$isPostMethod;
            $this->lastError = 'Invalid JSON response from Telegram';
            return ['ok'=>false, 'result'=>null];
        }
        if (!$result['ok']) {
            $this->lastHttpCode = intval($httpCode);
            $this->lastError = isset($result['description']) ? (string)$result['description'] : 'Unknown error';
            return ['ok'=>false, 'result'=>null];
        }
        return ['ok'=>true, 'result'=>$result['result']];
    }

    private function curlFailureCouldHaveDelivered($isPostMethod, $errno, $requestSize, $pretransferTime = 0.0)
    {
        if(!$isPostMethod) return false;
        if(intval($requestSize) > 0) return true;
        if(intval($errno) === 28) return (float)$pretransferTime > 0;
        return in_array(intval($errno), [18,23,52,55,56,92], true);
    }

    private function httpFailureCouldHaveDelivered($isPostMethod, $httpCode)
    {
        return $isPostMethod && intval($httpCode) >= 500;
    }

    private function getProxyConfig($config)
    {
        if (!empty($config['telegram_proxy'])) {
            return $this->buildProxyConfig($config, 'telegram_proxy_', 'Telegram专用代理', true);
        }
        if (!empty($config['proxy'])) {
            return $this->buildProxyConfig($config, 'proxy_', '全局代理', false);
        }
        return [];
    }

    private function buildProxyConfig($config, $prefix, $label, $strict)
    {
        $server = isset($config[$prefix . 'server']) ? trim((string)$config[$prefix . 'server']) : '';
        $port = isset($config[$prefix . 'port']) ? intval($config[$prefix . 'port']) : 0;
        $typeName = isset($config[$prefix . 'type']) ? strtolower(trim((string)$config[$prefix . 'type'])) : 'http';
        if ($server === '' || $port < 1 || $port > 65535) {
            return $strict ? ['error' => 'Telegram专用代理配置不完整'] : [];
        }

        if($typeName === 'https' && !defined('CURLPROXY_HTTPS')){
            return ['error' => $label.'需要当前 cURL 支持 HTTPS 代理'];
        }
        $types = [
            'http' => CURLPROXY_HTTP,
            'https' => defined('CURLPROXY_HTTPS') ? constant('CURLPROXY_HTTPS') : -1,
            'sock4' => CURLPROXY_SOCKS4,
            'sock5' => CURLPROXY_SOCKS5,
            'sock5h' => defined('CURLPROXY_SOCKS5_HOSTNAME') ? constant('CURLPROXY_SOCKS5_HOSTNAME') : 7,
        ];
        if (!isset($types[$typeName])) {
            return $strict ? ['error' => 'Telegram专用代理协议不受支持'] : [];
        }

        return [
            'server' => $server,
            'port' => $port,
            'type' => $types[$typeName],
            'user' => isset($config[$prefix . 'user']) ? (string)$config[$prefix . 'user'] : '',
            'auth_value' => isset($config[$prefix . 'pwd']) ? (string)$config[$prefix . 'pwd'] : '',
            'label' => $label,
        ];
    }

    public function getMe()
    {
        return $this->request('getMe');
    }

    public function getUpdates($offset = null, $limit = null, $timeout = 0)
    {
        $params = [];
        if ($offset !== null) {
            $params['offset'] = $offset;
        }
        if ($limit !== null) {
            $params['limit'] = $limit;
        }
        if ($timeout > 0) {
            $params['timeout'] = $timeout;
        }
        return $this->request('getUpdates', $params);
    }

    public function setWebhook($url, $options = [])
    {
        $params = ['url' => $url];
        if (isset($options['max_connections'])) {
            $params['max_connections'] = $options['max_connections'];
        }
        if (isset($options['allowed_updates'])) {
            $params['allowed_updates'] = json_encode($options['allowed_updates']);
        }
        return $this->request('setWebhook', $params);
    }

    public function deleteWebhook()
    {
        return $this->request('deleteWebhook');
    }

    public function getWebhookInfo()
    {
        return $this->request('getWebhookInfo');
    }

    public function sendMessage($chatId, $text, $options = [])
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
        ];
        if(!array_key_exists('parse_mode', $options)){
            $params['parse_mode'] = 'HTML';
        }elseif($options['parse_mode'] !== null && $options['parse_mode'] !== ''){
            $params['parse_mode'] = $options['parse_mode'];
        }
        
        if (isset($options['reply_markup'])) {
            $params['reply_markup'] = json_encode($options['reply_markup']);
        }
        if (isset($options['disable_notification'])) {
            $params['disable_notification'] = $options['disable_notification'];
        }
        if (isset($options['reply_to_message_id'])) {
            $params['reply_to_message_id'] = $options['reply_to_message_id'];
        }
        
        return $this->request('sendMessage', $params);
    }

    public function editMessageText($chatId, $messageId, $text, $options = [])
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => $options['parse_mode'] ?? 'HTML',
        ];
        
        if (isset($options['reply_markup'])) {
            $params['reply_markup'] = json_encode($options['reply_markup']);
        }
        
        return $this->request('editMessageText', $params);
    }

    public function deleteMessage($chatId, $messageId)
    {
        return $this->request('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    public function answerCallbackQuery($callbackQueryId, $text = '', $showAlert = false)
    {
        return $this->request('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $showAlert,
        ]);
    }

    public function sendPhoto($chatId, $photo, $options = [])
    {
        $params = [
            'chat_id' => $chatId,
            'photo' => $photo,
        ];
        
        if (isset($options['caption'])) {
            $params['caption'] = $options['caption'];
            $params['parse_mode'] = $options['parse_mode'] ?? 'HTML';
        }
        if (isset($options['reply_markup'])) {
            $params['reply_markup'] = json_encode($options['reply_markup']);
        }
        
        return $this->request('sendPhoto', $params);
    }

    public static function createKeyboard($buttons, $resize = true, $oneTime = false)
    {
        return [
            'keyboard' => $buttons,
            'resize_keyboard' => $resize,
            'one_time_keyboard' => $oneTime,
        ];
    }

    public static function createInlineKeyboard($buttons)
    {
        return [
            'inline_keyboard' => $buttons,
        ];
    }

    public static function createInlineButton($text, $callbackData = null, $url = null)
    {
        $button = ['text' => $text];
        if ($callbackData !== null) {
            $button['callback_data'] = $callbackData;
        }
        if ($url !== null) {
            $button['url'] = $url;
        }
        return $button;
    }

    public function setMyCommands($commands)
    {
        return $this->request('setMyCommands', [
            'commands' => json_encode($commands)
        ]);
    }

    public function deleteMyCommands()
    {
        return $this->request('deleteMyCommands');
    }

    public static function removeKeyboard()
    {
        return [
            'remove_keyboard' => true,
        ];
    }
}
