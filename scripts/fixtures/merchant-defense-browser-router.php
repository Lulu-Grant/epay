<?php
namespace lib\Shop {
    // These fixtures isolate the existing optional shop overlay, never a gateway or production database.
    class ConfigService {public static function shouldRecordMerchant($uid){return false;}public static function shouldUseCheckout($uid){return false;}}
    class OrderService {public static function syncPaymentRoute($trade){} }
}
namespace Alipay {
    class AlipayTradeService {
        public function __construct($config){}
        public function qrPay($params){
            $GLOBALS['DB']->query("INSERT INTO pre_config (k,v) VALUES ('fixture_upstream_calls','1') ON DUPLICATE KEY UPDATE v=CAST(v AS UNSIGNED)+1",[]);
            return ['out_trade_no'=>$params['out_trade_no'],'qr_code'=>'https://qr.alipay.com/fixture'];
        }
    }
}
namespace {
    require __DIR__.'/merchant-defense-db.php';
    if(PHP_SAPI==='cli'){
        if(($argv[1]??'')==='--setup'){
            [$database,$pdo,$admin]=defenseFixtureCreate();defenseFixtureSeed($pdo,time());
            $pdo->exec("UPDATE pay_user SET status=1,`key`='fixture-only-key',money=1000");
            $pdo->exec('UPDATE pay_user SET defense_mode=0 WHERE uid=2');
            $pdo->prepare('UPDATE pay_channel SET config=? WHERE id=100')->execute([json_encode(['appid'=>'2021000000000001','appkey'=>'fixture-only-key','appsecret'=>'fixture-only-key'])]);
            echo json_encode(['database'=>$database]);exit;
        }
        if(($argv[1]??'')==='--drop'){
            $database=$argv[2]??'';
            if(!preg_match('/^epay_defense_fixture_[a-f0-9]{16}$/D',$database))exit(2);
            defenseFixtureConnection()->exec('DROP DATABASE `'.$database.'`');exit;
        }
        exit(2);
    }
    if(PHP_SAPI!=='cli-server' || !in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)){http_response_code(404);exit;}
    $database=getenv('EPAY_DEFENSE_TEST_DATABASE');$pdo=defenseFixtureConnection($database);$DB=defenseFixtureWrapper($pdo);
    $root=dirname(__DIR__,2);define('IN_CRONLITE',true);define('ROOT',$root.'/');define('SYSTEM_ROOT',$root.'/includes/');
    define('PAYPAGE_ROOT',SYSTEM_ROOT.'pages/');define('TEMPLATE_ROOT',ROOT.'template/');define('PLUGIN_ROOT',ROOT.'plugins/');
    define('SYS_KEY','fixture-only-system-key');
    error_reporting(E_ERROR|E_PARSE|E_COMPILE_ERROR);
    require_once SYSTEM_ROOT.'functions.php';
    $siteurl='http://'.$_SERVER['HTTP_HOST'].'/';$cdnpublic='/assets/cdn/';
    $conf=['merchant_defense_ip_mode'=>'direct','merchant_defense_net_verified'=>'1','payurl'=>'https://'.$_SERVER['HTTP_HOST'].'/',
        'localurl'=>$siteurl,'sitename'=>'Defense fixture','admin_user'=>'fixture-admin','pay_verify'=>0,'pay_maxmoney'=>0,'pay_minmoney'=>0,'blockname'=>'',
        'cert_force'=>0,'forceqq'=>0,'pay_domain_forbid'=>0,'pay_iplimit'=>0,'user_review'=>0,'ordername'=>'','profit_rate'=>0,'profit_rate2'=>0];
    // Explicit, loopback-only synthetic ESA peers; these addresses are NOT an operational origin allowlist.
    if(getenv('EPAY_PAYER_IP_FIXTURE')==='1'){
        $conf+=['payer_ip_version'=>'1','payer_ip_enabled'=>'1','payer_ip_esa_verified'=>'1','payer_ip_header'=>'Ali-Cdn-Real-Ip','payer_ip_esa_cidrs'=>'8.8.8.0/24'];
        $_SERVER['REMOTE_ADDR']=($_SERVER['HTTP_X_FIXTURE_ESA_PEER']??'')==='untrusted'?'1.1.1.1':'8.8.8.8';
    }
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    if($path==='/__fixture/state'){
        header('Content-Type: application/json');
        $calls=$pdo->query("SELECT v FROM pay_config WHERE k='fixture_upstream_calls'")->fetchColumn();
        $contexts=$pdo->query('SELECT ip,reported_ip FROM pay_merchant_defense_context')->fetchAll(\PDO::FETCH_ASSOC);
        $failures=$pdo->query("SELECT action,reason FROM pay_merchant_defense_audit WHERE action='payment_denied' ORDER BY id DESC LIMIT 5")->fetchAll(\PDO::FETCH_ASSOC);
        $orders=getenv('EPAY_PAYER_IP_FIXTURE')==='1'?$pdo->query('SELECT trade_no,out_trade_no,ip,payer_ip,payer_ip_peer,payer_ip_source,payer_ip_at,status,channel,money,realmoney FROM pay_order')->fetchAll(\PDO::FETCH_ASSOC):[];
        echo json_encode(['calls'=>(int)$calls,'contexts'=>$contexts,'failures'=>$failures,'orders'=>$orders]);return;
    }
    if($path==='/__fixture/mapi'){\lib\api\Pay::create();return;}
    if($path==='/__fixture/submit'){\lib\api\Pay::submit();return;}
    if($path==='/__fixture/ready'){echo 'ready';return;}
    if(getenv('EPAY_PAYER_IP_FIXTURE')==='1' && preg_match('#^/pay/(submit|qrcode)/([A-Za-z0-9]+)/$#D',$path,$match)){
        $_GET['s']=$match[1].'/'.$match[2].'/';require $root.'/pay.php';return;
    }
    if($path==='/getshop.php'){header('Content-Type: application/json');echo '{"code":0}';return;}
    if(str_starts_with($path,'/assets/') && preg_match('/\.(?:css|js|png|jpg|gif|svg|woff2?|ttf|ico)$/i',$path))return false;
    if(in_array($path,['/defense.php','/submit2.php','/cashier.php','/pay.php'],true)){require $root.$path;return;}
    if(in_array($path,['/admin/ulist.php','/admin/merchant_defense.php','/admin/ajax_user.php'],true)){
        session_start();$islogin=($_COOKIE['fixture_admin']??'')==='1'?1:0;
        chdir($root.'/admin');require $root.$path;return;
    }
    if($path==='/user/defense.php'){
        session_start();$uid=(int)($_COOKIE['fixture_uid']??0);
        $userrow=in_array($uid,[1,2],true)?$DB->getRow('SELECT * FROM pre_user WHERE uid=:uid',[':uid'=>$uid]):null;
        $islogin2=$userrow?1:0;
        chdir($root.'/user');require $root.$path;return;
    }
    http_response_code(404);echo 'fixture route unavailable';
}
