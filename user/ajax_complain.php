<?php
include("../includes/common.php");
if($islogin2!=1) merchant_complain_json(['code'=>-3, 'msg'=>'No Login'], 401);
if(!checkRefererHost()) merchant_complain_json(['code'=>403, 'msg'=>'Forbidden'], 403);

$act = isset($_GET['act']) ? trim((string)$_GET['act']) : '';
if($act !== 'list') merchant_complain_json(['code'=>-4, 'msg'=>'No Act'], 404);
if(empty($conf['merchant_complain_view_enabled'])) merchant_complain_json(['code'=>-1, 'msg'=>'投诉查询功能未开放'], 404);

try{
    $service = new \lib\Complain\MerchantViewService($DB, $conf);
    $result = $service->listForMerchant($uid, $_POST);
    merchant_complain_json([
        'code'=>0,
        'total'=>$result['total'],
        'rows'=>$result['rows'],
        'pageNumber'=>$result['pageNumber'],
        'pageSize'=>$result['pageSize'],
    ]);
}catch(InvalidArgumentException $e){
    merchant_complain_json(['code'=>-1, 'msg'=>$e->getMessage()], 400);
}catch(Throwable $e){
    error_log('Merchant complaint list failed: '.$e->getMessage());
    merchant_complain_json(['code'=>-1, 'msg'=>'投诉记录读取失败，请稍后重试'], 500);
}

function merchant_complain_json($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    exit(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
}
