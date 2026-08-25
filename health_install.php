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
    if(!\lib\Health\Installer::install()) throw new RuntimeException('Health report addon install failed');
    if(!\lib\Health\Installer::isInstalled()) throw new RuntimeException('Health report schema verification failed');
    echo json_encode(['ok'=>true, 'version'=>\lib\Health\Installer::VERSION], JSON_UNESCAPED_UNICODE).PHP_EOL;
} catch(Throwable $e){
    fwrite(STDERR, 'health install failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}
