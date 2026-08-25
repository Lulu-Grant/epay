#!/usr/bin/env php
<?php
if(PHP_SAPI !== 'cli') exit(1);
$root=dirname(__DIR__);
require $root.'/includes/lib/Health/AiClient.php';
require $root.'/includes/lib/Health/RawAiPipeline.php';

class HealthRawAiFakeDb {
    public $tasks=[];
    public $updates=[];
    public $used=0;
    public function getColumn($sql,$bind=[]){
        if(strpos($sql,'GET_LOCK')!==false||strpos($sql,'RELEASE_LOCK')!==false)return 1;
        return $this->used+count($this->tasks);
    }
    public function insert($table,$data){ $id=count($this->tasks)+1;$data['id']=$id;$this->tasks[$id]=$data;return $id; }
    public function update($table,$data,$where){$id=intval($where['id']);$this->tasks[$id]=array_merge($this->tasks[$id],$data);$this->updates[]=$data;return 1;}
    public function find($table,$columns,$where,$order=null,$limit=null){
        foreach(array_reverse($this->tasks,true) as $task){
            $match=true;foreach($where as $key=>$value)if(!array_key_exists($key,$task)||(string)$task[$key]!== (string)$value){$match=false;break;}
            if($match)return $task;
        }
        return null;
    }
    public function error(){return 'fake';}
}

