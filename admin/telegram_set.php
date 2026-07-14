<?php
/**
 * Telegram 通知设置
**/
include("../includes/common.php");
if($islogin==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");

if(empty($conf['addon_telegram']) || intval($conf['addon_telegram']) < 1101){
	\lib\Telegram\Installer::install();
	$conf=$CACHE->pre_fetch();
}

$title='Telegram通知设置';
include './head.php';

function telegram_h($value){
	return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function telegram_mask_token($value){
	$value = (string)$value;
	if($value === '') return '未配置';
	if(strlen($value) <= 12) return '已配置';
	return substr($value, 0, 6).'***'.substr($value, -4);
}

$errmsg = '';
$arr = $CACHE->read('telegramerrmsg');
if($arr){
	$errmsg = $arr['time'].' - '.$arr['errmsg'];
}

$stats = [0=>0, 1=>0, 2=>0];
$statsRows = $DB->getAll("SELECT status,COUNT(*) count FROM pre_telegram_notify_queue GROUP BY status");
if($statsRows){
	foreach($statsRows as $row){
		$stats[intval($row['status'])] = intval($row['count']);
	}
}
$binds = $DB->getAll("SELECT t.*,u.account,u.username,u.email,u.phone FROM pre_telegram_bind t LEFT JOIN pre_user u ON t.uid=u.uid ORDER BY t.id DESC LIMIT 100");
$workerStatus = $CACHE->read('telegram_worker_status');
$workerStatus = $workerStatus ? @unserialize($workerStatus) : [];
if(!is_array($workerStatus))$workerStatus = [];
$lastUpdateId = (new \lib\Telegram\BotService($DB, $conf))->getLastUpdateId();
?>
<div class="container" style="padding-top:70px;">
	<div class="col-md-12 center-block" style="float:none;">
		<div class="panel panel-primary">
			<div class="panel-heading"><h3 class="panel-title">Telegram 基础配置</h3></div>
			<div class="panel-body">
				<?php if($errmsg){?><div class="alert alert-warning">上一次报错信息：<?php echo telegram_h($errmsg);?></div><?php }?>
				<form onsubmit="return saveSetting(this)" method="post" class="form-horizontal" role="form">
					<div class="form-group">
						<label class="col-sm-2 control-label">Telegram通知开关</label>
						<div class="col-sm-10"><select class="form-control" name="telegram_notice" default="<?php echo telegram_h($conf['telegram_notice'])?>"><option value="0">关闭</option><option value="1">开启</option></select></div>
					</div>
					<div class="form-group">
						<label class="col-sm-2 control-label">Bot Token</label>
						<div class="col-sm-10">
							<input type="text" name="telegram_bot_token" value="" class="form-control" placeholder="<?php echo telegram_h(telegram_mask_token($conf['telegram_bot_token'])); ?>，留空不修改"/>
							<p class="help-block">为避免页面完整暴露 Token，此处留空表示不修改；需要更换时重新粘贴新 Token。</p>
						</div>
					</div>
					<div class="form-group">
						<label class="col-sm-2 control-label">管理员 Chat ID</label>
						<div class="col-sm-10">
							<input type="text" name="telegram_admin_chat_id" value="<?php echo telegram_h($conf['telegram_admin_chat_id']); ?>" class="form-control" placeholder="管理员个人 Chat ID"/>
							<p class="help-block">管理员菜单仅允许该个人 Chat ID 的私聊使用，不建议填写群组 Chat ID。</p>
						</div>
					</div>
					<div class="form-group">
						<label class="col-sm-2 control-label">机器人用户名</label>
						<div class="col-sm-10"><input type="text" name="telegram_bot_name" value="<?php echo telegram_h(isset($conf['telegram_bot_name']) ? $conf['telegram_bot_name'] : ''); ?>" class="form-control" placeholder="@your_bot_name"/></div>
					</div>
					<hr>
					<div class="form-group">
						<label class="col-sm-2 control-label">Telegram专用代理</label>
						<div class="col-sm-10">
							<select class="form-control" name="telegram_proxy" default="<?php echo telegram_h(isset($conf['telegram_proxy']) ? $conf['telegram_proxy'] : 0); ?>"><option value="0">关闭</option><option value="1">开启</option></select>
							<p class="help-block">仅 Telegram Bot API 使用，不影响支付通道、商户异步通知和其他网络请求。</p>
						</div>
					</div>
					<div class="form-group">
						<label class="col-sm-2 control-label">代理地址</label>
						<div class="col-sm-10"><input type="text" name="telegram_proxy_server" value="<?php echo telegram_h(isset($conf['telegram_proxy_server']) ? $conf['telegram_proxy_server'] : ''); ?>" class="form-control" placeholder="代理主机名或IP，不要填写协议前缀"/></div>
					</div>
					<div class="form-group">
						<label class="col-sm-2 control-label">代理端口</label>
						<div class="col-sm-10"><input type="number" name="telegram_proxy_port" min="1" max="65535" value="<?php echo telegram_h(isset($conf['telegram_proxy_port']) ? $conf['telegram_proxy_port'] : ''); ?>" class="form-control" placeholder="1080"/></div>
					</div>
					<div class="form-group">
						<label class="col-sm-2 control-label">代理账号</label>
						<div class="col-sm-10"><input type="text" name="telegram_proxy_user" value="<?php echo telegram_h(isset($conf['telegram_proxy_user']) ? $conf['telegram_proxy_user'] : ''); ?>" class="form-control" autocomplete="off"/></div>
					</div>
					<div class="form-group">
						<label class="col-sm-2 control-label">代理密码</label>
						<div class="col-sm-10">
							<input type="password" name="telegram_proxy_pwd" value="" class="form-control" autocomplete="new-password" placeholder="<?php echo !empty($conf['telegram_proxy_pwd']) ? '已配置，留空不修改' : '未配置'; ?>"/>
							<p class="help-block">密码不会在页面中回显；留空时保留原密码。</p>
						</div>
					</div>
					<div class="form-group">
						<label class="col-sm-2 control-label">代理协议</label>
						<div class="col-sm-10">
							<select class="form-control" name="telegram_proxy_type" default="<?php echo telegram_h(isset($conf['telegram_proxy_type']) ? $conf['telegram_proxy_type'] : 'sock5h'); ?>">
								<option value="sock5h">SOCKS5H（代理解析域名）</option>
								<option value="sock5">SOCKS5（本机解析域名）</option>
								<option value="http">HTTP</option>
								<option value="https">HTTPS</option>
								<option value="sock4">SOCKS4</option>
							</select>
						</div>
					</div>
					<div class="form-group">
						<div class="col-sm-offset-2 col-sm-10">
							<button type="submit" class="btn btn-primary">保存配置</button>
							<button type="button" class="btn btn-success" onclick="testAdmin()">发送测试消息</button>
							<button type="button" class="btn btn-info" onclick="processQueue()">立即处理队列</button>
							<button type="button" class="btn btn-warning" onclick="setCommands()">设置Bot命令</button>
							<button type="button" class="btn btn-default" onclick="clearTelegramError()">清理错误缓存</button>
						</div>
					</div>
				</form>
			</div>
		</div>

		<div class="panel panel-default">
			<div class="panel-heading"><h3 class="panel-title">长驻机器人状态</h3></div>
			<div class="panel-body">
				<div class="row">
					<div class="col-sm-3"><strong>运行状态：</strong><?php echo telegram_h(isset($workerStatus['state']) ? $workerStatus['state'] : '未上报');?></div>
					<div class="col-sm-3"><strong>进程 PID：</strong><?php echo telegram_h(isset($workerStatus['pid']) ? $workerStatus['pid'] : '-');?></div>
					<div class="col-sm-3"><strong>最后 Update ID：</strong><?php echo telegram_h($lastUpdateId);?></div>
					<div class="col-sm-3"><strong>上报时间：</strong><?php echo telegram_h(isset($workerStatus['time']) ? $workerStatus['time'] : '-');?></div>
				</div>
				<?php if(!empty($workerStatus['bot_username'])){?><p class="help-block">Bot：@<?php echo telegram_h($workerStatus['bot_username']);?></p><?php }?>
				<?php if(!empty($workerStatus['last_error'])){?><div class="alert alert-warning" style="margin-top:10px;">Worker 最近错误：<?php echo telegram_h($workerStatus['last_error']);?></div><?php }?>
			</div>
		</div>

		<div class="row">
			<div class="col-sm-4">
				<div class="panel panel-info">
					<div class="panel-heading">待发送队列</div>
					<div class="panel-body"><h3><?php echo $stats[0];?></h3></div>
				</div>
			</div>
			<div class="col-sm-4">
				<div class="panel panel-success">
					<div class="panel-heading">已发送记录</div>
					<div class="panel-body"><h3><?php echo $stats[1];?></h3></div>
				</div>
			</div>
			<div class="col-sm-4">
				<div class="panel panel-danger">
					<div class="panel-heading">发送失败记录</div>
					<div class="panel-body"><h3><?php echo $stats[2];?></h3></div>
				</div>
			</div>
		</div>

		<div class="panel panel-primary">
			<div class="panel-heading"><h3 class="panel-title">绑定商户 Telegram Chat ID</h3></div>
			<div class="panel-body">
				<form id="bindForm" class="form-inline" onsubmit="return saveBind(this)">
					<div class="form-group">
						<label>商户号</label>
						<input type="text" name="uid" class="form-control" placeholder="UID">
					</div>
					<div class="form-group">
						<label>Chat ID</label>
						<input type="text" name="chat_id" class="form-control" placeholder="Telegram Chat ID">
					</div>
					<label class="checkbox-inline"><input type="hidden" name="notify_order" value="0"><input type="checkbox" name="notify_order" value="1" checked>订单</label>
					<label class="checkbox-inline"><input type="hidden" name="notify_settle" value="0"><input type="checkbox" name="notify_settle" value="1" checked>结算</label>
					<label class="checkbox-inline"><input type="hidden" name="notify_login" value="0"><input type="checkbox" name="notify_login" value="1" checked>登录</label>
					<label class="checkbox-inline"><input type="hidden" name="notify_complain" value="0"><input type="checkbox" name="notify_complain" value="1" checked>投诉</label>
					<label class="checkbox-inline"><input type="hidden" name="notify_balance" value="0"><input type="checkbox" name="notify_balance" value="1" checked>余额</label>
					<button type="submit" class="btn btn-primary">保存绑定</button>
				</form>
			</div>
			<div class="table-responsive">
				<table class="table table-striped table-bordered table-hover">
					<thead>
						<tr>
							<th>ID</th>
							<th>Chat ID</th>
							<th>商户</th>
							<th>通知</th>
							<th>绑定时间</th>
							<th>状态</th>
							<th>操作</th>
						</tr>
					</thead>
					<tbody>
					<?php if($binds){ foreach($binds as $row){ ?>
						<tr>
							<td><?php echo intval($row['id']);?></td>
							<td><?php echo telegram_h($row['chat_id']);?></td>
							<td><a href="./ulist.php?column=uid&value=<?php echo intval($row['uid']);?>" target="_blank"><?php echo intval($row['uid']);?></a> <?php echo telegram_h($row['username'] ?: $row['account'] ?: $row['email'] ?: $row['phone']);?></td>
							<td>
								<?php echo $row['notify_order']?'订单 ':'';?>
								<?php echo $row['notify_settle']?'结算 ':'';?>
								<?php echo $row['notify_login']?'登录 ':'';?>
								<?php echo $row['notify_complain']?'投诉 ':'';?>
								<?php echo $row['notify_balance']?'余额 ':'';?>
							</td>
							<td><?php echo telegram_h($row['bindtime']);?></td>
							<td><?php echo $row['status']?'<span class="label label-success">正常</span>':'<span class="label label-default">已解绑</span>';?></td>
							<td>
								<?php if($row['status']){?><button class="btn btn-xs btn-warning" onclick="unbind(<?php echo intval($row['id']);?>)">解绑</button><?php }?>
								<button class="btn btn-xs btn-danger" onclick="deleteBind(<?php echo intval($row['id']);?>)">删除</button>
							</td>
						</tr>
					<?php }}else{ ?>
						<tr><td colspan="7" class="text-center">暂无绑定记录</td></tr>
					<?php } ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</div>
<script src="<?php echo $cdnpublic?>layer/3.1.1/layer.js"></script>
<script>
function saveSetting(obj){
	var ii = layer.load(2, {shade:[0.1,'#fff']});
	var data = $(obj).serializeArray().filter(function(item){
		return !((item.name == 'telegram_bot_token' || item.name == 'telegram_proxy_pwd') && item.value == '');
	});
	$.ajax({
		type: 'POST',
		url: 'ajax.php?act=set',
		data: $.param(data),
		dataType: 'json',
		success: function(data){
			layer.close(ii);
			if(data.code == 0){
				layer.alert('设置保存成功！', {icon:1, closeBtn:false}, function(){ window.location.reload(); });
			}else{
				layer.alert(data.msg, {icon:2});
			}
		},
		error: function(){
			layer.close(ii);
			layer.msg('服务器错误');
		}
	});
	return false;
}
function testAdmin(){
	var ii = layer.load(2, {shade:[0.1,'#fff']});
	$.post('ajax_telegram.php?act=testAdmin', {}, function(data){
		layer.close(ii);
		if(data.code == 0) layer.alert(data.msg, {icon:1});
		else layer.alert(data.msg, {icon:2});
	}, 'json').fail(function(){ layer.close(ii); layer.msg('服务器错误'); });
}
function processQueue(){
	var ii = layer.load(2, {shade:[0.1,'#fff']});
	$.post('ajax_telegram.php?act=processQueue', {}, function(data){
		layer.close(ii);
		if(data.code == 0) layer.alert(data.msg, {icon:1}, function(){ window.location.reload(); });
		else layer.alert(data.msg, {icon:2});
	}, 'json').fail(function(){ layer.close(ii); layer.msg('服务器错误'); });
}
function setCommands(){
	var ii = layer.load(2, {shade:[0.1,'#fff']});
	$.post('ajax_telegram.php?act=setCommands', {}, function(data){
		layer.close(ii);
		if(data.code == 0) layer.alert(data.msg, {icon:1});
		else layer.alert(data.msg, {icon:2});
	}, 'json').fail(function(){ layer.close(ii); layer.msg('服务器错误'); });
}
function clearTelegramError(){
	$.post('ajax_telegram.php?act=clearError', {}, function(data){
		if(data.code == 0) window.location.reload();
		else layer.alert(data.msg, {icon:2});
	}, 'json');
}
function saveBind(obj){
	var ii = layer.load(2, {shade:[0.1,'#fff']});
	$.ajax({
		type: 'POST',
		url: 'ajax_telegram.php?act=saveBind',
		data: $(obj).serialize(),
		dataType: 'json',
		success: function(data){
			layer.close(ii);
			if(data.code == 0) layer.alert(data.msg, {icon:1}, function(){ window.location.reload(); });
			else layer.alert(data.msg, {icon:2});
		},
		error: function(){ layer.close(ii); layer.msg('服务器错误'); }
	});
	return false;
}
function unbind(id){
	layer.confirm('确定解除该绑定？', function(){
		$.post('ajax_telegram.php?act=unbind', {id:id}, function(data){
			if(data.code == 0) window.location.reload();
			else layer.alert(data.msg, {icon:2});
		}, 'json');
	});
}
function deleteBind(id){
	layer.confirm('确定删除该绑定记录？', function(){
		$.post('ajax_telegram.php?act=deleteBind', {id:id}, function(data){
			if(data.code == 0) window.location.reload();
			else layer.alert(data.msg, {icon:2});
		}, 'json');
	});
}
$(document).ready(function(){
	var items = $('select[default]');
	for(i = 0; i < items.length; i++){
		$(items[i]).val($(items[i]).attr('default') || 0);
	}
});
</script>
