<?php
include("../includes/common.php");
if($islogin2!=1) exit("<script>window.location.href='./login.php';</script>");
$title = '交易投诉';
include './head.php';

if(empty($conf['merchant_complain_view_enabled'])) showmsg('投诉查询功能暂未开放');

$service = new \lib\Complain\MerchantViewService($DB, $conf);
try{
    $summary = $service->summaryForMerchant($uid);
}catch(Throwable $e){
    error_log('Merchant complaint summary failed: '.$e->getMessage());
    $summary = ['total'=>0, 'pending'=>0, 'processing'=>0, 'completed'=>0];
}

function merchant_complain_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$typeOptions = '<option value="0">所有支付方式</option>';
$types = $DB->getAll("SELECT id,showname FROM pre_type WHERE status=1 ORDER BY id ASC");
foreach($types ?: [] as $row){
    $typeOptions .= '<option value="'.intval($row['id']).'">'.merchant_complain_h($row['showname']).'</option>';
}
?>
<style>
.complaint-summary{display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:10px;margin-bottom:15px}
.complaint-summary-item{padding:12px 14px;border:1px solid #dee5e7;background:#fff}
.complaint-summary-label{color:#777;font-size:12px}
.complaint-summary-value{margin-top:3px;font-size:22px;line-height:1.2}
.complaint-cell{max-width:300px;white-space:normal;word-break:break-word}
.complaint-dates{max-width:125px}
.fixed-table-toolbar,.fixed-table-pagination{padding:15px}
@media (max-width:767px){
  .complaint-summary{grid-template-columns:repeat(2,minmax(0,1fr))}
  .complaint-summary-value{font-size:18px}
  #searchToolbar .form-group,.input-daterange{display:block;margin:0 0 8px;width:100%}
  #searchToolbar .form-control{width:100%;max-width:none}
}
</style>
<link href="../assets/css/datepicker.css" rel="stylesheet">
<div id="content" class="app-content" role="main">
  <div class="app-content-body">
    <div class="bg-light lter b-b wrapper-md hidden-print">
      <h1 class="m-n font-thin h3">交易投诉</h1>
    </div>
    <div class="wrapper-md control">
      <div class="complaint-summary" aria-label="投诉统计">
        <div class="complaint-summary-item"><div class="complaint-summary-label">全部投诉</div><div class="complaint-summary-value"><?php echo intval($summary['total']);?></div></div>
        <div class="complaint-summary-item"><div class="complaint-summary-label">待处理</div><div class="complaint-summary-value text-danger"><?php echo intval($summary['pending']);?></div></div>
        <div class="complaint-summary-item"><div class="complaint-summary-label">处理中</div><div class="complaint-summary-value text-warning"><?php echo intval($summary['processing']);?></div></div>
        <div class="complaint-summary-item"><div class="complaint-summary-label">处理完成</div><div class="complaint-summary-value text-success"><?php echo intval($summary['completed']);?></div></div>
      </div>
      <div class="alert alert-info">此页面仅展示本商户投诉和关联订单信息。退款、回复及投诉状态处理请联系平台管理员。</div>
      <div class="panel panel-default">
        <div class="panel-heading font-bold">投诉订单记录</div>
        <form onsubmit="return searchSubmit()" method="GET" class="form-inline" id="searchToolbar">
          <div class="form-group">
            <select name="type" class="form-control">
              <option value="1">系统订单号</option>
              <option value="2">商户订单号</option>
              <option value="3">第三方投诉单号</option>
              <option value="4">问题类型</option>
              <option value="5">投诉原因</option>
            </select>
          </div>
          <div class="form-group"><input type="text" class="form-control" name="kw" maxlength="100" placeholder="搜索内容"></div>
          <div class="input-group input-daterange">
            <input type="text" id="starttime" name="starttime" class="form-control complaint-dates" placeholder="开始日期" autocomplete="off">
            <span class="input-group-addon"><i class="fa fa-chevron-right"></i></span>
            <input type="text" id="endtime" name="endtime" class="form-control complaint-dates" placeholder="结束日期" autocomplete="off">
          </div>
          <div class="form-group"><select name="paytype" class="form-control"><?php echo $typeOptions;?></select></div>
          <div class="form-group">
            <select name="dstatus" class="form-control">
              <option value="-1">全部状态</option>
              <option value="0">待处理</option>
              <option value="1">处理中</option>
              <option value="2">处理完成</option>
            </select>
          </div>
          <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> 搜索</button>
          <a href="javascript:searchClear()" class="btn btn-default" title="重置筛选"><i class="fa fa-refresh"></i></a>
        </form>
        <table id="listTable"></table>
      </div>
    </div>
  </div>
</div>
<?php include 'foot.php';?>
<script src="<?php echo merchant_complain_h($cdnpublic);?>bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js"></script>
<script src="<?php echo merchant_complain_h($cdnpublic);?>bootstrap-datepicker/1.9.0/locales/bootstrap-datepicker.zh-CN.min.js"></script>
<script src="../assets/js/bootstrap-table.min.js"></script>
<script src="../assets/js/bootstrap-table-page-jump-to.min.js"></script>
<script src="../assets/js/custom.js"></script>
<script>
function complaintEscape(value){
  var element=document.createElement('div');
  element.textContent=value===null||typeof value==='undefined'?'':String(value);
  return element.innerHTML;
}
function complaintStatus(value){
  if(parseInt(value,10)===1) return '<span class="text-warning">处理中</span>';
  if(parseInt(value,10)===2) return '<span class="text-success">处理完成</span>';
  return '<span class="text-danger">待处理</span>';
}
$(document).ready(function(){
  updateToolbar();
  var requestedPage=parseInt(window.$_GET.pageNumber,10);
  var requestedSize=parseInt(window.$_GET.pageSize,10);
  var pageNumber=requestedPage>0?requestedPage:1;
  var pageSize=[10,20,50].indexOf(requestedSize)>=0?requestedSize:20;
  $('#listTable').bootstrapTable({
    url:'ajax_complain.php?act=list',
    pageNumber:pageNumber,
    pageSize:pageSize,
    pageList:[10,20,50],
    classes:'table table-striped table-hover table-bordered',
    responseHandler:function(response){
      if(response.code!==0){
        if(typeof layer!=='undefined') layer.msg(response.msg||'投诉记录读取失败');
        return {total:0,rows:[]};
      }
      return response;
    },
    columns:[
      {field:'trade_no',title:'订单号',formatter:function(value,row){
        var trade=complaintEscape(value);
        var merchant=complaintEscape(row.out_trade_no||'关联订单不可用');
        return '<div class="complaint-cell"><a href="./order.php?type=1&kw='+encodeURIComponent(value)+'">'+trade+'</a><br>'+merchant+'</div>';
      }},
      {field:'order_name',title:'商品/金额',formatter:function(value,row){
        var amount=row.money===null?'-':'￥'+complaintEscape(row.money);
        return '<div class="complaint-cell">'+complaintEscape(value||'关联订单不可用')+'<br>'+amount+'</div>';
      }},
      {field:'pay_type_name',title:'支付方式',formatter:function(value){return complaintEscape(value||'-');}},
      {field:'complaint_type',title:'问题类型/投诉原因',formatter:function(value,row){
        return '<div class="complaint-cell">'+complaintEscape(value||'-')+'<br>'+complaintEscape(row.complaint_title||'-')+'</div>';
      }},
      {field:'created_at',title:'创建/更新时间',formatter:function(value,row){return complaintEscape(value)+'<br>'+complaintEscape(row.updated_at);}},
      {field:'status',title:'状态',formatter:function(value){return complaintStatus(value);}},
      {field:'id',title:'操作',formatter:function(value){return '<a class="btn btn-info btn-xs" href="complain_info.php?id='+encodeURIComponent(value)+'">查看详情</a>';}}
    ]
  });
  $('.input-daterange').datepicker({format:'yyyy-mm-dd',autoclose:true,clearBtn:true,language:'zh-CN'});
});
</script>
