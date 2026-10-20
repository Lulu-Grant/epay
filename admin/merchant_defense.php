<?php
include '../includes/common.php';
if($islogin!=1){http_response_code(403);exit('需要管理员登录');}
$title='商户支付防御';
include './head.php';
if(empty($_SESSION['merchant_defense_csrf']))$_SESSION['merchant_defense_csrf']=bin2hex(random_bytes(32));
$uid=(int)($_GET['uid']??0);
?>
<meta name="merchant-defense-csrf" content="<?=htmlspecialchars($_SESSION['merchant_defense_csrf'],ENT_QUOTES,'UTF-8')?>">
<div class="container" id="merchant-defense-admin" data-uid="<?=$uid?>">
<div class="panel panel-primary">
<div class="panel-heading"><h3 class="panel-title">商户 <?=$uid?> 支付防御</h3></div>
<div class="panel-body">
<p>按本商户的付款 IP 独立执行：浏览器验证、每天成功最多 5 单、投诉封禁 48 小时。失败不计成功，待确认订单暂占名额；MAPI 将返回平台验证收银台链接。</p>
<p id="defense-admin-status" role="status" aria-live="polite">正在加载…</p>
<button type="button" class="btn btn-primary merchant-defense-toggle" id="defense-detail-toggle" disabled>加载中</button>
<a class="btn btn-default" href="./ulist.php">返回商户列表</a>
<h4>启用条件</h4><ul id="defense-blockers"></ul>
<p class="text-muted">已付款订单继续正常验签和入账。关闭开关不清空当日计数、原投诉事件或全站黑名单。</p>
</div></div>
<div class="panel panel-default"><div class="panel-heading">最近 100 条投诉 IP 封禁事件</div>
<div class="table-responsive"><table class="table table-striped"><thead><tr><th>IP</th><th>订单</th><th>首次识别</th><th>到期</th><th>状态与操作</th></tr></thead><tbody id="defense-bans"></tbody></table></div></div>
<div class="panel panel-default"><div class="panel-heading">最近 100 条操作记录</div>
<div class="table-responsive"><table class="table"><thead><tr><th>时间</th><th>动作</th><th>原因</th><th>操作者</th></tr></thead><tbody id="defense-audit"></tbody></table></div></div>
</div>
<script src="/assets/js/merchant-defense-admin.js?v=1" defer></script>
