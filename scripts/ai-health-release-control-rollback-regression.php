#!/usr/bin/env php
<?php
if(PHP_SAPI !== 'cli') exit(1);

$root = dirname(__DIR__);
$work = '/private/tmp/epay-ai-health-release-control-test-'.getmypid().'-'.bin2hex(random_bytes(4));
$failure = null;
try {
    foreach([$work,$work.'/releases',$work.'/shared',$work.'/systemd',$work.'/backups'] as $directory){
        if(!mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('cannot create fixture directory');
    }
    $old = $work.'/releases/old';
    $candidate = $work.'/releases/candidate';
    mkdir($old, 0755);
    mkdir($candidate, 0755);
    file_put_contents($work.'/shared/config.php', "<?php\n// isolated release control fixture\n");
    chmod($work.'/shared/config.php', 0600);
    symlink($work.'/shared/config.php', $old.'/config.php');
    symlink($work.'/shared/config.php', $candidate.'/config.php');
    symlink($old, $work.'/current');

    $records = rollback_manifest($root);
    foreach($records['release'] as $record){
        $target = $candidate.'/'.$record['relative'];
        if(!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) throw new RuntimeException('cannot create candidate parent');
        if(!copy($record['source'], $target) || !chmod($target, fileperms($record['source']) & 0777)) throw new RuntimeException('cannot stage '.$record['relative']);
    }
    $legacyHashes = [];
    foreach($records['systemd'] as $record){
        $name = basename($record['destination']);
        $path = $work.'/systemd/'.$name;
        file_put_contents($path, "# legacy unit {$name}\n");
        chmod($path, 0644);
        $legacyHashes[$name] = hash_file('sha256', $path);
    }
    ksort($legacyHashes, SORT_STRING);

    $databaseBackup = $work.'/database.sql';
    file_put_contents($databaseBackup, "-- isolated database backup fixture\n");
    chmod($databaseBackup, 0600);
    $systemctl = rollback_executable($work.'/systemctl', "#!/bin/sh\nexit 0\n");
    $migration = rollback_executable($work.'/migration', "#!/bin/sh\nexit 0\n");
    $healthFail = rollback_executable($work.'/health-fail', "#!/bin/sh\nexit 23\n");
    $healthRestored = rollback_executable($work.'/health-restored', "#!/bin/sh\nexit 0\n");
    $backup = $work.'/backups/apply';

    $command = [
        realpath(PHP_BINARY), $root.'/deploy/ai-health-release-control.php',
        '--operation=apply', '--release-root='.$candidate, '--current-link='.$work.'/current',
        '--systemd-root='.$work.'/systemd', '--backup-root='.$backup, '--db-backup='.$databaseBackup,
        '--health-check-json='.json_encode([$healthFail], JSON_UNESCAPED_SLASHES),
        '--restored-health-check-json='.json_encode([$healthRestored], JSON_UNESCAPED_SLASHES),
        '--migration-command-json='.json_encode([$migration], JSON_UNESCAPED_SLASHES),
        '--systemctl-binary='.$systemctl, '--php='.realpath(PHP_BINARY), '--local-test-mode=1',
        '--confirm=EPAY-HEALTH-RELEASE-APPLY',
    ];
    $result = rollback_command($command, $root);
    if($result['exit_code'] === 0) throw new RuntimeException('fault-injected release unexpectedly succeeded');
    if(strpos($result['stderr'], 'filesystem rollback completed') === false){
        throw new RuntimeException('controller did not report completed rollback: '.trim($result['stderr']));
    }
    if(!is_link($work.'/current') || readlink($work.'/current') !== $old) throw new RuntimeException('current link was not restored');
    $actualHashes = [];
    foreach($records['systemd'] as $record){
        $name = basename($record['destination']);
        $actualHashes[$name] = hash_file('sha256', $work.'/systemd/'.$name);
    }
    ksort($actualHashes, SORT_STRING);
    if($actualHashes !== $legacyHashes) throw new RuntimeException('systemd units were not restored');
    if(!is_file($backup.'/state.json')) throw new RuntimeException('rollback state was not retained for audit');
    echo json_encode([
        'ok'=>true, 'fault'=>'post-switch-health-check', 'controller_exit_nonzero'=>true,
        'current_restored'=>true, 'systemd_restored'=>true, 'backup_state_retained'=>true,
    ], JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch(Throwable $e){
    $failure = $e;
} finally {
    rollback_remove_tree($work);
}
if($failure){
    fwrite(STDERR, 'release control rollback regression failed: '.$failure->getMessage().PHP_EOL);
    exit(1);
}

function rollback_manifest($root)
{
    $records = ['release'=>[], 'systemd'=>[]];
    foreach(file($root.'/deploy/ai-health-release-files.txt', FILE_IGNORE_NEW_LINES) as $line){
        if(trim($line) === '' || strpos(ltrim($line), '#') === 0) continue;
        list($source,$destination) = explode("\t", $line, 2);
        if(strpos($destination, '{RELEASE_ROOT}/') === 0){
            $records['release'][] = ['source'=>$root.'/'.$source, 'relative'=>substr($destination, 15), 'destination'=>$destination];
        }else{
            $records['systemd'][] = ['source'=>$root.'/'.$source, 'destination'=>$destination];
        }
    }
    return $records;
}

function rollback_executable($path, $contents)
{
    file_put_contents($path, $contents);
    chmod($path, 0700);
    return $path;
}

function rollback_command($command, $cwd)
{
    $pipes = [];
    $process = proc_open($command, [1=>['pipe','w'],2=>['pipe','w']], $pipes, $cwd, null, ['bypass_shell'=>true]);
    if(!is_resource($process)) throw new RuntimeException('cannot start release controller');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit_code'=>proc_close($process), 'stdout'=>$stdout, 'stderr'=>$stderr];
}

function rollback_remove_tree($path)
{
    if(!file_exists($path) && !is_link($path)) return;
    if(is_link($path) || is_file($path)){ @unlink($path); return; }
    foreach(scandir($path) as $name){
        if($name === '.' || $name === '..') continue;
        rollback_remove_tree($path.'/'.$name);
    }
    @rmdir($path);
}
