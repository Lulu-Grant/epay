#!/usr/bin/env php
<?php
if(PHP_SAPI !== 'cli') exit(1);

$root = dirname(__DIR__);
$evidenceRoot = $root.'/docs/evidence/ai-health-1204-r52';
$write = false;
$seen = [];
for($i=1;$i<$argc;$i++){
    if($argv[$i] === '--write-manifest'){
        if(isset($seen['write'])) evidence_fail('duplicate --write-manifest');
        $seen['write'] = true;
        $write = true;
    }
    elseif($argv[$i] === '--evidence-root'){
        if(isset($seen['root'])) evidence_fail('duplicate --evidence-root');
        if(!isset($argv[$i+1]) || strpos($argv[$i+1], '--') === 0) evidence_fail('missing value for --evidence-root');
        $seen['root'] = true;
        $evidenceRoot = $argv[++$i];
    }
    else evidence_fail('unsupported argument '.$argv[$i]);
}

function evidence_fail($message)
{
    fwrite(STDERR, 'health UI evidence verification failed: '.$message.PHP_EOL);
    exit(1);
}

function evidence_path($path)
{
    if(!is_string($path) || $path === '' || strpos($path, "\0") !== false || strpos($path, "\\") !== false) return false;
    $parts = explode('/', $path);
    foreach($parts as $part) if($part === '' || $part === '.' || $part === '..') return false;
    return implode('/', $parts) === $path ? $path : false;
}

function evidence_digest($digest)
{
    if(!preg_match('/^[a-f0-9]{64}$/D', (string)$digest)) evidence_fail('invalid digest');
    return implode(':', str_split((string)$digest, 8));
}

function evidence_raw_digest($digest)
{
    $raw = str_replace(':', '', (string)$digest);
    if(!preg_match('/^[a-f0-9]{64}$/D', $raw)) evidence_fail('invalid displayed digest');
    return $raw;
}

function evidence_record($absolute, $relative)
{
    if(is_link($absolute) || !is_file($absolute)) evidence_fail('missing regular file '.$relative);
    $size = filesize($absolute);
    $sha = hash_file('sha256', $absolute);
    if($size === false || $sha === false) evidence_fail('cannot inspect '.$relative);
    return [
        'path'=>$relative,
        'size'=>(string)$size,
        'sha256'=>evidence_digest($sha),
    ];
}

require_once $root.'/includes/lib/Health/RuleEngine.php';

function evidence_png_dimensions($path, $relative)
{
    $header = file_get_contents($path, false, null, 0, 24);
    if($header === false || strlen($header) !== 24 || substr($header, 0, 8) !== "\x89PNG\r\n\x1a\n" || substr($header, 12, 4) !== 'IHDR') evidence_fail('invalid PNG '.$relative);
    $dimensions = unpack('Nwidth/Nheight', substr($header, 16, 8));
    if(empty($dimensions['width']) || empty($dimensions['height'])) evidence_fail('invalid PNG dimensions '.$relative);
    return [intval($dimensions['width']), intval($dimensions['height'])];
}

