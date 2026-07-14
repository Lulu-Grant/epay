#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$nosession = true;
$rootDir = dirname(__DIR__);
chdir($rootDir);
require './includes/common.php';

$limit = 200;
foreach (array_slice($argv, 1) as $argument) {
    if (strpos($argument, '--limit=') === 0) {
        $limit = intval(substr($argument, 8));
    }
}
$limit = max(1, min(1000, $limit));

$lockPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'epay-shop-shadow-'.substr(md5($rootDir), 0, 12).'.lock';
$lockHandle = fopen($lockPath, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo json_encode(array(
        'code' => 0,
        'msg' => 'another reconciliation process is running',
        'locked' => true,
        'time' => date('Y-m-d H:i:s'),
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

$exitCode = 0;
try {
    $result = \lib\Shop\OrderService::reconcilePending($limit);
    $result['code'] = empty($result['failed']) ? 0 : 1;
    $result['time'] = date('Y-m-d H:i:s');
    $exitCode = intval($result['code']);
} catch (Exception $e) {
    $result = array(
        'code' => 1,
        'failed' => 1,
        'message' => $e->getMessage(),
        'time' => date('Y-m-d H:i:s'),
    );
    $exitCode = 1;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
exit($exitCode);
