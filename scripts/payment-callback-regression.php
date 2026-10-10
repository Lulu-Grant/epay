#!/usr/bin/env php
<?php
namespace Alipay {
	class AlipayTradeService {
		public static $valid = true;
		public static $response = null;
		public static $throw = false;
		public static $queriedApps = [];
		private $appid;
		public function __construct($appid){$this->appid = $appid;}
		public function query($trade_no, $out_trade_no){
			self::$queriedApps[] = $this->appid;
			if(self::$throw)throw new \RuntimeException('sensitive upstream exception must not be logged');
			return self::$response ?? ['trade_no'=>$trade_no, 'out_trade_no'=>$out_trade_no, 'total_amount'=>$_POST['total_amount'], 'trade_status'=>$_POST['trade_status']];
		}
	}
	class CallbackVerifier {
		public static $sharedKey = false;
		private $appid;
		public function __construct($appid){$this->appid = $appid;}
		public function check($data){return AlipayTradeService::$valid && (self::$sharedKey || $data['app_id'] === $this->appid);}
	}
}

namespace lib {
	class Channel {
		public static $channels = [];
		public static function get($id){return self::$channels[$id] ?? null;}
	}
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
	$quiet = false;
	foreach(array_slice($argv, 1) as $argument){
		if($argument === '--quiet' && !$quiet) $quiet = true;
		else { fwrite(STDERR, "Unsupported payment callback regression argument\n"); exit(1); }
	}
	if($quiet) ini_set('error_log', '/dev/null');

