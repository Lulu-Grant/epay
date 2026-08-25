<?php
include("../includes/common.php");
if($islogin2!=1) exit("<script>window.location.href='./login.php';</script>");
$title = '投诉详情';
include './head.php';

if(empty($conf['merchant_complain_view_enabled'])) showmsg('投诉查询功能暂未开放');

function merchant_complain_detail_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$service = new \lib\Complain\MerchantViewService($DB, $conf);
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
try{
    $complaint = $service->detailForMerchant($uid, $id);
}catch(Throwable $e){
    error_log('Merchant complaint detail failed: '.$e->getMessage());
    $complaint = null;
}
if(!$complaint) showmsg('该投诉单不存在');

$statusClass = $complaint['status'] === 2 ? 'text-success' : ($complaint['status'] === 1 ? 'text-warning' : 'text-danger');
$orderUrl = './order.php?type=1&kw='.rawurlencode($complaint['trade_no']);
?>
<style>
.complaint-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 28px}
.complaint-field{padding:9px 0;border-bottom:1px solid #edf1f2}
.complaint-field-label{margin-bottom:4px;color:#777;font-size:12px}
.complaint-field-value{min-height:20px;overflow-wrap:anywhere;white-space:pre-wrap}
.complaint-content{padding:12px;border:1px solid #dee5e7;background:#f9fafb;line-height:1.7;overflow-wrap:anywhere;white-space:pre-wrap}
@media (max-width:767px){.complaint-detail-grid{grid-template-columns:1fr}}
</style>
<div id="content" class="app-content" role="main">
  <div class="app-content-body">
    <div class="bg-light lter b-b wrapper-md hidden-print">
      <h1 class="m-n font-thin h3">投诉详情</h1>
    </div>
    <div class="wrapper-md control">
      <div class="alert alert-info">此页面仅展示平台已保存的信息。投诉处理、退款及回复由平台管理员完成。</div>
      <div class="panel panel-primary">
        <div class="panel-heading"><h3 class="panel-title">投诉记录 #<?php echo intval($complaint['id']);?></h3></div>
        <div class="panel-body">
          <div class="complaint-detail-grid">
            <div class="complaint-field"><div class="complaint-field-label">处理状态</div><div class="complaint-field-value <?php echo $statusClass;?>"><?php echo merchant_complain_detail_h($complaint['status_text']);?></div></div>
            <div class="complaint-field"><div class="complaint-field-label">第三方投诉单号</div><div class="complaint-field-value"><?php echo merchant_complain_detail_h($complaint['thirdid'] ?: '-');?></div></div>
            <div class="complaint-field"><div class="complaint-field-label">问题类型</div><div class="complaint-field-value"><?php echo merchant_complain_detail_h($complaint['complaint_type'] ?: '-');?></div></div>
            <div class="complaint-field"><div class="complaint-field-label">投诉原因</div><div class="complaint-field-value"><?php echo merchant_complain_detail_h($complaint['complaint_title'] ?: '-');?></div></div>
            <div class="complaint-field"><div class="complaint-field-label">联系电话</div><div class="complaint-field-value"><?php echo merchant_complain_detail_h($complaint['phone_masked']);?></div></div>
            <div class="complaint-field"><div class="complaint-field-label">支付方式</div><div class="complaint-field-value"><?php echo merchant_complain_detail_h($complaint['pay_type_name'] ?: '-');?></div></div>
            <div class="complaint-field"><div class="complaint-field-label">创建时间</div><div class="complaint-field-value"><?php echo merchant_complain_detail_h($complaint['created_at'] ?: '-');?></div></div>
            <div class="complaint-field"><div class="complaint-field-label">最后更新时间</div><div class="complaint-field-value"><?php echo merchant_complain_detail_h($complaint['updated_at'] ?: '-');?></div></div>
          </div>
          <h4 class="m-t-lg">投诉内容</h4>
          <div class="complaint-content"><?php echo merchant_complain_detail_h($complaint['complaint_content'] ?: '平台暂未保存投诉详情。');?></div>
          <p class="text-muted m-t-sm">附件仅在平台已完成本地安全快照时展示；当前记录没有可供商户查看的本地附件。</p>
        </div>
      </div>

      <div class="panel panel-info">
        <div class="panel-heading"><h3 class="panel-title">关联订单信息</h3></div>
        <div class="panel-body">
          <div class="complaint-detail-grid">
            <div class="complaint-field"><div class="complaint-field-label">系统订单号</div><div class="complaint-field-value"><?php echo merchant_complain_detail_h($complaint['trade_no'] ?: '-');?></div></div>
            <div class="complaint-field"><div class="complaint-field-label">商户订单号</div><div class="complaint-field-value"><?php echo merchant_complain_detail_h($complaint['out_trade_no'] ?: '-');?></div></div>
            <div class="complaint-field"><div class="complaint-field-label">商品名称</div><div class="complaint-field-value"><?php echo merchant_complain_detail_h($complaint['order_name'] ?: '-');?></div></div>
            <div class="complaint-field"><div class="complaint-field-label">订单金额</div><div class="complaint-field-value"><?php echo $complaint['money'] === null ? '-' : '￥'.merchant_complain_detail_h($complaint['money']);?></div></div>
            <div class="complaint-field"><div class="complaint-field-label">订单状态</div><div class="complaint-field-value"><?php echo merchant_complain_detail_h($complaint['order_status_text']);?></div></div>
          </div>
          <?php if($complaint['trade_no'] !== ''){?>
          <a class="btn btn-info m-t-md" href="<?php echo merchant_complain_detail_h($orderUrl);?>"><i class="fa fa-list"></i> 查看本商户订单</a>
          <?php }?>
          <a class="btn btn-default m-t-md" href="complain.php"><i class="fa fa-arrow-left"></i> 返回投诉列表</a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include 'foot.php';?>
