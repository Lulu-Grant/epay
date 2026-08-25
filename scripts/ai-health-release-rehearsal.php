#!/usr/bin/env php
<?php
if(PHP_SAPI !== 'cli') exit(1);

$projectRoot = dirname(__DIR__);
$workRoot = null;
$evidenceFile = null;
foreach(array_slice($argv, 1) as $argument){
    if(strpos($argument, '--work-root=') === 0){
        if($workRoot !== null) rehearsal_fail('duplicate --work-root');
        $workRoot = substr($argument, strlen('--work-root='));
    }elseif(strpos($argument, '--evidence=') === 0){
        if($evidenceFile !== null) rehearsal_fail('duplicate --evidence');
        $evidenceFile = substr($argument, strlen('--evidence='));
    }else{
        rehearsal_fail('unsupported argument '.$argument);
    }
}
if($workRoot === null) rehearsal_fail('--work-root is required');
$workRoot = rehearsal_safe_root($workRoot);
if(file_exists($workRoot) || is_link($workRoot)) rehearsal_fail('work root already exists');
if(!mkdir($workRoot, 0700, true)) rehearsal_fail('cannot create work root');
if($evidenceFile === null) $evidenceFile = $workRoot.'/evidence.json';
if(dirname($evidenceFile) !== $workRoot || basename($evidenceFile) !== 'evidence.json'){
    rehearsal_fail('evidence must be WORK_ROOT/evidence.json');
}

$sourceVerification = rehearsal_command([
    PHP_BINARY, $projectRoot.'/scripts/verify-ai-health-release.php', '--quiet',
], $projectRoot);
if($sourceVerification['exit_code'] !== 0) rehearsal_fail('source manifest verification failed');
$records = rehearsal_manifest($projectRoot);
rehearsal_validate_units($projectRoot);

$results = [];
foreach(['none','after-systemd','after-migration','after-cutover'] as $fault){
    $results[] = rehearsal_scenario($projectRoot, $workRoot, $records, $fault);
}

$payloadBinding = null;
$checksum = file_get_contents($projectRoot.'/deploy/ai-health-release-sha256.txt');
if($checksum !== false && preg_match('/^# PAYLOAD_BINDING_SHA256=([a-f0-9:]+)$/m', $checksum, $match)){
    $payloadBinding = str_replace(':', '', $match[1]);
}
$evidence = [
    'ok'=>true,
    'scope'=>'isolated-local-rehearsal-only',
    'production_authorized'=>false,
    'generated_at'=>gmdate('c'),
    'php_version'=>PHP_VERSION,
    'project_root_path_sha256'=>hash('sha256', $projectRoot),
    'rehearsal_script_sha256'=>hash_file('sha256', __FILE__),
    'payload_binding_sha256'=>$payloadBinding,
    'source_manifest_verified'=>$sourceVerification['exit_code'] === 0,
    'work_root'=>$workRoot,
    'scenarios'=>$results,
];
$json = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if($json === false || file_put_contents($evidenceFile, $json."\n", LOCK_EX) === false){
    rehearsal_fail('cannot write evidence');
}
chmod($evidenceFile, 0600);
echo json_encode([
    'ok'=>true,
    'production_authorized'=>false,
    'scenario_count'=>count($results),
    'payload_binding_sha256'=>$payloadBinding,
    'evidence'=>$evidenceFile,
], JSON_UNESCAPED_SLASHES).PHP_EOL;

function rehearsal_fail($message)
{
    fwrite(STDERR, 'release rehearsal failed: '.$message.PHP_EOL);
    exit(1);
}

function rehearsal_safe_root($path)
{
    if(!is_string($path) || strpos($path, "\0") !== false || strpos($path, "\\") !== false) rehearsal_fail('invalid work root');
    if(!preg_match('#^/private/tmp/(epay-ai-health-release-rehearsal-[A-Za-z0-9._-]+)$#D', $path, $match)){
        rehearsal_fail('work root must be /private/tmp/epay-ai-health-release-rehearsal-*');
    }
    if(strpos($match[1], '..') !== false) rehearsal_fail('work root is not canonical');
    if(realpath('/private/tmp') !== '/private/tmp') rehearsal_fail('/private/tmp is not canonical');
    return $path;
}

