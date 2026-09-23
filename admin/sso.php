<?php
include("../includes/common.php");

use lib\AdminSso;
use lib\AdminSsoException;

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

function adminSsoEscape($value){
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function adminSsoPage($title, $body, $formAction = null, $autoSubmit = false){
    $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    $formPolicy = "'self'";
    if($formAction){
        $origin = parse_url($formAction, PHP_URL_SCHEME).'://'.parse_url($formAction, PHP_URL_HOST);
        if(parse_url($formAction, PHP_URL_PORT)) $origin .= ':'.parse_url($formAction, PHP_URL_PORT);
        $formPolicy .= ' '.$origin;
    }
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-".$nonce."'; form-action ".$formPolicy."; base-uri 'none'; frame-ancestors 'none'");
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.adminSsoEscape($title).'</title><style>body{margin:0;background:#f4f6f9;color:#1f2937;font:16px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif}.card{box-sizing:border-box;width:min(560px,calc(100% - 32px));margin:8vh auto;padding:28px;background:#fff;border:1px solid #d8dee8;border-radius:12px;box-shadow:0 12px 32px rgba(15,23,42,.08)}h1{font-size:24px;margin:0 0 12px}.muted{color:#5f6b7a}.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:22px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 18px;border:1px solid #2457d6;border-radius:8px;background:#2f6fed;color:#fff;font-weight:600;text-decoration:none;cursor:pointer}.btn-secondary{background:#fff;color:#26364d;border-color:#9aa8ba}.btn-danger{background:#b42318;border-color:#b42318}.btn:focus-visible,a:focus-visible{outline:3px solid #111827;outline-offset:3px}form{margin:0}.notice{padding:12px 14px;border-radius:8px;background:#eef4ff;border:1px solid #b9cdfc}</style></head><body><main class="card">'.$body.'</main>';
    if($autoSubmit) echo '<script nonce="'.$nonce.'">document.getElementById("sso-handoff").submit();</script>';
    echo '</body></html>';
    exit;
}

$confirmRequest = $_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'confirm';
if($islogin!=1 && !$confirmRequest){
    http_response_code(403);
    adminSsoPage('需要管理员登录', '<h1>管理员会话已失效</h1><p>请重新登录管理后台后，再从商户列表发起代登录。</p><div class="actions"><a class="btn" href="./login.php">返回登录</a></div>');
}

try{
    if(!AdminSso::enabled($conf)) throw new AdminSsoException('管理员代登录功能尚未启用');
    if(!hash_equals(AdminSso::origin($conf, 'admin'), AdminSso::currentOrigin())) throw new AdminSsoException('当前管理域名不在代登录允许列表');

    if(empty($_SESSION['admin_sso_csrf'])) $_SESSION['admin_sso_csrf'] = bin2hex(random_bytes(32));
    $csrf = $_SESSION['admin_sso_csrf'];
    $adminToken = (string)($_COOKIE['admin_token'] ?? '');

    if($_SERVER['REQUEST_METHOD'] === 'GET'){
        $uid = filter_input(INPUT_GET, 'uid', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if(!$uid) throw new AdminSsoException('目标商户参数无效');
        $user = $DB->getRow('SELECT uid,status FROM pre_user WHERE uid=:uid LIMIT 1', [':uid'=>$uid]);
        if(!$user) throw new AdminSsoException('目标商户不存在');
        $disabled = (int)$user['status'] !== 1;
        $body = '<h1>管理员代登录</h1><p class="muted">目标商户 UID：<strong>'.adminSsoEscape($uid).'</strong></p>';
        if($disabled) $body .= '<p class="notice">该商户已停用，无法创建代登录会话。</p>';
        else $body .= '<p class="notice">将在商户域创建一个最长 15 分钟的独立、可撤销会话。不会共享商户密钥，也不会扩大该商户的支付或结算权限。</p>';
        $body .= '<div class="actions">';
        if(!$disabled) $body .= '<form method="post" action="'.adminSsoEscape(AdminSso::endpoint($conf, 'admin')).'"><input type="hidden" name="action" value="start"><input type="hidden" name="uid" value="'.adminSsoEscape($uid).'"><input type="hidden" name="csrf_token" value="'.adminSsoEscape($csrf).'"><button class="btn" type="submit">继续代登录</button></form>';
        $body .= '<form method="post" action="'.adminSsoEscape(AdminSso::endpoint($conf, 'admin')).'"><input type="hidden" name="action" value="revoke_uid"><input type="hidden" name="uid" value="'.adminSsoEscape($uid).'"><input type="hidden" name="csrf_token" value="'.adminSsoEscape($csrf).'"><button class="btn btn-danger" type="submit">撤销该商户代登录</button></form><a class="btn btn-secondary" href="./ulist.php">取消</a></div>';
        adminSsoPage('管理员代登录', $body);
    }

    if($_SERVER['REQUEST_METHOD'] !== 'POST') throw new AdminSsoException('不支持的请求方式');
    $action = (string)($_POST['action'] ?? '');

    if($action === 'start' || $action === 'revoke_uid'){
        if(!checkRefererHost()) throw new AdminSsoException('请求来源校验失败');
        if(!isset($_POST['csrf_token']) || !hash_equals($csrf, (string)$_POST['csrf_token'])) throw new AdminSsoException('请求防伪校验失败');
        $uid = filter_var($_POST['uid'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if(!$uid) throw new AdminSsoException('目标商户参数无效');
        if($action === 'revoke_uid'){
            AdminSso::revokeUid($DB, (int)$uid, $adminToken);
            adminSsoPage('已撤销代登录', '<h1>撤销完成</h1><p>该商户待消费的交接票据和活动代登录会话已撤销。</p><div class="actions"><a class="btn" href="./ulist.php">返回商户列表</a></div>');
        }

        $flowToken = AdminSso::newOpaqueToken();
        setcookie(AdminSso::FLOW_COOKIE, $flowToken, ['expires'=>time()+180,'path'=>AdminSso::cookiePath($conf, 'admin'),'secure'=>true,'httponly'=>true,'samesite'=>'None']);
        $handoff = AdminSso::prepare($DB, $conf, (int)$uid, $adminToken, $flowToken, (int)$admin_expiretime);
        $merchantEndpoint = AdminSso::endpoint($conf, 'merchant');
        $body = '<h1>正在建立商户域绑定</h1><p class="muted">若浏览器没有自动继续，请使用下方按钮。</p><form id="sso-handoff" method="post" action="'.adminSsoEscape($merchantEndpoint).'"><input type="hidden" name="action" value="bootstrap"><input type="hidden" name="ticket" value="'.adminSsoEscape($handoff['ticket']).'"><button class="btn" type="submit">继续到商户域</button></form>';
        adminSsoPage('代登录交接', $body, $merchantEndpoint, true);
    }

    if($action === 'confirm'){
        if(!AdminSso::requestOriginMatches(AdminSso::origin($conf, 'merchant'))) throw new AdminSsoException('商户域交接来源不匹配');
        $ticket = (string)($_POST['ticket'] ?? '');
        $nonceHash = (string)($_POST['nonce_hash'] ?? '');
        $flowToken = (string)($_COOKIE[AdminSso::FLOW_COOKIE] ?? '');
        AdminSso::bind($DB, $conf, $ticket, $nonceHash, $flowToken);
        setcookie(AdminSso::FLOW_COOKIE, '', ['expires'=>time()-3600,'path'=>AdminSso::cookiePath($conf, 'admin'),'secure'=>true,'httponly'=>true,'samesite'=>'None']);
        $merchantEndpoint = AdminSso::endpoint($conf, 'merchant');
        $body = '<h1>正在创建独立代登录会话</h1><p class="muted">交接票据只能消费一次。</p><form id="sso-handoff" method="post" action="'.adminSsoEscape($merchantEndpoint).'"><input type="hidden" name="action" value="consume"><input type="hidden" name="ticket" value="'.adminSsoEscape($ticket).'"><button class="btn" type="submit">进入商户中心</button></form>';
        adminSsoPage('代登录交接', $body, $merchantEndpoint, true);
    }

    throw new AdminSsoException('未知的代登录操作');
}catch(Throwable $e){
    http_response_code(400);
    $message = $e instanceof AdminSsoException ? $e->getMessage() : '代登录服务暂时不可用，请稍后重新发起';
    if(!($e instanceof AdminSsoException)) error_log('Administrator merchant handoff failed');
    adminSsoPage('无法完成代登录', '<h1>无法完成代登录</h1><p>'.adminSsoEscape($message).'</p><p class="muted">请返回商户列表重新发起；不要刷新或重放当前交接页。</p><div class="actions"><a class="btn" href="./ulist.php">返回商户列表</a></div>');
}
