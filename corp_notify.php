<?php
$envPath = '/etc/epay/heling-front.env';
function load_env($path) {
    $env = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v);
    }
    return $env;
}
function md5_sign(array $data, string $key): string {
    ksort($data);
    $parts = [];
    foreach ($data as $k => $v) {
        if ($v === '' || $v === null || is_array($v) || $k === 'sign' || $k === 'sign_type') continue;
        $parts[] = $k . '=' . $v;
    }
    return md5(implode('&', $parts) . $key);
}
$env = load_env($envPath);
$data = $_GET ?: $_POST;
if (empty($data['sign'])) exit('fail');
$sign = $data['sign'];
unset($data['sign'], $data['sign_type']);
if (!hash_equals(md5_sign($data, $env['KEY']), $sign)) exit('fail');
@mkdir('/srv/heling-corp/data', 0750, true);
$line = date('c') . "\t" . ($data['out_trade_no'] ?? '') . "\t" . ($data['trade_no'] ?? '') . "\t" . ($data['money'] ?? '') . "\n";
@file_put_contents('/srv/heling-corp/data/paid.log', $line, FILE_APPEND | LOCK_EX);
echo 'success';