	$root = dirname(__DIR__);
	define('PLUGIN_ROOT', $root.'/plugins/');
	define('PAY_ROOT', $root.'/plugins/alipay/');
	define('TRADE_NO', '2026071412000000001');
	require $root.'/includes/functions.php';
	require $root.'/plugins/alipay/alipay_plugin.php';
	class LocalAlipayCallback extends \alipay_plugin {
		protected static function notifyServices($selected){
			// Never execute the plugin's configuration loader or access credentials.
			return [new \Alipay\CallbackVerifier($selected['appid']), new \Alipay\AlipayTradeService($selected['appid'])];
		}
	}
	$logfile = tempnam(sys_get_temp_dir(), 'epay-callback-test-');
	ini_set('error_log', $logfile);

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
		public $matches = [];
		public $row;
		public $writeFails = false;
		public $raceRow = null;
		public function getAll($sql, $params){
			return array_values(array_filter($this->matches, function($row) use ($params){
				$channel = \lib\Channel::get($row['id']);
				return $channel && $channel['appid'] === $params[':appid'];
			}));
		}
		public function query($sql, $params){
			if($this->writeFails)return false;
			if($this->raceRow){$this->row = $this->raceRow; return new CallbackStatement(0);}
			if($this->row['channel'] != $params[':previous'] || !in_array($this->row['status'], [0,4], true))return new CallbackStatement(0);
			$this->row['channel'] = $params[':actual'];
			$this->row['subchannel'] = 0;
			return new CallbackStatement(1);
		}
		public function getRow($sql, $params){return $this->row;}
		public function update($table, $data, $where){
			$this->updateData = $data;
			$this->updateWhere = $where;
			return true;
		}
	}
	class CallbackStatement {
		private $count;
		public function __construct($count){$this->count = $count;}
		public function rowCount(){return $this->count;}
	}

	$DB = new PaymentCallbackFakeDb();
	$assertSame(true, scheduleMerchantNotifyRetry(['trade_no'=>TRADE_NO, 'endtime'=>'2026-07-14 12:00:00'], 1), 'retry scheduling succeeds');
	$assertSame(1, $DB->updateData['notify'], 'retry scheduling stores attempt');
	$assertSame(['trade_no'=>TRADE_NO], $DB->updateWhere, 'retry scheduling targets one order');

	$originalChannel = ['id'=>15, 'plugin'=>'alipay', 'appid'=>'2026000000000015'];
	$originalOrder = ['trade_no'=>TRADE_NO, 'realmoney'=>'30.00', 'channel'=>15, 'subchannel'=>0, 'status'=>0, 'type'=>1, 'uid'=>1];
	$channel = $originalChannel;
	$order = $originalOrder;
	$validPost = [
		'app_id'=>$channel['appid'],
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
	$result = LocalAlipayCallback::notify();
	$assertSame('success', $result['data'], 'valid alipay callback');
	$assertSame(1, count(\lib\Payment::$calls), 'valid callback processes order once');
	$assertSame(true, \lib\Payment::$calls[0][0], 'valid callback is asynchronous');

	$_POST = $validPost;
	$_POST['total_amount'] = '30.01';
	\lib\Payment::$calls = [];
	$result = LocalAlipayCallback::notify();
	$assertSame('fail', $result['data'], 'amount mismatch is rejected');
	$assertSame(0, count(\lib\Payment::$calls), 'amount mismatch does not process order');

	$_POST = $validPost;
	$_POST['out_trade_no'] = '2026071412000000002';
	$result = LocalAlipayCallback::notify();
	$assertSame('fail', $result['data'], 'trade number mismatch is rejected');

	$_POST = $validPost;
	\Alipay\AlipayTradeService::$valid = false;
	$result = LocalAlipayCallback::notify();
	$assertSame('fail', $result['data'], 'invalid signature is rejected');

	$_POST = $validPost;
	$_POST['trade_status'] = 'TRADE_FINISHED';
	\Alipay\AlipayTradeService::$valid = true;
	\lib\Payment::$calls = [];
	$result = LocalAlipayCallback::notify();
	$assertSame('success', $result['data'], 'finished payment is acknowledged');
	$assertSame(1, count(\lib\Payment::$calls), 'finished payment processes order');

	$receiving = ['id'=>16, 'plugin'=>'alipay', 'appid'=>'2026000000000016'];
	\lib\Channel::$channels = [16=>$receiving];
	$DB->matches = [['id'=>16]];
	$_POST = $validPost;
	$_POST['app_id'] = $receiving['appid'];
	$_POST += ['sign_type'=>'RSA2', 'charset'=>'utf-8', 'notify_type'=>'trade_status_sync', 'seller_id'=>'2088000000000000'];
	$DB->row = $order = $originalOrder;
	$channel = $originalChannel;
	\lib\Payment::$calls = [];
	\Alipay\AlipayTradeService::$queriedApps = [];
	$result = LocalAlipayCallback::notify();
	$assertSame('success', $result['data'], 'receiving channel fallback succeeds');
	$assertSame(16, $DB->row['channel'], 'receiving route stored');
	$assertSame(16, \lib\Payment::$calls[0][1]['channel'], 'processing uses receiving route');
	$assertSame([$receiving['appid']], \Alipay\AlipayTradeService::$queriedApps, 'query uses only receiving app');
	$DB->row = $order = $originalOrder;
	$channel = $originalChannel;
	\Alipay\CallbackVerifier::$sharedKey = true;
	\Alipay\AlipayTradeService::$queriedApps = [];
	$result = LocalAlipayCallback::notify();
	$assertSame('success', $result['data'], 'shared public key still resolves app mismatch');
	$assertSame([$receiving['appid']], \Alipay\AlipayTradeService::$queriedApps, 'shared public key never queries wrong app');
	\Alipay\CallbackVerifier::$sharedKey = false;

	foreach(['TRADE_FINISHED', 'TRADE_CLOSED', 'WAIT_BUYER_PAY'] as $status){
		$DB->row = $order = $originalOrder;
		$channel = $originalChannel;
		$_POST['trade_status'] = $status;
		\lib\Payment::$calls = [];
		$result = LocalAlipayCallback::notify();
		$assertSame($status === 'TRADE_FINISHED' ? 'success' : 'fail', $result['data'], 'fallback status '.$status);
		$assertSame($status === 'TRADE_FINISHED' ? 16 : 15, $DB->row['channel'], 'fallback status route '.$status);
	}
	$_POST['trade_status'] = 'TRADE_SUCCESS';
	$badQueries = [
		['trade_status'=>'WAIT_BUYER_PAY'], ['total_amount'=>'30.01'], ['out_trade_no'=>'OTHER'], ['trade_no'=>'OTHER'],
	];
	foreach($badQueries as $override){
		$DB->row = $order = $originalOrder;
		$channel = $originalChannel;
		\lib\Payment::$calls = [];
		\Alipay\AlipayTradeService::$response = array_merge(['trade_status'=>'TRADE_SUCCESS', 'trade_no'=>$_POST['trade_no'], 'out_trade_no'=>TRADE_NO, 'total_amount'=>'30.00'], $override);
		$result = LocalAlipayCallback::notify();
		$assertSame('fail', $result['data'], 'query mismatch rejected '.key($override));
		$assertSame(15, $DB->row['channel'], 'query mismatch does not repair route');
		$assertSame(0, count(\lib\Payment::$calls), 'query mismatch does not process');
	}
	\Alipay\AlipayTradeService::$response = null;
	foreach(['invalid-sign', 'no-match', 'ambiguous', 'write-failure', 'query-exception', 'amount-mismatch', 'order-mismatch', 'paid-other-channel', 'race-other-channel', 'race-same-channel'] as $scenario){
		$DB->row = $order = $originalOrder;
		$channel = $originalChannel;
		$_POST = array_merge($validPost, ['app_id'=>$receiving['appid']]);
		$DB->matches = [['id'=>16]];
		$DB->writeFails = false;
		$DB->raceRow = null;
		\Alipay\AlipayTradeService::$valid = true;
		\Alipay\AlipayTradeService::$throw = false;
		\lib\Payment::$calls = [];
		if($scenario === 'invalid-sign')\Alipay\AlipayTradeService::$valid = false;
		if($scenario === 'no-match')$DB->matches = [];
		if($scenario === 'ambiguous')$DB->matches[] = ['id'=>16];
		if($scenario === 'write-failure')$DB->writeFails = true;
		if($scenario === 'query-exception')\Alipay\AlipayTradeService::$throw = true;
		if($scenario === 'amount-mismatch')$_POST['total_amount'] = '31.00';
		if($scenario === 'order-mismatch')$_POST['out_trade_no'] = 'OTHER';
		if($scenario === 'paid-other-channel')$DB->row['status'] = $order['status'] = 1;
		if($scenario === 'race-other-channel')$DB->raceRow = array_merge($originalOrder, ['channel'=>17]);
		if($scenario === 'race-same-channel')$DB->raceRow = array_merge($originalOrder, ['channel'=>16]);
		$result = LocalAlipayCallback::notify();
		$assertSame($scenario === 'race-same-channel' ? 'success' : 'fail', $result['data'], $scenario);
		$assertSame($scenario === 'race-same-channel' ? 1 : 0, count(\lib\Payment::$calls), $scenario.' process count');
	}
	$logs = file_get_contents($logfile);
	$assertSame(true, strpos($logs, '"stage":"verify"') !== false, 'verification stage logged');
	$assertSame(true, strpos($logs, '"stage":"query"') !== false, 'query stage logged separately');
	$assertSame(true, strpos($logs, '"previous_channel":"15"') !== false, 'repair previous channel logged');
	$assertSame(true, strpos($logs, '"actual_channel":"16"') !== false, 'repair actual channel logged');
	$assertSame(false, strpos($logs, 'test-sign') !== false, 'signature excluded');
	$assertSame(false, strpos($logs, 'sensitive upstream exception') !== false, 'exception detail excluded');
	foreach(explode("\n", $logs) as $line){
		$offset = strpos($line, '[payment-callback] ');
		if($offset === false)continue;
		$event = json_decode(substr($line, $offset + strlen('[payment-callback] ')), true);
		if(($event['event'] ?? '') !== 'alipay_notify_rejected')continue;
		foreach(['app_id','app_id_matches','sign_type','charset','notify_type','trade_status','total_amount','seller_id','stage'] as $field){
			$assertSame(true, array_key_exists($field, $event), 'rejection diagnostic field '.$field);
		}
	}
	logPaymentCallbackEvent('test', TRADE_NO, 15, 'safe', ['sign'=>'do-not-log', 'appsecret'=>'do-not-log', 'app_id'=>"safe\nvalue"]);
	$logs = file_get_contents($logfile);
	$assertSame(false, strpos($logs, 'do-not-log') !== false, 'context credentials excluded');
	$assertSame(true, strpos($logs, '"app_id":"safevalue"') !== false, 'context control characters removed');
	unlink($logfile);

	if($failures){
		fwrite(STDERR, implode(PHP_EOL, $failures).PHP_EOL);
		exit(1);
	}

	if(!$quiet) echo "payment callback regression: ok\n";
}
