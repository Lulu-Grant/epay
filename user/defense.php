<?php
include '../includes/common.php';
if($islogin2!=1){http_response_code(403);exit('请先登录商户中心');}
$title='支付防御';
include './head.php';
$summary=null;$message='';
try{
    if(!\lib\Defense\Runtime::installed())$message='平台尚未开放商户支付防御配置';
    else $summary=(new \lib\Defense\MerchantViewService(\lib\Defense\Runtime::store()))->summaryForMerchant((int)$userrow['uid']);
}catch(Throwable $e){$message='支付防御状态暂不可用，请稍后重试';}
?>
<div id="content" class="app-content" role="main"><div class="app-content-body"><div class="wrapper-md control">
<div class="panel panel-default">
<div class="panel-heading"><h3 class="panel-title">支付防御</h3></div>
<div class="panel-body">
<?php if($summary){ ?>
<p>商户 <?=intval($summary['uid'])?>：<strong><?=$summary['enabled']?'防御模式已开启':'防御模式已关闭'?></strong></p>
<p>开启后，买家需通过平台浏览器验证。同一付款 IP 在本商户下每天最多成功支付 5 单，失败不计成功，结果待确认的订单暂占名额。投诉关联到可信付款 IP 后封禁 48 小时。</p>
<p>规则从启用后的新订单开始生效，历史付款不追补进防御额度。旧未纳管订单请重新下单；已发出的上游链接无法撤销，真实到账继续正常处理。已纳管订单的计数和占位保留。</p>
<p>当前保留的有效投诉封禁 IP：<?=intval($summary['active_banned_ips'])?> 个。关闭防御期间不执行本功能的拦截，记录仍按原期限保留。</p>
<p>使用 MAPI 时，请将买家跳转到平台返回的付款地址完成验证；开启后不直接返回可绕过验证的二维码。</p>
<p>防御模式由平台管理员配置。如需调整或人工解封，请联系平台处理。</p>
<?php }else{ ?>
<p role="status"><?=htmlspecialchars($message,ENT_QUOTES,'UTF-8')?></p>
<?php } ?>
</div></div>
</div></div></div>
<?php include 'foot.php'; ?>
