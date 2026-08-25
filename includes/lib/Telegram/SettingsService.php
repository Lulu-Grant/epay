<?php
namespace lib\Telegram;

class SettingsService
{
    private $db;
    private $cache;
    private $config;

    public function __construct($db, $cache, array $config)
    {
        $this->db = $db;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function save(array $input)
    {
        $settings = $this->validate($input);
        $inTransaction = false;
        try {
            if(!$this->db->beginTransaction()) throw new \RuntimeException('Telegram settings transaction failed to start');
            $inTransaction = true;
            foreach($settings as $key=>$value){
                if($this->db->exec('REPLACE INTO pre_config (k,v) VALUES (:key,:value)', [':key'=>$key, ':value'=>$value]) === false){
                    throw new \RuntimeException('Telegram setting write failed');
                }
            }
            if(!$this->db->commit()) throw new \RuntimeException('Telegram settings transaction failed to commit');
            $inTransaction = false;
        } catch(\Throwable $e){
            if($inTransaction){
                try { $this->db->rollBack(); } catch(\Throwable $ignored) {}
            }
            throw $e;
        }
        if($this->cache->clear() === false) throw new \RuntimeException('Telegram settings cache refresh failed');
        return true;
    }

    public function validate(array $input)
    {
        $allowed = [
            'telegram_notice','telegram_bot_token','telegram_admin_chat_id','telegram_bot_name','telegram_proxy',
            'telegram_proxy_server','telegram_proxy_port','telegram_proxy_user','telegram_proxy_pwd','telegram_proxy_type',
        ];
        foreach(array_keys($input) as $field){
            if(!in_array($field, $allowed, true)) throw new \InvalidArgumentException('Telegram 设置字段不受支持');
        }
        $required = [
            'telegram_notice','telegram_admin_chat_id','telegram_bot_name','telegram_proxy',
            'telegram_proxy_server','telegram_proxy_port','telegram_proxy_user','telegram_proxy_type',
        ];
        foreach($required as $field){
            if(!array_key_exists($field, $input)) throw new \InvalidArgumentException('Telegram 设置字段缺失');
        }

        $notice = (string)$input['telegram_notice'];
        $proxyEnabled = (string)$input['telegram_proxy'];
        if(!in_array($notice, ['0','1'], true)) throw new \InvalidArgumentException('Telegram通知开关参数错误');
        if(!in_array($proxyEnabled, ['0','1'], true)) throw new \InvalidArgumentException('Telegram代理开关参数错误');

        $token = isset($input['telegram_bot_token']) ? trim((string)$input['telegram_bot_token']) : '';
        $chatId = trim((string)$input['telegram_admin_chat_id']);
        $botName = trim((string)$input['telegram_bot_name']);
        $server = trim((string)$input['telegram_proxy_server']);
        $portRaw = trim((string)$input['telegram_proxy_port']);
        $proxyUser = trim((string)$input['telegram_proxy_user']);
        $proxyPassword = isset($input['telegram_proxy_pwd']) ? (string)$input['telegram_proxy_pwd'] : '';
        $proxyType = strtolower(trim((string)$input['telegram_proxy_type']));
        foreach([$token,$chatId,$botName,$server,$portRaw,$proxyUser,$proxyPassword,$proxyType] as $value){
            if(strpos($value, "\r") !== false || strpos($value, "\n") !== false){
                throw new \InvalidArgumentException('Telegram 设置不能包含换行符');
            }
        }
        if($token !== '' && strlen($token) > 255) throw new \InvalidArgumentException('Bot Token 过长');
        if($chatId !== '' && !preg_match('/^-?[0-9]{5,32}$/D', $chatId)) throw new \InvalidArgumentException('管理员 Chat ID 格式不正确');
        if(mb_strlen($botName, 'UTF-8') > 100) throw new \InvalidArgumentException('Bot 用户名过长');
        if(strlen($server) > 253 || strlen($proxyUser) > 255 || strlen($proxyPassword) > 255){
            throw new \InvalidArgumentException('Telegram 代理设置过长');
        }
        if(!in_array($proxyType, ['http','https','sock4','sock5','sock5h'], true)){
            throw new \InvalidArgumentException('Telegram代理协议不受支持');
        }

        $port = 0;
        if($portRaw !== ''){
            if(!ctype_digit($portRaw)) throw new \InvalidArgumentException('Telegram代理端口格式错误');
            $port = intval($portRaw);
            if($port < 1 || $port > 65535) throw new \InvalidArgumentException('Telegram代理端口格式错误');
        }
        if($proxyEnabled === '1'){
            $isIp = filter_var($server, FILTER_VALIDATE_IP) !== false;
            $isHost = preg_match('/^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/D', $server) === 1;
            if(!$isIp && !$isHost) throw new \InvalidArgumentException('Telegram代理地址格式错误');
            if($port === 0) throw new \InvalidArgumentException('Telegram代理端口格式错误');
        }

        $portValue = $portRaw === '' ? '' : (string)$port;
        $existingPortRaw = trim(isset($this->config['telegram_proxy_port']) ? (string)$this->config['telegram_proxy_port'] : '');
        $existingPort = $existingPortRaw === '' ? '' : (string)intval($existingPortRaw);
        $existingType = strtolower(trim(isset($this->config['telegram_proxy_type']) ? (string)$this->config['telegram_proxy_type'] : 'sock5h'));
        $identityChanged = $server !== trim(isset($this->config['telegram_proxy_server']) ? (string)$this->config['telegram_proxy_server'] : '')
            || $portValue !== $existingPort
            || $proxyUser !== trim(isset($this->config['telegram_proxy_user']) ? (string)$this->config['telegram_proxy_user'] : '')
            || $proxyType !== $existingType;
        if($identityChanged && !empty($this->config['telegram_proxy_pwd']) && $proxyPassword === ''){
            throw new \InvalidArgumentException('代理地址或账号已变更，请重新输入代理密码');
        }

        $settings = [
            'telegram_notice'=>$notice,
            'telegram_admin_chat_id'=>$chatId,
            'telegram_bot_name'=>$botName,
            'telegram_proxy'=>$proxyEnabled,
            'telegram_proxy_server'=>$server,
            'telegram_proxy_port'=>$portValue,
            'telegram_proxy_user'=>$proxyUser,
            'telegram_proxy_type'=>$proxyType,
        ];
        if($token !== '') $settings['telegram_bot_token'] = $token;
        if($proxyPassword !== '') $settings['telegram_proxy_pwd'] = $proxyPassword;
        return $settings;
    }
}
