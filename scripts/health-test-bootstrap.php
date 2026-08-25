<?php

function health_test_bootstrap()
{
    $names = ['EPAY_TEST_DB_HOST','EPAY_TEST_DB_PORT','EPAY_TEST_DB_USER','EPAY_TEST_DB_PASSWORD','EPAY_TEST_DB_NAME','EPAY_TEST_DB_PREFIX'];
    $values = [];
    foreach($names as $name){
        $value = getenv($name);
        if($value === false || $value === '') return false;
        $values[$name] = $value;
    }
    if(strpos($values['EPAY_TEST_DB_PREFIX'], 'base64:') === 0){
        $decodedPrefix = base64_decode(substr($values['EPAY_TEST_DB_PREFIX'], 7), true);
        if($decodedPrefix === false || $decodedPrefix === ''){
            fwrite(STDERR, "Invalid encoded test database prefix\n");
            exit(1);
        }
        $values['EPAY_TEST_DB_PREFIX'] = $decodedPrefix;
    }
    if(PHP_SAPI !== 'cli' || !preg_match('/(?:_local|_test)$/D', $values['EPAY_TEST_DB_NAME'])){
        fwrite(STDERR, "Refusing explicit test bootstrap outside a CLI test database\n");
        exit(1);
    }
    if(!preg_match('/^[A-Za-z0-9_]{1,32}$/D', $values['EPAY_TEST_DB_PREFIX'])){
        fwrite(STDERR, "Invalid test database prefix\n");
        exit(1);
    }
    $testHost = strcasecmp($values['EPAY_TEST_DB_HOST'], 'localhost') === 0 ? '127.0.0.1' : $values['EPAY_TEST_DB_HOST'];
    try {
        $probe = new \PDO(
            'mysql:host='.$testHost.';dbname='.$values['EPAY_TEST_DB_NAME'].';port='.intval($values['EPAY_TEST_DB_PORT']),
            $values['EPAY_TEST_DB_USER'],
            $values['EPAY_TEST_DB_PASSWORD'],
            [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT=>5]
        );
        $probe = null;
    } catch(\Throwable $e){
        fwrite(STDERR, "Unable to connect to the isolated test database\n");
        exit(1);
    }
    if(!defined('ROOT')) define('ROOT', dirname(__DIR__).'/');
    if(!defined('DBQZ')) define('DBQZ', $values['EPAY_TEST_DB_PREFIX']);
    require_once ROOT.'includes/lib/PdoHelper.php';
    require_once ROOT.'includes/lib/Cache.php';
    require_once ROOT.'includes/lib/Health/Installer.php';
    require_once ROOT.'includes/lib/Health/MetricsService.php';
    require_once ROOT.'includes/lib/Health/RuleEngine.php';
    require_once ROOT.'includes/lib/Health/AiClient.php';
    require_once ROOT.'includes/lib/Health/RawDataService.php';
    require_once ROOT.'includes/lib/Health/RawAiPipeline.php';
    require_once ROOT.'includes/lib/Health/EvidenceCompactor.php';
    require_once ROOT.'includes/lib/Health/CompactAiPipeline.php';
    require_once ROOT.'includes/lib/Health/TelegramRenderer.php';
    require_once ROOT.'includes/lib/Health/ReportService.php';
    require_once ROOT.'includes/lib/Telegram/BotAPI.php';
    require_once ROOT.'includes/lib/Telegram/NotifyHelper.php';
    require_once ROOT.'includes/lib/Telegram/QueueHelper.php';

    $dbconfig = [
        'host'=>$testHost, 'port'=>intval($values['EPAY_TEST_DB_PORT']),
        'user'=>$values['EPAY_TEST_DB_USER'], 'pwd'=>$values['EPAY_TEST_DB_PASSWORD'],
        'dbname'=>$values['EPAY_TEST_DB_NAME'], 'dbqz'=>$values['EPAY_TEST_DB_PREFIX'],
    ];
    $GLOBALS['dbconfig'] = $dbconfig;
    $GLOBALS['DB'] = new \lib\PdoHelper($dbconfig);
    $GLOBALS['CACHE'] = new \lib\Cache();
    $GLOBALS['conf'] = [];
    if(!function_exists('saveSetting')){
        function saveSetting($key, $value)
        {
            global $DB;
            return $DB->exec('REPLACE INTO pre_config (k,v) VALUES (:key,:value)', [':key'=>$key, ':value'=>$value]);
        }
    }
    $rows = $GLOBALS['DB']->getAll('SELECT k,v FROM pre_config');
    if($rows === false){
        fwrite(STDERR, "Unable to read isolated test configuration\n");
        exit(1);
    }
    foreach($rows as $row) $GLOBALS['conf'][$row['k']] = $row['v'];
    return true;
}
