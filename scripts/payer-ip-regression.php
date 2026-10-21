#!/usr/bin/env php
<?php
// Synthetic loopback database only; never loads application configuration or connects to a gateway.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/fixtures/merchant-defense-db.php';
use lib\PayerIp;
use lib\Defense\{Repository,PolicyService,Runtime,DefenseException};

function payerConf(){return ['payer_ip_version'=>'1','payer_ip_enabled'=>'1','payer_ip_esa_verified'=>'1','payer_ip_header'=>'Ali-Cdn-Real-Ip','payer_ip_esa_cidrs'=>'8.8.8.0/24'];}
function payerServer(){return ['REMOTE_ADDR'=>'8.8.8.8','HTTP_ALI_CDN_REAL_IP'=>'9.9.9.9','REQUEST_METHOD'=>'GET','HTTP_ACCEPT'=>'text/html,application/xhtml+xml','HTTP_USER_AGENT'=>'Mozilla/5.0 Chrome/131.0.0.0 Safari/537.36','HTTP_SEC_FETCH_DEST'=>'document'];}
function payerRead(PDO $pdo,$trade){$stmt=$pdo->prepare('SELECT * FROM pay_order WHERE trade_no=?');$stmt->execute([$trade]);return $stmt->fetch(PDO::FETCH_ASSOC);}

