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
    $options = \lib\Health\SnapshotCommand::parse(
        array_slice($argv, 1),
        isset($conf['health_snapshot_reconcile_hours']) ? intval($conf['health_snapshot_reconcile_hours']) : 6,
        \lib\Health\MetricsService::MAX_SAFE_BACKFILL_HOURS
    );
    $service = new \lib\Health\MetricsService($DB);
    $days = isset($conf['health_snapshot_days']) ? intval($conf['health_snapshot_days']) : 90;
    if($service->cleanSnapshots($days) === false) throw new RuntimeException('Health snapshot retention cleanup failed');
    if(empty($conf['health_snapshot_enabled']) && !$options['force']){
        echo json_encode(['ok'=>true, 'disabled'=>true], JSON_UNESCAPED_UNICODE).PHP_EOL;
        exit(0);
    }
    $minimumReconcile = max(4, intval(isset($conf['health_snapshot_settle_hours']) ? $conf['health_snapshot_settle_hours'] : 3) + 1);
    $backfill = max($minimumReconcile, $options['backfill']);
    $lockName = 'epay_'.substr(hash('sha256', (defined('DBQZ') ? DBQZ : 'pre').':health_snapshot'), 0, 24);
    if(!$DB->getColumn('SELECT GET_LOCK(:name,0)', [':name'=>$lockName])) throw new RuntimeException('Snapshot task is already running');
    if($DB->exec('SET SESSION max_statement_time=5') === false) $DB->exec('SET SESSION MAX_EXECUTION_TIME=5000');
    $results = $service->saveHourlySnapshots($options['time'], $backfill);
    $result = end($results);
    $DB->getColumn('SELECT RELEASE_LOCK(:name)', [':name'=>$lockName]);
    echo json_encode(['ok'=>true, 'period_start'=>$result['period_start'], 'period_end'=>$result['period_end'],
        'orders'=>$result['platform']['total_orders'], 'reconciled_hours'=>count($results)], JSON_UNESCAPED_UNICODE).PHP_EOL;
} catch(Throwable $e){
    if(isset($DB) && isset($lockName)) $DB->getColumn('SELECT RELEASE_LOCK(:name)', [':name'=>$lockName]);
    fwrite(STDERR, 'health snapshot failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}
