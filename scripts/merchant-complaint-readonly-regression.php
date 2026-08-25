#!/usr/bin/env php
<?php
if(PHP_SAPI !== 'cli'){
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root.'/includes/lib/Complain/MerchantViewService.php';

class MerchantComplaintFakeDb
{
    public $calls = [];
    public $detailRow = null;

    public function getRow($sql, $params = [])
    {
        $this->calls[] = ['method'=>'getRow', 'sql'=>$sql, 'params'=>$params];
        if(strpos($sql, 'total_count') !== false){
            return ['total_count'=>4, 'pending_count'=>1, 'processing_count'=>2, 'completed_count'=>1];
        }
        return $this->detailRow;
    }

    public function getColumn($sql, $params = [])
    {
        $this->calls[] = ['method'=>'getColumn', 'sql'=>$sql, 'params'=>$params];
        if(strpos($sql, "status='0'") !== false) return 1;
        return 1;
    }

    public function getAll($sql, $params = [])
    {
        $this->calls[] = ['method'=>'getAll', 'sql'=>$sql, 'params'=>$params];
        return [[
            'id'=>17,
            'trade_no'=>'202607210001',
            'thirdid'=>'THIRD-COMPLAINT-17',
            'complaint_type'=>'商品问题',
            'complaint_title'=>'<img src=x onerror=alert(1)>',
            'status'=>0,
            'addtime'=>'2026-07-21 09:00:00',
            'edittime'=>'2026-07-21 09:10:00',
            'out_trade_no'=>'M-17',
            'order_name'=>'<script>alert(1)</script>',
            'money'=>'31.00',
            'order_status'=>1,
            'pay_type_code'=>'alipay',
            'pay_type_name'=>'支付宝',
            'uid'=>9999,
            'channel'=>14,
            'phone'=>'13800138000',
            'thirdmchid'=>'secret-upstream-merchant',
        ]];
    }
}

$failures = [];
$assertSame = function($expected, $actual, $label) use (&$failures){
    if($expected !== $actual){
        $failures[] = $label.' expected='.var_export($expected, true).' actual='.var_export($actual, true);
    }
};
$assertTrue = function($actual, $label) use (&$failures){
    if(!$actual) $failures[] = $label;
};
$assertThrows = function($callback, $class, $label) use (&$failures){
    try{
        $callback();
        $failures[] = $label.' expected '.$class;
    }catch(Throwable $e){
        if(!($e instanceof $class)) $failures[] = $label.' threw '.get_class($e);
    }
};

$disabledDb = new MerchantComplaintFakeDb();
$disabled = new \lib\Complain\MerchantViewService($disabledDb, ['merchant_complain_view_enabled'=>'0']);
$assertSame(false, $disabled->isEnabled(), 'disabled configuration');
$assertThrows(function() use ($disabled){ $disabled->summaryForMerchant(1001); }, RuntimeException::class, 'disabled summary is rejected');
$assertSame(0, count($disabledDb->calls), 'disabled feature performs no query');

$db = new MerchantComplaintFakeDb();
$service = new \lib\Complain\MerchantViewService($db, ['merchant_complain_view_enabled'=>'1']);
$assertSame(true, $service->isEnabled(), 'enabled configuration');
$summary = $service->summaryForMerchant(1001);
$assertSame(['total'=>4, 'pending'=>1, 'processing'=>2, 'completed'=>1], $summary, 'summary DTO');
$assertSame([':uid'=>1001], $db->calls[0]['params'], 'summary binds current merchant UID');
$assertSame(1, $service->pendingCount(1001), 'pending count');
$assertSame([':uid'=>1001], $db->calls[1]['params'], 'menu count binds current merchant UID');

$attack = "%' OR A.uid=1002 --";
$list = $service->listForMerchant(1001, [
    'pageNumber'=>2,
    'pageSize'=>50,
    'type'=>'5',
    'kw'=>$attack,
    'starttime'=>'2026-07-01',
    'endtime'=>'2026-07-21',
    'paytype'=>'1',
    'dstatus'=>'0',
    'uid'=>'1002',
]);
$countCall = $db->calls[2];
$listCall = $db->calls[3];
$assertTrue(strpos($countCall['sql'], 'A.uid=:uid') !== false, 'list count SQL enforces complaint UID');
$assertTrue(strpos($countCall['sql'], 'B.uid=A.uid') !== false, 'order join enforces matching UID');
$assertTrue(strpos($countCall['sql'], $attack) === false, 'keyword never enters SQL text');
$assertSame(1001, $countCall['params'][':uid'], 'forged request UID is ignored');
$assertSame('%'.str_replace(['=','%','_'], ['==','=%','=_'], $attack).'%', $countCall['params'][':keyword'], 'LIKE keyword is escaped and bound');
$assertTrue(strpos($listCall['sql'], 'LIMIT 50,50') !== false, 'pagination is bounded and deterministic');
$assertSame(1, $list['total'], 'list total');
$assertSame(50, $list['pageSize'], 'allowed page size');
$assertSame(2, $list['pageNumber'], 'page number');
$assertSame('<script>alert(1)</script>', $list['rows'][0]['order_name'], 'DTO preserves text for contextual output escaping');
foreach(['uid','channel','phone','thirdmchid','source','content'] as $forbidden){
    $assertSame(false, array_key_exists($forbidden, $list['rows'][0]), 'list DTO excludes '.$forbidden);
}

$db->detailRow = [
    'id'=>17,
    'trade_no'=>'202607210001',
    'thirdid'=>'THIRD-COMPLAINT-17',
    'complaint_type'=>'商品问题',
    'complaint_title'=>'描述',
    'complaint_content'=>'<svg/onload=alert(1)>',
    'status'=>2,
    'phone'=>'13800138000',
    'addtime'=>'2026-07-21 09:00:00',
    'edittime'=>'2026-07-21 10:00:00',
    'out_trade_no'=>'M-17',
    'order_name'=>'商品',
    'money'=>'31.00',
    'order_status'=>1,
    'pay_type_code'=>'alipay',
    'pay_type_name'=>'支付宝',
];
$detail = $service->detailForMerchant(1001, 17);
$detailCall = $db->calls[4];
$assertTrue(strpos($detailCall['sql'], 'A.id=:id AND A.uid=:uid') !== false, 'detail SQL binds ID and current UID');
$assertSame([':id'=>17, ':uid'=>1001], $detailCall['params'], 'detail parameters');
$assertSame('138****8000', $detail['phone_masked'], 'phone is masked');
$assertSame('<svg/onload=alert(1)>', $detail['complaint_content'], 'detail text remains data');
$assertSame(false, array_key_exists('phone', $detail), 'detail excludes full phone');

$db->detailRow = false;
$assertSame(null, $service->detailForMerchant(1001, 9999), 'missing or cross-merchant detail is indistinguishable');
$assertThrows(function() use ($service){
    $service->listForMerchant(1001, ['starttime'=>'2026-01-01', 'endtime'=>'2026-07-21']);
}, InvalidArgumentException::class, 'date range over 90 days is rejected');
$assertSame('****', \lib\Complain\MerchantViewService::maskPhone('1234'), 'short phone is fully masked');

$ajaxSource = file_get_contents($root.'/user/ajax_complain.php');
$detailSource = file_get_contents($root.'/user/complain_info.php');
$downloadSource = file_get_contents($root.'/user/download.php');
$listSource = file_get_contents($root.'/user/complain.php');
$headSource = file_get_contents($root.'/user/head.php');
foreach(['feedbackSubmit','replySubmit','supplementSubmit','refundProgressSubmit','uploadImage','apirefund'] as $writeAction){
    $assertTrue(strpos($ajaxSource, $writeAction) === false, 'merchant AJAX excludes write action '.$writeAction);
}
foreach(['getNewInfo','CommUtil','apirefund','refund_submit'] as $upstreamAction){
    $assertTrue(strpos($detailSource, $upstreamAction) === false, 'detail excludes upstream/write action '.$upstreamAction);
}
foreach(['channel', 'subchannel', 'mediaid', 'getImage'] as $unsafeImageInput){
    $assertTrue(strpos($downloadSource, $unsafeImageInput) === false, 'image endpoint excludes client-controlled '.$unsafeImageInput);
}
$assertTrue(strpos($downloadSource, "exit('Not Found')") !== false, 'image endpoint uses one indistinguishable response body');
$assertTrue(strpos($downloadSource, 'Image unavailable') === false, 'image endpoint does not expose complaint existence');
$assertTrue(strpos($detailSource, 'ENT_QUOTES | ENT_SUBSTITUTE') !== false, 'detail uses contextual HTML escaping');
$assertTrue(strpos($listSource, 'textContent') !== false, 'list formatters escape external text through textContent');
$assertTrue(strpos($headSource, 'merchant_complain_view_enabled') !== false, 'menu uses independent feature flag');

if($failures){
    fwrite(STDERR, implode(PHP_EOL, $failures).PHP_EOL);
    exit(1);
}

echo "merchant complaint read-only regression: ok\n";
