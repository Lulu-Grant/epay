<?php
include("../includes/common.php");

use lib\AdminSso;
use lib\AdminSsoException;

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

function merchantSsoEscape($value){
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function merchantSsoPage($title, $body, $formAction = null, $autoSubmit = false){
    $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    $formPolicy = "'self'";
    if($formAction){
        $origin = parse_url($formAction, PHP_URL_SCHEME).'://'.parse_url($formAction, PHP_URL_HOST);
        if(parse_url($formAction, PHP_URL_PORT)) $origin .= ':'.parse_url($formAction, PHP_URL_PORT);
        $formPolicy .= ' '.$origin;
    }
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-".$nonce."'; form-action ".$formPolicy."; base-uri 'none'; frame-ancestors 'none'");
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.merchantSsoEscape($title).'</title><style>body{margin:0;background:#0b0f14;color:#e8eef5;font:16px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif}.card{box-sizing:border-box;width:min(560px,calc(100% - 32px));margin:8vh auto;padding:28px;background:#171e27;border:1px solid #2c3848;border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,.45)}h1{font-size:24px;margin:0 0 12px}.muted{color:#b4c0ce}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 18px;border:1px solid #5b8cff;border-radius:8px;background:#5b8cff;color:#0b0f14;font-weight:700;text-decoration:none;cursor:pointer}.btn:focus-visible,a:focus-visible{outline:3px solid #fff;outline-offset:3px}form{margin-top:22px}</style></head><body><main class="card">'.$body.'</main>';
    if($autoSubmit) echo '<script nonce="'.$nonce.'">document.getElementById("sso-handoff").submit();</script>';
    echo '</body></html>';
    exit;
}

try{
    if(!AdminSso::enabled($conf)) throw new AdminSsoException('管理员代登录功能尚未启用');
    if(!hash_equals(AdminSso::origin($conf, 'merchant'), AdminSso::currentOrigin())) throw new AdminSsoException('当前商户域名不在代登录允许列表');
    if($_SERVER['REQUEST_METHOD'] !== 'POST') throw new AdminSsoException('请从管理后台商户列表重新发起代登录');

    $action = (string)($_POST['action'] ?? '');
    if($action === 'logout'){
        $sameOrigin = AdminSso::requestOriginMatches(AdminSso::origin($conf, 'merchant')) || checkRefererHost();
        if(!$sameOrigin) throw new AdminSsoException('退出请求来源校验失败');
        $csrf = (string)($_SESSION['admin_sso_logout_csrf'] ?? '');
        if($csrf === '' || !isset($_POST['csrf_token']) || !hash_equals($csrf, (string)$_POST['csrf_token'])) throw new AdminSsoException('退出请求防伪校验失败');
        AdminSso::revokeSession($DB, $_COOKIE[AdminSso::SESSION_COOKIE] ?? '', 'merchant_logout');
        setcookie(AdminSso::SESSION_COOKIE, '', ['expires'=>time()-3600,'path'=>AdminSso::cookiePath($conf, 'merchant'),'secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
        unset($_SESSION['admin_sso_logout_csrf']);
        header('Location: '.AdminSso::origin($conf, 'merchant').AdminSso::cookiePath($conf, 'merchant'), true, 303);
        exit;
    }

    if(!AdminSso::requestOriginMatches(AdminSso::origin($conf, 'admin'))) throw new AdminSsoException('管理域交接来源不匹配');
    $ticket = (string)($_POST['ticket'] ?? '');

    if($action === 'bootstrap'){
        $bootstrap = AdminSso::bootstrap($DB, $conf, $ticket);
        setcookie(AdminSso::NONCE_COOKIE, $bootstrap['nonce'], ['expires'=>time()+180,'path'=>AdminSso::cookiePath($conf, 'merchant'),'secure'=>true,'httponly'=>true,'samesite'=>'None']);
        $adminEndpoint = AdminSso::endpoint($conf, 'admin');
        $body = '<h1>正在绑定当前浏览器</h1><p class="muted">目标商户 UID：'.merchantSsoEscape($bootstrap['uid']).'。若页面没有自动继续，请使用下方按钮。</p><form id="sso-handoff" method="post" action="'.merchantSsoEscape($adminEndpoint).'"><input type="hidden" name="action" value="confirm"><input type="hidden" name="ticket" value="'.merchantSsoEscape($ticket).'"><input type="hidden" name="nonce_hash" value="'.merchantSsoEscape($bootstrap['nonce_hash']).'"><button class="btn" type="submit">返回管理域确认</button></form>';
        merchantSsoPage('代登录浏览器绑定', $body, $adminEndpoint, true);
    }

    if($action === 'consume'){
        $nonce = (string)($_COOKIE[AdminSso::NONCE_COOKIE] ?? '');
        $session = AdminSso::consume($DB, $conf, $ticket, $nonce);
        session_regenerate_id(true);
        unset($_SESSION['Oauth_alipay_uid'], $_SESSION['Oauth_qq_uid'], $_SESSION['findpwd_qq'], $_SESSION['openid']);
        setcookie(AdminSso::SESSION_COOKIE, $session['session'], ['expires'=>strtotime($session['expires_at']),'path'=>AdminSso::cookiePath($conf, 'merchant'),'secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
        setcookie(AdminSso::NONCE_COOKIE, '', ['expires'=>time()-3600,'path'=>AdminSso::cookiePath($conf, 'merchant'),'secure'=>true,'httponly'=>true,'samesite'=>'None']);
        header('Location: '.AdminSso::origin($conf, 'merchant').AdminSso::cookiePath($conf, 'merchant'), true, 303);
        exit;
    }

    throw new AdminSsoException('未知的代登录操作');
}catch(Throwable $e){
    http_response_code(400);
    $message = $e instanceof AdminSsoException ? $e->getMessage() : '代登录服务暂时不可用，请稍后重新发起';
    if(!($e instanceof AdminSsoException)) error_log('Merchant handoff endpoint failed');
    $returnUrl = '#';
    try{ $returnUrl = AdminSso::origin($conf, 'admin').AdminSso::cookiePath($conf, 'admin'); }catch(Throwable $ignored){}
    merchantSsoPage('无法完成代登录', '<h1>无法完成代登录</h1><p>'.merchantSsoEscape($message).'</p><p class="muted">请回到管理后台的商户列表重新发起。当前商户的普通登录状态没有被修改。</p><p><a class="btn" href="'.merchantSsoEscape($returnUrl).'">返回管理后台</a></p>');
}
