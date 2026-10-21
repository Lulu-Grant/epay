<?php
// Synthetic, disposable databases only. Never load application config.php or caller credentials.
if(!in_array(PHP_SAPI,['cli','cli-server'],true)){http_response_code(404);exit;}
if(PHP_VERSION_ID<80400 || PHP_VERSION_ID>=80500)throw new RuntimeException('PHP 8.4.x required');
date_default_timezone_set('Asia/Shanghai');
require_once dirname(__DIR__,2).'/includes/autoloader.php';
Autoloader::register();

function defenseFixtureConnection($database = null)
{
    $port=(int)(getenv('EPAY_DEFENSE_TEST_PORT')?:23306);
    if(!in_array($port,[23306,3306],true))throw new RuntimeException('Fixture port rejected');
    if($port===3306 && (getenv('GITHUB_ACTIONS')!=='true'||getenv('CI')!=='true'))throw new RuntimeException('3306 is reserved for CI');
    if($database!==null && !preg_match('/^epay_defense_fixture_[a-f0-9]{16}$/D',$database))throw new RuntimeException('Fixture database rejected');
    $password=$port===23306?'epay_defense_fixture':'epay_test';
    $pdo=new PDO('mysql:host=127.0.0.1;port='.$port.($database?';dbname='.$database:'').';charset=utf8','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET time_zone='+08:00'");$pdo->exec("SET sql_mode=''");
    return $pdo;
}
function defenseFixtureWrapper(PDO $pdo)
{
    $reflection=new ReflectionClass(\lib\PdoHelper::class);
    $db=$reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('db')->setValue($db,$pdo);
    $reflection->getProperty('prefix')->setValue($db,'pay_');
    return $db;
}
function defenseFixtureSql(PDO $pdo, $sql)
{
    $sql=preg_replace('/^\s*--[^\r\n]*/m','',$sql);
    foreach(explode(';',$sql) as $statement){
        $statement=trim($statement);
        if($statement!=='')$pdo->exec(str_replace('pre_','pay_',$statement));
    }
}
function defenseFixtureCreate($fresh = false)
{
    $admin=defenseFixtureConnection();
    $database='epay_defense_fixture_'.bin2hex(random_bytes(8));
    $admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    try{
    $pdo=defenseFixtureConnection($database);
    $sql=file_get_contents(dirname(__DIR__,2).'/install/install.sql');
    foreach(['config','user','group','type','channel','roll','order','blacklist','psreceiver'] as $table){
        if(!preg_match('/CREATE TABLE `pre_'.preg_quote($table,'/').'` \([\s\S]+?\) ENGINE=[^;]+;/i',$sql,$match))throw new RuntimeException('Missing fixture schema '.$table);
        $create=$fresh?$match[0]:preg_replace('/^\s*`defense_[^\r\n]+\r?\n/m','',$match[0]);
        $pdo->exec(str_replace('pre_','pay_',$create));
    }
    $sql=file_get_contents(dirname(__DIR__,2).'/tools/complain-plugin/install.php');
    preg_match('/CREATE TABLE IF NOT EXISTS `pre_complain` \([\s\S]+?\) ENGINE=[^;]+;/',$sql,$match);
    $create=$fresh?$match[0]:preg_replace('/^\s*`defense_[^\r\n]+\r?\n/m','',$match[0]);
    $pdo->exec(str_replace('pre_','pay_',$create));
    if($fresh){
        $install=file_get_contents(dirname(__DIR__,2).'/install/install.sql');
        preg_match_all('/CREATE TABLE IF NOT EXISTS `?pre_merchant_defense_[a-z_]+`? \([\s\S]+?\) ENGINE=[^;]+;/',$install,$tables);
        foreach($tables[0] as $table)$pdo->exec(str_replace('pre_','pay_',$table));
        $pdo->exec("INSERT INTO pay_config (k,v) VALUES ('merchant_defense_version','1'),('merchant_defense_cursor','0')");
    }else defenseFixtureSql($pdo,file_get_contents(dirname(__DIR__,2).'/install/migrations/20261020_merchant_defense.sql'));
    return [$database,$pdo,$admin];
    }catch(Throwable $e){$admin->exec('DROP DATABASE `'.$database.'`');throw $e;}
}
function defenseFixtureSeed(PDO $pdo, $now)
{
    foreach(['merchant_defense_audit','merchant_defense_complaint','merchant_defense_entry','merchant_defense_challenge','merchant_defense_context','merchant_defense_order','merchant_defense_quota','complain','order','user','group','type','channel','roll'] as $table)$pdo->exec('DELETE FROM pay_'.$table);
    $pdo->exec("UPDATE pay_config SET v='0' WHERE k='merchant_defense_cursor'");
    $pdo->exec("INSERT INTO pay_type (id,name,showname,status) VALUES (1,'alipay','Fixture Alipay',1)");
    $pdo->exec("INSERT INTO pay_channel (id,type,plugin,name,status,apptype,mode,config) VALUES (100,1,'alipay','Fixture QR',1,'3',0,'{}')");
    $pdo->prepare('INSERT INTO pay_group (gid,name,info) VALUES (0,?,?)')->execute(['Fixture group',json_encode(['1'=>['type'=>'channel','channel'=>100,'rate'=>100]])]);
    foreach([1,2] as $uid)$pdo->prepare('INSERT INTO pay_user (uid,gid,defense_mode,defense_enabled_at,defense_revision) VALUES (?,0,1,?,1)')->execute([$uid,$now-3600]);
}
function defenseFixtureOrder(PDO $pdo, $number, $uid = 1)
{
    $trade='LP'.str_pad((string)$number,19,'0',STR_PAD_LEFT);
    $pdo->prepare("INSERT INTO pay_order (trade_no,out_trade_no,uid,channel,type,tid,name,money,realmoney,getmoney,ip,notify_url,return_url,addtime) VALUES (?,?,?,100,1,0,'Synthetic item',1,1,1,'203.0.113.9','https://fixture.invalid/notify','https://fixture.invalid/return',NOW())")->execute([$trade,'D-'.$number,$uid]);
    $stmt=$pdo->prepare('SELECT * FROM pay_order WHERE trade_no=?');$stmt->execute([$trade]);return $stmt->fetch(PDO::FETCH_ASSOC);
}
function defenseFixtureCommand(array $arguments)
{
    $command=[PHP_BINARY];
    if(PHP_OS_FAMILY==='Windows')$command=array_merge($command,['-d','extension_dir='.ini_get('extension_dir'),'-d','extension=pdo_mysql']);
    return array_merge($command,$arguments);
}
