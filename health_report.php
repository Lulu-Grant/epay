#!/usr/bin/env php
<?php
if(PHP_SAPI !== 'cli'){
    http_response_code(404);
    exit;
}
$nosession = true;
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__.'/includes/common.php';

try {
    if(!\lib\Health\Installer::isInstalled()) throw new RuntimeException('Health report addon is not installed');
    $options = \lib\Health\ReportCommand::parse(array_slice($argv, 1));
    $service = new \lib\Health\ReportService($DB, $conf);
    if($service->cleanReports(isset($conf['health_report_days']) ? intval($conf['health_report_days']) : 365) === false){
        throw new RuntimeException('Health report retention cleanup failed');
    }
    if(empty($conf['health_report_enabled']) && !$options['force']){
        echo json_encode(['ok'=>true, 'disabled'=>true], JSON_UNESCAPED_UNICODE).PHP_EOL;
        exit(0);
    }
    $lockName = 'epay_'.substr(hash('sha256', (defined('DBQZ') ? DBQZ : 'pre').':health_report_cli'), 0, 24);
    if(!$DB->getColumn('SELECT GET_LOCK(:name,0)', [':name'=>$lockName])) throw new RuntimeException('Report task is already running');
    $reportDate = $options['date'] ?: date('Y-m-d', strtotime('-1 day'));
    $report = $service->generateDaily($reportDate, $options['use_ai'], $options['force']);
    $delivery = null;
    if($options['send']){
        $delivery = $service->enqueueTelegram($report);
        if(empty($delivery['ok']) && !in_array(intval($report['ai_status']),[4,5],true)) throw new RuntimeException('Telegram enqueue failed: '.$delivery['message']);
    }
    $DB->getColumn('SELECT RELEASE_LOCK(:name)', [':name'=>$lockName]);
    echo json_encode(['ok'=>true, 'report_id'=>$report['id'], 'date'=>$reportDate,
        'level'=>$report['rules']['level'], 'ai_status'=>$report['ai_status'], 'ai_calls'=>isset($report['ai_calls'])?$report['ai_calls']:0, 'delivery'=>$delivery], JSON_UNESCAPED_UNICODE).PHP_EOL;
} catch(Throwable $e){
    if(isset($DB) && isset($lockName)) $DB->getColumn('SELECT RELEASE_LOCK(:name)', [':name'=>$lockName]);
    fwrite(STDERR, 'health report failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}
