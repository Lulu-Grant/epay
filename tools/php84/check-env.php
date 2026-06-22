<?php
/**
 * PHP 7.4 compatible environment check for the PHP 8.4 upgrade work.
 *
 * This script is read-only. It does not connect to the database and does not
 * call any payment provider.
 */

$requiredExtensions = array(
    'pdo_mysql',
    'curl',
    'openssl',
    'json',
    'mbstring',
    'gd',
    'fileinfo',
    'session',
);

$recommendedExtensions = array(
    'gmp',
    'bcmath',
    'intl',
    'zip',
    'xml',
);

function print_check_line($label, $ok, $detail)
{
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . ': ' . $detail . PHP_EOL;
}

echo 'PHP binary: ' . PHP_BINARY . PHP_EOL;
echo 'PHP version: ' . PHP_VERSION . PHP_EOL;
echo 'PHP SAPI: ' . PHP_SAPI . PHP_EOL;
echo 'Loaded php.ini: ' . (php_ini_loaded_file() ?: 'none') . PHP_EOL;
echo PHP_EOL;

$failed = 0;

foreach ($requiredExtensions as $extension) {
    $loaded = extension_loaded($extension);
    print_check_line('required extension ' . $extension, $loaded, $loaded ? 'loaded' : 'missing');
    if (!$loaded) {
        $failed++;
    }
}

echo PHP_EOL;

foreach ($recommendedExtensions as $extension) {
    $loaded = extension_loaded($extension);
    print_check_line('recommended extension ' . $extension, $loaded, $loaded ? 'loaded' : 'missing');
}

echo PHP_EOL;

$versionOk = PHP_VERSION_ID >= 70400 && PHP_VERSION_ID < 80500;
$versionTooNew = PHP_VERSION_ID >= 80500;
if ($versionTooNew) {
    echo '[WARN] target PHP range >=7.4 <8.5: ' . PHP_VERSION . ' is newer than target; use PHP 8.4 for final acceptance' . PHP_EOL;
} else {
    print_check_line('target PHP range >=7.4 <8.5', $versionOk, PHP_VERSION);
}
if (!$versionOk && !$versionTooNew) {
    $failed++;
}

exit($failed > 0 ? 1 : 0);
