#!/usr/bin/php8.4
<?php
if(PHP_SAPI !== 'cli') exit(1);

set_exception_handler(function($error){
    $message = $error instanceof Throwable ? $error->getMessage() : 'unexpected release control failure';
    fwrite(STDERR, 'ai-health release control failed: '.$message.PHP_EOL);
    exit(1);
});

$projectRoot = dirname(__DIR__);
$options = release_parse_arguments(array_slice($argv, 1));
$operation = $options['operation'];
$releaseRoot = release_absolute_directory($options['release-root'], 'release root');
$currentLink = release_absolute_path($options['current-link'], 'current link');
$systemdRoot = release_absolute_directory($options['systemd-root'], 'systemd root');
$backupRoot = release_backup_path($options['backup-root'], $operation);
$phpBinary = release_absolute_path($options['php'], 'php binary');
$records = release_manifest($projectRoot);

release_validate_layout($projectRoot, $releaseRoot, $currentLink, $systemdRoot, $records, $operation);
if($options['local-test-mode']) release_validate_local_test_paths($releaseRoot, $currentLink, $systemdRoot, $backupRoot, $options['db-backup']);
release_run_manifest_verifier($projectRoot, $releaseRoot, $phpBinary);

if($operation === 'plan'){
    echo json_encode(release_plan($projectRoot, $releaseRoot, $currentLink, $systemdRoot, $backupRoot, $records), JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if($operation === 'apply'){
    release_require_confirmation($options, 'EPAY-HEALTH-RELEASE-APPLY');
    if(file_exists($backupRoot) || is_link($backupRoot)) release_fail('backup root already exists');
    if(!is_file($options['db-backup']) || is_link($options['db-backup'])) release_fail('database backup must be an existing regular file');
    release_require_private_file($options['db-backup'], 'database backup');
    $state = release_create_backup($backupRoot, $releaseRoot, $currentLink, $systemdRoot, $records, $options['db-backup']);
    try {
        release_install_systemd($systemdRoot, $records);
        release_run_migration($releaseRoot, $phpBinary, $options['migration-command']);
        release_switch_current($currentLink, $releaseRoot);
        release_systemctl($options['systemctl-binary'], ['daemon-reload']);
        release_run_probe($options['health-check'], 'post-switch health check');
        release_assert_state($currentLink, $releaseRoot, $systemdRoot, release_manifest_systemd_hashes($records), $state['config_sha256']);
        echo json_encode(['ok'=>true,'operation'=>'apply','current_target'=>$releaseRoot,'backup_root'=>$backupRoot,'database_rollback'=>'manual-additive-schema-policy'], JSON_UNESCAPED_SLASHES).PHP_EOL;
        exit(0);
    } catch(Throwable $error){
        try { release_restore_backup($backupRoot, $currentLink, $systemdRoot, $records, $options['systemctl-binary'], $options['restored-health-check']); } catch(Throwable $rollbackError){
            release_fail('apply failed and rollback failed: '.$error->getMessage().' / '.$rollbackError->getMessage());
        }
        release_fail('apply failed; filesystem rollback completed: '.$error->getMessage());
    }
}

if($operation === 'rollback'){
    release_require_confirmation($options, 'EPAY-HEALTH-RELEASE-ROLLBACK');
    $state = release_read_state($backupRoot);
    release_restore_backup($backupRoot, $currentLink, $systemdRoot, $records, $options['systemctl-binary'], $options['restored-health-check']);
    echo json_encode(['ok'=>true,'operation'=>'rollback','restored_target'=>$state['current_target'],'database'=>'not-automatically-restored'], JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

release_fail('unsupported operation '.$operation);

function release_parse_arguments($arguments)
{
    $values = [
        'operation'=>null,
        'release-root'=>null,
        'current-link'=>null,
        'systemd-root'=>null,
        'backup-root'=>null,
        'db-backup'=>null,
        'health-check-json'=>null,
        'restored-health-check-json'=>null,
        'local-test-mode'=>null,
        'migration-command-json'=>null,
        'systemctl-binary'=>null,
        'php'=>'/usr/bin/php8.4',
        'confirm'=>null,
    ];
    foreach($arguments as $argument){
        if($argument === '--help'){
            echo "Usage: ai-health-release-control.php --operation=plan|apply|rollback --release-root=DIR --current-link=LINK --systemd-root=DIR --backup-root=DIR [--db-backup=FILE] [--confirm=TEXT]\n";
            exit(0);
        }
        if(strpos($argument, '--') !== 0 || strpos($argument, '=') === false) release_fail('arguments must use --name=value');
        list($name,$value) = explode('=', substr($argument, 2), 2);
        if(!array_key_exists($name, $values)) release_fail('unsupported argument '.$name);
        if($values[$name] !== null && $name !== 'php') release_fail('duplicate argument '.$name);
        if($value === '') release_fail('empty argument '.$name);
        $values[$name] = $value;
    }
    foreach(['operation','release-root','current-link','systemd-root','backup-root'] as $required){
        if($values[$required] === null) release_fail('missing --'.$required);
    }
    if(!in_array($values['operation'], ['plan','apply','rollback'], true)) release_fail('operation must be plan, apply or rollback');
    if(in_array($values['operation'], ['apply','rollback'], true) && $values['confirm'] === null) release_fail('explicit --confirm is required');
    if($values['operation'] === 'apply' && $values['db-backup'] === null) release_fail('--db-backup is required for apply');
    if(in_array($values['operation'], ['apply','rollback'], true) && $values['restored-health-check-json'] === null){
        release_fail('--restored-health-check-json is required for apply and rollback');
    }
    if($values['operation'] === 'apply' && $values['health-check-json'] === null) release_fail('--health-check-json is required for apply');
    $values['health-check'] = $values['health-check-json'] === null ? null : release_command_json($values['health-check-json'], 'health check');
    $values['restored-health-check'] = $values['restored-health-check-json'] === null ? null : release_command_json($values['restored-health-check-json'], 'restored health check');
    $values['local-test-mode'] = $values['local-test-mode'] === '1';
    if(($values['migration-command-json'] !== null || $values['systemctl-binary'] !== null) && !$values['local-test-mode']){
        release_fail('test-only command overrides require --local-test-mode=1');
    }
    $values['migration-command'] = $values['migration-command-json'] === null ? null : release_command_json($values['migration-command-json'], 'migration command');
    if($values['systemctl-binary'] === null) $values['systemctl-binary'] = '/usr/bin/systemctl';
    return $values;
}

function release_fail($message)
{
    throw new RuntimeException($message);
}

function release_absolute_path($path, $label)
{
    if(!is_string($path) || $path === '' || $path[0] !== '/' || strpos($path, "\0") !== false || strpos($path, "\\") !== false){
        release_fail($label.' must be an absolute canonical path');
    }
    foreach(explode('/', substr($path, 1)) as $part){
        if($part === '' || $part === '.' || $part === '..') release_fail($label.' contains a non-canonical component');
    }
    return rtrim($path, '/');
}

function release_absolute_directory($path, $label)
{
    $path = release_absolute_path($path, $label);
    if(is_link($path) || !is_dir($path)) release_fail($label.' must be an existing non-symlink directory');
    if(realpath($path) !== $path) release_fail($label.' has a symlink ancestor');
    return $path;
}

function release_backup_path($path, $operation)
{
    $path = release_absolute_path($path, 'backup root');
    if($operation === 'rollback'){
        if(is_link($path) || !is_dir($path) || realpath($path) !== $path) release_fail('backup root must be an existing non-symlink directory for rollback');
    }elseif(file_exists($path) || is_link($path)){
        if(!is_dir($path) || is_link($path)) release_fail('backup root exists but is not a directory');
        release_fail('backup root already exists');
    }else{
        $parent = dirname($path);
        if(!is_dir($parent) || is_link($parent) || realpath($parent) !== $parent) release_fail('backup root parent must be an existing canonical directory');
    }
    return $path;
}

function release_manifest($projectRoot)
{
    $mapping = $projectRoot.'/deploy/ai-health-release-files.txt';
    if(!is_file($mapping)) release_fail('release map is missing');
    $records = ['release'=>[],'systemd'=>[]];
    foreach(file($mapping, FILE_IGNORE_NEW_LINES) as $lineNumber=>$line){
        if(trim($line) === '' || strpos(ltrim($line), '#') === 0) continue;
        $parts = explode("\t", $line);
        if(count($parts) !== 2) release_fail('invalid release map line '.($lineNumber + 1));
        list($source,$destination) = $parts;
        $sourcePath = $projectRoot.'/'.$source;
        if(is_link($sourcePath) || !is_file($sourcePath)) release_fail('source is not a regular file '.$source);
        $sourceSha = hash_file('sha256', $sourcePath);
        if($sourceSha === false) release_fail('cannot hash source '.$source);
        $record = [
            'source'=>$source,
            'source_path'=>$sourcePath,
            'destination'=>$destination,
            'sha256'=>$sourceSha,
        ];
        if(strpos($destination, '{RELEASE_ROOT}/') === 0) $records['release'][] = $record;
        elseif(strpos($destination, '/etc/systemd/system/') === 0) $records['systemd'][] = $record;
        else release_fail('unsupported release destination '.$destination);
    }
    if(count($records['release']) !== 33 || count($records['systemd']) !== 6) release_fail('unexpected release map shape');
    return $records;
}

function release_validate_layout($projectRoot, $releaseRoot, $currentLink, $systemdRoot, $records, $operation)
{
    if(is_link($releaseRoot) || !is_dir($releaseRoot)) release_fail('release root must be an existing non-symlink directory');
    if(realpath($releaseRoot) !== $releaseRoot) release_fail('release root has a symlink ancestor');
    if(!is_link($currentLink)) release_fail('current link must be an existing symlink');
    $currentTarget = readlink($currentLink);
    if($currentTarget === false || !is_dir($currentTarget)) release_fail('current link target must be an existing directory');
    if($operation !== 'rollback' && $currentTarget === $releaseRoot) release_fail('candidate release must differ from current target');
    if(!is_link($currentTarget.'/config.php') || !is_link($releaseRoot.'/config.php')) release_fail('config.php must remain a symlink in both releases');
    $currentConfig = realpath($currentTarget.'/config.php');
    $releaseConfig = realpath($releaseRoot.'/config.php');
    if($currentConfig === false || $releaseConfig === false || !is_file($currentConfig) || !is_file($releaseConfig)) release_fail('config.php symlink target is invalid');
    if(!hash_equals(hash_file('sha256', $currentConfig), hash_file('sha256', $releaseConfig))) release_fail('candidate config differs from current config');
    foreach($records['systemd'] as $record){
        $name = basename($record['destination']);
        $sourcePath = $projectRoot.'/deploy/systemd/'.$name;
        if(!is_file($sourcePath)) release_fail('systemd source missing '.$name);
        if(is_link($systemdRoot.'/'.$name)) release_fail('systemd target is a symlink '.$name);
    }
}

function release_validate_local_test_paths($releaseRoot, $currentLink, $systemdRoot, $backupRoot, $dbBackup)
{
    $prefix = '/private/tmp/epay-ai-health-release-control-test-';
    foreach([$releaseRoot,$currentLink,$systemdRoot,$backupRoot] as $path){
        if(strpos($path, $prefix) !== 0) release_fail('local test paths must stay below '.$prefix.'*');
    }
    if($dbBackup !== null && strpos($dbBackup, $prefix) !== 0) release_fail('local test database backup must stay below '.$prefix.'*');
}

function release_run_manifest_verifier($projectRoot, $releaseRoot, $phpBinary)
{
    if(!is_file($phpBinary) || is_link($phpBinary)) release_fail('php binary is not a regular file');
    $result = release_command([$phpBinary,$projectRoot.'/scripts/verify-ai-health-release.php','--release-root='.$releaseRoot,'--quiet'],$projectRoot);
    if($result['exit_code'] !== 0) release_fail('candidate manifest verification failed');
}

function release_plan($projectRoot, $releaseRoot, $currentLink, $systemdRoot, $backupRoot, $records)
{
    $currentTarget = readlink($currentLink);
    $units = [];
    foreach($records['systemd'] as $record){
        $name = basename($record['destination']);
        $units[] = ['name'=>$name,'target'=>$systemdRoot.'/'.$name,'source_sha256'=>$record['sha256']];
    }
    return [
        'ok'=>true,
        'operation'=>'plan',
        'production_authorized'=>false,
        'current_target'=>$currentTarget,
        'candidate_target'=>$releaseRoot,
        'backup_root'=>$backupRoot,
        'payload_count'=>count($records['release']) + count($records['systemd']),
        'steps'=>['backup-filesystem-state','verify-database-backup','install-systemd-atomically','run-health-install','switch-current-atomically','daemon-reload','post-switch-health-probe','observe'],
        'health_checks_bound'=>true,
        'database_policy'=>'additive-health-schema-is-retained-on-filesystem-rollback',
        'systemd_units'=>$units,
    ];
}

function release_require_confirmation($options, $expected)
{
    if(!hash_equals($expected, (string)$options['confirm'])) release_fail('confirmation token mismatch');
}

function release_require_private_file($path, $label)
{
    $mode = fileperms($path) & 0777;
    if(($mode & 0077) !== 0) release_fail($label.' must not be group/world readable');
}

function release_command_json($encoded, $label)
{
    $command = json_decode($encoded, true);
    if(!is_array($command) || !$command) release_fail($label.' must be a non-empty JSON argv array');
    foreach($command as $argument){
        if(!is_string($argument) || $argument === '' || strpos($argument, "\0") !== false) release_fail($label.' contains an invalid argv member');
    }
    return array_values($command);
}

function release_create_backup($backupRoot, $releaseRoot, $currentLink, $systemdRoot, $records, $dbBackup)
{
    if(!mkdir($backupRoot, 0700, true)) release_fail('cannot create backup root');
    if(!mkdir($backupRoot.'/systemd', 0700)) release_fail('cannot create systemd backup directory');
    $config = realpath(readlink($currentLink).'/config.php');
    $state = [
        'created_at'=>gmdate('c'),
        'current_target'=>readlink($currentLink),
        'config_sha256'=>hash_file('sha256', $config),
        'database_backup'=>realpath($dbBackup),
        'database_backup_sha256'=>hash_file('sha256', $dbBackup),
        'systemd'=>[],
    ];
    foreach($records['systemd'] as $record){
        $name = basename($record['destination']);
        $source = $systemdRoot.'/'.$name;
        if(!is_file($source) || is_link($source)) release_fail('cannot back up systemd unit '.$name);
        $target = $backupRoot.'/systemd/'.$name;
        if(!copy($source, $target) || !chmod($target, 0600)) release_fail('cannot back up '.$name);
        $state['systemd'][$name] = hash_file('sha256', $source);
    }
    $stateJson = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    if(file_put_contents($backupRoot.'/state.json', $stateJson, LOCK_EX) === false) release_fail('cannot write backup state');
    chmod($backupRoot.'/state.json', 0600);
    return $state;
}

function release_install_systemd($systemdRoot, $records)
{
    foreach($records['systemd'] as $record){
        $name = basename($record['destination']);
        $target = $systemdRoot.'/'.$name;
        $temporary = $target.'.epay-new';
        if(file_exists($temporary) || is_link($temporary)) release_fail('stale systemd temporary file '.$name);
        if(!copy($record['source_path'], $temporary) || !chmod($temporary, 0644) || !rename($temporary, $target)){
            release_fail('cannot install systemd unit '.$name);
        }
        if(!hash_equals($record['sha256'], hash_file('sha256', $target))) release_fail('systemd digest mismatch '.$name);
    }
}

function release_run_migration($releaseRoot, $phpBinary, $override)
{
    if($override !== null){
        $result = release_command($override, $releaseRoot);
        if($result['exit_code'] !== 0) release_fail('local migration command failed');
        return;
    }
    $migration = $releaseRoot.'/health_install.php';
    if(is_link($migration) || !is_file($migration)) release_fail('health_install.php is missing from candidate');
    $result = release_command([$phpBinary,$migration], $releaseRoot);
    if($result['exit_code'] !== 0) release_fail('health schema installation failed');
}

function release_switch_current($currentLink, $releaseRoot)
{
    $temporary = dirname($currentLink).'/.current.epay-new';
    if(file_exists($temporary) || is_link($temporary)) release_fail('stale current temporary link');
    if(!symlink($releaseRoot, $temporary) || !rename($temporary, $currentLink)) release_fail('atomic current switch failed');
}

function release_manifest_systemd_hashes($records)
{
    $hashes = [];
    foreach($records['systemd'] as $record) $hashes[basename($record['destination'])] = $record['sha256'];
    ksort($hashes, SORT_STRING);
    return $hashes;
}

function release_assert_state($currentLink, $releaseRoot, $systemdRoot, $expectedSystemd, $configSha256)
{
    if(!is_link($currentLink) || readlink($currentLink) !== $releaseRoot) release_fail('current post-switch probe failed');
    $config = realpath($releaseRoot.'/config.php');
    if($config === false || !hash_equals($configSha256, hash_file('sha256', $config))) release_fail('config post-switch probe failed');
    foreach($expectedSystemd as $name=>$expectedHash){
        $path = $systemdRoot.'/'.$name;
        if(!is_file($path) || is_link($path) || !hash_equals($expectedHash, hash_file('sha256', $path))){
            release_fail('systemd post-switch probe failed');
        }
    }
}

function release_read_state($backupRoot)
{
    if(is_link($backupRoot) || !is_dir($backupRoot) || !is_file($backupRoot.'/state.json')) release_fail('backup state is missing');
    $state = json_decode(file_get_contents($backupRoot.'/state.json'), true);
    if(!is_array($state) || !isset($state['current_target'],$state['config_sha256'],$state['systemd'])) release_fail('backup state is invalid');
    return $state;
}

function release_restore_backup($backupRoot, $currentLink, $systemdRoot, $records, $systemctlBinary, $restoredHealthCheck)
{
    $state = release_read_state($backupRoot);
    if(!is_dir($state['current_target']) || is_link($state['current_target'])) release_fail('previous release is unavailable');
    release_switch_current($currentLink, $state['current_target']);
    foreach($records['systemd'] as $record){
        $name = basename($record['destination']);
        $source = $backupRoot.'/systemd/'.$name;
        $target = $systemdRoot.'/'.$name;
        $temporary = $target.'.epay-rollback';
        if(!is_file($source) || is_link($source)) release_fail('systemd backup is missing '.$name);
        if(!copy($source, $temporary) || !chmod($temporary, 0644) || !rename($temporary, $target)) release_fail('systemd rollback failed '.$name);
    }
    release_systemctl($systemctlBinary, ['daemon-reload']);
    release_run_probe($restoredHealthCheck, 'restored health check');
    release_assert_state($currentLink, $state['current_target'], $systemdRoot, $state['systemd'], $state['config_sha256']);
}

function release_run_probe($command, $label)
{
    if(!is_array($command) || !$command) release_fail($label.' is not configured');
    $result = release_command($command, '/');
    if($result['exit_code'] !== 0) release_fail($label.' failed');
}

function release_systemctl($binary, $arguments)
{
    if(!is_file($binary) || is_link($binary)) release_fail('systemctl is unavailable');
    $result = release_command(array_merge([$binary], $arguments), '/');
    if($result['exit_code'] !== 0) release_fail('systemctl failed');
}

function release_command($command, $cwd)
{
    $pipes = [];
    $process = proc_open($command, [1=>['pipe','w'],2=>['pipe','w']], $pipes, $cwd, null, ['bypass_shell'=>true]);
    if(!is_resource($process)) release_fail('cannot start command');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit_code'=>proc_close($process),'stdout'=>$stdout,'stderr'=>$stderr];
}
