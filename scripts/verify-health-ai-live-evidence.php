#!/usr/bin/env php
<?php
if(PHP_SAPI !== 'cli') exit(1);

function evidence_fail($message)
{
    fwrite(STDERR, 'health AI live evidence verification failed: '.$message.PHP_EOL);
    exit(1);
}

$root = dirname(__DIR__);
$evidenceRoot = $root.'/docs/evidence/ai-health-live-20260720-r51';
$writeManifest = false;
$evidenceRootSeen = false;
$arguments = array_slice($argv, 1);
for($index=0;$index<count($arguments);$index++){
    $arg = $arguments[$index];
    if($arg === '--write-manifest'){
        if($writeManifest) evidence_fail('duplicate --write-manifest option');
        $writeManifest = true;
    }elseif($arg === '--evidence-root'){
        if($evidenceRootSeen) evidence_fail('duplicate --evidence-root option');
        if(!isset($arguments[$index + 1]) || substr($arguments[$index + 1], 0, 2) === '--') evidence_fail('--evidence-root requires a value');
        $evidenceRoot = rtrim($arguments[++$index], '/');
        $evidenceRootSeen = true;
    }else evidence_fail('unsupported argument '.$arg);
}

$artifactFiles = ['live-smoke.json','staged-report.json','provider-failure.json','disabled-worker.json'];
$sourceFiles = [
    'includes/lib/Cache.php',
    'includes/lib/Health/AiClient.php',
    'includes/lib/Health/Installer.php',
    'includes/lib/Health/MetricsService.php',
    'includes/lib/Health/ReportService.php',
    'includes/lib/Health/RuleEngine.php',
    'includes/lib/Health/TelegramRenderer.php',
    'includes/lib/PdoHelper.php',
    'includes/lib/Telegram/BotAPI.php',
    'includes/lib/Telegram/NotifyHelper.php',
    'includes/lib/Telegram/QueueHelper.php',
	'includes/lib/Telegram/SettingsService.php',
    'scripts/health-ai-live-smoke.php',
    'scripts/health-report-ai-stage-regression.php',
    'scripts/health-report-regression.php',
    'scripts/health-report-db-regression.php',
    'scripts/health-test-bootstrap.php',
    'scripts/verify-health-ai-live-evidence.php',
];

