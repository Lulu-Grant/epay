<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$root = dirname(__DIR__, 2);

if (!function_exists('isEmpty')) {
    function isEmpty($value)
    {
        return $value === null || $value === '';
    }
}

if (!function_exists('base64ToPem')) {
    function base64ToPem($key, $type)
    {
        return "-----BEGIN " . $type . "-----\n" .
            wordwrap($key, 64, "\n", true) .
            "\n-----END " . $type . "-----";
    }
}

require $root . '/includes/lib/Payment.php';

function fail($message)
{
    fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
    exit(1);
}

function ok($message)
{
    echo '[OK] ' . $message . PHP_EOL;
}

function key_body($pem)
{
    return preg_replace('/-----[^-]+-----|\s+/', '', $pem);
}

function sign_content_for_fixture(array $data)
{
    ksort($data);
    $signStr = '';
    foreach ($data as $key => $value) {
        if (is_array($value) || isEmpty($value) || $key === 'sign' || $key === 'sign_type') {
            continue;
        }
        $signStr .= $key . '=' . $value . '&';
    }
    return substr($signStr, 0, -1);
}

$md5Key = 'fixture-md5-key';
$md5Data = array(
    'pid' => '1000',
    'out_trade_no' => 'php84-signature-order',
    'type' => 'alipay',
    'name' => 'PHP84 signature fixture',
    'money' => '1.23',
    'notify_url' => 'https://merchant.example.test/notify',
    'return_url' => 'https://merchant.example.test/return',
    'empty_field' => '',
    'sign_type' => 'MD5',
);
$expectedMd5 = md5(sign_content_for_fixture($md5Data) . $md5Key);
$actualMd5 = \lib\Payment::makeSign($md5Data, $md5Key);
if ($actualMd5 !== $expectedMd5) {
    fail('MD5 signature changed: expected ' . $expectedMd5 . ', got ' . $actualMd5);
}
ok('MD5 signature matches canonical sorted fixture');

$md5Signed = $md5Data;
$md5Signed['sign'] = $actualMd5;
if (!\lib\Payment::verifySign($md5Signed, $md5Key, null)) {
    fail('MD5 verification failed with the correct key');
}
ok('MD5 verification accepts correct key');

if (\lib\Payment::verifySign($md5Signed, 'wrong-md5-key', null)) {
    fail('MD5 verification accepted the wrong key');
}
ok('MD5 verification rejects wrong key');

$private = openssl_pkey_new(array(
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'private_key_bits' => 2048,
));
$wrongPrivate = openssl_pkey_new(array(
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'private_key_bits' => 2048,
));
if (!$private || !$wrongPrivate) {
    fail('Unable to generate RSA fixture keys');
}

openssl_pkey_export($private, $privatePem);
$details = openssl_pkey_get_details($private);
$wrongDetails = openssl_pkey_get_details($wrongPrivate);
if (!$details || empty($details['key']) || !$wrongDetails || empty($wrongDetails['key'])) {
    fail('Unable to export RSA fixture keys');
}

$GLOBALS['conf'] = array(
    'private_key' => key_body($privatePem),
);

$rsaData = array(
    'code' => '0',
    'trade_no' => '2026062212000012345',
    'pay_type' => 'jump',
    'pay_info' => 'https://pay.example.test/submit',
    'timestamp' => '1780000000',
    'sign_type' => 'RSA',
);
$rsaSign = \lib\Payment::makeSign($rsaData, null);
if (!$rsaSign) {
    fail('RSA signature generation failed');
}
ok('RSA signature generated from base64 private key');

$rsaSigned = $rsaData;
$rsaSigned['sign'] = $rsaSign;
if (!\lib\Payment::verifySign($rsaSigned, null, key_body($details['key']))) {
    fail('RSA verification failed with the correct public key');
}
ok('RSA verification accepts correct public key');

if (\lib\Payment::verifySign($rsaSigned, null, key_body($wrongDetails['key']))) {
    fail('RSA verification accepted the wrong public key');
}
ok('RSA verification rejects wrong public key');

$pem = base64ToPem(key_body($details['key']), 'PUBLIC KEY');
if (strpos($pem, '-----BEGIN PUBLIC KEY-----') !== 0 || strpos($pem, '-----END PUBLIC KEY-----') === false) {
    fail('base64ToPem did not produce a valid public key wrapper');
}
ok('PEM/base64 conversion wrapper is valid');

ok('signature checks passed');
