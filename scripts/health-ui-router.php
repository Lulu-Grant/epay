<?php
$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);

if($path === '/admin/ajax_health_report.php' && isset($_GET['act']) && $_GET['act'] === 'list'){
    $state = isset($_COOKIE['health_ui_state']) ? (string)$_COOKIE['health_ui_state'] : 'ready';
    if($state === 'failure'){
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(503);
        echo '{"code":-1,"msg":"Fixture list failure"}';
        exit;
    }
    if($state === 'empty'){
        header('Content-Type: application/json; charset=UTF-8');
        echo '{"code":0,"data":[]}';
        exit;
    }
}

if($path === '/admin/ajax_health_report.php' || $path === '/admin/health_report.php' || $path === '/admin/health_report_set.php' || $path === '/__health_fixture'){
    health_fixture_boot();
    if($path === '/__health_fixture'){
        health_fixture_control();
        exit;
    }
    chdir(dirname(__DIR__).'/admin');
    include dirname(__DIR__).$path;
    exit;
}

$file = dirname(__DIR__).$path;
if($path !== '/' && is_file($file)) return false;
http_response_code(404);
echo 'Not Found';

function health_fixture_boot()
{
    global $nosession, $islogin, $DB, $CACHE, $conf, $dbconfig, $cdnpublic;
    $nosession = true;
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    if(empty($_SERVER['HTTP_REFERER'])) $_SERVER['HTTP_REFERER'] = 'http://127.0.0.1/admin/health_report.php';
    require_once dirname(__DIR__).'/includes/common.php';
    $database = isset($dbconfig['dbname']) ? (string)$dbconfig['dbname'] : '';
    if(!preg_match('/(?:_local|_test)$/D', $database)){
        http_response_code(500);
        exit('Fixture database is not isolated');
    }
    $islogin = 1;
    $_SESSION = ['health_csrf_token'=>'health-ui-fixture-csrf-token'];
}

function health_fixture_control()
{
    global $DB, $conf;
    if(!isset($_SERVER['REMOTE_ADDR']) || !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1','::1'], true)){
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: application/json; charset=UTF-8');
    $dates = health_fixture_dates();
    $act = isset($_GET['act']) ? (string)$_GET['act'] : 'meta';
    if($act === 'meta'){
        echo json_encode(['code'=>0,'dates'=>$dates,'settings'=>[
            'snapshot_enabled'=>!empty($conf['health_snapshot_enabled']),
            'report_enabled'=>!empty($conf['health_report_enabled']),
            'ai_enabled'=>!empty($conf['health_ai_enabled']),
        ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }
    if($act === 'state'){
        $rows=$DB->getAll("SELECT r.report_date,r.telegram_status,r.telegram_queue_id,q.status queue_status,q.sendstarttime,q.error_msg
            FROM pre_health_report r LEFT JOIN pre_telegram_notify_queue q ON q.id=r.telegram_queue_id
            WHERE r.report_date IN (:overview,:failure,:uncertain) ORDER BY r.report_date", [
                ':overview'=>$dates['overview'], ':failure'=>$dates['failure'], ':uncertain'=>$dates['uncertain'],
            ]);
        echo json_encode(['code'=>0,'report_enabled'=>!empty($conf['health_report_enabled']),'rows'=>$rows?:[]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }
    if($act === 'review_samples'){
        $samples = health_fixture_review_samples($DB, $conf, $dates);
        if($samples === false){
            http_response_code(500);
            echo '{"code":-1,"msg":"Review samples are unavailable"}';
            return;
        }
        echo json_encode(['code'=>0,'samples'=>$samples], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }
    http_response_code(400);
    echo '{"code":-1,"msg":"Unsupported fixture control"}';
}

function health_fixture_review_samples($DB, $config, $dates)
{
    $map = [
        'healthy'=>'review_healthy','degraded'=>'review_degraded','currentIncomplete'=>'review_incomplete',
        'baselineMissing'=>'review_baseline_missing','sampleInsufficient'=>'review_sample_insufficient',
    ];
    $payloads = [];
    $inputs = [];
    $telegram = [];
    $service = new \lib\Health\ReportService($DB, $config);
    $renderer = new \lib\Health\TelegramRenderer();
    foreach($map as $name=>$dateKey){
        $row = $DB->getRow("SELECT * FROM pre_health_report WHERE report_date=:date AND report_type='daily'", [':date'=>$dates[$dateKey]]);
        if(!$row) return false;
        $report = [
            'id'=>intval($row['id']),'report_date'=>$row['report_date'],'metrics'=>json_decode($row['metrics_json'],true),
            'rules'=>json_decode($row['rules_json'],true),'ai'=>$row['ai_json']?json_decode($row['ai_json'],true):null,
            'ai_status'=>intval($row['ai_status']),'ai_error'=>$row['ai_error'],
            'ai_reason'=>\lib\Health\AdminSupport::aiFeedback(intval($row['ai_status']),$row['ai_error'])['reason'],
        ];
        if(!is_array($report['metrics']) || !is_array($report['rules'])) return false;
        $preview = $service->previewDaily($dates[$dateKey]);
        if($preview['rules']['level'] !== $report['rules']['level'] || array_column($preview['rules']['issues'],'code') !== array_column($report['rules']['issues'],'code')) return false;
        $payloads[$name] = $report;
        $inputs[$name] = ['metrics'=>$preview['metrics'],'baseline'=>$preview['baseline']];
        $telegram[$name] = $renderer->render($report);
    }
    return ['reportPayloads'=>$payloads,'ruleInputs'=>$inputs,'telegram'=>$telegram];
}

function health_fixture_dates()
{
    return [
        'overview'=>date('Y-m-d', strtotime('-1 day')),
        'rule'=>date('Y-m-d', strtotime('-2 days')),
        'ai_degraded'=>date('Y-m-d', strtotime('-3 days')),
        'ai_budget'=>date('Y-m-d', strtotime('-4 days')),
        'ai_incomplete'=>date('Y-m-d', strtotime('-20 days')),
        'delivered'=>date('Y-m-d', strtotime('-13 days')),
        'failure'=>date('Y-m-d', strtotime('-14 days')),
        'uncertain'=>date('Y-m-d', strtotime('-15 days')),
        'missing_id'=>date('Y-m-d', strtotime('-16 days')),
        'missing_row'=>date('Y-m-d', strtotime('-17 days')),
        'unsupported'=>date('Y-m-d', strtotime('-18 days')),
        'review_healthy'=>'2002-01-08',
        'review_degraded'=>'2002-01-17',
        'review_incomplete'=>'2002-01-26',
        'review_baseline_missing'=>'2002-02-04',
        'review_sample_insufficient'=>'2002-02-13',
    ];
}
