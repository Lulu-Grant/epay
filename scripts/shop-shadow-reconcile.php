<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$started = microtime(true);
$lockHandle = null;
$DB = null;
$exitCode = 1;
try {
    if (PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80500) throw new RuntimeException('php84_required');
    $limit = 200;
    $dryRun = false;
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--dry-run') $dryRun = true;
        elseif (preg_match('/^--limit=([1-9][0-9]*)$/D', $argument, $matches)) $limit = min(200, (int)$matches[1]);
        else throw new InvalidArgumentException('invalid_argument');
    }
    date_default_timezone_set('Asia/Shanghai');
    $rootDir = dirname(__DIR__);
    // Avoid HTTP/session/config-cache side effects, especially in dry-run mode.
    require $rootDir.'/config.php';
    require $rootDir.'/includes/autoloader.php';
    require $rootDir.'/includes/functions.php';
    Autoloader::register();
    $siteurl = 'https://localhost/'; // Attachment URLs are neither emitted nor used by this worker.
    $identity = hash('sha256', $dbconfig['host'].':'.$dbconfig['port'].'/'.$dbconfig['dbname'].'/'.$dbconfig['dbqz']);
    $lockDir = getenv('EPAY_SHOP_LOCK_DIR') ?: sys_get_temp_dir();
    $lockPath = rtrim($lockDir, '/\\').DIRECTORY_SEPARATOR.'epay-shop-shadow-'.substr($identity,0,24).'.lock';
    umask(0077);
    $lockHandle = fopen($lockPath, 'c');
    if (!$lockHandle) throw new RuntimeException('lock_open_failed');
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        $result = ['code'=>0, 'locked'=>true, 'dry_run'=>$dryRun];
        $exitCode = 0;
    } else {
        $DB = new \lib\PdoHelper($dbconfig, true);
        $version = (string)$DB->getColumn('SELECT VERSION()');
        if (!str_contains($version, 'MariaDB')) throw new RuntimeException('bounded_worker_requires_mariadb');
        if ($DB->exec('SET SESSION max_statement_time=5') === false || $DB->exec('SET SESSION innodb_lock_wait_timeout=3') === false) {
            throw new RuntimeException('query_timeout_setup_failed');
        }
        if ($dryRun) {
            if ($DB->exec('SET TRANSACTION READ ONLY') === false || !$DB->beginTransaction()) throw new RuntimeException('read_only_transaction_failed');
        }
        $result = \lib\Shop\OrderService::reconcilePending($limit, $dryRun, 45);
        if ($dryRun) $DB->rollBack();
        $result['code'] = empty($result['failed']) ? 0 : 1;
        $exitCode = $result['code'];
    }
} catch (\Throwable $e) {
    if ($DB && $DB->db->inTransaction()) $DB->rollBack();
    $reason = $e instanceof InvalidArgumentException ? 'invalid_argument' : 'reconciliation_failed';
    $result = ['code'=>1, 'failed'=>1, 'reason'=>$reason];
} finally {
    if (is_resource($lockHandle)) { flock($lockHandle, LOCK_UN); fclose($lockHandle); }
}
$result['duration_ms'] = (int)round((microtime(true)-$started)*1000);
$result['time'] = date('c');
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($exitCode);