function rehearsal_manifest($projectRoot)
{
    $records = ['release'=>[], 'systemd'=>[]];
    $mapping = $projectRoot.'/deploy/ai-health-release-files.txt';
    foreach(file($mapping, FILE_IGNORE_NEW_LINES) as $lineNumber=>$line){
        if(trim($line) === '' || strpos(ltrim($line), '#') === 0) continue;
        $parts = explode("\t", $line);
        if(count($parts) !== 2) rehearsal_fail('invalid manifest line '.($lineNumber + 1));
        list($source,$destination) = $parts;
        $sourcePath = $projectRoot.'/'.$source;
        if(is_link($sourcePath) || !is_file($sourcePath)) rehearsal_fail('invalid manifest source '.$source);
        $record = [
            'source'=>$source,
            'source_path'=>$sourcePath,
            'destination'=>$destination,
            'mode'=>fileperms($sourcePath) & 0777,
            'size'=>filesize($sourcePath),
            'sha256'=>hash_file('sha256', $sourcePath),
        ];
        if($record['size'] === false || $record['sha256'] === false) rehearsal_fail('cannot inspect manifest source '.$source);
        if(strpos($destination, '{RELEASE_ROOT}/') === 0) $records['release'][] = $record;
        elseif(strpos($destination, '/etc/systemd/system/') === 0) $records['systemd'][] = $record;
        else rehearsal_fail('unsupported destination '.$destination);
    }
    if(count($records['release']) !== 33 || count($records['systemd']) !== 6){
        rehearsal_fail('unexpected release shape');
    }
    return $records;
}

function rehearsal_validate_units($projectRoot)
{
    $checks = [
        'epay-health-snapshot.service'=>[
            'WorkingDirectory=/srv/epay/current',
            'ExecStart=/usr/bin/php8.4 /srv/epay/current/health_snapshot.php',
            'User=www-data', 'Group=www-data', 'NoNewPrivileges=true', 'ProtectSystem=strict',
        ],
        'epay-health-report.service'=>[
            'WorkingDirectory=/srv/epay/current',
            'ExecStart=/usr/bin/php8.4 /srv/epay/current/health_report.php --send --ai',
            'EnvironmentFile=-/etc/epay/ai-health.env',
            'User=www-data', 'Group=www-data', 'NoNewPrivileges=true', 'ProtectSystem=strict',
        ],
        'epay-health-snapshot.timer'=>[
            'OnCalendar=*-*-* *:10:00 Asia/Shanghai', 'Persistent=true',
        ],
        'epay-health-report.timer'=>[
            'OnCalendar=*-*-* 09:05:00 Asia/Shanghai', 'Persistent=true',
        ],
    ];
    foreach($checks as $name=>$required){
        $contents = file_get_contents($projectRoot.'/deploy/systemd/'.$name);
        if($contents === false) rehearsal_fail('cannot read '.$name);
        foreach($required as $line){
            if(strpos($contents, $line."\n") === false) rehearsal_fail($name.' is missing '.$line);
        }
    }
}

function rehearsal_scenario($projectRoot, $workRoot, $records, $fault)
{
    $scenarioRoot = $workRoot.'/'.$fault;
    $paths = rehearsal_fixture($scenarioRoot, $records);
    $phases = [];
    $migration = null;
    $expectedFailure = $fault !== 'none';
    $failure = null;
    try {
        rehearsal_install_candidate($projectRoot, $paths, $records);
        $phases[] = 'candidate-installed';
        rehearsal_verify_candidate($projectRoot, $paths, $records);
        $phases[] = 'candidate-verified';

        rehearsal_install_units($paths, $records);
        $phases[] = 'systemd-installed';
        rehearsal_inject($fault, 'after-systemd');

        $migration = rehearsal_command([PHP_BINARY, $projectRoot.'/scripts/health-schema-migration-regression.php'], $projectRoot);
        $migrationJson = json_decode(trim($migration['stdout']), true);
        if($migration['exit_code'] !== 0 || !is_array($migrationJson) || empty($migrationJson['fixture_restored'])){
            throw new RuntimeException('isolated migration regression failed');
        }
        $phases[] = 'migration-regression-restored';
        rehearsal_inject($fault, 'after-migration');

        rehearsal_switch($paths['current'], $paths['candidate']);
        $phases[] = 'current-switched';
        rehearsal_inject($fault, 'after-cutover');

        rehearsal_probe($paths, $records, true);
        $phases[] = 'post-cutover-probes-passed';
        if($expectedFailure) throw new RuntimeException('expected fault was not injected');
    } catch(Throwable $e){
        $failure = $e->getMessage();
        if(!$expectedFailure || $failure !== 'injected fault '.$fault){
            rehearsal_rollback($paths, $records);
            throw new RuntimeException($fault.' scenario failed unexpectedly: '.$failure);
        }
    }

    rehearsal_rollback($paths, $records);
    $phases[] = 'rollback-complete';
    rehearsal_probe($paths, $records, false);
    $phases[] = 'rollback-probes-passed';
    return [
        'fault'=>$fault,
        'expected_failure'=>$expectedFailure,
        'fault_observed'=>$expectedFailure ? $failure : null,
        'migration_regression'=>$migration === null ? 'not-reached' : 'passed-and-restored',
        'phases'=>$phases,
        'rollback_verified'=>true,
    ];
}

