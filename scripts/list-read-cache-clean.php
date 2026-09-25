<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    if (PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80500) throw new RuntimeException('php84_required');
    $limit = 1000;
    foreach (array_slice($argv, 1) as $argument) {
        if (!preg_match('/^--limit=([1-9][0-9]{0,3})$/D', $argument, $match) || (int)$match[1] > 1000) {
            throw new InvalidArgumentException('invalid_argument');
        }
        $limit = (int)$match[1];
    }
    require dirname(__DIR__).'/config.php';
    require dirname(__DIR__).'/includes/autoloader.php';
    Autoloader::register();
    $result = \lib\ListReadCache::forSite()->clean($limit);
    $code = $result['failed'] > 0 ? 1 : 0;
    echo json_encode(['code'=>$code] + $result).PHP_EOL;
    exit($code);
} catch (Throwable $e) {
    echo json_encode(['code'=>1, 'reason'=>$e instanceof InvalidArgumentException ? 'invalid_argument' : 'cache_cleanup_failed']).PHP_EOL;
    exit(1);
}
