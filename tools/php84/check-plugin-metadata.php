<?php
/**
 * Read-only plugin metadata smoke check.
 *
 * This intentionally does not call submit/mapi/notify/return/refund because
 * those methods can require live channel config, database state, certificates,
 * or external payment gateways.
 */

$root = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR;
$pluginRoot = $root . 'plugins' . DIRECTORY_SEPARATOR;

if (!defined('ROOT')) {
    define('ROOT', $root);
}
if (!defined('PLUGIN_ROOT')) {
    define('PLUGIN_ROOT', $pluginRoot);
}
if (!defined('IN_PLUGIN')) {
    define('IN_PLUGIN', true);
}

$requiredInfoKeys = array('name', 'showname', 'author', 'link', 'types');
$p0Plugins = array('alipay', 'wxpay', 'qqpay', 'epay', 'epayn');
$p1Plugins = array('stripe', 'paypal', 'unionpay', 'swiftpass', 'swiftpass2', 'kuaiqian', 'ysepay', 'sandpay');
$p0RequiredMethods = array('submit', 'mapi', 'notify', 'refund');
$p0ExpectedMethods = array('return');
$p1RequiredMethods = array('submit');
$p1ExpectedMethods = array('mapi', 'notify', 'return', 'refund');
$failed = 0;
$warnings = 0;
$checked = 0;

if (!is_dir($pluginRoot)) {
    fwrite(STDERR, 'Plugin directory not found: ' . $pluginRoot . PHP_EOL);
    exit(1);
}

$entries = scandir($pluginRoot);
if ($entries === false) {
    fwrite(STDERR, 'Unable to read plugin directory: ' . $pluginRoot . PHP_EOL);
    exit(1);
}

foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    if (strpos($entry, '.') !== false) {
        continue;
    }

    $pluginFile = $pluginRoot . $entry . DIRECTORY_SEPARATOR . $entry . '_plugin.php';
    if (!is_file($pluginFile)) {
        continue;
    }

    $checked++;
    $className = $entry . '_plugin';

    try {
        include_once $pluginFile;
    } catch (Throwable $e) {
        echo '[FAIL] ' . $entry . ': include failed: ' . $e->getMessage() . PHP_EOL;
        $failed++;
        continue;
    }

    if (!class_exists($className, false)) {
        echo '[FAIL] ' . $entry . ': class not found: ' . $className . PHP_EOL;
        $failed++;
        continue;
    }

    if (!property_exists($className, 'info')) {
        echo '[FAIL] ' . $entry . ': static $info missing' . PHP_EOL;
        $failed++;
        continue;
    }

    $info = $className::$info;
    if (!is_array($info)) {
        echo '[FAIL] ' . $entry . ': static $info is not an array' . PHP_EOL;
        $failed++;
        continue;
    }

    foreach ($requiredInfoKeys as $key) {
        if (!array_key_exists($key, $info)) {
            echo '[FAIL] ' . $entry . ': info key missing: ' . $key . PHP_EOL;
            $failed++;
            continue 2;
        }
    }

    if ($info['name'] !== $entry) {
        echo '[FAIL] ' . $entry . ': info name mismatch: ' . $info['name'] . PHP_EOL;
        $failed++;
        continue;
    }

    if (!is_array($info['types']) || count($info['types']) === 0) {
        echo '[FAIL] ' . $entry . ': info types must be a non-empty array' . PHP_EOL;
        $failed++;
        continue;
    }

    if (in_array($entry, $p0Plugins, true)) {
        foreach ($p0RequiredMethods as $method) {
            if (!method_exists($className, $method)) {
                echo '[FAIL] ' . $entry . ': P0 method missing: ' . $method . PHP_EOL;
                $failed++;
                continue 2;
            }
        }
        foreach ($p0ExpectedMethods as $method) {
            if (!method_exists($className, $method)) {
                echo '[WARN] ' . $entry . ': P0 expected method missing, runtime acceptance must cover behavior: ' . $method . PHP_EOL;
                $warnings++;
            }
        }
        echo '[OK] ' . $entry . ' [P0]: ' . $info['showname'] . PHP_EOL;
        continue;
    }

    if (in_array($entry, $p1Plugins, true)) {
        foreach ($p1RequiredMethods as $method) {
            if (!method_exists($className, $method)) {
                echo '[FAIL] ' . $entry . ': P1 method missing: ' . $method . PHP_EOL;
                $failed++;
                continue 2;
            }
        }
        foreach ($p1ExpectedMethods as $method) {
            if (!method_exists($className, $method)) {
                echo '[WARN] ' . $entry . ': P1 expected method missing or unsupported: ' . $method . PHP_EOL;
                $warnings++;
            }
        }
        echo '[OK] ' . $entry . ' [P1]: ' . $info['showname'] . PHP_EOL;
        continue;
    }

    echo '[OK] ' . $entry . ' [P2]: ' . $info['showname'] . PHP_EOL;
}

echo 'Checked plugin metadata files: ' . $checked . PHP_EOL;
echo 'Plugin compatibility warnings: ' . $warnings . PHP_EOL;

exit($failed > 0 ? 1 : 0);
