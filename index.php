<?php
if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    die('require PHP >= 7.4 !');
}
include("./includes/common.php");

if(isset($_GET['doc'])){
    $doc = trim($_GET['doc']);
    if(!$conf['apiurl'])$conf['apiurl'] = $siteurl;
    $loadfile = \lib\Template::loadDoc($doc);
    include $loadfile;
    exit;
}

$mod = isset($_GET['mod'])?$_GET['mod']:'index';

if(isset($_GET['invite'])){
    $invite_code = trim($_GET['invite']);
    $uid = get_invite_uid($invite_code);
    if($uid && is_numeric($uid)){
        $_SESSION['invite_uid'] = intval($uid);
    }
}

if($mod=='index'){
    header('Location: /shopping.php', true, 302);
    exit;
}

$loadfile = \lib\Template::load($mod);
include $loadfile;
