<?php
$clientip=real_ip($conf['ip_type']?$conf['ip_type']:0);

$admin_expiretime = 0;
if(isset($_COOKIE["admin_token"]))
{
	$token=authcode(daddslashes($_COOKIE['admin_token']), 'DECODE', SYS_KEY);
	$parts = explode("\t", (string)$token);
	if(count($parts) === 3){
		list($user, $sid, $expiretime) = $parts;
		$session=md5($conf['admin_user'].$conf['admin_pwd'].$password_hash);
		if($session==$sid && $expiretime>time()) {
			$islogin=1;
			$admin_expiretime=(int)$expiretime;
		}
	}
}

$admin_sso_session = null;
if(isset($_COOKIE[\lib\AdminSso::SESSION_COOKIE]))
{
	try{
		$ssoAuth = \lib\AdminSso::authenticate($DB, $conf, (string)$_COOKIE[\lib\AdminSso::SESSION_COOKIE]);
		if($ssoAuth){
			$userrow = $ssoAuth['user'];
			$uid = (int)$userrow['uid'];
			$islogin2 = 1;
			$admin_sso_session = $ssoAuth['session'];
		}
	}catch(\Throwable $e){
		error_log('Administrator merchant session validation failed');
	}
}
if(empty($islogin2) && isset($_COOKIE["user_token"]))
{
	$token=authcode(daddslashes($_COOKIE['user_token']), 'DECODE', SYS_KEY);
	$parts = explode("\t", (string)$token);
	if(count($parts) === 3){
		list($uid, $sid, $expiretime) = $parts;
		$uid = intval($uid);
		$userrow=$DB->getRow("SELECT * FROM pre_user WHERE uid=:uid limit 1", [':uid'=>$uid]);
		$session=$userrow ? md5($userrow['uid'].$userrow['key'].$password_hash) : '';
		if($userrow && $session==$sid && $expiretime>time()) {
			$islogin2=1;
		}
	}
}
?>
