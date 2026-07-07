<?php
include("./includes/common.php");

if(!$conf['apiurl'])$conf['apiurl'] = $siteurl;

if(isset($_GET['old']) && $_GET['old'] == '1'){
	$loadfile = \lib\Template::load('doc_old');
	include $loadfile;
	exit;
}

$doc = isset($_GET['doc']) ? trim($_GET['doc']) : 'index';
$loadfile = \lib\Template::loadDoc($doc);
include $loadfile;
