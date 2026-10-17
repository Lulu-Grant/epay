<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
date_default_timezone_set('Asia/Shanghai');
require dirname(__DIR__).'/includes/autoloader.php';
Autoloader::register();

use lib\ListQueryFilter;

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo 'PASS '.$label.PHP_EOL;
        return;
    }
    $failures++;
    echo 'FAIL '.$label.PHP_EOL;
}
function expectError(callable $fn, string $label): void
{
    try {
        $fn();
        check(false, $label);
    } catch (InvalidArgumentException $e) {
        check($e->getMessage() !== '', $label);
    }
}
function shanghai(string $text): int
{
    return (new DateTimeImmutable($text, new DateTimeZone('Asia/Shanghai')))->getTimestamp();
}

$window = ListQueryFilter::recentWindow(shanghai('2026-10-17 15:04:00'));
check($window === ['start'=>'2026-10-11', 'end'=>'2026-10-17'], 'fixed clock is 7 calendar days ending that day');
$rolled = ListQueryFilter::recentWindow(shanghai('2026-10-18 00:00:00'));
check($rolled === ['start'=>'2026-10-12', 'end'=>'2026-10-18'], 'midnight rolls the window forward');

$live = ListQueryFilter::recentWindow();
$admin = ListQueryFilter::payment([]);
check($admin->dateScope === 'recent' && $admin->dateStart === $live['start'] && $admin->dateEnd === $live['end'], 'empty admin dates use the server window');
check(str_contains($admin->where, 'A.addtime>=:starttime') && str_contains($admin->where, 'A.addtime<=:endtime'), 'admin window filters addtime');
check($admin->bind[':starttime'] === $live['start'].' 00:00:00' && $admin->bind[':endtime'] === $live['end'].' 23:59:59', 'window bounds are inclusive calendar days');

$ignored = ListQueryFilter::payment(['date_scope'=>'recent', 'starttime'=>'2020-01-01', 'endtime'=>'2020-01-07']);
check($ignored->dateStart === $live['start'] && !in_array('2020-01-01 00:00:00', $ignored->bind, true), 'posted recent dates cannot widen the window');

foreach (['trade_no'=>'0000000000000000011', 'out_trade_no'=>'fixture-11', 'api_trade_no'=>'api-11'] as $column => $value) {
    $exact = ListQueryFilter::payment([
        'date_scope'=>'recent', 'starttime'=>$live['start'], 'endtime'=>$live['end'],
        'column'=>$column, 'value'=>$value,
    ]);
    check($exact->dateScope === 'exact' && !str_contains($exact->where, 'addtime') && in_array($value, $exact->bind, true), 'exact '.$column.' skips the default window');
}
$named = ListQueryFilter::payment(['column'=>'name', 'value'=>'Fixture']);
check($named->dateScope === 'recent' && str_contains($named->where, 'A.addtime>=:starttime') && str_contains($named->where, 'LIKE'), 'name search keeps the window');

$all = ListQueryFilter::payment(['date_scope'=>'all', 'starttime'=>'2026-10-01', 'endtime'=>'2026-10-02']);
check($all->dateScope === 'all' && $all->dateStart === '' && !str_contains($all->where, 'addtime'), 'all-dates drops the window and posted dates');

$customExact = ListQueryFilter::payment([
    'date_scope'=>'custom', 'starttime'=>'2026-01-01', 'endtime'=>'2026-01-03',
    'column'=>'trade_no', 'value'=>'ABC123',
]);
check($customExact->dateScope === 'custom' && $customExact->bind[':starttime'] === '2026-01-01 00:00:00' && in_array('ABC123', $customExact->bind, true), 'custom range still limits an exact order number');

$legacy = ListQueryFilter::payment(['starttime'=>'2026-09-01', 'endtime'=>'2026-09-02']);
check($legacy->dateScope === 'custom' && $legacy->bind[':endtime'] === '2026-09-02 23:59:59', 'legacy dated posts are honored');
$legacyExact = ListQueryFilter::payment(['column'=>'out_trade_no', 'value'=>'fixture-11']);
check($legacyExact->dateScope === 'exact' && !str_contains($legacyExact->where, 'addtime'), 'legacy exact lookup without dates is unlimited');
$startOnly = ListQueryFilter::payment(['starttime'=>'2026-09-01']);
check($startOnly->dateScope === 'custom' && $startOnly->dateStart === '2026-09-01' && $startOnly->dateEnd === '' && !isset($startOnly->bind[':endtime']), 'start-only range has no end bound');

$emptyCustom = ListQueryFilter::payment(['date_scope'=>'custom']);
check($emptyCustom->dateScope === 'recent' && str_contains($emptyCustom->where, 'addtime'), 'empty custom falls back to the recent window');

$zero = ListQueryFilter::payment(['value'=>'0', 'column'=>'money']);
check(in_array('0', $zero->bind, true) && $zero->dateScope === 'recent', 'zero amount stays a filter inside the window');

expectError(fn()=>ListQueryFilter::payment(['starttime'=>'2026-02-30']), 'impossible day rejected');
expectError(fn()=>ListQueryFilter::payment(['starttime'=>'2026-09-25', 'endtime'=>'2026-09-24']), 'reversed dates rejected');
expectError(fn()=>ListQueryFilter::payment(['starttime'=>"2026-10-01' OR 1=1"]), 'non-date text rejected');
expectError(fn()=>ListQueryFilter::payment(['starttime'=>['2026-10-01']]), 'array date rejected');

check(ListQueryFilter::requestId("abc\r\nSet-Cookie") === '', 'request id rejects header injection');
check(ListQueryFilter::requestId('short') === '' && ListQueryFilter::requestId(20261017) === '', 'request id requires an 8-40 character string');
check(ListQueryFilter::requestId('20261017150405007ab12') === '20261017150405007ab12', 'request id accepts the client token');

$merchant = ListQueryFilter::payment([], 1000);
check($merchant->dateScope === '' && !str_contains($merchant->where, 'addtime') && $merchant->bind[':f0'] === 1000, 'merchant empty dates stay unlimited');
$merchantExact = ListQueryFilter::payment(['kw'=>'T1', 'type'=>1], 1000);
check(!str_contains($merchantExact->where, 'addtime') && in_array('T1', $merchantExact->bind, true) && str_contains($merchantExact->where, 'A.trade_no='), 'merchant order-number search has no default window');
$merchantDates = ListQueryFilter::payment(['starttime'=>'2026-01-01', 'endtime'=>'2026-01-02', 'date_scope'=>'all'], 1000);
check($merchantDates->bind[':starttime'] === '2026-01-01 00:00:00' && $merchantDates->bind[':endtime'] === '2026-01-02 23:59:59', 'merchant posted dates still apply');

if ($failures > 0) {
    fwrite(STDERR, $failures." failed\n");
    exit(1);
}
echo "LIST_QUERY_FILTER_DATE_WINDOW_OK\n";
