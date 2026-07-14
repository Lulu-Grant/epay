<?php
/**
 * 商城商品管理
**/
include("../includes/common.php");
$title = '商城商品管理';
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
.shop-thumb{width:42px;height:42px;object-fit:cover;border:1px solid #ddd;background:#f7f7f7}
.shop-toolbar .form-control{max-width:180px}
</style>
<div class="container-fluid" style="padding-top:70px;">
  <div class="col-md-12 center-block" style="float:none;">
    <div id="toolbar" class="shop-toolbar">
      <form class="form-inline" onsubmit="return searchGoods()">
        <div class="form-group">
          <input type="text" class="form-control" name="keyword" placeholder="商品名称">
        </div>
        <div class="form-group">
          <select class="form-control" name="status">
            <option value="-1">全部状态</option>
            <option value="1">上架</option>
            <option value="0">下架</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary">搜索</button>
        <button type="button" class="btn btn-default" onclick="resetGoods()">重置</button>
        <button type="button" class="btn btn-success" onclick="openGoodsModal(0)"><i class="fa fa-plus"></i> 新增商品</button>
        <a href="./shop_config.php" class="btn btn-default">商城配置</a>
        <a href="./shop_orders.php" class="btn btn-default">商城订单</a>
      </form>
    </div>
    <table id="goodsTable"></table>
  </div>
</div>

<div class="modal" id="goods-modal" role="dialog" aria-hidden="true" data-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title" id="goods-modal-title">商品</h4>
      </div>
      <div class="modal-body">
        <form class="form-horizontal" id="goods-form">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8');?>">
          <input type="hidden" name="id" id="goods-id">
          <div class="form-group">
            <label class="col-sm-2 control-label">名称</label>
            <div class="col-sm-10"><input type="text" class="form-control" name="name" id="goods-name"></div>
          </div>
          <div class="form-group">
            <label class="col-sm-2 control-label">展示价格</label>
            <div class="col-sm-10"><input type="text" class="form-control" name="price" id="goods-price" placeholder="0.00"><p class="help-block">仅作为展示数据保留，不参与原商户订单金额计算。</p></div>
          </div>
          <div class="form-group">
            <label class="col-sm-2 control-label">库存</label>
            <div class="col-sm-10"><input type="text" class="form-control" name="stock" id="goods-stock" value="-1"><p class="help-block">-1 表示不限库存。</p></div>
          </div>
          <div class="form-group">
            <label class="col-sm-2 control-label">图片</label>
            <div class="col-sm-10"><input type="text" class="form-control" name="image" id="goods-image" placeholder="/assets/img/logo.png"></div>
          </div>
          <div class="form-group">
            <label class="col-sm-2 control-label">排序</label>
            <div class="col-sm-10"><input type="text" class="form-control" name="sort" id="goods-sort" value="0"></div>
          </div>
          <div class="form-group">
            <label class="col-sm-2 control-label">状态</label>
            <div class="col-sm-10">
              <select class="form-control" name="status" id="goods-status">
                <option value="1">上架</option>
                <option value="0">下架</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-2 control-label">描述</label>
            <div class="col-sm-10"><textarea class="form-control" name="description" id="goods-description" rows="4"></textarea></div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">关闭</button>
        <button type="button" class="btn btn-primary" onclick="saveGoods()">保存</button>
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
function queryParams(params){
  var form = $('#toolbar form').serializeArray();
  $.each(form, function(_, item){ params[item.name] = item.value; });
  return params;
}
$(function(){
  $('#goodsTable').bootstrapTable({
    url: 'ajax_shop.php?act=goodsList',
    method: 'post',
    toolbar: '#toolbar',
    sidePagination: 'server',
    pagination: true,
    pageSize: 20,
    queryParams: queryParams,
    classes: 'table table-striped table-hover table-bordered',
    columns: [
      {field:'id', title:'ID', width:60},
      {field:'image', title:'图片', formatter:function(value){return value ? '<img src="'+escapeHtml(value)+'" class="shop-thumb">' : '';}},
      {field:'name', title:'商品名称', formatter:function(value,row){return '<b>'+escapeHtml(value)+'</b><br><small>'+escapeHtml(row.description || '')+'</small>';}},
      {field:'price', title:'展示价格', formatter:function(value){return parseFloat(value) <= 0 ? '<span class="label label-default">不显示</span>' : '￥'+escapeHtml(value);}},
      {field:'stock', title:'库存', formatter:function(value){return parseInt(value,10) === -1 ? '不限' : escapeHtml(value);}},
      {field:'sort', title:'排序'},
      {field:'status', title:'状态', formatter:function(value){return parseInt(value,10) === 1 ? '<span class="label label-success">上架</span>' : '<span class="label label-default">下架</span>';}},
      {field:'addtime', title:'创建时间'},
      {field:'id', title:'操作', formatter:function(value,row){
        var nextStatus = parseInt(row.status,10) === 1 ? 0 : 1;
        var statusText = nextStatus === 1 ? '上架' : '下架';
        return '<button class="btn btn-xs btn-info" onclick="openGoodsModal('+value+')">编辑</button> '+
          '<button class="btn btn-xs btn-warning" onclick="setGoodsStatus('+value+','+nextStatus+')">'+statusText+'</button> '+
          '<button class="btn btn-xs btn-danger" onclick="deleteGoods('+value+')">删除</button>';
      }}
    ]
  });
});
function searchGoods(){ $('#goodsTable').bootstrapTable('refresh', {pageNumber:1}); return false; }
function resetGoods(){ $('#toolbar form')[0].reset(); searchGoods(); }
function openGoodsModal(id){
  $('#goods-form')[0].reset();
  $('#goods-id').val(id || '');
  $('#goods-stock').val('-1');
  $('#goods-sort').val('0');
  $('#goods-status').val('1');
  $('#goods-modal-title').text(id ? '编辑商品' : '新增商品');
  if(!id){ $('#goods-modal').modal('show'); return; }
  $.getJSON('ajax_shop.php?act=getGoods&id='+id, function(data){
    if(data.code !== 0){ layer.alert(data.msg, {icon:2}); return; }
    var row = data.data;
    $('#goods-name').val(row.name);
    $('#goods-price').val(row.price);
    $('#goods-stock').val(row.stock);
    $('#goods-image').val(row.image);
    $('#goods-sort').val(row.sort);
    $('#goods-status').val(row.status);
    $('#goods-description').val(row.description);
    $('#goods-modal').modal('show');
  });
}
function saveGoods(){
  var ii = layer.load(2, {shade:[0.1,'#fff']});
  $.ajax({type:'POST', url:'ajax_shop.php?act=saveGoods', data:$('#goods-form').serialize(), dataType:'json',
    success:function(data){ layer.close(ii); if(data.code===0){ $('#goods-modal').modal('hide'); $('#goodsTable').bootstrapTable('refresh'); layer.msg(data.msg,{icon:1}); }else{ layer.alert(data.msg,{icon:2}); } },
    error:function(){ layer.close(ii); layer.msg('服务器错误'); }
  });
}
function setGoodsStatus(id,status){
  $.post('ajax_shop.php?act=setGoodsStatus', {id:id,status:status,csrf_token:shopCsrfToken}, function(data){
    if(data.code===0){ $('#goodsTable').bootstrapTable('refresh'); }else{ layer.alert(data.msg,{icon:2}); }
  }, 'json');
}
function deleteGoods(id){
  layer.confirm('确认删除该商品？删除后前台不可见。', {icon:0}, function(index){
    layer.close(index);
    $.post('ajax_shop.php?act=deleteGoods', {id:id,csrf_token:shopCsrfToken}, function(data){
      if(data.code===0){ $('#goodsTable').bootstrapTable('refresh'); }else{ layer.alert(data.msg,{icon:2}); }
    }, 'json');
  });
}
</script>
