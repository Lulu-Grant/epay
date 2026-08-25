<?php
include("../includes/common.php");
if($islogin!=1) exit('{"code":-3,"msg":"No Login"}');
if(!checkRefererHost()) exit('{"code":403,"msg":"Forbidden"}');
@header('Content-Type: application/json; charset=UTF-8');

if(empty($conf['addon_health_report']) || intval($conf['addon_health_report']) < intval(\lib\Health\Installer::VERSION) || !\lib\Health\Installer::isInstalled()){
    exit('{"code":-1,"msg":"健康简报组件尚未由管理员完成安装"}');
}

function health_json($data){ exit(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
function health_date($value){ $d=DateTime::createFromFormat('Y-m-d',(string)$value); return $d && $d->format('Y-m-d')===$value; }
function health_check_csrf(){ $token=isset($_POST['csrf_token'])?(string)$_POST['csrf_token']:''; if($token===''||empty($_SESSION['health_csrf_token'])||!hash_equals($_SESSION['health_csrf_token'],$token)) health_json(['code'=>403,'msg'=>'CSRF Token Error']); }
function health_secret_value($env, $key){
    if(array_key_exists($key, $env)) return trim((string)$env[$key]);
    $value=getenv($key);
    return $value===false?'':trim((string)$value);
}
$act = isset($_GET['act']) ? $_GET['act'] : '';

try {
switch($act){
case 'save':
    health_check_csrf();
    $settings = [];
    $minimumReconcile = max(4, intval(isset($conf['health_snapshot_settle_hours']) ? $conf['health_snapshot_settle_hours'] : 3) + 1);
    $binary = ['health_snapshot_enabled','health_report_enabled','health_ai_enabled'];
    $integer = ['health_ai_timeout'=>[60,600],'health_ai_first_byte_timeout'=>[30,600],'health_ai_idle_timeout'=>[30,300],
        'health_ai_max_tokens'=>[300,5000],'health_ai_daily_limit'=>[1,100],
        'health_snapshot_reconcile_hours'=>[$minimumReconcile,\lib\Health\MetricsService::MAX_SAFE_BACKFILL_HOURS]];
    foreach($binary as $key) $settings[$key] = !empty($_POST[$key]) ? '1' : '0';
    foreach($integer as $key=>$range){ $v=intval(isset($_POST[$key])?$_POST[$key]:0); if($v<$range[0]||$v>$range[1]) health_json(['code'=>-1,'msg'=>$key.' 超出允许范围']); $settings[$key]=(string)$v; }
    $payloadMode=isset($_POST['health_ai_payload_mode'])?(string)$_POST['health_ai_payload_mode']:'compact';
    if(!in_array($payloadMode,['raw','compact'],true)) health_json(['code'=>-1,'msg'=>'AI 数据模式不受支持']);
    $settings['health_ai_payload_mode']=$payloadMode;
    if(intval($settings['health_ai_first_byte_timeout']) > intval($settings['health_ai_timeout'])) health_json(['code'=>-1,'msg'=>'AI 首包等待不能超过单次绝对上限']);
    if(intval($settings['health_ai_idle_timeout']) > intval($settings['health_ai_timeout'])) health_json(['code'=>-1,'msg'=>'AI 流式空闲等待不能超过单次绝对上限']);
    $chat=trim(isset($_POST['health_report_chat_id'])?$_POST['health_report_chat_id']:''); if($chat!==''&&!preg_match('/^-?[0-9]{5,20}$/',$chat)) health_json(['code'=>-1,'msg'=>'Chat ID 格式不正确']);
    $base=trim(isset($_POST['health_ai_base_url'])?$_POST['health_ai_base_url']:''); if(strlen($base)>255) health_json(['code'=>-1,'msg'=>'AI API 地址过长']);
    if($base!==''){
        $parts=parse_url($base);
        if(!is_array($parts)||strtolower(isset($parts['scheme'])?$parts['scheme']:'')!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||(isset($parts['port'])&&intval($parts['port'])!==443)) health_json(['code'=>-1,'msg'=>'AI API 地址必须是无凭据、无查询参数的 HTTPS 443 地址']);
    }
    $model=trim(isset($_POST['health_ai_model'])?$_POST['health_ai_model']:''); if($model!==''&&!preg_match('/^[A-Za-z0-9._:-]{1,100}$/D',$model)) health_json(['code'=>-1,'msg'=>'模型名称格式不正确']);
    if($settings['health_ai_enabled']==='1'){
        $env=\lib\Health\AiClient::loadSecretEnvironment();
        $apiKey=health_secret_value($env,'HEALTH_AI_API_KEY');
        $allowedModels=array_values(array_filter(array_map('trim',explode(',',health_secret_value($env,'HEALTH_AI_ALLOWED_MODELS'))),'strlen'));
        $allowedHosts=array_values(array_filter(array_map(function($host){return strtolower(rtrim(trim($host),'.'));},explode(',',health_secret_value($env,'HEALTH_AI_ALLOWED_HOSTS'))),'strlen'));
        $baseHost=$base!==''?strtolower(rtrim((string)parse_url($base,PHP_URL_HOST),'.')):'';
        if($base===''||$baseHost===''||!in_array($baseHost,$allowedHosts,true)) health_json(['code'=>-1,'msg'=>'AI API 地址主机不在服务器允许列表中']);
        if($model===''||!in_array($model,$allowedModels,true)) health_json(['code'=>-1,'msg'=>'模型不在服务器允许列表中']);
        if($apiKey==='') health_json(['code'=>-1,'msg'=>'服务器尚未配置 AI API Key']);
    }
    if($settings['health_report_enabled']==='1' && $settings['health_ai_enabled']!=='1') health_json(['code'=>-1,'msg'=>'每日简报依赖 AI 订单健康分析，请先启用 AI']);
    $effectiveChat = $chat !== '' ? $chat : trim(isset($conf['telegram_admin_chat_id']) ? (string)$conf['telegram_admin_chat_id'] : '');
    if($settings['health_report_enabled']==='1' && (empty($conf['telegram_notice']) || empty($conf['telegram_bot_token']) || $effectiveChat==='')) health_json(['code'=>-1,'msg'=>'开启每日发送前必须先配置并启用 Telegram Bot 与接收 Chat ID']);
    $settings['health_report_time']='09:05'; $settings['health_report_chat_id']=$chat; $settings['health_ai_base_url']=$base;
    $settings['health_ai_model']=$model; $settings['health_ai_provider']='openai_compatible';
    if(!$DB->beginTransaction()) throw new RuntimeException('设置事务启动失败');
    try {
        foreach($settings as $key=>$value) if(saveSetting($key,$value)===false) throw new RuntimeException('设置写入失败');
        if(!$DB->commit()) throw new RuntimeException('设置事务提交失败');
    } catch(Throwable $e){
        $DB->rollBack();
        throw $e;
    }
    if($CACHE->clear()===false) throw new RuntimeException('配置缓存清理失败');
    health_json(['code'=>0,'msg'=>'设置已保存']);
break;
case 'generate':
    health_check_csrf();
    $date=isset($_POST['date'])?trim($_POST['date']):date('Y-m-d',strtotime('-1 day'));
    if(!health_date($date)||$date>=date('Y-m-d')||$date<date('Y-m-d',strtotime('-31 days'))) health_json(['code'=>-1,'msg'=>'只能生成最近31天内已结束自然日的报告']);
    if(!empty($_POST['send'])){
        $readiness=\lib\Health\AdminSupport::deliveryReadiness($conf);
        if(empty($readiness['ok'])) health_json(['code'=>1,'msg'=>$readiness['message'],'data'=>['delivery_ready'=>false]]);
    }
    $service=new \lib\Health\ReportService($DB,$conf);
    $useAi=true;
    $report=$service->generateDaily($date,$useAi,!empty($_POST['retry_terminal']));
    $delivery=null; if(!empty($_POST['send'])) $delivery=$service->enqueueTelegram($report);
    $aiFeedback=\lib\Health\AdminSupport::aiFeedback(isset($report['ai_status'])?$report['ai_status']:0,isset($report['ai_error'])?$report['ai_error']:null);
    $msg='订单健康报告已生成，AI：'.$aiFeedback['reason']; if($delivery) $msg.='，队列状态：'.$delivery['message'];
    $responseCode = $delivery && empty($delivery['ok']) ? 1 : 0;
    health_json(['code'=>$responseCode,'msg'=>$msg,'data'=>['id'=>$report['id'],'level'=>$report['rules']['level'],'ai_status'=>intval(isset($report['ai_status'])?$report['ai_status']:0),'ai_label'=>$aiFeedback['label'],'ai_reason'=>$aiFeedback['reason'],'delivery'=>$delivery,'delivery_ready'=>true]]);
break;
case 'list':
    $rows=$DB->getAll("SELECT r.*,q.status queue_status,q.retry_count queue_retry_count,q.sendstarttime queue_sendstarttime,q.error_msg queue_error
        FROM pre_health_report r LEFT JOIN pre_telegram_notify_queue q ON q.id=r.telegram_queue_id
        ORDER BY r.report_date DESC,r.id DESC LIMIT 90"); if($rows===false) throw new RuntimeException('报告列表读取失败'); $data=[];
    $levels=['healthy'=>'正常','attention'=>'需关注','warning'=>'告警','critical'=>'严重','unknown'=>'数据不足'];
    foreach($rows?:[] as $row){$m=json_decode($row['metrics_json'],true);$p=is_array($m)&&isset($m['platform'])?$m['platform']:[];$aiLabels=[0=>'未调用',1=>'已完成',2=>'失败',3=>'已关闭',4=>'等待重试',5=>'已终止'];$delivery=health_delivery_state($row);$healthLevel=isset($levels[$row['health_level']])?$levels[$row['health_level']]:'未知';$data[]=['id'=>intval($row['id']),'report_date'=>$row['report_date'],'health_level'=>$healthLevel,'total_orders'=>intval(isset($p['total_orders'])?$p['total_orders']:0),'success_rate'=>isset($p['success_rate'])&&$p['success_rate']!==null?number_format($p['success_rate'],2).'%':'-','ai_label'=>isset($aiLabels[intval($row['ai_status'])])?$aiLabels[intval($row['ai_status'])]:'未知','telegram_label'=>$delivery['label'],'updated_at'=>$row['updated_at']];}
    health_json(['code'=>0,'data'=>$data]);
break;
case 'get':
    $id=intval(isset($_GET['id'])?$_GET['id']:0);$row=$DB->find('health_report','*',['id'=>$id],null,1);if(!$row)health_json(['code'=>-1,'msg'=>'报告不存在']);
    $queue=null;if(!empty($row['telegram_queue_id']))$queue=$DB->find('telegram_notify_queue','status,retry_count,addtime,claimtime,sendstarttime,sendtime,error_msg',['id'=>intval($row['telegram_queue_id'])],null,1);
    $deliveryRow=$row;if(is_array($queue)){foreach($queue as $key=>$value)$deliveryRow['queue_'.$key]=$value;}
    $aiFeedback=\lib\Health\AdminSupport::aiFeedback(intval($row['ai_status']),$row['ai_error']);
    health_json(['code'=>0,'data'=>['id'=>intval($row['id']),'report_date'=>$row['report_date'],'metrics'=>json_decode($row['metrics_json'],true),'rules'=>json_decode($row['rules_json'],true),'ai'=>$row['ai_json']?json_decode($row['ai_json'],true):null,'ai_error'=>$row['ai_error'],'ai_reason'=>$aiFeedback['reason'],'ai_status'=>intval($row['ai_status']),'ai_calls'=>intval(isset($row['ai_calls'])?$row['ai_calls']:0),'ai_source_rows'=>intval(isset($row['ai_source_rows'])?$row['ai_source_rows']:0),'ai_sample_rows'=>intval(isset($row['ai_sample_rows'])?$row['ai_sample_rows']:0),'ai_drilldown_rows'=>intval(isset($row['ai_drilldown_rows'])?$row['ai_drilldown_rows']:0),'ai_payload_mode'=>isset($row['ai_payload_mode'])?$row['ai_payload_mode']:'compact','ai_request_bytes'=>intval(isset($row['ai_request_bytes'])?$row['ai_request_bytes']:0),'ai_response_bytes'=>intval(isset($row['ai_response_bytes'])?$row['ai_response_bytes']:0),'ai_input_tokens'=>intval(isset($row['ai_input_tokens'])?$row['ai_input_tokens']:0),'ai_output_tokens'=>intval(isset($row['ai_output_tokens'])?$row['ai_output_tokens']:0),'ai_token_source'=>isset($row['ai_token_source'])?$row['ai_token_source']:null,'ai_pipeline_version'=>isset($row['ai_pipeline_version'])?$row['ai_pipeline_version']:null,'ai_next_retry_at'=>isset($row['ai_next_retry_at'])?$row['ai_next_retry_at']:null,'delivery'=>health_delivery_state($deliveryRow)]]);
break;
default: health_json(['code'=>-4,'msg'=>'No Act']);
}
} catch(Throwable $e){ error_log('Health report admin error: '.$e->getMessage()); health_json(['code'=>-1,'msg'=>'操作失败，请检查服务器日志']); }

function health_delivery_state($row)
{
    $reportStatus=intval(isset($row['telegram_status'])?$row['telegram_status']:0);
    $queueStatus=array_key_exists('queue_status',$row)&&$row['queue_status']!==null?intval($row['queue_status']):null;
    $started=isset($row['queue_sendstarttime'])?$row['queue_sendstarttime']:null;
    $error=isset($row['queue_error_msg'])?trim((string)$row['queue_error_msg']):(isset($row['queue_error'])?trim((string)$row['queue_error']):'');
    $error=preg_replace('/https?:\/\/[^\s]+/i','[地址已隐藏]',$error);
    $error=preg_replace('/[\x00-\x1F\x7F]+/u',' ',$error);
    $result=['status'=>'not_queued','label'=>'未加入队列','category'=>'none','queue_id'=>isset($row['telegram_queue_id'])?intval($row['telegram_queue_id']):0,
        'retry_count'=>intval(isset($row['queue_retry_count'])?$row['queue_retry_count']:0),'queued_at'=>isset($row['queue_addtime'])?$row['queue_addtime']:null,
        'claimed_at'=>isset($row['queue_claimtime'])?$row['queue_claimtime']:null,'send_started_at'=>$started,
        'sent_at'=>!empty($row['sent_at'])?$row['sent_at']:(isset($row['queue_sendtime'])?$row['queue_sendtime']:null),
        'error_summary'=>$error===''?null:mb_substr($error,0,240,'UTF-8'),'review_guidance'=>null];
    if($reportStatus===2||$queueStatus===1){$result['status']='sent';$result['label']='已送达';$result['category']='delivered';}
    elseif($queueStatus===3){$result['status']='sending';$result['label']='发送中';$result['category']='in_progress';}
    elseif($reportStatus===1&&$queueStatus===0){$result['status']='queued';$result['label']=$result['retry_count']>0?'等待重试':'排队中';$result['category']='pending';}
    elseif($queueStatus===2){$result['status']=$started?'uncertain':'failed';$result['label']=$started?'送达不确定，需人工复核':'发送失败，需人工复核';$result['category']=$started?'uncertain':'deterministic_failure';
        $result['review_guidance']=$started?'复核路径：核对 Telegram 机器人日志、队列 ID 和目标会话历史；送达不确定，不要盲目重发。':'复核路径：核对 Telegram 机器人日志、队列 ID 和目标会话配置；已确认未送达，修复原因后按运维流程重新发送。';}
    elseif($reportStatus===3){$result['status']='unknown';$result['label']='队列状态未知，需人工复核';$result['category']='unknown';$result['review_guidance']='复核路径：队列记录缺失或状态不受支持，无法证明是否已送达；不要盲目重发。';}
    elseif($reportStatus===1){$result['status']='unknown';$result['label']='队列状态异常，需人工复核';$result['category']='unknown';}
    return $result;
}
