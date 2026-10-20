<?php
$nosession = true;
require './includes/common.php';

use lib\Defense\Runtime;
use lib\Defense\DefenseException;
use lib\Defense\EntryService;
use lib\Defense\SessionService;

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'self'; base-uri 'none'; form-action 'self'");

function merchantDefenseResume($order, $entry)
{
    if(\lib\Shop\ConfigService::shouldRecordMerchant($order['uid']) && \lib\Shop\ConfigService::shouldUseCheckout($order['uid'])){
        $type = Runtime::store()->row('SELECT name FROM pre_type WHERE id=:id', [':id'=>$entry['requested_type']]);
        $shop = \lib\Shop\OrderService::recordPaymentOrder($order['trade_no'], $type['name'] ?? '');
        if($shop && !empty($shop['checkout_url'])){
            $parts = parse_url($shop['checkout_url']);
            if(($parts['path']??'') !== '/shopping.php')throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
            return '/shopping.php?'.($parts['query']??'');
        }
    }
    return (int)$entry['requested_type'] > 0 ? '/submit2.php?typeid='.(int)$entry['requested_type'].'&trade_no='.rawurlencode($order['trade_no']) : '/cashier.php?trade_no='.rawurlencode($order['trade_no']);
}

$trade = $_GET['trade_no'] ?? '';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
try{
    if(!Runtime::installed())throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
    $order = Runtime::order($trade);
    $browser = $_COOKIE[Runtime::COOKIE] ?? '';
    if(!is_string($browser) || !preg_match('/^[a-f0-9]{64}$/D', $browser)){
        if($isPost)throw new DefenseException('DEFENSE_VERIFY_FAILED');
        $browser = bin2hex(random_bytes(32));
        setcookie(Runtime::COOKIE, $browser, ['expires'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
    }
    if($isPost){
        header('Content-Type: application/json; charset=UTF-8');
        if(!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') || (int)($_SERVER['CONTENT_LENGTH']??0)>8192)throw new DefenseException('DEFENSE_VERIFY_FAILED');
        if(($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site')throw new DefenseException('DEFENSE_VERIFY_FAILED');
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if($origin !== '' && rtrim($origin,'/') !== rtrim($siteurl,'/'))throw new DefenseException('DEFENSE_VERIFY_FAILED');
        $raw = file_get_contents('php://input', false, null, 0, 8193);
        if(strlen($raw)>8192)throw new DefenseException('DEFENSE_VERIFY_FAILED');
        $input = json_decode($raw,true,8,JSON_THROW_ON_ERROR);
        if(!is_array($input))throw new DefenseException('DEFENSE_VERIFY_FAILED');
        $store = Runtime::store();
        $entry = (new EntryService($store))->verify($trade,$input['access']??null);
        if(!Runtime::enabled($order['uid'])){
            echo json_encode(['code'=>0,'resume_url'=>merchantDefenseResume($order,$entry)]);exit;
        }
        if((int)$order['status']!==0)throw new DefenseException('DEFENSE_ORDER_RETIRED');
        $sessions = new SessionService($store);$ip = Runtime::ip();$ua = $_SERVER['HTTP_USER_AGENT']??'';
        if(($input['action']??'') === 'init'){
            try{
                $sessions->context($order,$ip,$browser,$ua);
                echo json_encode(['code'=>0,'resume_url'=>merchantDefenseResume($order,$entry)]);exit;
            }catch(DefenseException $e){if($e->reason!=='DEFENSE_BROWSER_REQUIRED' && $e->reason!=='DEFENSE_VERIFY_FAILED')throw $e;}
            $challenge = $sessions->issue($order,$ip,$browser,$ua);
            echo json_encode(['code'=>0]+$challenge);exit;
        }
        if(($input['action']??'') !== 'finish')throw new DefenseException('DEFENSE_VERIFY_FAILED');
        $sessions->consume($order,$ip,$browser,$ua,$input['challenge']??null,$input['answer']??null);
        $store->query('UPDATE pre_merchant_defense_context SET reported_ip=:ip WHERE trade_no=:trade', [':ip'=>$entry['reported_ip'],':trade'=>$trade]);
        echo json_encode(['code'=>0,'resume_url'=>merchantDefenseResume($order,$entry)]);exit;
    }
}catch(Throwable $e){
    $error = $e instanceof DefenseException ? $e : new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
    if($isPost && isset($order) && is_array($order)){
        try{Runtime::store()->denial($order['uid'],$order['trade_no'],$error->reason);}catch(Throwable $ignored){}
    }
    if($isPost){header('Content-Type: application/json; charset=UTF-8');echo json_encode(['code'=>-1,'reason'=>$error->reason,'msg'=>$error->getMessage()],JSON_UNESCAPED_UNICODE);exit;}
    http_response_code(400);
    $pageError = $error->getMessage();
}
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>支付安全验证</title>
<link rel="stylesheet" href="/assets/css/alipay_pay.css?v=3">
<script src="/assets/js/merchant-defense.js?v=1" defer></script>
</head>
<body>
<main class="body" id="merchant-defense" data-trade="<?=htmlspecialchars((string)$trade,ENT_QUOTES,'UTF-8')?>">
<h1 class="mod-title"><span class="text">支付安全验证</span></h1>
<div class="mod-ct">
<p id="defense-message" role="status" aria-live="polite"><?=htmlspecialchars($pageError??'正在检查浏览器，请保持页面打开…',ENT_QUOTES,'UTF-8')?></p>
<?php if(!isset($pageError)){ ?><button type="button" class="btn-check" id="defense-retry" hidden>重新验证</button><?php } ?>
<noscript><p>请启用 JavaScript 后重新从商户页面发起支付。</p></noscript>
</div>
</main>
</body>
</html>
