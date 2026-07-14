<?php
namespace lib\Telegram;

class BotAPI
{
    private $token;
    private $apiUrl = 'https://api.telegram.org/bot';
    private $lastError;

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

    private function request($method, $params = [])
    {
        if (empty($this->token)) {
            $this->lastError = 'Bot token is empty';
            return false;
        }

        $url = $this->apiUrl . $this->token . '/' . $method;
        
        $ch = curl_init();
        
        $getMethods = ['getMe', 'getUpdates', 'getWebhookInfo', 'deleteWebhook'];
        if (in_array($method, $getMethods)) {
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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $requestTimeout = 30;
        if ($method === 'getUpdates' && !empty($params['timeout'])) {
            $requestTimeout = max(30, intval($params['timeout']) + 10);
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
        
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        global $conf;
        $proxyLabel = '';
        $proxyConfig = $this->getProxyConfig(is_array($conf) ? $conf : []);
        if (isset($proxyConfig['error'])) {
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }
            $this->lastError = $proxyConfig['error'];
            return false;
        }
        if (!empty($proxyConfig)) {
            curl_setopt($ch, CURLOPT_PROXY, $proxyConfig['server']);
            curl_setopt($ch, CURLOPT_PROXYPORT, $proxyConfig['port']);
            curl_setopt($ch, CURLOPT_PROXYTYPE, $proxyConfig['type']);
            if ($proxyConfig['user'] !== '' && $proxyConfig['password'] !== '') {
                curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyConfig['user'] . ':' . $proxyConfig['password']);
            }
            $proxyLabel = $proxyConfig['label'];
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        if ($error !== '') {
            $this->lastError = 'CURL Error: ' . $error . ($proxyLabel !== '' ? ' (' . $proxyLabel . ')' : ' (直连)');
            return false;
        }

        if ($httpCode != 200) {
            $this->lastError = 'HTTP Error: ' . $httpCode;
            return false;
        }

        $result = json_decode($response, true);
        
        if (!$result) {
            $this->lastError = 'Invalid JSON response: ' . substr($response, 0, 100);
            return false;
        }

        if (!$result['ok']) {
            $this->lastError = $result['description'] ?? 'Unknown error';
            return false;
        }

        return $result['result'];
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

        $types = [
            'http' => CURLPROXY_HTTP,
            'https' => defined('CURLPROXY_HTTPS') ? constant('CURLPROXY_HTTPS') : CURLPROXY_HTTP,
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
            'password' => isset($config[$prefix . 'pwd']) ? (string)$config[$prefix . 'pwd'] : '',
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
            'parse_mode' => $options['parse_mode'] ?? 'HTML',
        ];
        
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
