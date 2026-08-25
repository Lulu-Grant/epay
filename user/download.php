<?php
include("../includes/common.php");

if($islogin2!=1) exit("<script>window.location.href='./login.php';</script>");

if(empty($conf['merchant_complain_view_enabled'])){
    http_response_code(404);
    exit('Not Found');
}

$act = isset($_GET['act']) ? (string)$_GET['act'] : '';
if($act !== 'complain_image' || !checkRefererHost()){
    http_response_code(404);
    exit('Not Found');
}

$complaintId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$service = new \lib\Complain\MerchantViewService($DB, $conf);
try{
    $service->detailForMerchant($uid, $complaintId);
}catch(Throwable $e){
    error_log('Merchant complaint image authorization failed: '.$e->getMessage());
}

// Phase one has no trusted local attachment snapshots. Fail closed after UID authorization.
http_response_code(404);
header('Cache-Control: no-store');
exit('Not Found');
