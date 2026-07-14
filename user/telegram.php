<?php
include("../includes/common.php");
if($islogin2==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");

if(empty($conf['addon_telegram']) || intval($conf['addon_telegram']) < 1100){
	\lib\Telegram\Installer::install();
	$conf=$CACHE->pre_fetch();
}

$title='Telegram通知';
include './head.php';

function telegram_user_h($value){
	return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$service = new \lib\Telegram\BotService($DB, $conf);
$bind = $DB->find('telegram_bind', '*', ['uid'=>$uid, 'status'=>1], null, 1);
$code = $service->getActiveBindCode($uid);
$botName = !empty($conf['telegram_bot_name']) ? $conf['telegram_bot_name'] : '';
?>
<div id="content" class="app-content" role="main">
	<div class="app-content-body">
		<div class="bg-light lter b-b wrapper-md">
			<h1 class="m-n font-thin h3">Telegram 通知</h1>
			<small class="text-muted">绑定后可在 Telegram 接收订单通知并查询订单统计</small>
		</div>
		<div class="wrapper-md">
			<div class="panel panel-default">
				<div class="panel-heading">绑定状态</div>
				<div class="panel-body">
					<?php if($bind){ ?>
						<div class="alert alert-success">
							已绑定 Telegram Chat ID：<code><?php echo telegram_user_h($bind['chat_id']);?></code><br>
							绑定时间：<?php echo telegram_user_h($bind['bindtime']);?>
						</div>
						<button class="btn btn-warning" onclick="unbindTelegram()">解除绑定</button>
					<?php }else{ ?>
						<div class="alert alert-info">当前未绑定 Telegram。请先生成一次性绑定码，然后在机器人中发送 <code>/bind 绑定码</code>。</div>
						<?php if($code){ ?>
							<p>当前有效绑定码：</p>
							<h2><code id="bindCode"><?php echo telegram_user_h($code['code']);?></code></h2>
							<p class="text-muted">过期时间：<?php echo telegram_user_h($code['expiretime']);?></p>
						<?php }else{ ?>
							<p class="text-muted">暂无有效绑定码。</p>
						<?php } ?>
						<button class="btn btn-primary" onclick="createBindCode()">生成绑定码</button>
						<?php if($botName){ ?><a class="btn btn-default" href="https://t.me/<?php echo telegram_user_h(ltrim($botName, '@'));?>" target="_blank">打开机器人</a><?php } ?>
					<?php } ?>
				</div>
			</div>
			<div class="panel panel-default">
				<div class="panel-heading">可用命令</div>
				<div class="panel-body">
					<table class="table table-bordered">
						<tr><th>命令</th><th>说明</th></tr>
						<tr><td><code>/start</code></td><td>显示菜单</td></tr>
						<tr><td><code>/bind 绑定码</code></td><td>绑定当前商户</td></tr>
						<tr><td><code>/today</code></td><td>查看今日统计</td></tr>
						<tr><td><code>/yesterday</code></td><td>查看昨日统计</td></tr>
						<tr><td><code>/order 订单号</code></td><td>查询自己的订单</td></tr>
						<tr><td><code>/settings</code></td><td>设置通知开关</td></tr>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>
<?php include './foot.php';?>
<script src="<?php echo $cdnpublic?>layer/3.1.1/layer.min.js"></script>
<script>
function createBindCode(){
	var ii = layer.load(2, {shade:[0.1,'#fff']});
	$.post('ajax_telegram.php?act=createBindCode', {}, function(data){
		layer.close(ii);
		if(data.code == 0){
			layer.alert('绑定码：'+data.data.code+'<br>过期时间：'+data.data.expiretime, {icon:1}, function(){ window.location.reload(); });
		}else{
			layer.alert(data.msg, {icon:2});
		}
	}, 'json').fail(function(){ layer.close(ii); layer.msg('服务器错误'); });
}
function unbindTelegram(){
	layer.confirm('确定解除 Telegram 绑定？', function(){
		$.post('ajax_telegram.php?act=unbind', {}, function(data){
			if(data.code == 0) layer.alert(data.msg, {icon:1}, function(){ window.location.reload(); });
			else layer.alert(data.msg, {icon:2});
		}, 'json');
	});
}
</script>
