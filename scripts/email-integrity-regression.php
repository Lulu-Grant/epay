#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__).'/includes/lib/EmailAddress.php';

use lib\EmailAddress;

function emailAssert($actual, $expected, $label){
    if ($actual !== $expected) {
        fwrite(STDERR, $label.' expected='.var_export($expected, true).' actual='.var_export($actual, true).PHP_EOL);
        exit(1);
    }
}
function emailReject($callback, $label){
    try { $callback(); }
    catch (InvalidArgumentException|RuntimeException $e) { return $e->getMessage(); }
    fwrite(STDERR, $label.' was accepted'.PHP_EOL);
    exit(1);
}
function asciiEmailOfLength($length){
    $suffix = '@x.co';
    if ($length <= 69) return str_repeat('a', $length - strlen($suffix)).$suffix;
    if ($length === 254) return str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 61);
    if ($length === 255) return str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 62);
    throw new RuntimeException('unsupported fixture length');
}

foreach ([31, 32, 33, 40, 254] as $length) {
    $email = asciiEmailOfLength($length);
    emailAssert(strlen($email), $length, 'fixture length '.$length);
    emailAssert(EmailAddress::normalize($email), $email, 'valid email '.$length);
}
emailAssert(EmailAddress::normalize('  User+tag@example.com  '), 'User+tag@example.com', 'outer whitespace is normalized once');
emailAssert(EmailAddress::normalize('', true), '', 'optional empty email');
emailReject(fn()=>EmailAddress::normalize(asciiEmailOfLength(255)), '255-byte email');
emailReject(fn()=>EmailAddress::normalize(str_repeat('a', 65).'@x.co'), '65-byte local part');
emailReject(fn()=>EmailAddress::normalize('usér@example.com'), 'non-ASCII email');
emailReject(fn()=>EmailAddress::normalize("user@example.com\r\nBcc:x@example.com"), 'control characters');
emailReject(fn()=>EmailAddress::normalize('not-an-email'), 'invalid syntax');

class EmailIntegrityFakeDb {
    public int $capacity;
    public string $stored = '';
    public bool $mismatch = false;
    public bool $began = false;
    public bool $committed = false;
    public bool $rolledBack = false;
    public function __construct($capacity){ $this->capacity = $capacity; }
    public function getColumn($sql, $params = []){
        if (str_contains($sql, 'information_schema.COLUMNS')) return $this->capacity;
        if (str_contains($sql, 'SELECT email')) return $this->mismatch ? substr($this->stored, 0, 32) : $this->stored;
        if (str_contains($sql, 'SELECT `to`')) return $this->stored;
        return false;
    }
    public function beginTransaction(){ $this->began = true; return true; }
    public function update($table, $data, $where){ $this->stored = (string)$data['email']; return true; }
    public function commit(){ $this->committed = true; return true; }
    public function rollBack(){ $this->rolledBack = true; return true; }
}

$legacy = new EmailIntegrityFakeDb(32);
EmailAddress::assertStorageCapacity($legacy, asciiEmailOfLength(32), ['user.email','regcode.to']);
$legacyMessage = emailReject(fn()=>EmailAddress::assertStorageCapacity($legacy, asciiEmailOfLength(33), ['user.email']), 'old schema long email');
emailAssert(str_contains($legacyMessage, '32'), true, 'old schema rejection explains capacity');

$expanded = new EmailIntegrityFakeDb(254);
$longEmail = asciiEmailOfLength(254);
EmailAddress::assertStorageCapacity($expanded, $longEmail, ['user.email','regcode.to']);
emailAssert(EmailAddress::updateUser($expanded, 1001, $longEmail), $longEmail, 'expanded schema update');
emailAssert($expanded->began && $expanded->committed && !$expanded->rolledBack, true, 'verified update commits');
emailAssert($expanded->stored, $longEmail, 'stored email remains complete');

$truncating = new EmailIntegrityFakeDb(254);
$truncating->mismatch = true;
emailReject(fn()=>EmailAddress::updateUser($truncating, 1002, asciiEmailOfLength(40)), 'post-write truncation');
emailAssert($truncating->rolledBack && !$truncating->committed, true, 'mismatched write rolls back');

echo "email integrity regression: ok\n";
