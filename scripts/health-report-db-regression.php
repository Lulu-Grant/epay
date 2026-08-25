#!/usr/bin/env php
<?php
if(PHP_SAPI !== 'cli') exit(1);

$nosession = true;
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__.'/health-test-bootstrap.php';
if(!health_test_bootstrap()) require dirname(__DIR__).'/includes/common.php';
require_once dirname(__DIR__).'/includes/lib/Telegram/SettingsService.php';

$database = (string)$DB->getColumn('SELECT DATABASE()');
if(!preg_match('/(?:_local|_test)$/D', $database)){
    fwrite(STDERR, "Refusing to run against a non-test database\n");
    exit(1);
}

$row = $DB->getRow("SELECT * FROM pre_health_report WHERE telegram_status=0 ORDER BY id DESC LIMIT 1");
if(!$row){
    fwrite(STDERR, "No local health report fixture exists\n");
    exit(1);
}

$originalConf = $conf;
$originalStatus = intval($row['telegram_status']);
$originalQueueId = $row['telegram_queue_id'];
$originalSentAt = $row['sent_at'];
$queueId = 0;
$disabledQueueId = 0;
$deterministicQueueId = 0;
$uncertainQueueId = 0;
$legacyResult = [];
$settingsResult = false;
$failure = null;

