<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80500) { fwrite(STDERR, "PHP 8.4 required\n"); exit(1); }
require dirname(__DIR__).'/includes/autoloader.php';
Autoloader::register();
use lib\ListReadCache;
use lib\ListQueryFilter;

function cacheAssert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
    echo 'PASS '.$message.PHP_EOL;
}
function cacheExpectError(callable $call, string $message): void
{
    try { $call(); } catch (Throwable $e) { cacheAssert(true, $message); return; }
    cacheAssert(false, $message);
}
function cacheRemove(string $path): void
{
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new DirectoryIterator($path) as $entry) if (!$entry->isDot()) cacheRemove($entry->getPathname());
    rmdir($path);
}
function cacheFixture(string $directory): array
{
    return ['directory'=>$directory, 'counts_enabled'=>true, 'summaries_enabled'=>true,
        'dictionaries_enabled'=>true, 'metadata_enabled'=>true, 'admin_enabled'=>true, 'merchants_enabled'=>true];
}

if (($argv[1] ?? '') === '--worker') {
    $directory = $argv[2];
    $cache = new ListReadCache(cacheFixture($directory), ['dbname'=>'unit'], 'test');
    $deadline = microtime(true) + 10;
    file_put_contents($directory.'/ready-'.$argv[3], '1');
    while (!file_exists($directory.'/go') && microtime(true) < $deadline) usleep(1000);
    $result = $cache->remember('counts', 'concurrency', ['audience'=>'admin'], [], ['shared'], function () use ($directory) {
        file_put_contents($directory.'/loads', '1', FILE_APPEND | LOCK_EX);
        usleep(10000);
        return 42;
    });
    echo json_encode($result + ['metrics'=>ListReadCache::metrics()]);
    exit($result['value'] === 42 ? 0 : 1);
}