$sourcePaths = [
    'admin/ajax_health_report.php',
    'admin/head.php',
    'admin/health_report.php',
    'admin/health_report_set.php',
    'includes/lib/Health/AdminSupport.php',
    'includes/lib/Health/ReportService.php',
    'includes/lib/Health/RuleEngine.php',
    'includes/lib/Telegram/QueueHelper.php',
    'scripts/capture-health-ui-evidence.sh',
    'scripts/health-ui-fixture-prepare.php',
    'scripts/health-ui-playwright.cjs',
    'scripts/health-ui-router.php',
    'scripts/health-test-bootstrap.php',
    'scripts/verify-health-ui-evidence.php',
];
$pngs = [
    'desktop-overview.png'=>[1440,1000,true],
    'desktop-detail.png'=>[1440,1000,false],
    'desktop-delivery-success.png'=>[1440,1000,false],
    'desktop-delivery-failure.png'=>[1440,1000,false],
    'desktop-delivery-uncertain.png'=>[1440,1000,false],
    'desktop-queue-missing-id.png'=>[1440,1000,false],
    'desktop-queue-missing-row.png'=>[1440,1000,false],
    'desktop-queue-unsupported.png'=>[1440,1000,false],
    'desktop-queue-confirm.png'=>[1440,1000,false],
    'desktop-queue-accepted.png'=>[1440,1000,false],
    'desktop-queued-detail.png'=>[1440,1000,false],
    'desktop-rule-generated.png'=>[1440,1000,false],
    'desktop-ai-degraded.png'=>[1440,1000,false],
    'desktop-ai-budget.png'=>[1440,1000,false],
    'desktop-ai-incomplete.png'=>[1440,1000,false],
    'desktop-review-healthy.png'=>[1440,1000,false],
    'desktop-review-degraded.png'=>[1440,1000,false],
    'desktop-review-current-incomplete.png'=>[1440,1000,false],
    'desktop-review-baseline-missing.png'=>[1440,1000,false],
    'desktop-review-sample-insufficient.png'=>[1440,1000,false],
    'mobile-overview.png'=>[390,844,true],
    'mobile-detail.png'=>[390,844,false],
    'mobile-settings.png'=>[390,844,false],
    'mobile-settings-saved.png'=>[390,844,false],
    'mobile-delivery-success.png'=>[390,844,false],
    'mobile-delivery-failure.png'=>[390,844,false],
    'mobile-delivery-uncertain.png'=>[390,844,false],
    'mobile-queue-missing-id.png'=>[390,844,false],
    'mobile-queue-missing-row.png'=>[390,844,false],
    'mobile-queue-unsupported.png'=>[390,844,false],
    'mobile-ai-degraded.png'=>[390,844,false],
    'mobile-ai-budget.png'=>[390,844,false],
    'mobile-ai-incomplete.png'=>[390,844,false],
    'mobile-review-healthy.png'=>[390,844,false],
    'mobile-review-degraded.png'=>[390,844,false],
    'mobile-review-current-incomplete.png'=>[390,844,false],
    'mobile-review-baseline-missing.png'=>[390,844,false],
    'mobile-review-sample-insufficient.png'=>[390,844,false],
    'mobile-loading.png'=>[390,844,true],
    'mobile-empty.png'=>[390,844,true],
    'mobile-failure.png'=>[390,844,true],
    'desktop-loading.png'=>[1440,1000,true],
    'desktop-empty.png'=>[1440,1000,true],
    'desktop-failure.png'=>[1440,1000,true],
];
$artifactPaths = array_merge(array_keys($pngs), [
    'browser-results.json',
    'fixture-prepare.log',
    'playwright.stdout.log',
    'playwright.stderr.log',
    'server.stdout.log',
    'server.stderr.log',
]);
sort($sourcePaths, SORT_STRING);
sort($artifactPaths, SORT_STRING);

if(!is_dir($evidenceRoot) || is_link($evidenceRoot)) evidence_fail('evidence root must be a regular directory');
$resultPath = $evidenceRoot.'/browser-results.json';
$result = json_decode((string)file_get_contents($resultPath), true);
if(!is_array($result) || isset($result['error']) || $result['format'] !== 'epay-health-ui-browser-result-v2' || $result['passed'] !== true) evidence_fail('browser result is not a passing v2 result');
if(!isset($result['endpointMode']) || $result['endpointMode'] !== 'actual-admin-endpoint' || !isset($result['baseOrigin']) || $result['baseOrigin'] !== 'loopback-isolated-database') evidence_fail('browser result did not use the isolated real admin endpoint');
$expectedCaptures = array_keys($pngs);
sort($expectedCaptures, SORT_STRING);
$actualCaptures = isset($result['captures']) && is_array($result['captures']) ? $result['captures'] : [];
sort($actualCaptures, SORT_STRING);
if($actualCaptures !== $expectedCaptures) evidence_fail('browser capture inventory is incomplete');
$scenarioNames = [];
foreach(isset($result['scenarios']) && is_array($result['scenarios']) ? $result['scenarios'] : [] as $scenario){
    if(!is_array($scenario) || empty($scenario['name'])) evidence_fail('invalid scenario result');
    $scenarioNames[] = $scenario['name'];
    foreach(['unexpectedConsoleErrors','pageErrors','requestFailures'] as $field){
        if(!isset($scenario[$field]) || !is_array($scenario[$field]) || count($scenario[$field]) !== 0) evidence_fail($scenario['name'].' has '.$field);
    }
}
    $expectedScenarios = ['desktop-empty','desktop-failure','desktop-loading','desktop-operator-workflow','mobile-200-percent-text','mobile-empty','mobile-failure','mobile-loading','mobile-overview','mobile-settings-workflow'];
