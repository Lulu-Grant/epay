#!/usr/bin/env php
<?php
if(PHP_SAPI !== 'cli') exit(1);

$root = dirname(__DIR__);
$mappingFile = $root.'/deploy/ai-health-release-files.txt';
$checksumFile = $root.'/deploy/ai-health-release-sha256.txt';
$arguments = array_slice($argv, 1);
$write = in_array('--write', $arguments, true);
$verifyAbsolute = in_array('--verify-absolute', $arguments, true);
$immutableStaging = false;
$quiet = false;
$releaseRoot = null;
$skipNext = false;
foreach($arguments as $index => $argument){
    if($skipNext){ $skipNext = false; continue; }
    if(strpos($argument, '--release-root=') === 0) $releaseRoot = substr($argument, strlen('--release-root='));
    elseif($argument === '--release-root'){
        if(!isset($arguments[$index + 1])) release_fail('--release-root requires a value');
        $releaseRoot = $arguments[$index + 1];
        $skipNext = true;
    }elseif($argument === '--quiet'){
        if($quiet) release_fail('duplicate --quiet');
        $quiet = true;
    }elseif($argument === '--immutable-staging'){
        if($immutableStaging) release_fail('duplicate --immutable-staging');
        $immutableStaging = true;
    }elseif(!in_array($argument, ['--write','--verify-absolute'], true)) release_fail('unsupported argument '.$argument);
}
if($immutableStaging && $write) release_fail('--immutable-staging cannot be combined with --write');

function release_fail($message)
{
    fwrite(STDERR, 'release manifest verification failed: '.$message.PHP_EOL);
    exit(1);
}

function release_normalized_source($path)
{
    if($path === '' || $path[0] === '/' || strpos($path, "\\") !== false || strpos($path, "\0") !== false) return false;
    $parts = explode('/', $path);
    foreach($parts as $part) if($part === '' || $part === '.' || $part === '..') return false;
    return implode('/', $parts) === $path;
}

function release_normalized_destination($path)
{
    $prefix = '{RELEASE_ROOT}/';
    if(strpos($path, $prefix) === 0) return release_normalized_source(substr($path, strlen($prefix)));
    if($path === '' || $path[0] !== '/' || strpos($path, "\\") !== false || strpos($path, "\0") !== false) return false;
    foreach(explode('/', substr($path, 1)) as $part) if($part === '' || $part === '.' || $part === '..') return false;
    return true;
}

function release_absolute_directory($path)
{
    if(!is_string($path) || $path === '' || $path[0] !== '/' || strpos($path, "\0") !== false || strpos($path, "\\") !== false) return false;
    foreach(explode('/', substr($path, 1)) as $part) if($part === '' || $part === '.' || $part === '..') return false;
    $trimmed = rtrim($path, '/');
    return $trimmed === '' ? '/' : $trimmed;
}

function release_display_digest($digest)
{
    if(!preg_match('/^[a-f0-9]{64}$/D', (string)$digest)) release_fail('invalid digest format');
    return implode(':', str_split((string)$digest, 8));
}

function release_assert_target($path, $record)
{
    $parent = dirname($path);
    $parts = explode('/', ltrim($parent, '/'));
    $cursor = '/';
    foreach($parts as $part){
        if($part === '') continue;
        $cursor = rtrim($cursor, '/').'/'.$part;
        if(is_link($cursor)) release_fail('target ancestor is a symlink '.$cursor);
    }
    if(is_link($path) || !is_file($path)) release_fail('target is not a regular non-symlink file '.$path);
    $mode = sprintf('%04o', fileperms($path) & 0777);
    $size = filesize($path);
    $sha = hash_file('sha256', $path);
    if($size === false || $sha === false) release_fail('cannot inspect target '.$path);
    if($mode !== $record['mode'] || (string)$size !== $record['size'] || !hash_equals($record['sha256'], $sha)){
        release_fail('target metadata or digest does not match '.$path);
    }
}

