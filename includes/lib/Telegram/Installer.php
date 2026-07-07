<?php
namespace lib\Telegram;

class Installer
{
    public static function install()
    {
        global $DB, $CACHE;

        $file = ROOT.'install/addon_telegram.sql';
        if(!file_exists($file)) return false;

        $sql = file_get_contents($file);
        foreach(explode(';', $sql) as $value){
            $value = trim($value);
            if($value === '') continue;
            $DB->exec($value);
        }

        if(function_exists('saveSetting')){
            saveSetting('addon_telegram', '1000');
        }
        if(isset($CACHE)) $CACHE->clear();
        return true;
    }
}