sort($scenarioNames, SORT_STRING);
if($scenarioNames !== $expectedScenarios) evidence_fail('browser scenario inventory is incomplete');
$actionNames = [];
foreach(isset($result['actions']) && is_array($result['actions']) ? $result['actions'] : [] as $action){
    if(!is_array($action) || empty($action['id']) || !isset($action['passed']) || $action['passed'] !== true || empty($action['feedback'])) evidence_fail('invalid browser action result');
    $actionNames[] = $action['id'];
}
$expectedActions = ['accessibility-200-percent-text','accessibility-contrast-zoom','accessibility-dialog-keyboard','accessibility-live-status','ai-budget','ai-degraded','ai-incomplete','disable-preserves-queue','generate-rule','queue-report','settings-save-disable','settings-validation','unknown-queue-states'];
sort($actionNames, SORT_STRING);
    if($actionNames !== $expectedActions) evidence_fail('browser action inventory is incomplete');

$expectedLayoutAssertions = [
    'mobile-ai-budget','mobile-ai-degraded','mobile-ai-incomplete','mobile-delivery-failure','mobile-delivery-success','mobile-delivery-uncertain',
    'mobile-empty','mobile-failure','mobile-loading','mobile-overview-detail','mobile-queue-missing-id','mobile-queue-missing-row','mobile-queue-unsupported',
    'mobile-history-card-action','mobile-channel-detail-cards','mobile-review-healthy','mobile-review-degraded','mobile-review-currentIncomplete',
    'mobile-review-baselineMissing','mobile-review-sampleInsufficient',
];
$actualLayoutAssertions = [];
foreach(isset($result['layoutAssertions']) && is_array($result['layoutAssertions']) ? $result['layoutAssertions'] : [] as $assertion){
    if(!is_array($assertion) || empty($assertion['id']) || $assertion['viewport'] !== [390,844] || $assertion['noHorizontalOverflow'] !== true || $assertion['expectedTextVisible'] !== true || $assertion['primaryControlReachable'] !== true){
        evidence_fail('invalid mobile layout assertion');
    }
    $actualLayoutAssertions[] = $assertion['id'];
}
sort($expectedLayoutAssertions, SORT_STRING);
sort($actualLayoutAssertions, SORT_STRING);
if($actualLayoutAssertions !== $expectedLayoutAssertions) evidence_fail('mobile layout assertion inventory is incomplete');

