<?php

require_once dirname(__DIR__).'/includes/functions.php';

function order_number_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$seen = [];
for ($i = 0; $i < 200; $i++) {
    $tradeNo = generate_trade_no();
    order_number_assert(
        preg_match('/^LP[0-9]{19}$/D', $tradeNo) === 1,
        'system order number must use the LP prefix and 19 numeric characters'
    );
    $seen[$tradeNo] = true;
}
order_number_assert(count($seen) >= 195, 'order number generator has excessive collisions');

$callSites = [
    'admin/ajax_pay.php' => 1,
    'user/ajax.php' => 2,
    'user/ajax2.php' => 2,
    'paypage/ajax.php' => 1,
    'includes/lib/Shop/OrderService.php' => 1,
    'includes/lib/api/Pay.php' => 2,
];

foreach ($callSites as $relativePath => $minimumCalls) {
    $contents = file_get_contents(dirname(__DIR__).'/'.$relativePath);
    order_number_assert($contents !== false, "cannot read {$relativePath}");
    order_number_assert(
        substr_count($contents, 'generate_trade_no()') >= $minimumCalls,
        "{$relativePath} bypasses the shared P5 order number generator"
    );
}

$pluginLoader = file_get_contents(dirname(__DIR__).'/includes/lib/Plugin.php');
order_number_assert($pluginLoader !== false, 'cannot read includes/lib/Plugin.php');
order_number_assert(
    strpos($pluginLoader, '([A-Za-z0-9]+)\\/$/') !== false,
    'payment route rejects the LP order number prefix'
);
order_number_assert(
    substr_count($pluginLoader, "preg_match('/^[A-Za-z0-9]+$/',\$trade_no)") >= 2,
    'plugin submit or refund validation rejects the LP order number prefix'
);
order_number_assert(
    strpos($pluginLoader, "preg_match('/^(.[0-9]+)$/',\$trade_no)") === false,
    'legacy numeric-only plugin validation must not return'
);

$schemaFiles = [
    'install/install.sql',
    'install/update2.sql',
    'install/update3.sql',
    'install/addon_shop.sql',
    'tools/complain-plugin/install.php',
];
foreach ($schemaFiles as $relativePath) {
    $contents = file_get_contents(dirname(__DIR__).'/'.$relativePath);
    order_number_assert($contents !== false, "cannot read {$relativePath}");
    order_number_assert(
        preg_match('/`(?:pay_)?trade_no`\s+char\(19\)/i', $contents) !== 1,
        "{$relativePath} cannot store a prefixed P5 order number"
    );
}

echo "order_number_regression_ok\n";