$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'epay-list-cache-test-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$config = cacheFixture($root);
$scope = ['audience'=>'admin'];
$cache = new ListReadCache($config, ['dbname'=>'unit'], 'test');
$calls = 0;
$loader = function () use (&$calls) { return ++$calls; };
try {
    $first = $cache->remember('counts', 'orders', $scope, ['status'=>1, 'uid'=>2], ['orders'], $loader);
    $second = $cache->remember('counts', 'orders', $scope, ['uid'=>2, 'status'=>1], ['orders'], $loader);
    cacheAssert($first['value'] === 1 && $second['value'] === 1 && $second['meta']['cached'], 'normalized filters hit across requests');
    $other = new ListReadCache($config, ['dbname'=>'unit'], 'test');
    cacheAssert($other->remember('counts', 'orders', $scope, ['uid'=>2, 'status'=>1], ['orders'], $loader)['value'] === 1, 'new cache instance shares data');
    $cache->remember('counts', 'orders', ['audience'=>'merchant', 'uid'=>2], [], ['uid2'], $loader);
    $cache->remember('counts', 'orders', ['audience'=>'merchant', 'uid'=>3], [], ['uid3'], $loader);
    cacheAssert($calls === 3, 'merchant scopes are isolated');
    $otherSite = new ListReadCache($config, ['dbname'=>'other'], 'test');
    $otherSite->remember('counts', 'orders', $scope, ['uid'=>2, 'status'=>1], ['orders'], $loader);
    cacheAssert($calls === 4, 'database identity is isolated');
    $cache->invalidate(['orders']);
    cacheAssert($cache->remember('counts', 'orders', $scope, ['uid'=>2, 'status'=>1], ['orders'], $loader)['value'] === 5, 'write invalidation refreshes count');
    $cache->remember('counts', 'orders', $scope, ['uid'=>2, 'status'=>1], ['orders'], $loader, true);
    cacheAssert($calls === 6, 'explicit fresh bypasses cache');
    $cache->remember('counts', 'race', $scope, [], ['race'], function () use ($cache) { $cache->invalidate(['race']); return 5; });
    $race = $cache->remember('counts', 'race', $scope, [], ['race'], fn()=>8);
    cacheAssert($race['value'] === 8 && !$race['meta']['cached'], 'in-flight old result cannot repopulate after invalidation');
    cacheAssert($cache->remember('counts', 'zero', $scope, [], ['zero'], fn()=>0)['value'] === 0, 'zero is valid cached data');
    cacheExpectError(fn()=>$cache->remember('counts', 'error', $scope, [], ['error'], fn()=>false), 'SQL failure is not an empty success');
    cacheAssert($cache->remember('counts', 'error', $scope, [], ['error'], fn()=>9)['value'] === 9, 'failed load is not cached');
    $short = new ListReadCache($config + ['counts_ttl'=>1], ['dbname'=>'unit'], 'short');
    $short->remember('counts', 'ttl', $scope, [], ['ttl'], fn()=>1);
    usleep(1100000);
    cacheAssert($short->remember('counts', 'ttl', $scope, [], ['ttl'], fn()=>2)['value'] === 2, 'absolute TTL expires');
    $short->remember('counts', 'slow', $scope, [], ['slow'], function () { usleep(1100000); return 1; });
    cacheAssert($short->remember('counts', 'slow', $scope, [], ['slow'], fn()=>2)['value'] === 2, 'slow load does not extend expiry');
    $disabled = new ListReadCache([], ['dbname'=>'unit']);
    $disabled->remember('counts', 'disabled', $scope, [], [], $loader);
    $disabled->remember('counts', 'disabled', $scope, [], [], $loader);
    cacheAssert($calls === 8, 'disabled mode always loads SQL');
    $broken = new ListReadCache(array_replace($config, ['directory'=>$root.'/missing']), ['dbname'=>'unit']);
    cacheAssert($broken->remember('counts', 'broken', $scope, [], [], fn()=>3)['value'] === 3, 'missing directory fails open to SQL');
    $badConfig = new ListReadCache(array_replace($config, ['merchant_allowlist'=>'invalid']), ['dbname'=>'unit']);
    cacheAssert($badConfig->remember('counts', 'bad-config', ['audience'=>'merchant', 'uid'=>2], [], [], fn()=>4)['value'] === 4, 'malformed config disables cache');
    $huge = str_repeat('x', 65536);
    $cache->remember('counts', 'huge', $scope, [], [], fn()=>$huge);
    cacheAssert(!$cache->remember('counts', 'huge', $scope, [], [], fn()=>$huge)['meta']['cached'], 'oversized entries are not stored');

    $isolated = $root.'/faults';
    mkdir($isolated, 0700);
    $faultCache = new ListReadCache(cacheFixture($isolated), ['dbname'=>'unit'], 'test');
    $faultRead = fn($value)=>$faultCache->remember('counts', 'faults', $scope, [], ['faults'], fn()=>$value);
    $faultRead(10);
    $site = glob($isolated.'/*', GLOB_ONLYDIR)[0];
    $files = glob($site.'/data/*/*.json');
    $file = array_values(array_filter($files, fn($path)=>basename($path) !== 'index.json'))[0];
    file_put_contents($file, '{broken');
    cacheAssert($faultRead(11)['value'] === 11, 'corrupt JSON falls back to loader');
    $entry = json_decode(file_get_contents($file), true); $entry['value'] = ['bad'=>1];
    file_put_contents($file, json_encode($entry));
    cacheAssert($faultRead(12)['value'] === 12, 'invalid count payload falls back to loader');
    $epoch = new ListReadCache(cacheFixture($isolated) + ['schema_epoch'=>'2'], ['dbname'=>'unit'], 'test');
    cacheAssert(!$epoch->remember('counts', 'faults', $scope, [], ['faults'], fn()=>13)['meta']['cached'], 'schema epoch isolates old data');
    $release = new ListReadCache(cacheFixture($isolated), ['dbname'=>'unit'], 'next-release');
    cacheAssert(!$release->remember('counts', 'faults', $scope, [], ['faults'], fn()=>14)['meta']['cached'], 'release isolates old data');
    file_put_contents(glob($site.'/tags/*')[0], 'broken');
    cacheAssert(!$faultRead(15)['meta']['cached'], 'damaged generation never revives old data');
    if (PHP_OS_FAMILY !== 'Windows') {
        $files = glob($site.'/data/*/*.json');
        foreach ($files as $candidate) if (basename($candidate) !== 'index.json') unlink($candidate);
        $held = [];
        for ($i=0;$i<256;$i++) { $h=fopen($site.'/locks/'.sprintf('%02x',$i).'.lock','c+b'); flock($h, LOCK_EX); $held[]=$h; }
        $start=microtime(true);
        cacheAssert($faultRead(16)['value'] === 16 && microtime(true)-$start < .2, 'busy locks fall back within bounded wait');
        foreach ($held as $h) { flock($h,LOCK_UN); fclose($h); }
        chmod($site.'/tags',0500);
        $faultCache->invalidate(['faults']);
        cacheAssert($faultRead(17)['value'] === 17, 'invalidation write failure never throws to caller');
        chmod($site.'/tags',0700);
        $faultCache = new ListReadCache(cacheFixture($isolated), ['dbname'=>'unit'], 'test');
        // A malicious link must never be followed, including by cleanup.
        $outside=$root.'/outside'; file_put_contents($outside,'untouched');
        for($i=0;$i<256;$i++) { $link=$site.'/data/'.sprintf('%02x',$i); if(!file_exists($link)) break; }
        symlink($outside,$link);
        $faultCache->clean(1000);
        cacheAssert(file_get_contents($outside)==='untouched', 'cleanup does not follow shard links');
        unlink($link);
    }
    $quotaDir=$root.'/quota'; mkdir($quotaDir,0700);
    $quota = new ListReadCache(cacheFixture($quotaDir)+['max_entries'=>256], ['dbname'=>'unit'], 'test');
    for($i=0;$i<600;$i++) $quota->remember('counts','quota',$scope,['n'=>$i],[],fn()=>$i);
    $dataFiles=array_filter(glob($quotaDir.'/*/data/*/*.json'),fn($path)=>basename($path)!=='index.json');
    cacheAssert(count($dataFiles)<=256 && (ListReadCache::metrics()['capacity.shard_limit']??0)>0, 'shard capacity stops new writes and returns live values');
    $byteQuota = new ListReadCache(cacheFixture($quotaDir)+['max_bytes'=>262144], ['dbname'=>'bytes'], 'test');
    $byteQuota->remember('dictionaries','large',$scope,[],[],fn()=>[str_repeat('x',2000)]);
    cacheAssert(!$byteQuota->remember('dictionaries','large',$scope,[],[],fn()=>[str_repeat('x',2000)])['meta']['cached'], 'byte budget fails open');

    $cleanDir=$root.'/clean'; mkdir($cleanDir,0700);
    $cleanCache=new ListReadCache(cacheFixture($cleanDir),['dbname'=>'clean'],'test');
    $cleanCache->remember('counts','clean',$scope,[],['keep-tag'],fn()=>1);
    $cleanSite=glob($cleanDir.'/*',GLOB_ONLYDIR)[0];
    $shardDir=glob($cleanSite.'/data/*',GLOB_ONLYDIR)[0];
    $index=json_decode(file_get_contents($shardDir.'/index.json'),true);
    $firstKey=array_key_first($index);
    $entry=json_decode(file_get_contents($shardDir.'/'.$firstKey.'.json'),true);
    foreach(['e','f'] as $digit) {
        $key=basename($shardDir).str_repeat($digit,62);
        $entry['expires']=microtime(true)+($digit==='f'?-1:30);
        $raw=json_encode($entry); file_put_contents($shardDir.'/'.$key.'.json',$raw);
        $index[$key]=['bytes'=>strlen($raw),'expires'=>$entry['expires']];
    }
    file_put_contents($shardDir.'/index.json',json_encode($index));
    file_put_contents($cleanSite.'/cleanup.json',json_encode(['shard'=>hexdec(basename($shardDir))]));
    file_put_contents($shardDir.'/'.$firstKey.'.json.tmp','abandoned');
    $removed=0; for($i=0;$i<3;$i++) { $part=$cleanCache->clean(1); cacheAssert($part['checked']===1,'cleanup obeys limit=1'); $removed+=$part['removed']; }
    cacheAssert($removed===1 && !file_exists($shardDir.'/'.$firstKey.'.json.tmp'), 'small cleanup limit makes progress and reclaims abandoned temp');
    cacheAssert(count(glob($cleanSite.'/tags/*'))===1 && count(glob($cleanSite.'/locks/*'))>0, 'cleanup retains live generations and stable locks');

    $payment = ListQueryFilter::payment(['uid'=>999, 'kw'=>'LP0000000000000000001', 'type'=>1, 'paytype'=>2, 'channel'=>3], 1000);
    cacheAssert($payment->bind[':f0'] === 1000 && !in_array(999, $payment->bind, true), 'merchant identity comes from trusted argument');
    cacheAssert(str_contains($payment->where, 'A.type=') && !str_contains($payment->where, 'A.channel='), 'payment type priority preserved');
    cacheAssert(in_array('LP0000000000000000001', $payment->bind, true), 'LP order number stays a string');
    cacheAssert(in_array('0', ListQueryFilter::payment(['value'=>'0', 'column'=>'money'])->bind, true), 'zero amount remains a filter');
    cacheExpectError(fn()=>ListQueryFilter::payment(['starttime'=>'2026-02-30']), 'invalid date rejected');
    cacheExpectError(fn()=>ListQueryFilter::payment(['starttime'=>'2026-09-25', 'endtime'=>'2026-09-24']), 'reversed dates rejected');
    cacheExpectError(fn()=>ListQueryFilter::shop(['search_field'=>'merchant_uid', 'keyword'=>'LP1']), 'UID search never converts text into zero');
    cacheAssert(ListQueryFilter::shop(['keyword'=>'LP1'])->bind[':merchant_uid'] === 0, 'legacy all-fields search remains compatible');
    cacheAssert(ListQueryFilter::pagination(-1, 999) === [0,100], 'pagination bounded');

    $workers = [];
    for ($i=0; $i<8; $i++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $root, (string)$i], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('worker startup');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $deadline = microtime(true) + 10;
    do { $ready = count(glob($root.'/ready-*')); if ($ready === 8) break; usleep(10000); } while (microtime(true) < $deadline);
    file_put_contents($root.'/go', '1');
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $decoded = json_decode($output, true);
        if ($errors !== '') fwrite(STDERR, 'worker: '.$errors);
        cacheAssert(proc_close($process) === 0 && $decoded['value'] === 42, 'concurrent process returned valid data');
    }
    $loadCount = strlen(file_get_contents($root.'/loads'));
    if (PHP_OS_FAMILY === 'Windows') {
        cacheAssert($loadCount >= 1 && $loadCount <= 8, 'Windows bounded fallback remains correct (loads '.$loadCount.')');
    } else cacheAssert($loadCount === 1, 'Linux concurrent cold requests coalesce one fast loader');
    $clean = $cache->clean(1000);
    cacheAssert($clean['checked'] <= 1000 && $clean['removed'] > 0 && $clean['failed'] === 0, 'bounded cleanup removes expired or old-release entries');
    echo json_encode(['metrics'=>ListReadCache::metrics()], JSON_UNESCAPED_SLASHES).PHP_EOL;
} finally { cacheRemove($root); }