$reviewSamples = isset($result['reviewSamples']) && is_array($result['reviewSamples']) ? $result['reviewSamples'] : [];
$serverSamples = isset($reviewSamples['server']) && is_array($reviewSamples['server']) ? $reviewSamples['server'] : [];
$payloads = isset($serverSamples['reportPayloads']) && is_array($serverSamples['reportPayloads']) ? $serverSamples['reportPayloads'] : [];
$ruleInputs = isset($serverSamples['ruleInputs']) && is_array($serverSamples['ruleInputs']) ? $serverSamples['ruleInputs'] : [];
$telegram = isset($serverSamples['telegram']) && is_array($serverSamples['telegram']) ? $serverSamples['telegram'] : [];
$expectedReview = [
    'healthy'=>['level'=>'healthy','codes'=>[],'telegram'=>['总体状态：正常','本地规则未发现需要外部调查的异常']],
    'degraded'=>['level'=>'warning','codes'=>['success_rate_drop'],'telegram'=>['总体状态：告警','曾支付成功率较基线下降30个百分点']],
	'currentIncomplete'=>['level'=>'critical','codes'=>['data_incomplete','notify_failed'],'telegram'=>['总体状态：严重（数据不足）','[严重] 平台存在最终通知失败','当前统计窗口快照不完整','不能判定系统正常']],
    'baselineMissing'=>['level'=>'unknown','codes'=>['baseline_missing'],'telegram'=>['总体状态：数据不足','最近7日基线快照不完整','不能判定系统正常']],
	'sampleInsufficient'=>['level'=>'warning','codes'=>['sample_insufficient','frozen_orders'],'telegram'=>['总体状态：告警（数据不足）','[告警] 平台存在24笔冻结订单','统计窗口订单样本不足','不能判定系统正常']],
];
$engine = new \lib\Health\RuleEngine([
    'min_sample'=>30,'attention_drop'=>5,'warning_drop'=>10,'failure_streak'=>10,'stale_hours'=>6,
    'notify_critical_count'=>20,'notify_critical_rate'=>20,
]);
foreach($expectedReview as $name=>$expected){
    if(!isset($payloads[$name]['metrics'],$payloads[$name]['rules'],$payloads[$name]['ai_reason'],$ruleInputs[$name]['metrics'],$ruleInputs[$name]['baseline'],$telegram[$name]['html'],$telegram[$name]['plain'])) evidence_fail('missing review sample '.$name);
    $metrics = $payloads[$name]['metrics'];
    $platform = isset($metrics['platform']) && is_array($metrics['platform']) ? $metrics['platform'] : [];
    $total = intval(isset($platform['total_orders']) ? $platform['total_orders'] : 0);
    $paid = intval(isset($platform['paid_orders']) ? $platform['paid_orders'] : 0);
    $storedRate = isset($platform['success_rate']) ? $platform['success_rate'] : null;
    $computedRate = $total > 0 ? round($paid * 100 / $total, 2) : null;
    if(($computedRate === null) !== ($storedRate === null) || ($computedRate !== null && abs(floatval($storedRate)-$computedRate) > 0.01)) evidence_fail('review sample arithmetic is inconsistent for '.$name);
    $recomputed = $engine->evaluate($ruleInputs[$name]['metrics'], $ruleInputs[$name]['baseline']);
    $persistedCodes = array_column($payloads[$name]['rules']['issues'], 'code');
    $recomputedCodes = array_column($recomputed['issues'], 'code');
    sort($persistedCodes, SORT_STRING); sort($recomputedCodes, SORT_STRING); sort($expected['codes'], SORT_STRING);
    if($payloads[$name]['rules']['level'] !== $expected['level'] || $recomputed['level'] !== $expected['level'] || $persistedCodes !== $expected['codes'] || $recomputedCodes !== $expected['codes']) evidence_fail('review sample rule evaluation is inconsistent for '.$name);
    foreach($expected['telegram'] as $needle) if(strpos($telegram[$name]['plain'], $needle) === false) evidence_fail('rendered Telegram sample is invalid for '.$name);
}
if(intval($payloads['currentIncomplete']['ai_status']) !== 3 || intval($payloads['baselineMissing']['ai_status']) !== 3 || intval($payloads['sampleInsufficient']['ai_status']) !== 3) evidence_fail('classification-blocked review samples did not persist the AI skip state');
if(strpos($payloads['currentIncomplete']['ai_reason'], '当前统计窗口快照不完整') === false || strpos($payloads['baselineMissing']['ai_reason'], '最近7日基线快照不完整') === false || strpos($payloads['sampleInsufficient']['ai_reason'], '订单样本低于外部分析阈值') === false) evidence_fail('review AI skip reasons are not distinct');
$visibleUi = isset($reviewSamples['visibleUi']) && is_array($reviewSamples['visibleUi']) ? $reviewSamples['visibleUi'] : [];
foreach(['deliverySuccess'=>'Telegram：已送达','deliveryFailure'=>'发送失败，需人工复核','deliveryUncertain'=>'送达不确定，需人工复核','reportDisabled'=>'关闭只停止未来自动生成和新入队','mobileOverviewDetail'=>'Telegram：排队中','mobileDeliverySuccess'=>'Telegram：已送达','mobileDeliveryFailure'=>'发送失败，需人工复核','mobileDeliveryUncertain'=>'送达不确定，需人工复核','mobile-queue-missing-id'=>'队列状态未知，需人工复核','mobile-queue-missing-row'=>'队列状态未知，需人工复核','mobile-queue-unsupported'=>'队列状态未知，需人工复核','mobile-ai-degraded'=>'AI 服务未完成','mobile-ai-budget'=>'AI 调用上限','mobile-ai-incomplete'=>'当前统计窗口快照不完整','desktopReview-healthy'=>'状态：正常','desktopReview-degraded'=>'成功率较基线下降30个百分点','desktopReview-currentIncomplete'=>'状态：严重（数据不足）','desktopReview-baselineMissing'=>'最近7日基线快照不完整','desktopReview-sampleInsufficient'=>'状态：告警（数据不足）','mobileReview-healthy'=>'状态：正常','mobileReview-degraded'=>'成功率较基线下降30个百分点','mobileReview-currentIncomplete'=>'状态：严重（数据不足）','mobileReview-baselineMissing'=>'最近7日基线快照不完整','mobileReview-sampleInsufficient'=>'状态：告警（数据不足）','mobileLoading'=>'正在加载','mobileEmpty'=>'暂无报告','mobileFailure'=>'加载失败，请刷新重试'] as $key=>$needle){
    if(empty($visibleUi[$key]) || strpos($visibleUi[$key], $needle) === false) evidence_fail('missing visible UI sample '.$key);
}

