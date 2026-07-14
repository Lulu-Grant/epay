<?php
namespace lib\Telegram;

class Installer
{
    public static function install()
    {
        global $DB, $CACHE, $conf;

        $file = ROOT.'install/addon_telegram.sql';
        if(!file_exists($file)) return false;

        $sql = file_get_contents($file);
        foreach(explode(';', $sql) as $value){
            $value = trim($value);
            if($value === '') continue;
            if($DB->exec($value) === false){
                if(method_exists($DB, 'error')){
                    error_log('Telegram addon install failed: '.$DB->error());
                }
                return false;
            }
        }

        if(function_exists('saveSetting')){
            $defaults = [
                'telegram_proxy' => '0',
                'telegram_proxy_server' => '',
                'telegram_proxy_port' => '',
                'telegram_proxy_user' => '',
                'telegram_proxy_pwd' => '',
                'telegram_proxy_type' => 'sock5h',
            ];
            foreach($defaults as $key => $value){
                if(!isset($conf[$key])) saveSetting($key, $value);
            }
            saveSetting('addon_telegram', '1101');
        }
        if(isset($CACHE)) $CACHE->clear();
        return true;
    }
}