try {
    $live = read_json($evidenceRoot.'/live-smoke.json');
    $stage = read_json($evidenceRoot.'/staged-report.json');
    $providerFailure = read_json($evidenceRoot.'/provider-failure.json');
    $worker = read_json($evidenceRoot.'/disabled-worker.json');
    validate_common($live, 'grounded-ai-smoke');
    validate_common($stage, 'staged-report-enhancement');
    validate_common($providerFailure, 'staged-report-provider-failure');
    validate_common($worker, 'disabled-worker-completion');
    exact_keys($live['result'], ['ok','duration_ms','finding_count','rule_code','scope','local_playbook','request_sha256','response_sha256']);
    if($live['result']['ok'] !== true || intval($live['result']['duration_ms']) < 1 || intval($live['result']['duration_ms']) > 60000
        || intval($live['result']['finding_count']) !== 1 || $live['result']['rule_code'] !== 'notify_failed'
        || $live['result']['scope'] !== 'platform' || $live['result']['local_playbook'] !== true
        || !preg_match('/^[a-f0-9]{64}$/D', (string)$live['result']['request_sha256'])
        || !preg_match('/^[a-f0-9]{64}$/D', (string)$live['result']['response_sha256'])){
        throw new RuntimeException('Live smoke result is invalid');
    }
    exact_keys($stage['result'], ['ok','report_id_stable','ai_status','finding_count']);
    if($stage['result']['ok'] !== true || $stage['result']['report_id_stable'] !== true
        || intval($stage['result']['ai_status']) !== 1 || intval($stage['result']['finding_count']) < 1){
        throw new RuntimeException('Staged report result is invalid');
    }
	exact_keys($providerFailure['result'], ['ok','report_id_stable','ai_status','retry_suppressed','error_class','request_sha256','response_sha256']);
	if($providerFailure['result']['ok'] !== true || $providerFailure['result']['report_id_stable'] !== true
		|| intval($providerFailure['result']['ai_status']) !== 2 || $providerFailure['result']['retry_suppressed'] !== true
		|| $providerFailure['result']['error_class'] !== 'provider_http_error'
		|| !preg_match('/^[a-f0-9]{64}$/D', (string)$providerFailure['result']['request_sha256'])
		|| !preg_match('/^[a-f0-9]{64}$/D', (string)$providerFailure['result']['response_sha256'])){
		throw new RuntimeException('Provider failure result is invalid');
	}
	exact_keys($worker['result'], ['ok','queue_dedupe','terminal_reconciliation','disabled_existing_queue_completed','deterministic_terminal_failed','uncertain_terminal_preserved','telegram_settings_security','legacy_success','legacy_settle_success','legacy_disabled_binding','legacy_retry_terminal','legacy_lock_contention','legacy_health_coexistence','frozen_health_recipient','transport']);
    if($worker['result']['ok'] !== true || $worker['result']['queue_dedupe'] !== true
        || $worker['result']['terminal_reconciliation'] !== true || $worker['result']['disabled_existing_queue_completed'] !== true
		|| $worker['result']['deterministic_terminal_failed'] !== true || $worker['result']['uncertain_terminal_preserved'] !== true
		|| $worker['result']['telegram_settings_security'] !== true
		|| $worker['result']['legacy_success'] !== true || $worker['result']['legacy_settle_success'] !== true
		|| $worker['result']['legacy_disabled_binding'] !== true
        || $worker['result']['legacy_retry_terminal'] !== true || $worker['result']['legacy_lock_contention'] !== true
        || $worker['result']['legacy_health_coexistence'] !== true || $worker['result']['frozen_health_recipient'] !== true
        || $worker['result']['transport'] !== 'cli-test-database-injected'){
        throw new RuntimeException('Disabled worker result is invalid');
    }

    $manifest = [
        'format'=>'epay-health-ai-live-evidence-manifest-v1',
        'artifacts'=>file_entries($artifactFiles, $evidenceRoot),
        'sources'=>file_entries($sourceFiles, $root),
    ];
    $manifest['bindingSha256'] = binding_hash($manifest);
    $manifestPath = $evidenceRoot.'/evidence-manifest.json';
    if($writeManifest){
        $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        if(file_put_contents($manifestPath, $encoded, LOCK_EX) !== strlen($encoded)) throw new RuntimeException('Manifest write failed');
    }else{
        $persisted = read_json($manifestPath);
        if($persisted !== $manifest) throw new RuntimeException('Evidence manifest does not match current artifacts and sources');
    }
    echo json_encode(['ok'=>true,'artifacts'=>count($artifactFiles),'sources'=>count($sourceFiles),'binding_sha256'=>$manifest['bindingSha256']], JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch(Throwable $e){
    fwrite(STDERR, 'health AI live evidence verification failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}

function read_json($path)
{
    if(!is_file($path) || is_link($path)) throw new RuntimeException('Missing regular evidence file: '.$path);
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    if(!is_array($data) || json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('Invalid JSON evidence file: '.$path);
    return $data;
}

function validate_common($data, $test)
{
    exact_keys($data, ['format','test','recorded_at','provider_host','endpoint_path','model','environment','result']);
    $providerValid = $test === 'disabled-worker-completion'
        ? $data['provider_host'] === null && $data['endpoint_path'] === null && $data['model'] === null
        : $data['provider_host'] === 'api.3s3.org' && $data['endpoint_path'] === '/v1/chat/completions' && $data['model'] === 'grok-4.5';
    if($data['format'] !== 'epay-health-ai-live-evidence-v1' || $data['test'] !== $test
        || !preg_match('/^2026-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\+08:00$/D', (string)$data['recorded_at'])
        || !$providerValid || $data['environment'] !== 'isolated-local-test'){
        throw new RuntimeException('Evidence metadata is invalid for '.$test);
    }
    if(!is_array($data['result'])) throw new RuntimeException('Evidence result is invalid for '.$test);
}

function exact_keys($data, $keys)
{
    if(!is_array($data)) throw new RuntimeException('Expected an object');
    $actual = array_keys($data);
    sort($actual);
    sort($keys);
    if($actual !== $keys) throw new RuntimeException('Unexpected evidence fields');
}

function file_entries($paths, $base)
{
    $result = [];
    foreach($paths as $relative){
        $path = $base.'/'.$relative;
        if(!is_file($path) || is_link($path)) throw new RuntimeException('Missing bound file: '.$relative);
        $result[] = ['path'=>$relative,'size'=>filesize($path),'sha256'=>hash_file('sha256', $path)];
    }
    return $result;
}

function binding_hash($manifest)
{
    $copy = $manifest;
    unset($copy['bindingSha256']);
    return hash('sha256', json_encode($copy, JSON_UNESCAPED_SLASHES));
}
