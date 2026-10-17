<?php
/**
 * 运行: php tests/AdminOrderListFilterTest.php
 */
date_default_timezone_set('Asia/Shanghai');
require dirname(__DIR__).'/includes/lib/AdminOrderListFilter.php';

use lib\AdminOrderListFilter;

$failures = 0;
function check($cond, $msg){
	global $failures;
	if(!$cond){
		fwrite(STDERR, "FAIL: {$msg}\n");
		$failures++;
	}
}

$now = (new DateTimeImmutable('2026-10-17 15:04:00', new DateTimeZone('Asia/Shanghai')))->getTimestamp();

$recent = AdminOrderListFilter::timeFilter([], $now);
check($recent['scope'] === 'recent', 'empty post scope');
check($recent['start'] === '2026-10-11', 'recent start '.$recent['start']);
check($recent['end'] === '2026-10-17', 'recent end '.$recent['end']);
check($recent['sql'] === " AND A.addtime>='2026-10-11 00:00:00' AND A.addtime<='2026-10-17 23:59:59'", 'recent sql '.$recent['sql']);
check(strpos($recent['sql'], 'A.addtime') !== false, 'filter uses addtime');
check(strpos($recent['sql'], '`date`') === false, 'filter does not use completion date');

$prefilled = AdminOrderListFilter::timeFilter([
	'date_scope' => 'recent',
	'starttime' => '2026-10-11',
	'endtime' => '2026-10-17',
], $now);
check($prefilled['scope'] === 'recent' && $prefilled['sql'] === $recent['sql'], 'prefilled recent window');

$exact = AdminOrderListFilter::timeFilter([
	'column' => 'trade_no',
	'value' => '2020010112000012345',
	'date_scope' => 'recent',
	'starttime' => '2026-10-11',
	'endtime' => '2026-10-17',
], $now);
check($exact['scope'] === 'exact' && $exact['sql'] === '' && $exact['exact'] === true, 'trade_no skips default window');

foreach(['out_trade_no', 'api_trade_no'] as $column){
	$row = AdminOrderListFilter::timeFilter([
		'column' => $column,
		'value' => 'OLD-1',
		'date_scope' => 'recent',
		'starttime' => '2026-10-11',
		'endtime' => '2026-10-17',
	], $now);
	check($row['scope'] === 'exact' && $row['sql'] === '', $column.' skips default window');
}

$name = AdminOrderListFilter::timeFilter([
	'column' => 'name',
	'value' => '会员',
	'date_scope' => 'recent',
	'starttime' => '2026-10-11',
	'endtime' => '2026-10-17',
], $now);
check($name['scope'] === 'recent' && $name['sql'] === $recent['sql'], 'name search keeps the window');

$all = AdminOrderListFilter::timeFilter([
	'date_scope' => 'all',
	'starttime' => '2026-10-11',
	'endtime' => '2026-10-17',
], $now);
check($all['scope'] === 'all' && $all['sql'] === '', 'all dates');

$customExact = AdminOrderListFilter::timeFilter([
	'column' => 'trade_no',
	'value' => '2020010112000012345',
	'date_scope' => 'custom',
	'starttime' => '2020-01-01',
	'endtime' => '2020-01-31',
], $now);
check($customExact['scope'] === 'custom', 'custom scope with trade_no');
check($customExact['sql'] === " AND A.addtime>='2020-01-01 00:00:00' AND A.addtime<='2020-01-31 23:59:59'", 'custom range still applies '.$customExact['sql']);

$legacy = AdminOrderListFilter::timeFilter([
	'column' => 'trade_no',
	'value' => '2020010112000012345',
	'starttime' => '2020-05-01',
	'endtime' => '2020-05-02',
], $now);
check($legacy['scope'] === 'custom' && strpos($legacy['sql'], '2020-05-01') !== false, 'legacy explicit dates still apply');

$legacyEmpty = AdminOrderListFilter::timeFilter([
	'column' => 'out_trade_no',
	'value' => 'MCH1',
], $now);
check($legacyEmpty['scope'] === 'exact' && $legacyEmpty['sql'] === '', 'legacy exact lookup without dates');

$onlyStart = AdminOrderListFilter::timeFilter([
	'date_scope' => 'custom',
	'starttime' => '2024-02-01',
], $now);
check($onlyStart['sql'] === " AND A.addtime>='2024-02-01 00:00:00'" && $onlyStart['end'] === '', 'start only');

$bad = AdminOrderListFilter::timeFilter([
	'date_scope' => 'custom',
	'starttime' => "2020-01-01' OR 1=1 --",
	'endtime' => '2020-01-31',
], $now);
check($bad['start'] === '' && strpos($bad['sql'], 'OR') === false, 'invalid start dropped');
check($bad['sql'] === " AND A.addtime<='2020-01-31 23:59:59'", 'valid end kept '.$bad['sql']);

$badDay = AdminOrderListFilter::timeFilter([
	'date_scope' => 'custom',
	'starttime' => '2026-02-31',
	'endtime' => '2026-03-01',
], $now);
check($badDay['start'] === '' && strpos($badDay['sql'], '2026-02-31') === false, 'impossible day dropped');

$emptyCustom = AdminOrderListFilter::timeFilter([
	'date_scope' => 'custom',
	'starttime' => '',
	'endtime' => '',
], $now);
check($emptyCustom['scope'] === 'recent' && $emptyCustom['sql'] === $recent['sql'], 'empty custom falls back to recent');

$arrays = AdminOrderListFilter::timeFilter([
	'column' => ['trade_no'],
	'value' => ['x'],
	'starttime' => ['2020-01-01'],
	'date_scope' => ['all'],
], $now);
check($arrays['scope'] === 'recent' && $arrays['sql'] === $recent['sql'], 'array params do not throw');

check(AdminOrderListFilter::sanitizeRequestId("20261017150400123ab\r\nX-Ignore: 1") === '', 'request id rejects crlf');
check(AdminOrderListFilter::sanitizeRequestId('20261017150400123abcd') === '20261017150400123abcd', 'request id accepts token');
check(AdminOrderListFilter::sanitizeRequestId('short') === '', 'request id rejects short token');

$midnight = (new DateTimeImmutable('2026-10-18 00:10:00', new DateTimeZone('Asia/Shanghai')))->getTimestamp();
$next = AdminOrderListFilter::timeFilter(['date_scope'=>'recent'], $midnight);
check($next['start'] === '2026-10-12' && $next['end'] === '2026-10-18', 'window ends on the request day');

if($failures > 0){
	fwrite(STDERR, "{$failures} failed\n");
	exit(1);
}
echo "ok\n";
