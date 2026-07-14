<?php
/**
 * 商城订单管理
**/
include("../includes/common.php");
$title = '商城订单管理';
include './head.php';
if ($islogin == 1) {
} else {
    exit("<script language='javascript'>window.location.href='./login.php';</script>");
}
if (empty($_SESSION['shop_csrf_token'])) {
    $_SESSION['shop_csrf_token'] = random(32);
}
$csrf_token = $_SESSION['shop_csrf_token'];
?>
<style>
.shop-order-toolbar .form-control{max-width:180px}
.shop-summary{margin-bottom:12px}
</style>
<div class="container-fluid" style="padding-top:70px;">
  <div class="col-md-12 center-block" style="float:none;">
    <div class="alert alert-info shop-summary" id="shop-summary">正在加载商城订单概况...</div>
    <div id="toolbar" class="shop-order-toolbar">
      <form class="form-inline" onsubmit="return searchOrders()">
        <div class="form-group"><input type="text" class="form-control" name="keyword" placeholder="订单号/商户UID/商品/联系"></div>
        <div class="form-group">
          <select class="form-control" name="pay_status">
            <option value="-1">全部支付状态</option>
            <option value="0">未支付</option>
            <option value="1">已支付</option>
            <option value="2">已取消</option>
            <option value="3">已退款</option>
          </select>
        </div>
        <div class="form-group">
          <select class="form-control" name="order_status">
            <option value="-1">全部履约状态</option>
            <option value="0">待支付</option>
            <option value="1">待发货</option>
            <option value="2">已发货</option>
            <option value="3">已签收</option>
            <option value="4">已完成</option>
            <option value="5">已取消</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary">搜索</button>
        <button type="button" class="btn btn-default" onclick="resetOrders()">重置</button>
        <a href="./shop_config.php" class="btn btn-default">商城配置</a>
        <a href="./shop_goods.php" class="btn btn-default">商品管理</a>
      </form>
    </div>
    <table id="ordersTable"></table>
  </div>
</div>

<div class="modal" id="order-modal" role="dialog" aria-hidden="true" data-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title">商城订单详情</h4>
      </div>
      <div class="modal-body">
        <div id="order-detail"></div>
        <hr>
        <form class="form-horizontal" id="logistics-form">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8');?>">
          <input type="hidden" name="id" id="order-id">
          <div class="form-group">
            <label class="col-sm-2 control-label">物流公司</label>
            <div class="col-sm-10"><input type="text" class="form-control" name="logistics_company" id="logistics-company"></div>
          </div>
          <div class="form-group">
            <label class="col-sm-2 control-label">物流单号</label>
            <div class="col-sm-10"><input type="text" class="form-control" name="tracking_no" id="tracking-no"></div>
          </div>
          <div class="form-group">
            <label class="col-sm-2 control-label">履约状态</label>
            <div class="col-sm-10">
              <select class="form-control" name="order_status" id="order-status">
                <option value="1">待发货</option>
                <option value="2">已发货</option>
                <option value="3">已签收</option>
                <option value="4">已完成</option>
                <option value="5">已取消</option>
              </select>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">关闭</button>
        <button type="button" class="btn btn-primary" onclick="saveLogistics()">保存物流</button>
      </div>
    </div>
  </div>
</div>