try {
    $conf['health_report_enabled'] = '1';
    $conf['telegram_notice'] = '1';
    $conf['telegram_bot_token'] = 'local-regression-token';
    $conf['telegram_admin_chat_id'] = '123456';
    $service = new \lib\Health\ReportService($DB, $conf);
    $report = [
        'id'=>intval($row['id']),
        'report_date'=>$row['report_date'],
        'metrics'=>json_decode($row['metrics_json'], true),
        'rules'=>json_decode($row['rules_json'], true),
        'ai'=>['health_level'=>'healthy','telegram_brief'=>'本地数据库队列回归夹具'],
        'ai_status'=>1,
    ];
    $first = $service->enqueueTelegram($report);
    $second = $service->enqueueTelegram($report);
    if(empty($first['ok']) || empty($second['ok'])) throw new RuntimeException('Queue insertion failed');
    $queueId = intval($first['queue_id']);
    if($queueId <= 0 || $queueId !== intval($second['queue_id'])) throw new RuntimeException('Queue dedupe failed');
    $count = intval($DB->getColumn("SELECT COUNT(*) FROM pre_telegram_notify_queue WHERE id={$queueId} AND scene='daily_health'"));
    if($count !== 1) throw new RuntimeException('Expected exactly one queue row');
    $DB->update('telegram_notify_queue', ['status'=>2, 'error_msg'=>'local terminal fixture'], ['id'=>$queueId]);
    $DB->update('health_report', ['telegram_status'=>1, 'telegram_queue_id'=>$queueId], ['id'=>intval($row['id'])]);
    $third = $service->enqueueTelegram($report);
    if(!empty($third['ok'])) throw new RuntimeException('Terminal queue projection was not repaired');
    $queueStatus = intval($DB->findColumn('telegram_notify_queue', 'status', ['id'=>$queueId]));
    $reportStatus = intval($DB->findColumn('health_report', 'telegram_status', ['id'=>intval($row['id'])]));
    if($queueStatus !== 2 || $reportStatus !== 3) throw new RuntimeException('Terminal delivery state was not reconciled');
    $fourth = $service->enqueueTelegram($report);
    if(!empty($fourth['ok'])) throw new RuntimeException('Terminal delivery was incorrectly requeued');

    if(!$DB->beginTransaction()) throw new RuntimeException('Disable-preservation fixture transaction failed to start');
    try {
        $disabledQueueId = intval($DB->insert('telegram_notify_queue', [
            'uid'=>0,'scene'=>'daily_health','param'=>json_encode(['report_id'=>intval($row['id']),'html'=>'fixture','plain'=>'fixture','chat_id'=>'123456']),
            'status'=>0,'retry_count'=>0,'dedupe_key'=>'daily_health_cancel_fixture_'.bin2hex(random_bytes(6)),
            'next_attempt'=>'NOW()','addtime'=>'NOW()',
        ]));
        if($disabledQueueId <= 0) throw new RuntimeException('Disable-completion fixture queue insert failed');
        if($DB->update('health_report', ['telegram_status'=>1,'telegram_queue_id'=>$disabledQueueId], ['id'=>intval($row['id'])]) === false) throw new RuntimeException('Disable-completion fixture report update failed');
        $conf['addon_health_report'] = \lib\Health\Installer::VERSION;
        $conf['health_report_enabled'] = '0';
        $conf['telegram_bot_token'] = 'local-regression-token';
        $testBot = new class {
            public $sendCount = 0;
            public function sendMessage($chatId, $message, $options = []) { $this->sendCount++; return ['message_id'=>1]; }
            public function isDeterministicFormatError() { return false; }
            public function isDeliveryUncertain() { return false; }
            public function getLastError() { return null; }
        };
        $processed = \lib\Telegram\QueueHelper::processQueue(10, $testBot);
        if(intval($processed['success']) < 1 || $testBot->sendCount < 1) throw new RuntimeException('Disabled-report queue did not reach the controlled transport');
        if(intval($DB->findColumn('telegram_notify_queue','status',['id'=>$disabledQueueId])) !== 1) throw new RuntimeException('Disabled-report queue did not complete');
        $completedReportStatus = intval($DB->findColumn('health_report','telegram_status',['id'=>intval($row['id'])]));
        if($completedReportStatus !== 2) throw new RuntimeException('Disabled-report queue completion was not projected');

        $deterministicQueueId = intval($DB->insert('telegram_notify_queue', [
            'uid'=>0,'scene'=>'daily_health','param'=>json_encode(['report_id'=>intval($row['id']),'html'=>'fixture','plain'=>'fixture','chat_id'=>'123456']),
            'status'=>0,'retry_count'=>2,'dedupe_key'=>'daily_health_deterministic_fixture_'.bin2hex(random_bytes(6)),
            'next_attempt'=>'NOW()','addtime'=>'NOW()',
        ]));
        if($deterministicQueueId <= 0) throw new RuntimeException('Deterministic failure fixture queue insert failed');
        if($DB->update('health_report', ['telegram_status'=>1,'telegram_queue_id'=>$deterministicQueueId,'sent_at'=>null], ['id'=>intval($row['id'])]) === false) throw new RuntimeException('Deterministic failure fixture report update failed');
        $deterministicBot = new class {
            public function sendMessage($chatId, $message, $options = []) { return false; }
            public function isDeterministicFormatError() { return false; }
            public function isDeliveryUncertain() { return false; }
            public function getLastError() { return 'Controlled deterministic failure'; }
        };
        \lib\Telegram\QueueHelper::processQueue(1, $deterministicBot);
        $deterministicRow = $DB->find('telegram_notify_queue', 'status,retry_count,sendstarttime', ['id'=>$deterministicQueueId], null, 1);
        if(!$deterministicRow || intval($deterministicRow['status']) !== 2 || intval($deterministicRow['retry_count']) !== 3 || $deterministicRow['sendstarttime'] !== null) throw new RuntimeException('Deterministic terminal state was not preserved');
        if(intval($DB->findColumn('health_report','telegram_status',['id'=>intval($row['id'])])) !== 3) throw new RuntimeException('Deterministic terminal state was not projected');

        $uncertainQueueId = intval($DB->insert('telegram_notify_queue', [
            'uid'=>0,'scene'=>'daily_health','param'=>json_encode(['report_id'=>intval($row['id']),'html'=>'fixture','plain'=>'fixture','chat_id'=>'123456']),
            'status'=>0,'retry_count'=>0,'dedupe_key'=>'daily_health_uncertain_fixture_'.bin2hex(random_bytes(6)),
            'next_attempt'=>'NOW()','addtime'=>'NOW()',
        ]));
        if($uncertainQueueId <= 0) throw new RuntimeException('Uncertain failure fixture queue insert failed');
        if($DB->update('health_report', ['telegram_status'=>1,'telegram_queue_id'=>$uncertainQueueId,'sent_at'=>null], ['id'=>intval($row['id'])]) === false) throw new RuntimeException('Uncertain failure fixture report update failed');
        $uncertainBot = new class {
            public function sendMessage($chatId, $message, $options = []) { return false; }
            public function isDeterministicFormatError() { return false; }
            public function isDeliveryUncertain() { return true; }
            public function getLastError() { return 'Controlled uncertain failure'; }
        };
        \lib\Telegram\QueueHelper::processQueue(1, $uncertainBot);
        $uncertainRow = $DB->find('telegram_notify_queue', 'status,retry_count,sendstarttime', ['id'=>$uncertainQueueId], null, 1);
        if(!$uncertainRow || intval($uncertainRow['status']) !== 2 || intval($uncertainRow['retry_count']) !== 1 || empty($uncertainRow['sendstarttime'])) throw new RuntimeException('Uncertain terminal state was not preserved');
        if(intval($DB->findColumn('health_report','telegram_status',['id'=>intval($row['id'])])) !== 3) throw new RuntimeException('Uncertain terminal state was not projected');
        $DB->rollBack();
        $disabledQueueId = 0;
        $deterministicQueueId = 0;
        $uncertainQueueId = 0;
    } catch(Throwable $e){
        $DB->rollBack();
        throw $e;
    }
	$settingsResult = health_telegram_settings_regression($DB, $CACHE, $conf);
    if($queueId > 0){
        $DB->exec("DELETE FROM pre_telegram_notify_queue WHERE id={$queueId} AND scene='daily_health'");
        $queueId = 0;
    }
    $legacyResult = health_legacy_queue_regression($DB, $row, $conf, $dbconfig);
} catch(Throwable $e){
    $failure = $e;
} finally {
    if($queueId > 0) $DB->exec("DELETE FROM pre_telegram_notify_queue WHERE id={$queueId} AND scene='daily_health'");
    $DB->update('health_report', [
        'telegram_status'=>$originalStatus,
        'telegram_queue_id'=>$originalQueueId,
        'sent_at'=>$originalSentAt,
    ], ['id'=>intval($row['id'])]);
    $conf = $originalConf;
}
if($failure){
    fwrite(STDERR, 'health report database regression failed: '.$failure->getMessage().PHP_EOL);
    exit(1);
}
echo json_encode([
    'ok'=>true,
    'queue_dedupe'=>true,
    'terminal_reconciliation'=>true,
    'disabled_existing_queue_completed'=>true,
    'deterministic_terminal_failed'=>true,
    'uncertain_terminal_preserved'=>true,
	'telegram_settings_security'=>$settingsResult === true,
    'legacy_success'=>!empty($legacyResult['legacy_success']),
    'legacy_settle_success'=>!empty($legacyResult['legacy_settle_success']),
    'legacy_disabled_binding'=>!empty($legacyResult['legacy_disabled_binding']),
    'legacy_retry_terminal'=>!empty($legacyResult['legacy_retry_terminal']),
    'legacy_lock_contention'=>!empty($legacyResult['legacy_lock_contention']),
    'legacy_health_coexistence'=>!empty($legacyResult['legacy_health_coexistence']),
    'frozen_health_recipient'=>!empty($legacyResult['frozen_health_recipient']),
    'transport'=>'cli-test-database-injected',
], JSON_UNESCAPED_SLASHES).PHP_EOL;