$sources = [];
foreach($sourcePaths as $relative){
    if(evidence_path($relative) === false) evidence_fail('invalid source path');
    $sources[] = evidence_record($root.'/'.$relative, $relative);
}
$artifacts = [];
foreach($artifactPaths as $relative){
    if(evidence_path($relative) === false) evidence_fail('invalid artifact path');
    $record = evidence_record($evidenceRoot.'/'.$relative, $relative);
    if(isset($pngs[$relative])){
        list($width, $height) = evidence_png_dimensions($evidenceRoot.'/'.$relative, $relative);
        list($expectedWidth, $expectedHeight, $fullPage) = $pngs[$relative];
        if($width !== $expectedWidth || ($fullPage ? $height < $expectedHeight : $height !== $expectedHeight)) evidence_fail('unexpected screenshot dimensions '.$relative);
        $record['width'] = $width;
        $record['height'] = $height;
    }
    $artifacts[] = $record;
}

$bindingInput = "epay-health-ui-evidence-v2\0";
foreach(array_merge($sources, $artifacts) as $record){
    $bindingInput .= $record['path']."\0".$record['size']."\0".evidence_raw_digest($record['sha256'])."\0";
    if(isset($record['width'])) $bindingInput .= $record['width']."\0".$record['height']."\0";
}
$binding = evidence_digest(hash('sha256', $bindingInput));
$manifest = [
    'format'=>'epay-health-ui-evidence-manifest-v2',
    'browserResultFormat'=>$result['format'],
    'browserGeneratedAt'=>$result['generatedAt'],
    'sources'=>$sources,
    'artifacts'=>$artifacts,
    'bindingSha256'=>$binding,
];
$manifestPath = $evidenceRoot.'/evidence-manifest.json';
if($write){
    if(is_file($manifestPath) || is_link($manifestPath)) evidence_fail('refusing to overwrite an evidence manifest');
    $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    if(file_put_contents($manifestPath, $encoded, LOCK_EX) !== strlen($encoded)) evidence_fail('cannot write evidence manifest');
}else{
    $stored = json_decode((string)file_get_contents($manifestPath), true);
    if(!is_array($stored) || $stored !== $manifest) evidence_fail('stored evidence manifest does not match current source and artifacts');
}
echo json_encode(['ok'=>true,'sources'=>count($sources),'artifacts'=>count($artifacts),'binding_sha256'=>$binding], JSON_UNESCAPED_SLASHES).PHP_EOL;