$env=tempnam(sys_get_temp_dir(),'epay-raw-ai-');
file_put_contents($env,"HEALTH_AI_API_KEY=test\nHEALTH_AI_ALLOWED_HOSTS=ai-test.example\nHEALTH_AI_ALLOWED_MODELS=model-a\nHEALTH_AI_PINNED_IPV4=ai-test.example=1.1.1.1\n");
putenv('HEALTH_AI_ENV_FILE='.$env);
$stages=[];$requestSettings=[];
$transport=function($request)use(&$stages,&$requestSettings){
    $outer=json_decode($request['body'],true);
    $payload=json_decode($outer['messages'][1]['content'],true);
    $stage=$payload['stage'];$stages[]=$stage;
    $requestSettings[]=['stage'=>$stage,'stream'=>!empty($outer['stream']),'max_tokens'=>intval($outer['max_tokens'])];
    if($stage==='platform_synthesis'){
        $content=['headline'=>'原始数据日报','health_level'=>'warning','executive_summary'=>'AI依据逐笔订单完成分析。','key_metrics'=>[],
            'findings'=>[['severity'=>'warning','scope'=>'channel:14','title'=>'测试异常','evidence'=>'原始订单显示异常','evidence_refs'=>['order:2026072100000000000'],'likely_cause'=>'待核对','action'=>'检查日志','confidence'=>'medium']],
            'channel_overview'=>[],'telegram_brief'=>"总体：需关注\n重点：通道14需要核对"];
    }elseif($stage==='channel_reduce'){
        $content=['health_level'=>'warning','summary'=>'通道汇总','findings'=>[['severity'=>'warning','title'=>'通道异常','evidence'=>'分片证据','evidence_refs'=>$payload['chunk_analyses'][0]['findings'][0]['evidence_refs'],'likely_cause'=>'待核对','action'=>'检查']]];
    }else{
        $ref=$payload['orders'][0]['_evidence_ref'];
        $refs=[$ref];
        if(!empty($payload['complaints'][0]['_evidence_ref']))$refs[]=$payload['complaints'][0]['_evidence_ref'];
        $content=['health_level'=>'attention','summary'=>'批次分析','findings'=>[['severity'=>'attention','title'=>'订单异常','evidence'=>'测试证据','evidence_refs'=>$refs,'likely_cause'=>'测试','action'=>'核对']]];
    }
    return ['ok'=>true,'http_code'=>200,'response'=>json_encode(['choices'=>[['message'=>['content'=>json_encode($content,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]]]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'error'=>''];
};
$orders=[];
for($i=0;$i<201;$i++) $orders[]=['trade_no'=>sprintf('20260721%011d',$i),'channel'=>14,'uid'=>1001,'status'=>$i%2,'money'=>'10.00','addtime'=>'2026-07-21 12:00:00','_evidence_ref'=>'order:'.sprintf('20260721%011d',$i)];
$dataset=['report_date'=>'2026-07-21','orders'=>$orders,'complaints'=>[['_evidence_ref'=>'complaint:9','id'=>9,'channel'=>14,'trade_no'=>$orders[0]['trade_no']]],'baseline'=>[],
    'metrics'=>['platform'=>['total_orders'=>201,'paid_orders'=>100,'success_rate'=>49.75],'hourly'=>[],'merchants'=>[],'channels'=>['14'=>['channel_id'=>14,'total_orders'=>201,'paid_orders'=>100,'success_rate'=>49.75]]]];
$db=new HealthRawAiFakeDb();
$pipeline=new \lib\Health\RawAiPipeline($db,['health_ai_base_url'=>'https://ai-test.example/v1','health_ai_model'=>'model-a','health_ai_daily_limit'=>100,'health_ai_transport'=>$transport]);
$result=$pipeline->run(7,$dataset);
$failures=[];
if(empty($result['ok']))$failures[]='pipeline did not complete';
if(count($db->tasks)!==4)$failures[]='expected two chunks, one channel reducer and one platform synthesis';
if($stages!==['channel_raw_orders','channel_raw_orders','channel_reduce','platform_synthesis'])$failures[]='pipeline stages are not deterministic';
if($requestSettings!==[
    ['stage'=>'channel_raw_orders','stream'=>true,'max_tokens'=>3000],
    ['stage'=>'channel_raw_orders','stream'=>true,'max_tokens'=>3000],
    ['stage'=>'channel_reduce','stream'=>true,'max_tokens'=>3000],
    ['stage'=>'platform_synthesis','stream'=>true,'max_tokens'=>5000],
])$failures[]='raw AI pipeline did not enforce streaming stage limits';
$expectedBrief='总体：需关注'."\n".'重点：通道14需要核对';
if(($result['data']['telegram_brief']??'')!==$expectedBrief)$failures[]='AI telegram brief was not preserved: '.var_export($result['data']['telegram_brief']??null,true);
if(($result['data']['channel_analyses']['14']['health_level']??'')!=='warning')$failures[]='channel reduction result missing';
if(($result['data']['findings'][0]['evidence_refs'][0]??'')!=='order:2026072100000000000')$failures[]='final evidence reference was not validated and preserved';
if(!preg_match('/^[a-f0-9]{64}$/D',(string)$result['request_sha256']))$failures[]='combined request hash missing';
$invalidFinalRejected=false;
try{
    $validator=new ReflectionMethod($pipeline,'validateFinalResult');
    $validator->invoke($pipeline,['headline'=>'x','health_level'=>'warning','executive_summary'=>'x','telegram_brief'=>'x','findings'=>[
        ['severity'=>'warning','scope'=>'platform','title'=>'x','evidence'=>'x','evidence_refs'=>['order:not-present']]
    ]],$dataset,$result['data']['channel_analyses']);
}catch(UnexpectedValueException $e){$invalidFinalRejected=true;}
if(!$invalidFinalRejected)$failures[]='unknown final evidence reference was not rejected';
$cached=$pipeline->run(7,$dataset);
if(empty($cached['ok'])||count($db->tasks)!==4||intval($cached['calls'])!==0)$failures[]='successful AI stages were not reused on retry';

$validationAttempts=0;
$validationTransport=function($request)use(&$validationAttempts){
    $outer=json_decode($request['body'],true);$payload=json_decode($outer['messages'][1]['content'],true);
    if($payload['stage']==='platform_synthesis'){
        $validationAttempts++;
        $ref=$validationAttempts===1?'order:not-present':'order:2026072100000000000';
        $content=['headline'=>'验证恢复','health_level'=>'warning','executive_summary'=>'证据校验。','key_metrics'=>[],
            'findings'=>[['severity'=>'warning','scope'=>'platform','title'=>'异常','evidence'=>'证据','evidence_refs'=>[$ref],'likely_cause'=>'待核对','action'=>'核对','confidence'=>'medium']],
            'channel_overview'=>[],'telegram_brief'=>'验证恢复'];
    }elseif($payload['stage']==='channel_reduce'){
        $content=['health_level'=>'warning','summary'=>'通道汇总','findings'=>[['severity'=>'warning','title'=>'通道异常','evidence'=>'证据','evidence_refs'=>$payload['chunk_analyses'][0]['findings'][0]['evidence_refs'],'likely_cause'=>'待核对','action'=>'核对']]];
    }else{
        $ref=$payload['orders'][0]['_evidence_ref'];
        $content=['health_level'=>'attention','summary'=>'批次','findings'=>[['severity'=>'attention','title'=>'订单','evidence'=>'证据','evidence_refs'=>[$ref],'likely_cause'=>'待核对','action'=>'核对']]];
    }
    return ['ok'=>true,'http_code'=>200,'response'=>json_encode(['choices'=>[['message'=>['content'=>json_encode($content,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]]]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'error'=>''];
};
$validationDb=new HealthRawAiFakeDb();
$validationPipeline=new \lib\Health\RawAiPipeline($validationDb,['health_ai_base_url'=>'https://ai-test.example/v1','health_ai_model'=>'model-a','health_ai_daily_limit'=>100,'health_ai_transport'=>$validationTransport]);
$firstValidationFailed=false;
try{$validationPipeline->run(9,$dataset);}catch(UnexpectedValueException $e){$firstValidationFailed=true;}
$recovered=$validationPipeline->run(9,$dataset);
if(!$firstValidationFailed||empty($recovered['ok'])||$validationAttempts!==2)$failures[]='invalid AI evidence poisoned retry cache';
$validationStatuses=array_map(function($task){return intval($task['status']);},$validationDb->tasks);
if(count(array_filter($validationStatuses,function($status){return $status===2;}))!==1)$failures[]='invalid AI evidence task was not marked failed';

$budgetDb=new HealthRawAiFakeDb();$budgetDb->used=100;
$budget=(new \lib\Health\RawAiPipeline($budgetDb,['health_ai_base_url'=>'https://ai-test.example/v1','health_ai_model'=>'model-a','health_ai_daily_limit'=>100,'health_ai_transport'=>$transport]))->run(8,$dataset);
if(!empty($budget['ok'])||empty($budget['terminal'])||$budget['error']!=='Daily AI call limit reached')$failures[]='100-call budget did not fail closed';
if($budgetDb->tasks)$failures[]='budget exhaustion created an AI task';

putenv('HEALTH_AI_ENV_FILE');@unlink($env);
if($failures){fwrite(STDERR,implode(PHP_EOL,$failures).PHP_EOL);exit(1);}
echo "health raw AI pipeline regression: ok\n";