if(!is_file($mappingFile)) release_fail('mapping file is missing');
$records = [];
$sources = [];
$destinations = [];
foreach(file($mappingFile, FILE_IGNORE_NEW_LINES) as $lineNumber => $line){
    if(trim($line) === '' || strpos(ltrim($line), '#') === 0) continue;
    $parts = explode("\t", $line);
    if(count($parts) !== 2) release_fail('invalid mapping at line '.($lineNumber + 1));
    list($source, $destination) = $parts;
    if(!release_normalized_source($source)) release_fail('non-canonical source '.$source);
    if(!release_normalized_destination($destination)) release_fail('non-canonical destination '.$destination);
    if(isset($sources[$source])) release_fail('duplicate source '.$source);
    if(isset($destinations[$destination])) release_fail('duplicate destination '.$destination);
    $absolute = $root.'/'.$source;
    if(is_link($absolute) || !is_file($absolute)) release_fail('source is not a regular non-symlink file '.$source);
    $mode = sprintf('%04o', fileperms($absolute) & 0777);
    if($immutableStaging){
        if($mode !== '0444') release_fail('immutable staging source must have mode 0444 '.$source);
        $mode = '0644';
    }
    $size = filesize($absolute);
    $sha = hash_file('sha256', $absolute);
    if($size === false || $sha === false) release_fail('cannot inspect source '.$source);
    $records[$source] = ['source'=>$source, 'destination'=>$destination, 'mode'=>$mode, 'size'=>(string)$size, 'sha256'=>$sha];
    $sources[$source] = true;
    $destinations[$destination] = true;
}
if(!$records) release_fail('payload is empty');
ksort($records, SORT_STRING);

if($releaseRoot !== null){
    $releaseRoot = release_absolute_directory($releaseRoot);
    if($releaseRoot === false || $releaseRoot === '/' || is_link($releaseRoot) || !is_dir($releaseRoot)) release_fail('release root must be an existing absolute non-symlink directory');
    if(realpath($releaseRoot) !== $releaseRoot) release_fail('release root is not canonical or has a symlink ancestor');
}

$bindingInput = "epay-ai-health-release-v1\0";
$checksumLines = [];
foreach($records as $record){
    $bindingInput .= $record['source']."\0".$record['destination']."\0".$record['mode']."\0".$record['size']."\0".$record['sha256']."\0";
    $checksumLines[] = release_display_digest($record['sha256']).'  '.$record['source'];
}
$binding = hash('sha256', $bindingInput);
$checksumLines[] = '# PAYLOAD_COUNT='.count($records);
$checksumLines[] = '# BINDING_ALGORITHM=sha256(context\\0 + sorted(source\\0destination\\0mode\\0size\\0file_sha256\\0))';
$checksumLines[] = '# PAYLOAD_BINDING_SHA256='.release_display_digest($binding);
$expected = implode("\n", $checksumLines)."\n";

if($write){
    if(file_put_contents($checksumFile, $expected, LOCK_EX) === false) release_fail('cannot write checksum file');
}else{
    if(!is_file($checksumFile)) release_fail('checksum file is missing');
    $actual = file_get_contents($checksumFile);
    if($actual === false || !hash_equals($expected, $actual)) release_fail('checksum or payload binding does not match');
}

$targetCount = 0;
if($releaseRoot !== null){
    foreach($records as $record){
        if(strpos($record['destination'], '{RELEASE_ROOT}/') !== 0) continue;
        $relative = substr($record['destination'], strlen('{RELEASE_ROOT}/'));
        release_assert_target($releaseRoot.'/'.$relative, $record);
        $targetCount++;
    }
}
if($verifyAbsolute){
    foreach($records as $record){
        if($record['destination'][0] !== '/') continue;
        release_assert_target($record['destination'], $record);
        $targetCount++;
    }
}
if($verifyAbsolute && $releaseRoot === null) release_fail('--verify-absolute must be combined with --release-root');

if(!$quiet) echo json_encode(['ok'=>true, 'payload_count'=>count($records), 'payload_binding_sha256'=>$binding, 'verified_target_count'=>$targetCount], JSON_UNESCAPED_SLASHES).PHP_EOL;