function rehearsal_fixture($root, $records)
{
    foreach([$root,$root.'/releases',$root.'/shared',$root.'/systemd',$root.'/backup/systemd'] as $directory){
        if(!mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('cannot create fixture directory');
    }
    $old = $root.'/releases/old';
    $candidate = $root.'/releases/candidate';
    mkdir($old, 0755);
    file_put_contents($old.'/.release-id', "old\n");
    file_put_contents($old.'/.baseline-preserved', "existing application baseline\n");
    file_put_contents($root.'/shared/config.php', "<?php\n// isolated release rehearsal fixture\n");
    chmod($root.'/shared/config.php', 0640);
    symlink($root.'/shared/config.php', $old.'/config.php');
    symlink($old, $root.'/current');
    foreach($records['systemd'] as $record){
        $name = basename($record['destination']);
        $contents = "# isolated legacy fixture for {$name}\n";
        file_put_contents($root.'/systemd/'.$name, $contents);
        chmod($root.'/systemd/'.$name, 0644);
        copy($root.'/systemd/'.$name, $root.'/backup/systemd/'.$name);
        chmod($root.'/backup/systemd/'.$name, 0600);
    }
    $state = [
        'current_target'=>readlink($root.'/current'),
        'config_sha256'=>hash_file('sha256', $root.'/shared/config.php'),
        'systemd'=>rehearsal_hash_units($root.'/systemd', $records),
    ];
    file_put_contents($root.'/backup/state.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    chmod($root.'/backup/state.json', 0600);
    return [
        'root'=>$root, 'old'=>$old, 'candidate'=>$candidate,
        'current'=>$root.'/current', 'shared_config'=>$root.'/shared/config.php',
        'systemd'=>$root.'/systemd', 'backup'=>$root.'/backup',
    ];
}

function rehearsal_install_candidate($projectRoot, $paths, $records)
{
    rehearsal_clone_release($paths['old'], $paths['candidate']);
    file_put_contents($paths['candidate'].'/.release-id', "candidate\n");
    foreach($records['release'] as $record){
        $relative = substr($record['destination'], strlen('{RELEASE_ROOT}/'));
        $target = $paths['candidate'].'/'.$relative;
        if(!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) throw new RuntimeException('cannot create candidate parent');
        if(!copy($record['source_path'], $target) || !chmod($target, $record['mode'])) throw new RuntimeException('cannot install '.$relative);
    }
}

function rehearsal_clone_release($source, $destination)
{
    if(!mkdir($destination, 0755)) throw new RuntimeException('cannot create candidate');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach($iterator as $item){
        $relative = substr($item->getPathname(), strlen($source) + 1);
        $target = $destination.'/'.$relative;
        if($item->isLink()){
            if($relative !== 'config.php') throw new RuntimeException('unexpected baseline symlink '.$relative);
            if(!symlink(readlink($item->getPathname()), $target)) throw new RuntimeException('cannot preserve config symlink');
        }elseif($item->isDir()){
            if(!mkdir($target, $item->getPerms() & 0777)) throw new RuntimeException('cannot clone baseline directory');
        }elseif($item->isFile()){
            if(!copy($item->getPathname(), $target) || !chmod($target, $item->getPerms() & 0777)){
                throw new RuntimeException('cannot clone baseline file');
            }
        }else{
            throw new RuntimeException('unsupported baseline entry '.$relative);
        }
    }
}

function rehearsal_verify_candidate($projectRoot, $paths, $records)
{
    $verification = rehearsal_command([
        PHP_BINARY, $projectRoot.'/scripts/verify-ai-health-release.php',
        '--release-root='.$paths['candidate'], '--quiet',
    ], $projectRoot);
    if($verification['exit_code'] !== 0) throw new RuntimeException('candidate manifest verification failed');
    foreach($records['release'] as $record){
        if(substr($record['source'], -4) !== '.php') continue;
        $relative = substr($record['destination'], strlen('{RELEASE_ROOT}/'));
        $lint = rehearsal_command([PHP_BINARY, '-l', $paths['candidate'].'/'.$relative], $projectRoot);
        if($lint['exit_code'] !== 0) throw new RuntimeException('candidate PHP lint failed for '.$relative);
    }
}

function rehearsal_install_units($paths, $records)
{
    foreach($records['systemd'] as $record){
        $target = $paths['systemd'].'/'.basename($record['destination']);
        $temporary = $target.'.new';
        if(!copy($record['source_path'], $temporary) || !chmod($temporary, 0644) || !rename($temporary, $target)){
            throw new RuntimeException('cannot install systemd unit');
        }
        if(!hash_equals($record['sha256'], hash_file('sha256', $target))) throw new RuntimeException('systemd unit digest mismatch');
    }
}

function rehearsal_switch($current, $target)
{
    $temporary = dirname($current).'/.current.next';
    if(file_exists($temporary) || is_link($temporary)) throw new RuntimeException('stale cutover link');
    if(!symlink($target, $temporary) || !rename($temporary, $current)) throw new RuntimeException('atomic cutover failed');
}

function rehearsal_rollback($paths, $records)
{
    rehearsal_switch($paths['current'], $paths['old']);
    foreach($records['systemd'] as $record){
        $name = basename($record['destination']);
        $source = $paths['backup'].'/systemd/'.$name;
        $target = $paths['systemd'].'/'.$name;
        $temporary = $target.'.rollback';
        if(!copy($source, $temporary) || !chmod($temporary, 0644) || !rename($temporary, $target)){
            throw new RuntimeException('systemd rollback failed');
        }
    }
}

function rehearsal_probe($paths, $records, $candidateExpected)
{
    $state = json_decode(file_get_contents($paths['backup'].'/state.json'), true);
    $expectedCurrent = $candidateExpected ? $paths['candidate'] : $state['current_target'];
    if(!is_link($paths['current']) || readlink($paths['current']) !== $expectedCurrent) throw new RuntimeException('current target probe failed');
    if(!hash_equals($state['config_sha256'], hash_file('sha256', $paths['shared_config']))) throw new RuntimeException('shared config probe failed');
    if(!is_link($expectedCurrent.'/config.php') || realpath($expectedCurrent.'/config.php') !== $paths['shared_config']){
        throw new RuntimeException('config link probe failed');
    }
    if($candidateExpected && trim((string)file_get_contents($expectedCurrent.'/.baseline-preserved')) !== 'existing application baseline'){
        throw new RuntimeException('baseline preservation probe failed');
    }
    $actual = rehearsal_hash_units($paths['systemd'], $records);
    $expected = $candidateExpected ? [] : $state['systemd'];
    if($candidateExpected){
        foreach($records['systemd'] as $record) $expected[basename($record['destination'])] = $record['sha256'];
    }
    if($actual !== $expected) throw new RuntimeException('systemd state probe failed');
}

function rehearsal_hash_units($directory, $records)
{
    $hashes = [];
    foreach($records['systemd'] as $record){
        $name = basename($record['destination']);
        $path = $directory.'/'.$name;
        if(is_link($path) || !is_file($path)) throw new RuntimeException('invalid systemd fixture '.$name);
        $hashes[$name] = hash_file('sha256', $path);
    }
    ksort($hashes, SORT_STRING);
    return $hashes;
}

function rehearsal_inject($configured, $phase)
{
    if($configured === $phase) throw new RuntimeException('injected fault '.$configured);
}

function rehearsal_command($command, $cwd)
{
    $descriptor = [1=>['pipe','w'], 2=>['pipe','w']];
    $process = proc_open($command, $descriptor, $pipes, $cwd, null, ['bypass_shell'=>true]);
    if(!is_resource($process)) throw new RuntimeException('cannot start command');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return ['exit_code'=>$exitCode, 'stdout'=>$stdout, 'stderr'=>$stderr];
}
