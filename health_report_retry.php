#!/usr/bin/env php
<?php
if(PHP_SAPI !== 'cli'){
    http_response_code(404);
    exit;
}
$nosession = true;
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__.'/includes/common.php';

try {
    if(!\lib\Health\Installer::isInstalled()) throw new RuntimeException('Health report addon is not installed');
    $row = $DB->getRow("SELECT * FROM pre_health_report
        WHERE report_type='daily' AND ai_status=4 AND telegram_status=0
          AND ai_next_retry_at IS NOT NULL AND ai_next_retry_at<=NOW()
        ORDER BY report_date DESC LIMIT 1");
    if(!$row){
        echo json_encode(['ok'=>true,'pending'=>false],JSON_UNESCAPED_UNICODE).PHP_EOL;
        exit(0);
    }
    $service = new \lib\Health\ReportService($DB,$conf);
    $report = $service->generateDaily($row['report_date'],true);
    $delivery = null;
    if(intval($report['ai_status']) === 1) $delivery = $service->enqueueTelegram($report);
    elseif(intval($report['ai_status']) === 5){
        $chat = trim(isset($conf['health_report_chat_id'])?(string)$conf['health_report_chat_id']:'');
        if($chat === '') $chat = trim(isset($conf['telegram_admin_chat_id'])?(string)$conf['telegram_admin_chat_id']:'');
        if(preg_match('/^-?[0-9]{5,20}$/D',$chat)){
            $plain = '支付健康简报生成失败 | '.$row['report_date']."\nAI订单健康分析已达到6小时重试窗口或每日100次调用上限，请在管理后台检查任务记录。";
            \lib\Telegram\QueueHelper::addToQueue('daily_health',0,[
                'html'=>htmlspecialchars($plain,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),
                'plain'=>$plain,'report_id'=>intval($report['id']),'chat_id'=>$chat,
            ],true,'daily_health_failure:'.intval($report['id']));
        }
    }
    echo json_encode(['ok'=>true,'pending'=>intval($report['ai_status'])===4,'report_id'=>$report['id'],'ai_status'=>$report['ai_status'],'delivery'=>$delivery],JSON_UNESCAPED_UNICODE).PHP_EOL;
} catch(Throwable $e){
    fwrite(STDERR,'health report retry failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}