if(($argv[1]??'')==='--worker'){
    $database=$argv[2]??'';$directory=realpath($argv[3]??'');$number=(int)($argv[4]??0);
    if(!$directory || !str_starts_with($directory,realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR)
        || !preg_match('/^epay-payer-race-[a-f0-9]{16}$/D',basename($directory)) || !in_array($number,[1,2],true))exit(2);
    $pdo=defenseFixtureConnection($database);$order=payerRead($pdo,'LP0000000000000000900');
    touch($directory.'/ready-'.$number);$deadline=microtime(true)+20;
    while(!is_file($directory.'/go')){if(microtime(true)>$deadline)exit(3);usleep(10000);}
    $server=payerServer();$server['HTTP_ALI_CDN_REAL_IP']=$number===1?'9.9.9.9':'1.1.1.1';
    echo json_encode(['won'=>PayerIp::capture($order,'pay_submit',$server,payerConf(),defenseFixtureWrapper($pdo)),'ip'=>$server['HTTP_ALI_CDN_REAL_IP']]);exit;
}
if(count($argv)!==1)exit(2);
$checks=0;
function payerSame($expected,$actual,$label){global $checks;$checks++;if($expected!==$actual)throw new RuntimeException('FAIL: '.$label);}
$database=null;$admin=null;$directory=null;$processes=[];
try{
    [$database,$pdo,$admin]=defenseFixtureCreate();$DB=defenseFixtureWrapper($pdo);defenseFixtureSeed($pdo,time());
    $freshColumns=$pdo->query("SHOW FULL COLUMNS FROM pay_order WHERE Field LIKE 'payer_ip%'")->fetchAll(PDO::FETCH_ASSOC);
    $first=defenseFixtureOrder($pdo,1);$pdo->exec("UPDATE pay_order SET ip='127.0.0.1'");
    // Rehearse upgrade against the old order schema, including an existing legacy IP.
    foreach(['payer_ip','payer_ip_at','payer_ip_peer','payer_ip_source'] as $column)$pdo->exec('ALTER TABLE pay_order DROP COLUMN '.$column);
    defenseFixtureSql($pdo,file_get_contents(dirname(__DIR__).'/install/migrations/20261021_order_payer_ip.sql'));
    payerSame($freshColumns,$pdo->query("SHOW FULL COLUMNS FROM pay_order WHERE Field LIKE 'payer_ip%'")->fetchAll(PDO::FETCH_ASSOC),'fresh and upgraded payer columns identical');
    $first=payerRead($pdo,$first['trade_no']);
    payerSame(null,$first['payer_ip'],'migration never backfills legacy IP');
    payerSame('127.0.0.1',$first['ip'],'migration preserves legacy IP');
    payerSame('0',$pdo->query("SELECT v FROM pay_config WHERE k='payer_ip_enabled'")->fetchColumn(),'upgrade disabled by default');
    payerSame(45,(int)$pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pay_order' AND COLUMN_NAME='payer_ip'")->fetchColumn(),'IPv6 storage length');
    $config=payerConf();$server=payerServer();
    payerSame(true,PayerIp::configured($config),'explicit trust configuration');
    payerSame(['ip'=>'9.9.9.9','peer'=>'8.8.8.8','source'=>'esa:ali-cdn-real-ip'],PayerIp::resolve($server,$config),'trusted connection peer and header');
    foreach(['payer_ip_enabled'=>'0','payer_ip_esa_verified'=>'0','payer_ip_version'=>'','payer_ip_header'=>'X-Forwarded-For','payer_ip_esa_cidrs'=>''] as $key=>$value){
        $bad=$config;$bad[$key]=$value;payerSame(null,PayerIp::resolve($server,$bad),'incomplete or disabled configuration '.$key);
        $copy=$first;payerSame(false,PayerIp::capture($copy,'pay_submit',$server,$bad,null),'disabled collector makes no DB call');
    }
    foreach(['0.0.0.0/0','::/0','127.0.0.1','10.0.0.0/8','8.8.8.0/33','8.8.8.0/bad','8.8.8.0/24/1','8.8.8.0/24,invalid'] as $range){
        $bad=$config;$bad['payer_ip_esa_cidrs']=$range;payerSame(false,PayerIp::configured($bad),'invalid trust range '.$range);
    }
    foreach(['','127.0.0.1','10.1.1.1','169.254.1.1','::1','fc00::1','2001:db8::1','9.9.9.9, 1.1.1.1','9.9.9.9:443',' 9.9.9.9',"9.9.9.9\r\nX: 1",['9.9.9.9']] as $value){
        $bad=$server;$bad['HTTP_ALI_CDN_REAL_IP']=$value;payerSame(null,PayerIp::resolve($bad,$config),'reject invalid/nonpublic header');
    }
    $bad=$server;$bad['REMOTE_ADDR']='1.1.1.1';payerSame(null,PayerIp::resolve($bad,$config),'direct-origin forged header rejected');
    $bad=$server;unset($bad['HTTP_ALI_CDN_REAL_IP']);
    $bad+=['HTTP_X_FORWARDED_FOR'=>'9.9.9.9','HTTP_X_REAL_IP'=>'9.9.9.9','HTTP_CF_CONNECTING_IP'=>'9.9.9.9','HTTP_CLIENT_IP'=>'9.9.9.9','HTTP_ALI_REAL_CLIENT_IP'=>'9.9.9.9'];
    payerSame(null,PayerIp::resolve($bad,$config),'never falls back to other headers');
    $alternate=$config;$alternate['payer_ip_header']='Ali-Real-Client-Ip';
    payerSame('9.9.9.9',PayerIp::resolve($bad,$alternate)['ip'],'ESA alternate requires explicit selection');
    $ipv6=$server;$ipv6['HTTP_ALI_CDN_REAL_IP']='2606:4700:4700:0000:0000:0000:0000:1111';
    payerSame('2606:4700:4700::1111',PayerIp::resolve($ipv6,$config)['ip'],'canonical IPv6');
    $mapped=$server;$mapped['HTTP_ALI_CDN_REAL_IP']='::ffff:9.9.9.9';
    payerSame('9.9.9.9',PayerIp::resolve($mapped,$config)['ip'],'mapped IPv4 canonicalized');
    $ipv6['REMOTE_ADDR']='2606:4700::1';$v6config=$config;$v6config['payer_ip_esa_cidrs']='2606:4700::/32';
    payerSame('2606:4700::1',PayerIp::resolve($ipv6,$v6config)['peer'],'IPv6 origin range');
    $auth=$config;$auth['payer_ip_trust_mode']='origin_auth';$auth['payer_ip_esa_cidrs']='';
    $proof=$server;$proof['REMOTE_ADDR']='1.1.1.1';$proof['EPAY_ESA_ORIGIN_AUTH']='1';$proof['EPAY_ESA_ORIGIN_TLS']='on';
    payerSame(true,PayerIp::configured($auth),'explicit auth mode does not require CIDR list');
    payerSame('9.9.9.9',PayerIp::resolve($proof,$auth)['ip'],'verified HTTPS origin auth replaces CIDR matching');
    foreach([['EPAY_ESA_ORIGIN_AUTH','0'],['EPAY_ESA_ORIGIN_AUTH',1],['EPAY_ESA_ORIGIN_AUTH',''],['EPAY_ESA_ORIGIN_TLS',''],['EPAY_ESA_ORIGIN_TLS','off'],['EPAY_ESA_ORIGIN_TLS',true]] as [$key,$value]){
        $bad=$proof;$bad[$key]=$value;payerSame(null,PayerIp::resolve($bad,$auth),'auth proof requires exact server values');
    }
    $spoof=$server+['HTTP_EPAY_ESA_ORIGIN_AUTH'=>'1','HTTP_EPAY_ESA_ORIGIN_TLS'=>'on','HTTP_X_LP_ORIGIN_AUTH'=>'fixture-only-secret','HTTPS'=>'on','HTTP_X_FORWARDED_PROTO'=>'https'];
    payerSame(null,PayerIp::resolve($spoof,$auth),'client HTTP headers and forwarded HTTPS cannot create FastCGI proof');
    $bad=$proof;unset($bad['EPAY_ESA_ORIGIN_AUTH']);payerSame(null,PayerIp::resolve($bad,$auth),'missing server auth proof rejected');
    $bad=$proof;unset($bad['EPAY_ESA_ORIGIN_TLS']);payerSame(null,PayerIp::resolve($bad,$auth),'missing actual origin TLS proof rejected');
    $bad=$proof;$bad['HTTP_ALI_CDN_REAL_IP']='127.0.0.1';payerSame(null,PayerIp::resolve($bad,$auth),'origin auth does not bypass IP validation');
    $bad=$proof;unset($bad['HTTP_ALI_CDN_REAL_IP']);$bad['HTTP_X_FORWARDED_FOR']='9.9.9.9';payerSame(null,PayerIp::resolve($bad,$auth),'origin auth never falls back to XFF');
    $bad=$auth;$bad['payer_ip_trust_mode']='unknown';payerSame(false,PayerIp::configured($bad),'unknown trust mode rejected');
    $bad=$auth;$bad['payer_ip_esa_verified']='0';payerSame(null,PayerIp::resolve($proof,$bad),'auth mode still requires explicit verification');
    $number=10;
    foreach(['pay_submit','pay_qrcode','web_submit','cashier','submit2'] as $entry){
        $order=defenseFixtureOrder($pdo,$number++);$original=$order;
        payerSame(true,PayerIp::capture($order,$entry,$server,$config,$DB),'page captures '.$entry);
        $stored=payerRead($pdo,$order['trade_no']);
        payerSame('9.9.9.9',$stored['payer_ip'],'stored payer IP');payerSame('8.8.8.8',$stored['payer_ip_peer'],'stored origin peer');
        payerSame('esa:ali-cdn-real-ip',$stored['payer_ip_source'],'stored header source');payerSame(true,!empty($stored['payer_ip_at']),'stored observation time');
        foreach(['payer_ip','payer_ip_at','payer_ip_peer','payer_ip_source'] as $field){unset($original[$field],$stored[$field]);}
        payerSame($original,$stored,'capture leaves all original order fields unchanged');
    }
    $post=$server;$post['REQUEST_METHOD']='POST';$order=defenseFixtureOrder($pdo,$number++);
    payerSame(true,PayerIp::capture($order,'web_submit',$post,$config,$DB),'browser form POST supported');
    $partial=defenseFixtureOrder($pdo,$number++);unset($partial['status']);
    payerSame(true,PayerIp::capture($partial,'web_submit',$post,$config,$DB),'legacy web submit partial order uses authoritative DB status');
    $authOrder=defenseFixtureOrder($pdo,$number++);
    payerSame(true,PayerIp::capture($authOrder,'pay_submit',$proof,$auth,$DB),'verified origin-auth browser page captures');
    payerSame('1.1.1.1',payerRead($pdo,$authOrder['trade_no'])['payer_ip_peer'],'auth observation preserves actual connection peer');
    foreach(['mapi','notify','return','refund','preauth','unknown'] as $entry){$copy=$first;payerSame(false,PayerIp::capture($copy,$entry,$server,$config,null),'non-page ignored '.$entry);}
    foreach([['REQUEST_METHOD','POST'],['HTTP_USER_AGENT','curl/8.0.0'],['HTTP_ACCEPT','application/json'],['HTTP_SEC_FETCH_DEST','empty']] as [$key,$value]){
        $bad=$server;$bad[$key]=$value;$copy=$first;payerSame(false,PayerIp::capture($copy,'pay_submit',$bad,$config,null),'non-browser ignored '.$key);
    }
    $copy=$first;$bad=$server;$bad['REMOTE_ADDR']='1.1.1.1';
    payerSame(false,PayerIp::capture($copy,'pay_submit',$bad,$config,$DB),'untrusted visit cannot pin IP');
    payerSame(true,PayerIp::capture($copy,'pay_submit',$server,$config,$DB),'later trusted visit captures');
    $later=$server;$later['HTTP_ALI_CDN_REAL_IP']='1.1.1.1';$stale=$first;
    payerSame(false,PayerIp::capture($stale,'pay_submit',$later,$config,$DB),'stale snapshot cannot overwrite first observation');
    payerSame('9.9.9.9',payerRead($pdo,$first['trade_no'])['payer_ip'],'original observation retained');
    payerSame('127.0.0.1',payerRead($pdo,$first['trade_no'])['ip'],'original loopback IP retained');
    $paid=defenseFixtureOrder($pdo,$number++);$pdo->prepare('UPDATE pay_order SET status=1 WHERE trade_no=?')->execute([$paid['trade_no']]);
    payerSame(false,PayerIp::capture($paid,'pay_submit',$server,$config,$DB),'paid status race prevents capture');
    $wrong=defenseFixtureOrder($pdo,$number++);$wrong['uid']=2;
    payerSame(false,PayerIp::capture($wrong,'pay_submit',$server,$config,$DB),'merchant scope enforced in UPDATE');
    $broken=new class {function query($sql,$params){throw new RuntimeException('fixture-error-must-not-be-logged');}};
    $copy=defenseFixtureOrder($pdo,$number++);$log=tempnam(sys_get_temp_dir(),'epay-payer-log-');$oldLog=ini_set('error_log',$log);
    try{payerSame(false,PayerIp::capture($copy,'pay_submit',$server,$config,$broken),'storage failure does not throw');
        $message=file_get_contents($log);payerSame(true,str_contains($message,'[payer-ip] capture_storage_failed'),'constant failure diagnostic');
        payerSame(false,str_contains($message,'fixture-error')||str_contains($message,'9.9.9.9'),'failure log contains no payload or exception');
    }finally{ini_set('error_log',$oldLog);unlink($log);}
    $conf=$config+['merchant_defense_ip_mode'=>'esa','merchant_defense_net_verified'=>'1'];$_SERVER=$server+['HTTP_X_FORWARDED_FOR'=>'1.1.1.1'];
    payerSame(true,(new PolicyService(new Repository($DB),$conf))->networkReady(),'defense can explicitly use same verified ESA resolver');
    payerSame('9.9.9.9',Runtime::ip(),'defense ESA mode ignores XFF');
    $_SERVER['REMOTE_ADDR']='1.1.1.1';
    try{Runtime::ip();throw new RuntimeException('Expected untrusted defense rejection');}catch(DefenseException $e){payerSame('DEFENSE_VERIFY_FAILED',$e->reason,'defense rejects untrusted peer');}
    payerSame(0,(int)$pdo->query('SELECT COUNT(*) FROM pay_merchant_defense_context')->fetchColumn(),'passive capture creates no verified defense identity');

    defenseFixtureOrder($pdo,900);$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'epay-payer-race-'.bin2hex(random_bytes(8));mkdir($directory,0700);
    foreach([1,2] as $worker){
        $process=proc_open(defenseFixtureCommand([__FILE__,'--worker',$database,$directory,(string)$worker]),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Worker unavailable');fclose($pipes[0]);$processes[]=[$process,$pipes];
    }
    $deadline=microtime(true)+20;while(count(glob($directory.'/ready-*'))!==2){if(microtime(true)>$deadline)throw new RuntimeException('Race barrier timeout');usleep(10000);}touch($directory.'/go');
    $results=[];foreach($processes as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0)throw new RuntimeException('Worker failed: '.$error);$results[]=json_decode($out,true,8,JSON_THROW_ON_ERROR);}$processes=[];
    $winners=array_values(array_filter($results,fn($row)=>$row['won']));payerSame(1,count($winners),'two simultaneous page visits have one winner');
    payerSame($winners[0]['ip'],payerRead($pdo,'LP0000000000000000900')['payer_ip'],'race keeps winner metadata');
    echo 'Payer IP regression: '.$checks." checks passed, including migration, header spoofing, IPv6 and two-process first-write race.\n";
}finally{
    foreach($processes as [$process,$pipes])if(is_resource($process)){proc_terminate($process);foreach([1,2] as $i)if(is_resource($pipes[$i]))fclose($pipes[$i]);proc_close($process);}
    if($database&&$admin)$admin->exec('DROP DATABASE `'.$database.'`');
    if($directory&&is_dir($directory)){foreach(glob($directory.'/*') as $file)if(is_file($file))unlink($file);rmdir($directory);}
}
