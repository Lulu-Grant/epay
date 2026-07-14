#!/usr/bin/env php
<?php
namespace Alipay {
	class AlipayTradeService {
		public static $valid = true;
		public function __construct($config){}
		public function check($data){
			return self::$valid;
		}
	}
}

namespace lib {
	class Payment {
		public static $calls = [];
		public static $throw = false;
		public static function processOrder($isnotify, $order, $api_trade_no, $buyer){
			if(self::$throw) throw new \RuntimeException('simulated processing failure');
			self::$calls[] = [$isnotify, $order, $api_trade_no, $buyer];
		}
	}
}

namespace {
	if(PHP_SAPI !== 'cli'){
		http_response_code(404);
		exit;
	}

	$root = dirname(__DIR__);
	define('PLUGIN_ROOT', $root.'/plugins/');
	define('PAY_ROOT', $root.'/plugins/alipay/');
	define('TRADE_NO', '2026071412000000001');
	require $root.'/includes/functions.php';
	require $root.'/plugins/alipay/alipay_plugin.php';

	$failures = [];
	$assertSame = function($expected, $actual, $label) use (&$failures){
		if($expected !== $actual){
			$failures[] = $label.' expected='.var_export($expected, true).' actual='.var_export($actual, true);
		}
	};

	$assertSame(3000, paymentAmountToCents('30.00'), 'amount with two decimals');
	$assertSame(3050, paymentAmountToCents('30.5'), 'amount with one decimal');
	$assertSame(3000, paymentAmountToCents('30'), 'amount without decimals');
	$assertSame(false, paymentAmountToCents('30.001'), 'amount with excessive precision');
	$assertSame(false, paymentAmountToCents('invalid'), 'invalid amount');

	$assertSame(true, merchantNotifyResponseSucceeded('success'), 'plain success');
	$assertSame(true, merchantNotifyResponseSucceeded('prefix Success suffix'), 'legacy success substring');
	$assertSame(true, merchantNotifyResponseSucceeded('unsuccessful'), 'legacy compatibility remains enabled');
	$assertSame(false, merchantNotifyResponseSucceeded('ok'), 'non-success response');

	$base = strtotime('2026-07-14 12:00:00');
	$delays = [1=>1, 2=>3, 3=>20, 4=>60, 5=>120];
	foreach($delays as $attempt=>$minutes){
		$expected = date('Y-m-d H:i:s', $base + $minutes * 60);
		$assertSame($expected, getMerchantNotifyRetryTime('2026-07-14 12:00:00', $attempt, $base), 'retry attempt '.$attempt);
	}
	$assertSame('2026-07-14 12:31:00', getMerchantNotifyRetryTime('2026-07-14 12:00:00', 3, strtotime('2026-07-14 12:30:00')), 'late retry is not replayed immediately');
	$assertSame(false, getMerchantNotifyRetryTime('2026-07-14 12:00:00', 6, $base), 'invalid retry attempt');

	class PaymentCallbackFakeDb {
		public $updateData;
		public $updateWhere;
		public function update($table, $data, $where){
			$this->updateData = $data;
			$this->updateWhere = $where;
			return true;
		}
	}

	$DB = new PaymentCallbackFakeDb();
	$assertSame(true, scheduleMerchantNotifyRetry(['trade_no'=>TRADE_NO, 'endtime'=>'2026-07-14 12:00:00'], 1), 'retry scheduling succeeds');
	$assertSame(1, $DB->updateData['notify'], 'retry scheduling stores attempt');
	$assertSame(['trade_no'=>TRADE_NO], $DB->updateWhere, 'retry scheduling targets one order');

	$channel = ['id'=>15, 'plugin'=>'alipay', 'appid'=>'test', 'appkey'=>'test', 'appsecret'=>'test'];
	$order = ['trade_no'=>TRADE_NO, 'realmoney'=>'30.00'];
	$validPost = [
		'out_trade_no'=>TRADE_NO,
		'trade_no'=>'ALIPAY20260714120000',
		'total_amount'=>'30.00',
		'trade_status'=>'TRADE_SUCCESS',
		'buyer_id'=>'2088000000000000',
		'sign'=>'test-sign',
	];

	$_POST = $validPost;
	\Alipay\AlipayTradeService::$valid = true;
	\lib\Payment::$calls = [];
	$result = \alipay_plugin::notify();
	$assertSame('success', $result['data'], 'valid alipay callback');
	$assertSame(1, count(\lib\Payment::$calls), 'valid callback processes order once');
	$assertSame(true, \lib\Payment::$calls[0][0], 'valid callback is asynchronous');

	$_POST = $validPost;
	$_POST['total_amount'] = '30.01';
	\lib\Payment::$calls = [];
	$result = \alipay_plugin::notify();
	$assertSame('fail', $result['data'], 'amount mismatch is rejected');
	$assertSame(0, count(\lib\Payment::$calls), 'amount mismatch does not process order');

	$_POST = $validPost;
	$_POST['out_trade_no'] = '2026071412000000002';
	$result = \alipay_plugin::notify();
	$assertSame('fail', $result['data'], 'trade number mismatch is rejected');

	$_POST = $validPost;
	\Alipay\AlipayTradeService::$valid = false;
	$result = \alipay_plugin::notify();
	$assertSame('fail', $result['data'], 'invalid signature is rejected');

	$_POST = $validPost;
	$_POST['trade_status'] = 'TRADE_FINISHED';
	\Alipay\AlipayTradeService::$valid = true;
	\lib\Payment::$calls = [];
	$result = \alipay_plugin::notify();
	$assertSame('success', $result['data'], 'non-payment state is acknowledged');
	$assertSame(0, count(\lib\Payment::$calls), 'non-payment state does not process order');

	if($failures){
		fwrite(STDERR, implode(PHP_EOL, $failures).PHP_EOL);
		exit(1);
	}

	echo "payment callback regression: ok\n";
}
