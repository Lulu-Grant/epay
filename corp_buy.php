<?php
/**
 * Signed checkout helper for static corp site. Lives on api.helingpay.top only.
 */
$envPath = '/etc/epay/heling-front.env';
$productsPath = '/srv/heling-corp/public/../data/products.json';
if (!is_readable($productsPath)) {
    $productsPath = '/srv/heling-corp/data/products.json';
}
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
if (!is_readable($envPath)) { http_response_code(500); exit('config'); }
$env = load_env($envPath);
$id = isset($_GET['product_id']) ? (string)$_GET['product_id'] : '';
$products = json_decode(@file_get_contents($productsPath) ?: '[]', true) ?: [];
$product = null;
foreach ($products as $p) { if (($p['id'] ?? '') === $id) { $product = $p; break; } }
if (!$product) { http_response_code(404); exit('product'); }
$out_trade_no = 'LP' . date('YmdHis') . random_int(1000, 9999);
$params = [
    'pid' => $env['PID'],
    'type' => 'alipay',
    'out_trade_no' => $out_trade_no,
    'notify_url' => 'https://api.helingpay.top/corp_notify.php',
    'return_url' => 'https://www.helingpay.top/return.html',
    'name' => $product['name'],
    'money' => $product['price'],
    'param' => $product['id'],
    'sitename' => '广州禾领科技',
    'sign_type' => 'MD5',
];
$params['sign'] = md5_sign($params, $env['KEY']);
$action = rtrim($env['API'] ?? 'https://api.helingpay.top/', '/') . '/submit.php';
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>正在前往支付</title></head>
<body>
<form id="pay" method="post" action="<?= htmlspecialchars($action) ?>">
<?php foreach ($params as $k=>$v): ?>
<input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
<?php endforeach; ?>
</form>
<script>document.getElementById('pay').submit();</script>
</body></html>
