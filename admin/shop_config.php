<?php
/**
 * 商城配置
**/
include("../includes/common.php");
$title = '商城配置';
include './head.php';
if ($islogin == 1) {
} else {
    exit("<script language='javascript'>window.location.href='./login.php';</script>");
}

if (empty($_SESSION['shop_csrf_token'])) {
    $_SESSION['shop_csrf_token'] = random(32);
}
$csrf_token = $_SESSION['shop_csrf_token'];
$shop_config = \lib\Shop\ConfigService::all();
$shop_excluded_uids = \lib\Shop\ConfigService::getExcludedUids();

function shop_admin_html($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<div class="container" style="padding-top:70px;">
  <div class="col-md-8 center-block" style="float:none;">
    <div class="panel panel-primary">
      <div class="panel-heading"><h3 class="panel-title">商城配置</h3></div>
      <div class="panel-body">
        <form class="form-horizontal" id="shop-config-form">
          <input type="hidden" name="csrf_token" value="<?php echo shop_admin_html($csrf_token);?>">
          <div class="form-group">
            <label class="col-sm-3 control-label">商城开关</label>
            <div class="col-sm-9">
              <select name="shop_status" class="form-control">
                <option value="0" <?php echo intval($shop_config['shop_status']) === 0 ? 'selected' : '';?>>关闭</option>
                <option value="1" <?php echo intval($shop_config['shop_status']) === 1 ? 'selected' : '';?>>开启</option>
              </select>
              <p class="help-block">开启后，除排除清单外的普通商户订单会创建商城附属记录。</p>
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">订单流程</label>
            <div class="col-sm-9">
              <select name="shop_flow_mode" class="form-control">
                <option value="shadow" <?php echo $shop_config['shop_flow_mode'] === \lib\Shop\ConfigService::FLOW_SHADOW ? 'selected' : '';?>>无感影子订单（推荐）</option>
                <option value="checkout" <?php echo $shop_config['shop_flow_mode'] === \lib\Shop\ConfigService::FLOW_CHECKOUT ? 'selected' : '';?>>购买确认页（回滚模式）</option>
              </select>
              <p class="help-block">无感模式只在后台记录商品和履约状态，用户继续走原支付流程；回滚模式会恢复购买确认页。</p>
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">商城名称</label>
            <div class="col-sm-9">
              <input type="text" class="form-control" name="shop_name" value="<?php echo shop_admin_html($shop_config['shop_name']);?>">
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">商城描述</label>
            <div class="col-sm-9">
              <textarea class="form-control" name="shop_desc" rows="3"><?php echo shop_admin_html($shop_config['shop_desc']);?></textarea>
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">排除商户 UID</label>
            <div class="col-sm-9">
              <textarea class="form-control" name="shop_excluded_uids" rows="5" placeholder="例如：1003,1008"><?php echo shop_admin_html(implode(',', $shop_excluded_uids));?></textarea>
              <p class="help-block">多个 UID 使用逗号、空格或换行分隔。清单内商户不创建商城附属记录。</p>
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">订单归属</label>
            <div class="col-sm-9">
              <p class="form-control-static">沿用原订单商户、金额、商品名、通道配置和回调地址</p>
              <p class="help-block">商城只增加随机商品快照和履约记录，不改变资金与通知链路。</p>
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">订单查询校验</label>
            <div class="col-sm-9">
              <input type="hidden" name="shop_query_verify" value="1">
              <p class="form-control-static">已启用（订单号还需查询凭证或联系方式后四位）</p>
            </div>
          </div>
          <div class="form-group">
            <div class="col-sm-offset-3 col-sm-9">
              <button type="button" class="btn btn-primary" onclick="saveConfig()">保存配置</button>
              <a href="./shop_goods.php" class="btn btn-default">商品管理</a>
              <a href="./shop_orders.php" class="btn btn-default">订单管理</a>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<script src="<?php echo $cdnpublic?>layer/3.1.1/layer.min.js"></script>
<script>
function saveConfig(){
  var ii = layer.load(2, {shade:[0.1,'#fff']});
  $.ajax({
    type: 'POST',
    url: 'ajax_shop.php?act=saveConfig',
    data: $('#shop-config-form').serialize(),
    dataType: 'json',
    success: function(data){
      layer.close(ii);
      if(data.code === 0){
        layer.msg(data.msg, {icon:1, time:1200});
      }else{
        layer.alert(data.msg, {icon:2});
      }
    },
    error: function(){
      layer.close(ii);
      layer.msg('服务器错误');
    }
  });
}
</script>
