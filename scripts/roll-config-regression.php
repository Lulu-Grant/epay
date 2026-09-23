#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__).'/includes/lib/RollConfig.php';
require dirname(__DIR__).'/includes/lib/Channel.php';

function rollAssert($actual, $expected, $label){
    if ($actual !== $expected) {
        fwrite(STDERR, $label.' expected='.var_export($expected, true).' actual='.var_export($actual, true).PHP_EOL);
        exit(1);
    }
}
function rollReject($callback, $label){
    try { $callback(); }
    catch (InvalidArgumentException $e) { return; }
    fwrite(STDERR, $label.' was accepted'.PHP_EOL);
    exit(1);
}
function decimalIncrement($value){
    $digits = str_split((string)$value);
    for ($index = count($digits) - 1; $index >= 0; $index--) {
        if ($digits[$index] !== '9') {
            $digits[$index] = (string)(((int)$digits[$index]) + 1);
            return implode('', $digits);
        }
        $digits[$index] = '0';
    }
    return '1'.implode('', $digits);
}

use lib\RollConfig;

$old = RollConfig::parse('901,902');
rollAssert($old, [['channel'=>901,'weight'=>1],['channel'=>902,'weight'=>1]], 'legacy sequential weights');
rollAssert(RollConfig::serialize($old), '901:1,902:1', 'normalization on explicit save');
rollAssert(RollConfig::equivalent($old, RollConfig::fromInput([['channel'=>'901','weight'=>'1'],['channel'=>'902','weight'=>'1']], $old)), true, 'legacy omitted weights compare as no-op');
rollAssert(RollConfig::parse('901:0,902:99')[0]['weight'], 0, 'explicit zero preserved');
rollAssert(RollConfig::chooseWeighted(RollConfig::parse('901:0,902:99'), 1), 902, 'zero weight excluded');
rollAssert(RollConfig::chooseWeighted(RollConfig::parse('901:0,902:0')), false, 'all zero fails closed');
rollAssert(RollConfig::chooseWeighted(RollConfig::parse('901:8,902:2'), 8), 901, 'weighted boundary first');
rollAssert(RollConfig::chooseWeighted(RollConfig::parse('901:8,902:2'), 9), 902, 'weighted boundary second');
rollAssert(RollConfig::chooseWeighted(RollConfig::parse('901:'.PHP_INT_MAX.',902:1')), false, 'historical weight sum overflow fails closed');
rollAssert(RollConfig::fromInput([['channel'=>'901','weight'=>'80'],['channel'=>'902','weight'=>'20']]),
    [['channel'=>901,'weight'=>80],['channel'=>902,'weight'=>20]], 'admin input');
rollAssert(RollConfig::fromInput([['channel'=>'901','weight'=>'100']]), [['channel'=>901,'weight'=>100]], 'legacy 100 is a valid edited weight');

if (PHP_INT_SIZE < 8) {
    fwrite(STDERR, 'roll config requires a 64-bit PHP integer runtime'.PHP_EOL);
    exit(1);
}
$safeIntegers = ['9007199254740991', '9007199254740992', '9007199254740993', (string)PHP_INT_MAX];
foreach ($safeIntegers as $weight) {
    $validated = RollConfig::fromInput([['channel'=>'901','weight'=>$weight]]);
    rollAssert((string)$validated[0]['weight'], $weight, 'precise decimal round-trip '.$weight);
    $client = RollConfig::forClient($validated);
    rollAssert($client[0]['weight'], $weight, 'client weight remains a string '.$weight);
    rollAssert(is_string($client[0]['weight']), true, 'client weight type '.$weight);
}
$storedZero = RollConfig::parse('901:0,902:100');
rollAssert(RollConfig::fromInput([['channel'=>'901','weight'=>'0'],['channel'=>'902','weight'=>'100']], $storedZero), $storedZero, 'stored zero may remain unchanged');
rollAssert(RollConfig::sameChannels($storedZero, RollConfig::parse('901:8,902:2')), true, 'weight-only edit keeps channel order');
rollAssert(RollConfig::sameChannels($storedZero, RollConfig::parse('902:100,901:0')), false, 'channel reorder is detected');

foreach (['901:', '901:-1', '901:1.5', '901:hello', '901,,902', '901:1,901:2'] as $bad) {
    rollReject(function() use ($bad){ RollConfig::parse($bad); }, 'bad stored rule '.$bad);
}
foreach (['0','-1','1.5','1e2','01','+1','', 'abc', decimalIncrement((string)PHP_INT_MAX)] as $bad) {
    rollReject(function() use ($bad){ RollConfig::fromInput([['channel'=>'901','weight'=>$bad]]); }, 'bad new weight '.$bad);
}
rollReject(function() use ($storedZero){ RollConfig::fromInput([['channel'=>'901','weight'=>'1'],['channel'=>'902','weight'=>'0']], $storedZero); }, 'zero cannot move to another channel');
rollReject(function(){ RollConfig::fromInput([['channel'=>'901','weight'=>(string)PHP_INT_MAX],['channel'=>'902','weight'=>'1']]); }, 'new weight sum overflow');
rollReject(function(){ RollConfig::fromInput([['channel'=>901,'weight'=>1],['channel'=>901,'weight'=>2]]); }, 'duplicate channel');

class RollFakeDb {
    public $roll;
    public $channels;
    public $lastSql;
    public $updates = [];
    public function getRow($sql){ return $this->roll; }
    public function getAll($sql){ $this->lastSql = $sql; return $this->channels; }
    public function exec($sql){ $this->updates[] = $sql; return 1; }
}
$DB = new RollFakeDb();
$DB->roll = ['id'=>77,'type'=>1,'status'=>1,'kind'=>1,'info'=>'901:80,902:20','index'=>0];
$DB->channels = [['id'=>902,'paymin'=>0,'paymax'=>0]];
$selector = new ReflectionMethod('lib\Channel', 'getChannelFromRoll');
$selector->setAccessible(true);
rollAssert($selector->invoke(null, 77, 10), 902, 'filtered weighted candidate');
rollAssert(strpos($DB->lastSql, 'daystatus=0') !== false, true, 'day-status filter retained');
$DB->roll['kind'] = 2;
$DB->channels = [['id'=>902,'paymin'=>0,'paymax'=>0],['id'=>901,'paymin'=>0,'paymax'=>0]];
rollAssert($selector->invoke(null, 77, 10), 901, 'first configured channel, not database order');
$DB->roll['kind'] = 0;
$DB->roll['index'] = 9;
rollAssert($selector->invoke(null, 77, 10), 902, 'legacy sequence index normalized');
rollAssert(count($DB->updates), 1, 'sequence index updated once');
$DB->channels = [];
rollAssert($selector->invoke(null, 77, 10), false, 'no eligible channels');
$DB->roll['kind'] = 1;
$DB->roll['info'] = '901:0,902:0';
$DB->channels = [['id'=>901,'paymin'=>0,'paymax'=>0],['id'=>902,'paymin'=>0,'paymax'=>0]];
rollAssert($selector->invoke(null, 77, 10), false, 'legacy all-zero group fails closed');

echo "roll config regression: ok\n";