function health_telegram_settings_regression($DB, $CACHE, $conf)
{
	$keys = [
		'telegram_notice','telegram_bot_token','telegram_admin_chat_id','telegram_bot_name','telegram_proxy',
		'telegram_proxy_server','telegram_proxy_port','telegram_proxy_user','telegram_proxy_pwd','telegram_proxy_type',
	];
	$original = [];
	foreach($keys as $key) $original[$key] = $DB->find('config', 'k,v', ['k'=>$key], null, 1);
	$fixture = [
		'telegram_notice'=>'1','telegram_bot_token'=>'fixture-token','telegram_admin_chat_id'=>'123456',
		'telegram_bot_name'=>'fixture_bot','telegram_proxy'=>'1','telegram_proxy_server'=>'127.0.0.1',
		'telegram_proxy_port'=>'1080','telegram_proxy_user'=>'fixture-user','telegram_proxy_pwd'=>'fixture-password',
		'telegram_proxy_type'=>'sock5h',
	];
	try {
		foreach($fixture as $key=>$value){
			if($DB->exec('REPLACE INTO pre_config (k,v) VALUES (:key,:value)', [':key'=>$key, ':value'=>$value]) === false){
				throw new RuntimeException('Telegram settings fixture write failed');
			}
		}
		$service = new \lib\Telegram\SettingsService($DB, $CACHE, array_merge($conf, $fixture));
		$input = $fixture;
		$input['telegram_bot_token'] = '';
		$input['telegram_proxy_pwd'] = '';
		$input['telegram_bot_name'] = 'fixture_bot_saved';
		try {
			$service->validate(array_merge($input, ['localurl'=>'https://example.invalid/']));
			throw new RuntimeException('Telegram settings accepted an unrelated field');
		} catch(InvalidArgumentException $expected) {}
		$changedWithoutPassword = $input;
		$changedWithoutPassword['telegram_proxy_server'] = '127.0.0.2';
		try {
			$service->validate($changedWithoutPassword);
			throw new RuntimeException('Telegram settings accepted a proxy identity change without its password');
		} catch(InvalidArgumentException $expected) {}
		if(!$service->save($input)) throw new RuntimeException('Telegram settings fixture save failed');
		if($DB->findColumn('config', 'v', ['k'=>'telegram_bot_token']) !== 'fixture-token'
			|| $DB->findColumn('config', 'v', ['k'=>'telegram_proxy_pwd']) !== 'fixture-password'
			|| $DB->findColumn('config', 'v', ['k'=>'telegram_bot_name']) !== 'fixture_bot_saved'){
			throw new RuntimeException('Blank Telegram secrets were not preserved');
		}
		$changed = $input;
		$changed['telegram_proxy_server'] = '127.0.0.2';
		$changed['telegram_proxy_pwd'] = 'fixture-password-2';
		if(!$service->save($changed)) throw new RuntimeException('Telegram proxy identity update failed');
		if($DB->findColumn('config', 'v', ['k'=>'telegram_proxy_server']) !== '127.0.0.2'
			|| $DB->findColumn('config', 'v', ['k'=>'telegram_proxy_pwd']) !== 'fixture-password-2'){
			throw new RuntimeException('Telegram proxy identity update was not persisted');
		}
		return true;
	} finally {
		foreach($original as $key=>$row){
			if($row){
				$DB->exec('REPLACE INTO pre_config (k,v) VALUES (:key,:value)', [':key'=>$key, ':value'=>$row['v']]);
			}else{
				$DB->exec('DELETE FROM pre_config WHERE k=:key', [':key'=>$key]);
			}
		}
		$CACHE->clear();
	}
}

