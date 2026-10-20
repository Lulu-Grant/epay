#!/usr/bin/env php
<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/fixtures/merchant-defense-db.php';
use lib\Defense\{Repository,QuotaService,ComplaintService,DefenseException};

if(($argv[1]??'')==='--worker'){
    $database=$argv[2]??'';$directory=$argv[3]??'';$mode=$argv[4]??'';$number=(int)($argv[5]??0);$receipt=(int)($argv[6]??0);
    $temp=realpath(sys_get_temp_dir());$directory=realpath($directory);
    if(!$directory || !str_starts_with($directory,$temp.DIRECTORY_SEPARATOR) || !preg_match('/^epay-defense-concurrency-[a-f0-9]{16}$/D',basename($directory)) || !in_array($mode,['reserve','paid','complaint'],true) || $number<1 || $number>20)exit(2);
    $pdo=defenseFixtureConnection($database);$store=new Repository(defenseFixtureWrapper($pdo));
    touch($directory.'/ready-'.$mode.'-'.$number);
    $deadline=microtime(true)+25;
    while(!is_file($directory.'/go-'.$mode)){
        if(microtime(true)>$deadline)exit(3);usleep(10000);
    }
    $trade='LP'.str_pad((string)$number,19,'0',STR_PAD_LEFT);
    try{
        $quota=new QuotaService($store);
        if($mode==='reserve'){
            $order=$store->row('SELECT * FROM pre_order WHERE trade_no=:trade',[':trade'=>$trade]);
            $quota->reserve($order,['ip'=>'203.0.113.1','revision'=>1]);$result=['accepted'=>true,'number'=>$number];
        }elseif($mode==='paid'){
            $result=['counted'=>$quota->paid($trade),'number'=>$number];
        }else{
            $complaint=['uid'=>1,'trade_no'=>'LP0000000000000000001','thirdid'=>'parallel-case','source'=>0,'addtime'=>date('Y-m-d H:i:s',$receipt),'defense_received_at'=>date('Y-m-d H:i:s',$receipt)];
            $result=['inserted'=>(new ComplaintService($store))->observe($complaint,'alipay:fixture-app')];
        }
    }catch(DefenseException $e){$result=['accepted'=>false,'reason'=>$e->reason,'number'=>$number];}
    echo json_encode($result);exit;
}
if(count($argv)!==1)exit(2);

function defenseParallel($database,$directory,$mode,array $numbers,$receipt)
{
    $processes=[];$results=[];
    try{
        foreach($numbers as $number){
            $command=defenseFixtureCommand([__FILE__,'--worker',$database,$directory,$mode,(string)$number,(string)$receipt]);
            $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Fixture process unavailable');
            fclose($pipes[0]);$processes[]=[$process,$pipes];
        }
        $deadline=microtime(true)+20;
        while(count(glob($directory.'/ready-'.$mode.'-*'))<count(array_unique($numbers))){
            if(microtime(true)>$deadline)throw new RuntimeException('Concurrent fixture barrier timed out');usleep(10000);
        }
        touch($directory.'/go-'.$mode);
        foreach($processes as [$process,$pipes]){
            $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
            fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
            if($exit!==0)throw new RuntimeException('Concurrent fixture worker failed: '.$error);
            $results[]=json_decode($out,true,8,JSON_THROW_ON_ERROR);
        }
        $processes=[];return $results;
    }finally{
        foreach($processes as [$process,$pipes])if(is_resource($process)){proc_terminate($process);foreach([1,2] as $i)if(is_resource($pipes[$i]))fclose($pipes[$i]);proc_close($process);}
    }
}
$database=null;$admin=null;$directory=null;
try{
    [$database,$pdo,$admin]=defenseFixtureCreate();$now=time();defenseFixtureSeed($pdo,$now);
    $directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'epay-defense-concurrency-'.bin2hex(random_bytes(8));mkdir($directory,0700);
    foreach(range(1,20) as $number)defenseFixtureOrder($pdo,$number);
    $results=defenseParallel($database,$directory,'reserve',range(1,20),$now);
    $winners=[];
    foreach($results as $row){
        if($row['accepted'])$winners[]=$row['number'];
        elseif(($row['reason']??'')!=='DEFENSE_PENDING_LIMIT')throw new RuntimeException('Unexpected concurrent rejection');
    }
    if(count($winners)!==5)throw new RuntimeException('More or fewer than five qualifications issued');
    $numbers=[];foreach(range(0,19) as $index)$numbers[]=$winners[$index%5];
    $results=defenseParallel($database,$directory,'paid',$numbers,$now);
    if(count(array_filter($results,fn($row)=>$row['counted']??false))!==5)throw new RuntimeException('Success not counted exactly once');
    $count=(int)$pdo->query("SELECT success_count FROM pay_merchant_defense_quota WHERE uid=1 AND ip='203.0.113.1'")->fetchColumn();
    if($count!==5)throw new RuntimeException('Success counter differs from five');
    $pdo->prepare('INSERT INTO pay_merchant_defense_context (trade_no,uid,ip,session_hash,browser_hash,params_hash,revision,verified_at,valid_until) VALUES (?,1,?,?,?,?,1,?,?)')->execute(['LP0000000000000000001','203.0.113.1',str_repeat('a',64),str_repeat('b',64),str_repeat('c',64),$now,$now+1200]);
    $results=defenseParallel($database,$directory,'complaint',range(1,20),$now);
    if(count(array_filter($results,fn($row)=>$row['inserted']??false))!==1)throw new RuntimeException('Complaint event not deduplicated');
    $ban=$pdo->query('SELECT COUNT(*) n,MIN(expires_at) expires FROM pay_merchant_defense_complaint')->fetch(PDO::FETCH_ASSOC);
    if((int)$ban['n']!==1 || (int)$ban['expires']!==$now+172800)throw new RuntimeException('Complaint expiry changed during race');
    echo "Merchant defense concurrency: 20 concurrent qualifications -> 5 grants; 20 success retries -> 5 counts; 20 complaint deliveries -> 1 event / 48 hours.\n";
}finally{
    if($database&&$admin)$admin->exec('DROP DATABASE `'.$database.'`');
    if($directory && is_dir($directory)){
        foreach(glob($directory.DIRECTORY_SEPARATOR.'*') as $file)if(is_file($file))unlink($file);
        rmdir($directory);
    }
}