<script src="<?php echo $cdnpublic?>layer/3.1.1/layer.min.js"></script>
<script src="../assets/js/bootstrap-table.min.js"></script>
<script src="../assets/js/bootstrap-table-page-jump-to.min.js"></script>
<script>
var shopCsrfToken = <?php echo json_encode($csrf_token);?>;
function escapeHtml(str){
  return String(str == null ? '' : str).replace(/[&<>"']/g, function(s){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s];});
}
function payText(v){ return {0:'未支付',1:'已支付',2:'已取消',3:'已退款'}[parseInt(v,10)] || '未知'; }
function orderText(v){ return {0:'待支付',1:'待发货',2:'已发货',3:'已签收',4:'已完成',5:'已取消'}[parseInt(v,10)] || '未知'; }
function queryParams(params){
  var form = $('#toolbar form').serializeArray();
  $.each(form, function(_, item){ params[item.name] = item.value; });
  return params;
}
$(function(){
  loadSummary();
  $('#ordersTable').bootstrapTable({
    url: 'ajax_shop.php?act=orderList',
    method: 'post',
    toolbar: '#toolbar',
    sidePagination: 'server',
    pagination: true,
    pageSize: 20,
    queryParams: queryParams,
    classes: 'table table-striped table-hover table-bordered',
    columns: [
      {field:'shop_trade_no', title:'商城订单号<br>支付订单号', formatter:function(value,row){return '<a href="javascript:openOrder('+row.id+')">'+escapeHtml(value)+'</a><br>'+escapeHtml(row.pay_trade_no);}},
      {field:'merchant_uid', title:'原商户UID', formatter:function(value){return escapeHtml(value || '');}},
      {field:'record_source_text', title:'记录模式', formatter:function(value){return escapeHtml(value || '');}},
      {field:'goods_name', title:'商品<br>金额', formatter:function(value,row){return escapeHtml(value)+' × '+escapeHtml(row.quantity)+'<br>￥<b>'+escapeHtml(row.money)+'</b>';}},
      {field:'buyer_name', title:'购买人<br>联系方式', formatter:function(value,row){return escapeHtml(value)+'<br>'+escapeHtml(row.buyer_contact);}},
      {field:'pay_status', title:'支付状态', formatter:function(value){var cls=parseInt(value,10)===1?'success':'default'; return '<span class="label label-'+cls+'">'+payText(value)+'</span>';}},
      {field:'order_status', title:'履约状态', formatter:function(value){return '<span class="label label-info">'+orderText(value)+'</span>';}},
      {field:'logistics_company', title:'物流', formatter:function(value,row){return escapeHtml(value || '')+'<br>'+escapeHtml(row.tracking_no || '');}},
      {field:'addtime', title:'创建时间<br>支付时间', formatter:function(value,row){return escapeHtml(value)+'<br>'+(row.paytime ? escapeHtml(row.paytime) : '');}},
      {field:'id', title:'操作', formatter:function(value){return '<button class="btn btn-xs btn-info" onclick="openOrder('+value+')">详情/物流</button> <button class="btn btn-xs btn-danger" onclick="deleteOrder('+value+')">删除</button>';}}
    ]
  });
});
function loadSummary(){
  $.getJSON('ajax_shop.php?act=summary', function(data){
    if(data.code !== 0){ $('#shop-summary').text('商城订单概况加载失败'); return; }
    var d = data.data;
    $('#shop-summary').html('商城订单总数：<b>'+escapeHtml(d.total_count)+'</b>，已支付订单：<b>'+escapeHtml(d.paid_count)+'</b>，订单总金额：￥<b>'+escapeHtml(d.total_money)+'</b>，已支付金额：￥<b>'+escapeHtml(d.paid_money)+'</b>');
  });
}
function searchOrders(){ $('#ordersTable').bootstrapTable('refresh', {pageNumber:1}); loadSummary(); return false; }
function resetOrders(){ $('#toolbar form')[0].reset(); searchOrders(); }
function openOrder(id){
  $.getJSON('ajax_shop.php?act=getOrder&id='+id, function(data){
    if(data.code !== 0){ layer.alert(data.msg,{icon:2}); return; }
    var r = data.data;
    $('#order-id').val(r.id);
    $('#logistics-company').val(r.logistics_company || '');
    $('#tracking-no').val(r.tracking_no || '');
    $('#order-status').val(r.order_status);
    var html = '<table class="table table-bordered">'+
      '<tr><th>商城订单号</th><td>'+escapeHtml(r.shop_trade_no)+'</td><th>支付订单号</th><td>'+escapeHtml(r.pay_trade_no)+'</td></tr>'+
      '<tr><th>原商户UID</th><td colspan="3">'+escapeHtml(r.merchant_uid || '')+'</td></tr>'+
      '<tr><th>原订单参数</th><td colspan="3">'+escapeHtml(r.merchant_param || '')+'</td></tr>'+
      '<tr><th>商品</th><td>'+escapeHtml(r.goods_name)+' × '+escapeHtml(r.quantity)+'</td><th>金额</th><td>￥'+escapeHtml(r.money)+'</td></tr>'+
      '<tr><th>购买人</th><td>'+escapeHtml(r.buyer_name)+'</td><th>联系方式</th><td>'+escapeHtml(r.buyer_contact)+'</td></tr>'+
      '<tr><th>支付状态</th><td>'+escapeHtml(r.pay_status_text)+'</td><th>履约状态</th><td>'+escapeHtml(r.order_status_text)+'</td></tr>'+
      '<tr><th>备注</th><td colspan="3">'+escapeHtml(r.buyer_remark || '')+'</td></tr>'+
      '</table>';
    $('#order-detail').html(html);
    $('#order-modal').modal('show');
  });
}
function saveLogistics(){
  var ii = layer.load(2, {shade:[0.1,'#fff']});
  $.ajax({type:'POST', url:'ajax_shop.php?act=saveLogistics', data:$('#logistics-form').serialize(), dataType:'json',
    success:function(data){ layer.close(ii); if(data.code===0){ $('#order-modal').modal('hide'); $('#ordersTable').bootstrapTable('refresh'); layer.msg(data.msg,{icon:1}); }else{ layer.alert(data.msg,{icon:2}); } },
    error:function(){ layer.close(ii); layer.msg('服务器错误'); }
  });
}
function deleteOrder(id){
  layer.confirm('确认删除该商城订单记录？', {icon:0}, function(index){
    layer.close(index);
    $.post('ajax_shop.php?act=deleteOrder', {id:id,csrf_token:shopCsrfToken}, function(data){
      if(data.code===0){ $('#ordersTable').bootstrapTable('refresh'); loadSummary(); }else{ layer.alert(data.msg,{icon:2}); }
    }, 'json');
  });
}
</script>