function health_legacy_queue_regression($DB, $reportRow, &$conf, $dbconfig)
{
    $queueIds = [];
    $pausedRows = [];
    $bindId = 0;
    $lockPdo = null;
    $lockName = 'epay_'.substr(hash('sha256', (defined('DBQZ') ? DBQZ : 'pre').':telegram_notify_worker'), 0, 24);
    $originalConf = $conf;
    $originalReport = [
        'telegram_status'=>$reportRow['telegram_status'],
        'telegram_queue_id'=>$reportRow['telegram_queue_id'],
        'sent_at'=>$reportRow['sent_at'],
    ];
    $uid = 900000000 + random_int(1000, 9999);
    $merchantChat = (string)(700000000 + random_int(1000, 9999));
    try {
        $pausedRows = $DB->getAll("SELECT id,status,claimtime,sendstarttime,next_attempt FROM pre_telegram_notify_queue WHERE status=0");
        if($pausedRows === false) throw new RuntimeException('Existing queue isolation read failed');
        foreach($pausedRows as $pausedRow){
            if($DB->update('telegram_notify_queue', ['status'=>3,'claimtime'=>'NOW()'], ['id'=>intval($pausedRow['id']),'status'=>0]) === false){
                throw new RuntimeException('Existing queue isolation failed');
            }
        }
        $conf['addon_health_report'] = \lib\Health\Installer::VERSION;
        $conf['health_report_enabled'] = '1';
        $conf['telegram_notice'] = '1';
        $conf['telegram_bot_token'] = 'local-regression-token';
        $conf['health_report_chat_id'] = '';
        $conf['telegram_admin_chat_id'] = '123456';
        $bindId = intval($DB->insert('telegram_bind', [
            'chat_id'=>$merchantChat,'uid'=>$uid,'status'=>1,'bindtime'=>'NOW()','notify_order'=>1,
			'notify_settle'=>1,
        ]));
        if($bindId <= 0) throw new RuntimeException('Legacy merchant binding fixture failed');

        if($DB->update('health_report', ['telegram_status'=>0,'telegram_queue_id'=>null,'sent_at'=>null], ['id'=>intval($reportRow['id'])]) === false){
            throw new RuntimeException('Legacy coexistence report reset failed');
        }
        $report = [
            'id'=>intval($reportRow['id']),
            'report_date'=>$reportRow['report_date'],
            'metrics'=>json_decode($reportRow['metrics_json'], true),
            'rules'=>json_decode($reportRow['rules_json'], true),
            'ai'=>['health_level'=>'healthy','telegram_brief'=>'本地共存队列回归夹具'],
            'ai_status'=>1,
        ];
        $healthQueue = (new \lib\Health\ReportService($DB, $conf))->enqueueTelegram($report);
        if(empty($healthQueue['ok'])) throw new RuntimeException('Health coexistence queue insert failed');
        $healthQueueId = intval($healthQueue['queue_id']);
        $queueIds[] = $healthQueueId;
        $healthParam = json_decode((string)$DB->findColumn('telegram_notify_queue', 'param', ['id'=>$healthQueueId]), true);
        if(!is_array($healthParam) || $healthParam['chat_id'] !== '123456') throw new RuntimeException('Health fallback recipient was not frozen');

        $conf['telegram_admin_chat_id'] = '654321';
        $adminQueueId = intval(\lib\Telegram\QueueHelper::addToQueue('regaudit', 0, [
            'uid'=>1001,'account'=>'admin-fixture@example.invalid',
        ], true, 'legacy_admin_'.bin2hex(random_bytes(6))));
        $merchantQueueId = intval(\lib\Telegram\QueueHelper::addToQueue('order', $uid, [
            'name'=>'legacy-order-fixture','money'=>'9.99','type'=>'alipay','out_trade_no'=>'legacy-out-fixture',
            'trade_no'=>'legacy-trade-fixture','time'=>'2026-07-20 03:00:00',
        ], true, 'legacy_order_'.bin2hex(random_bytes(6))));
		$settleQueueId = intval(\lib\Telegram\QueueHelper::addToQueue('settle', $uid, [
			'money'=>'9.99','realmoney'=>'9.80','account'=>'legacy-settle-fixture',
			'time'=>'2026-07-20 03:00:30',
		], true, 'legacy_settle_'.bin2hex(random_bytes(6))));
		if($adminQueueId <= 0 || $merchantQueueId <= 0 || $settleQueueId <= 0) throw new RuntimeException('Legacy queue fixtures were rejected');
        $queueIds[] = $adminQueueId;
        $queueIds[] = $merchantQueueId;
		$queueIds[] = $settleQueueId;

        $successBot = new HealthLegacyRegressionBot(true);
		$processed = \lib\Telegram\QueueHelper::processQueue(4, $successBot);
		if(intval($processed['success']) !== 4 || count($successBot->calls) !== 4) throw new RuntimeException('Legacy and health coexistence processing failed');
		foreach([$healthQueueId,$adminQueueId,$merchantQueueId,$settleQueueId] as $id){
            if(intval($DB->findColumn('telegram_notify_queue', 'status', ['id'=>$id])) !== 1) throw new RuntimeException('Successful queue row did not become sent');
        }
        $callsByMessage = [];
        foreach($successBot->calls as $call){
            if(strpos($call['message'], '支付系统每日健康简报') !== false) $callsByMessage['health'] = $call;
            elseif(strpos($call['message'], '新注册商户待审核') !== false) $callsByMessage['admin'] = $call;
            elseif(strpos($call['message'], '新订单通知') !== false) $callsByMessage['merchant'] = $call;
			elseif(strpos($call['message'], '结算完成通知') !== false) $callsByMessage['settle'] = $call;
        }
		if(count($callsByMessage) !== 4) throw new RuntimeException('Representative legacy messages were not rendered');
        if($callsByMessage['health']['chat_id'] !== '123456') throw new RuntimeException('Pending health report followed a mutable fallback recipient');
		if($callsByMessage['admin']['chat_id'] !== '654321' || $callsByMessage['merchant']['chat_id'] !== $merchantChat
			|| $callsByMessage['settle']['chat_id'] !== $merchantChat){
            throw new RuntimeException('Legacy recipient routing changed');
        }

        if($DB->update('telegram_bind', ['notify_order'=>1], ['id'=>$bindId]) === false) throw new RuntimeException('Legacy binding enable fixture failed');
        $disabledQueueId = intval(\lib\Telegram\QueueHelper::addToQueue('order', $uid, [
            'name'=>'disabled-order-fixture','money'=>'1.00','type'=>'alipay','out_trade_no'=>'disabled-out-fixture',
            'trade_no'=>'disabled-trade-fixture','time'=>'2026-07-20 03:01:00',
        ], true, 'legacy_disabled_'.bin2hex(random_bytes(6))));
        if($disabledQueueId <= 0) throw new RuntimeException('Disabled-binding queue fixture failed');
        $queueIds[] = $disabledQueueId;
        if($DB->update('telegram_bind', ['notify_order'=>0], ['id'=>$bindId]) === false) throw new RuntimeException('Legacy binding disable fixture failed');
        $beforeDisabled = count($successBot->calls);
        $disabledProcessed = \lib\Telegram\QueueHelper::processQueue(1, $successBot);
        if(intval($disabledProcessed['success']) !== 1 || count($successBot->calls) !== $beforeDisabled
            || intval($DB->findColumn('telegram_notify_queue', 'status', ['id'=>$disabledQueueId])) !== 1){
            throw new RuntimeException('Disabled legacy binding did not suppress delivery safely');
        }

        $failedQueueId = intval(\lib\Telegram\QueueHelper::addToQueue('login', 0, [
            'user'=>'admin-fixture','clientip'=>'127.0.0.1','ipinfo'=>'local-test','time'=>'2026-07-20 03:02:00',
        ], true, 'legacy_retry_'.bin2hex(random_bytes(6))));
        if($failedQueueId <= 0) throw new RuntimeException('Legacy retry fixture failed');
        $queueIds[] = $failedQueueId;
        $failureBot = new HealthLegacyRegressionBot(false, 'Controlled legacy transport failure');
        for($attempt=1;$attempt<=3;$attempt++){
            \lib\Telegram\QueueHelper::processQueue(1, $failureBot);
            $failedRow = $DB->find('telegram_notify_queue', 'status,retry_count,error_msg', ['id'=>$failedQueueId], null, 1);
            $expectedStatus = $attempt === 3 ? 2 : 0;
            if(!$failedRow || intval($failedRow['status']) !== $expectedStatus || intval($failedRow['retry_count']) !== $attempt){
                throw new RuntimeException('Legacy retry transition mismatch at attempt '.$attempt);
            }
        }
        if(count($failureBot->calls) !== 3 || strpos($failureBot->calls[0]['message'], '账号登录通知') === false){
            throw new RuntimeException('Legacy failure path did not render and attempt the login message');
        }

        $dsn = 'mysql:host='.$dbconfig['host'].';port='.intval($dbconfig['port']).';dbname='.$dbconfig['dbname'].';charset=utf8mb4';
        $lockPdo = new PDO($dsn, $dbconfig['user'], $dbconfig['pwd'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>3]);
        $lockStatement = $lockPdo->prepare('SELECT GET_LOCK(:name,0)');
        $lockStatement->execute([':name'=>$lockName]);
        if(intval($lockStatement->fetchColumn()) !== 1) throw new RuntimeException('Legacy lock fixture could not acquire worker lock');
        $locked = \lib\Telegram\QueueHelper::processQueue(1, $successBot);
        if(strpos($locked['message'], 'already running') === false) throw new RuntimeException('Legacy worker lock contention was not surfaced');
        $release = $lockPdo->prepare('SELECT RELEASE_LOCK(:name)');
        $release->execute([':name'=>$lockName]);
        $lockPdo = null;

        return [
            'legacy_success'=>true,
			'legacy_settle_success'=>true,
            'legacy_disabled_binding'=>true,
            'legacy_retry_terminal'=>true,
            'legacy_lock_contention'=>true,
            'legacy_health_coexistence'=>true,
            'frozen_health_recipient'=>true,
        ];
    } finally {
        if($lockPdo instanceof PDO){
            try { $release = $lockPdo->prepare('SELECT RELEASE_LOCK(:name)'); $release->execute([':name'=>$lockName]); } catch(Throwable $ignored) {}
        }
        foreach($queueIds as $id) $DB->exec('DELETE FROM pre_telegram_notify_queue WHERE id='.intval($id));
        foreach($pausedRows as $pausedRow){
            $DB->update('telegram_notify_queue', [
                'status'=>intval($pausedRow['status']),'claimtime'=>$pausedRow['claimtime'],
                'sendstarttime'=>$pausedRow['sendstarttime'],'next_attempt'=>$pausedRow['next_attempt'],
            ], ['id'=>intval($pausedRow['id'])]);
        }
        if($bindId > 0) $DB->exec('DELETE FROM pre_telegram_bind WHERE id='.intval($bindId));
        $DB->update('health_report', $originalReport, ['id'=>intval($reportRow['id'])]);
        $conf = $originalConf;
    }
}

class HealthLegacyRegressionBot
{
    public $calls = [];
    private $success;
    private $error;
    public function __construct($success, $error = null){ $this->success=(bool)$success; $this->error=$error; }
    public function sendMessage($chatId, $message, $options=[]){
        $this->calls[] = ['chat_id'=>(string)$chatId,'message'=>(string)$message,'options'=>$options];
        return $this->success ? ['message_id'=>count($this->calls)] : false;
    }
    public function isDeterministicFormatError(){ return false; }
    public function isDeliveryUncertain(){ return false; }
    public function getLastError(){ return $this->error; }
}
