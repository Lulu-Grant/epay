<?php
/**
 * 支付通道轮询设置
**/
include("../includes/common.php");
$title='支付通道轮询设置';
include './head.php';
if($islogin==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");
?>
<style>
.form-inline .form-control{display:inline-block;width:auto;vertical-align:middle}
.roll-modal-body{max-height:calc(100vh - 140px);overflow:auto;padding-bottom:18px}
.roll-editor-actions{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0}
.roll-row{display:grid!important;grid-template-columns:minmax(0,1fr) minmax(150px,.55fr) 40px;gap:8px;align-items:end;margin-bottom:12px}
.roll-row .form-control{width:100%!important;max-width:100%}
.roll-row .btn-remove{min-width:40px;height:34px}
.roll-field-label{display:block;margin-bottom:5px;font-weight:600}
.roll-warning-list{margin:10px 0;padding:10px 14px 10px 32px;border:1px solid #f0ad4e;border-radius:4px;background:#fff8e5}
.roll-field-error{margin:5px 0 0;color:#a94442}
.roll-table-note{margin:12px 0;color:#555}
.roll-summary{min-width:230px;line-height:1.65}
.roll-summary__item{display:block;overflow-wrap:anywhere}
.roll-summary__warning{color:#a94442;font-weight:600}
.roll-status,.roll-actions .btn{white-space:nowrap}
.roll-actions{min-width:190px}
@media(max-width:480px){.roll-row{grid-template-columns:minmax(0,1fr) 44px}.roll-row__weight{grid-column:1/2}.roll-row .btn-remove{grid-column:2;grid-row:1/3;height:100%}}
</style>
  <div class="container" style="padding-top:70px;">
    <div class="col-md-10 center-block" style="float: none;">
<?php

$paytype = [];
$type_select = '';
$rs = $DB->getAll("SELECT * FROM pre_type ORDER BY id ASC") ?: [];
foreach($rs as $row){
	$paytype[$row['id']] = $row['showname'];
	$type_select .= '<option value="'.$row['id'].'">'.$row['showname'].'</option>';
}
unset($rs);
$rolltype = ['顺序轮询','加权随机轮询','首个启用'];

$list = $DB->getAll("SELECT * FROM pre_roll ORDER BY id ASC") ?: [];
$channelMeta = [];
$channelRows = $DB->getAll("SELECT id,name,status FROM pre_channel ORDER BY id ASC") ?: [];
foreach($channelRows as $channelRow){
	$channelMeta[(int)$channelRow['id']] = ['name'=>(string)$channelRow['name'], 'status'=>(int)$channelRow['status']];
}
function roll_h($value){
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function roll_summary($raw, $kind, $channelMeta){
	try{
		$items = \lib\RollConfig::parse($raw);
	}catch(\InvalidArgumentException $e){
		return '<span class="roll-summary__warning">配置格式异常，请打开配置检查</span>';
	}
	$parts = [];
	foreach($items as $item){
		$id = (int)$item['channel'];
		if(isset($channelMeta[$id])){
			$label = roll_h($channelMeta[$id]['name']).'（#'.$id.'）';
			if(!$channelMeta[$id]['status']) $label .= ' · 已停用';
		}else{
			$label = '通道 #'.$id.' · 已删除';
		}
		if((int)$kind === 1) $label .= ' · 权重 '.roll_h($item['weight']).((int)$item['weight'] === 0 ? '（不参与）' : '');
		$parts[] = '<span class="roll-summary__item">'.$label.'</span>';
	}
	return $parts ? implode('', $parts) : '<span class="roll-summary__warning">尚未配置通道</span>';
}
?>
<div class="modal" id="modal-store" role="dialog" aria-modal="true" aria-labelledby="modal-title" aria-hidden="true" data-backdrop="static">
	<div class="modal-dialog">
		<div class="modal-content animated flipInX">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal"><span
							aria-hidden="true">&times;</span><span
							class="sr-only">关闭</span></button>
				<h4 class="modal-title" id="modal-title">轮询组修改/添加</h4>
			</div>
			<div class="modal-body">
				<form class="form-horizontal" id="form-store">
					<input type="hidden" name="action" id="action"/>
					<input type="hidden" name="id" id="id"/>
					<input type="hidden" name="originalKind" id="originalKind"/>
					<input type="hidden" name="originalInfo" id="originalInfo"/>
					<input type="hidden" name="originalName" id="originalName"/>
					<input type="hidden" name="originalType" id="originalType"/>
					<div class="form-group">
						<label for="name" class="col-sm-2 control-label no-padding-right">显示名称</label>
						<div class="col-sm-10">
							<input type="text" class="form-control" name="name" id="name" placeholder="仅显示使用，不要与其他轮询组名称重复">
						</div>
					</div>
					<div class="form-group">
						<label for="type" class="col-sm-2 control-label">支付方式</label>
						<div class="col-sm-10">
							<select name="type" id="type" class="form-control">
								<option value="0">请选择支付方式</option><?php echo $type_select; ?>
							</select>
						</div>
					</div>
					<div class="form-group">
						<label for="kind" class="col-sm-2 control-label">轮询方式</label>
						<div class="col-sm-10">
							<select name="kind" id="kind" class="form-control">
							<option value="0">按顺序依次轮询</option><option value="1">按权重随机轮询</option><option value="2">仅使用第一个已启用的</option>
							</select>
						</div>
					</div>
					<div class="form-group hide">
						<label class="col-sm-2 control-label">支付通道</label>
						<div class="col-sm-10">
							<select id="channel" class="form-control">
							</select>
						</div>
					</div>
					<!--div class="form-group">
						<label class="col-sm-2 control-label">通道配置</label>
						<div class="col-sm-10">
							<dl class="fieldlist" data-name="list" data-listidx="0">
								<dd class="form-inline">
									<select name="list[][channel]" class="form-control">
									</select>
									<input type="text" name="list[][weight]" class="form-control" value="" size="10" placeholder="权重(1-99)">
									<span class="btn btn-sm btn-danger btn-remove"><i class="fa fa-times"></i></span>
								</dd>
								<dd>
									<a href="javascript:;" class="btn btn-sm btn-success pay-append"><i class="fa fa-plus"></i> 追加</a>
								</dd>
							</dl>
						</div>
					</div-->
				</form>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-white" data-dismiss="modal">关闭</button>
				<button type="button" class="btn btn-primary" id="store" onclick="save()">保存</button>
			</div>
			<div class="panel-footer">
          <span class="glyphicon glyphicon-info-sign"></span> 按顺序依次轮询不支持设置权重，按权重随机轮询支持设置每个通道的权重
        </div>
		</div>
	</div>
</div>

<div class="panel panel-info">
   <div class="panel-heading"><h3 class="panel-title">系统共有 <b><?php echo count($list);?></b> 个轮询组&nbsp;<span class="pull-right"><a href="javascript:addframe()" class="btn btn-default btn-xs"><i class="fa fa-plus"></i> 新增</a></span></h3></div>
      <p class="roll-table-note"><span class="glyphicon glyphicon-info-sign" aria-hidden="true"></span> 加权模式显示的是配置相对权重，实际候选通道还会受启停状态、限额等运行条件影响。</p>
      <div class="table-responsive">
        <table class="table table-striped">
          <thead><tr><th>ID</th><th>显示名称</th><th>支付方式</th><th>轮询方式</th><th>轮询规则</th><th>状态</th><th>操作</th></tr></thead>
          <tbody>
<?php
foreach($list as $res)
{
$rollId = (int)$res['id'];
$typeName = isset($paytype[$res['type']]) ? $paytype[$res['type']] : '未知方式 #'.(int)$res['type'];
$kindName = isset($rolltype[$res['kind']]) ? $rolltype[$res['kind']] : '未知模式';
echo '<tr><td><b>'.$rollId.'</b></td><td>'.roll_h($res['name']).'</td><td>'.roll_h($typeName).'</td><td>'.roll_h($kindName).'</td><td class="roll-summary">'.roll_summary($res['info'], $res['kind'], $channelMeta).'</td><td>'.($res['status']==1?'<button type="button" class="btn btn-xs btn-success roll-status" onclick="setStatus('.$rollId.',0)">已开启</button>':'<button type="button" class="btn btn-xs btn-warning roll-status" onclick="setStatus('.$rollId.',1)">已关闭</button>').'</td><td class="roll-actions"><button type="button" class="btn btn-xs btn-primary" onclick="editInfo('.$rollId.')">配置通道</button>&nbsp;<button type="button" class="btn btn-xs btn-info" onclick="editframe('.$rollId.')">编辑</button>&nbsp;<button type="button" class="btn btn-xs btn-danger" onclick="delItem('.$rollId.')">删除</button></td></tr>';
}
?>
          </tbody>
        </table>
      </div>
	</div>
    </div>
  </div>
<script src="<?php echo $cdnpublic?>layer/3.1.1/layer.min.js"></script>
<script>
function addframe(){
	$("#modal-store").modal('show');
	$("#modal-title").text("新增轮询组");
	$("#action").val("add");
	$("#id").val('');
	$("#name").val('');
	$("#type").val(0);
	$("#kind").val(0);
	$("#originalKind").val('');
	$("#originalInfo").val('');
	$("#originalName").val('');
	$("#originalType").val('');
}
function editframe(id){
	var ii = layer.load(2, {shade:[0.1,'#fff']});
	$.ajax({
		type : 'GET',
		url : 'ajax_pay.php?act=getRoll&id='+id,
		dataType : 'json',
		success : function(data) {
			layer.close(ii);
			if(data.code == 0){
				$("#modal-store").modal('show');
				$("#modal-title").text("修改轮询组 #"+data.data.id+"："+data.data.name);
				$("#action").val("edit");
				$("#id").val(data.data.id);
				$("#name").val(data.data.name);
				$("#type").val(data.data.type);
				$("#kind").val(data.data.kind);
				$("#originalKind").val(data.data.kind);
				$("#originalInfo").val(data.data.info);
				$("#originalName").val(data.data.name);
				$("#originalType").val(data.data.type);
			}else{
				layer.alert(data.msg, {icon: 2})
			}
		},
		error:function(data){
			layer.msg('服务器错误');
			return false;
		}
	});
}
function save(){
	if($("#name").val()==''){
		layer.alert('请确保各项不能为空！');return false;
	}
	if($("#type").val()==0){
		layer.alert('请选择支付方式！');return false;
	}
	var $store = $("#store");
	if($store.prop('disabled')) return false;
	var originalText = $store.text();
	$store.prop('disabled', true).text('保存中…');
	var ii = layer.load(2, {shade:[0.1,'#fff']});
	$.ajax({
		type : 'POST',
		url : 'ajax_pay.php?act=saveRoll',
		data : $("#form-store").serialize(),
		dataType : 'json',
		success : function(data) {
			layer.close(ii);
			if(data.code == 0){
				layer.alert(data.msg,{
					icon: 1,
					closeBtn: false
				}, function(){
				  window.location.reload()
				});
			}else{
				$store.prop('disabled', false).text(originalText);
				layer.alert(data.msg, {icon: 2})
			}
		},
		error:function(data){
			layer.close(ii);
			$store.prop('disabled', false).text(originalText);
			layer.msg('服务器错误');
			return false;
		}
	});
}
function delItem(id) {
	var confirmobj = layer.confirm('你确实要删除此轮询组吗？', {
	  btn: ['确定','取消'], icon:0
	}, function(){
	  $.ajax({
		type : 'GET',
		url : 'ajax_pay.php?act=delRoll&id='+id,
		dataType : 'json',
		success : function(data) {
			if(data.code == 0){
				window.location.reload()
			}else{
				layer.alert(data.msg, {icon: 2});
			}
		},
		error:function(data){
			layer.msg('服务器错误');
			return false;
		}
	  });
	}, function(){
	  layer.close(confirmobj);
	});
}
function setStatus(id,status) {
	$.ajax({
		type : 'GET',
		url : 'ajax_pay.php?act=setRoll&id='+id+'&status='+status,
		dataType : 'json',
		success : function(data) {
			if(data.code == 0){
				window.location.reload()
			}else{
				layer.msg(data.msg, {icon:2, time:1500});
			}
		},
		error:function(data){
			layer.msg('服务器错误');
			return false;
		}
	});
}
var rollRowIndex = 0;
var rollPhpIntMax = '<?php echo PHP_INT_MAX;?>';
function addRollRow(channel, weight, legacyZero){
	var index = rollRowIndex++;
	var channelId = 'roll-channel-' + index;
	var weightId = 'roll-weight-' + index;
	var errorId = 'roll-weight-error-' + index;
	var row = $('<dd class="form-inline roll-row"></dd>').attr('data-row-index', index);
	var channelWrap = $('<div class="roll-row__channel"></div>');
	var weightWrap = $('<div class="roll-row__weight"></div>');
	var select = $('<select class="form-control roll-channel"></select>').attr({name:'list['+index+'][channel]',id:channelId});
	select.append($('#channel option').clone());
	if(channel != null) select.val(String(channel));
	var input = $('<input type="text" inputmode="numeric" autocomplete="off" class="form-control roll-weight" placeholder="例如 100">')
		.attr({name:'list['+index+'][weight]',id:weightId,pattern:'(?:0|[1-9][0-9]*)','aria-describedby':'roll-weight-note '+errorId})
		.val(weight == null ? '1' : String(weight))
		.attr('data-original-weight', weight == null ? '' : String(weight))
		.attr('data-legacy-zero', legacyZero ? '1' : '0');
	channelWrap.append($('<label class="roll-field-label"></label>').attr('for',channelId).text('支付通道'), select);
	weightWrap.append($('<label class="roll-field-label"></label>').attr('for',weightId).text('相对权重'), input, $('<p class="roll-field-error" hidden></p>').attr('id',errorId));
	row.append(channelWrap, weightWrap, $('<button type="button" class="btn btn-sm btn-danger btn-remove" aria-label="移除这个通道"><i class="fa fa-times" aria-hidden="true"></i></button>'));
	$('#form-info .fieldlist').append(row);
	return row;
}
function compareDecimalStrings(left, right){
	if(left.length !== right.length) return left.length < right.length ? -1 : 1;
	return left === right ? 0 : (left < right ? -1 : 1);
}
function validateRollWeights(){
	var firstInvalid = null;
	$('#form-info .roll-weight').each(function(){
		var input = $(this);
		var value = input.val();
		var message = '';
		if(!/^(0|[1-9][0-9]*)$/.test(value)) message = '请输入不带符号、小数或前导零的十进制整数。';
		else if(value === '0' && !(input.attr('data-legacy-zero') === '1' && input.attr('data-original-weight') === '0')) message = '新增或修改的权重必须大于 0。';
		else if(compareDecimalStrings(value, rollPhpIntMax) > 0) message = '权重超出当前服务器整数上限 '+rollPhpIntMax+'。';
		var error = $('#'+input.attr('aria-describedby').split(' ').pop());
		input.attr('aria-invalid', message ? 'true' : 'false');
		error.text(message).prop('hidden', !message);
		if(message && !firstInvalid) firstInvalid = input;
	});
	if(firstInvalid){ firstInvalid.trigger('focus'); return false; }
	return true;
}
function editInfo(id){
	var ii = layer.load(2, {shade:[0.1,'#fff']});
	$("#channel").empty();
	$.ajax({
		type : 'GET',
		url : 'ajax_pay.php?act=rollInfo&id='+id,
		dataType : 'json',
		success : function(data) {
			layer.close(ii);
			if(data.code == 0){
				$.each(data.channels, function (i, res) {
					$("#channel").append($('<option></option>').val(res.id).text(res.name));
				})
				var item = '<div class="modal-body roll-modal-body"><form class="form" id="form-info" novalidate>'+
					'<input type="hidden" name="originalKind"><input type="hidden" name="originalInfo"><input type="hidden" name="originalType">'+
					'<div id="roll-warnings" role="status" aria-live="polite"></div><dl class="fieldlist"></dl><div class="roll-editor-actions"><button type="button" class="btn btn-sm btn-success pay-append">追加通道</button> '+
					'<button type="button" class="btn btn-sm btn-default roll-edit-weight">配置将来权重</button></div>'+
					'<p class="help-block" id="roll-weight-note"></p><button type="button" id="save" onclick="saveInfo('+id+')" class="btn btn-primary btn-block">保存</button></form></div>';
				var area = Math.min(620, Math.max(288, window.innerWidth - 32))+'px';
				layer.open({
				  type: 1,
				  area: area,
				  title: '配置轮询组 #'+data.id+'：'+$('<div>').text(data.name).html(),
				  skin: 'layui-layer-rim',
				  content: item,
				  success: function(layero){
					  layero.attr({'role':'dialog','aria-modal':'true','aria-labelledby':'roll-dialog-title'});
					  layero.find('.layui-layer-title').attr('id','roll-dialog-title');
					  layero.find('.layui-layer-close').attr('aria-label','关闭轮询配置');
					  rollRowIndex = 0;
					  $('#form-info input[name="originalKind"]').val(data.kind);
					  $('#form-info input[name="originalInfo"]').val(data.originalInfo);
					  $('#form-info input[name="originalType"]').val(data.type);
					  if(data.warnings && data.warnings.length){
						$('#roll-warnings').html($('<ul class="roll-warning-list"></ul>').append($.map(data.warnings,function(message){return $('<li></li>').text(message)[0];})));
					  }
					  $.each(data.info || [], function(i, res){ addRollRow(res.channel, res.weight, res.legacyZero); });
					  if(!data.info || !data.info.length) addRollRow(null, 1);
					  if(data.readOnly){
						$('#form-info :input').not('[type=hidden]').prop('disabled', true);
						$('#roll-weight-note').text('当前配置只读，未发生任何写入。');
					  }else if(data.kind != 1){
						$('#roll-weight-note').text('当前模式不使用权重；权重是相对份额，显式开启编辑后保存，将在切换到加权随机后使用。最大值 '+rollPhpIntMax+'。');
						$('#form-info .roll-weight').prop('readonly', true);
					  }else{
						$('#roll-weight-note').text('权重是相对份额，不是流量百分比；运行时还会过滤停用、限额等不可用通道。请输入 1 到 '+rollPhpIntMax+' 的整数。');
						$('#form-info .roll-edit-weight').hide();
					  }
					  window.setTimeout(function(){
						var target = data.readOnly ? layero.find('.layui-layer-close') : $('#form-info .roll-channel').first();
						target.trigger('focus');
					  }, 0);
				  }
				});
			}else{
				layer.alert(data.msg, {icon: 2})
			}
		},
		error:function(data){
			layer.msg('服务器错误');
			return false;
		}
	});
}
function saveInfo(id){
	if(!validateRollWeights()) return false;
	$('#save').prop('disabled', true).text('保存中…');
	var ii = layer.load(2, {shade:[0.1,'#fff']});
	$.ajax({
		type : 'POST',
		url : 'ajax_pay.php?act=saveRollInfo&id='+id,
		data : $("#form-info").serialize(),
		dataType : 'json',
			success : function(data) {
			layer.close(ii);
			$('#save').prop('disabled', false).text('保存');
			if(data.code == 0){
				if(data.msg === '轮询组配置未变化'){
					layer.msg(data.msg);
					return;
				}
				layer.alert(data.msg,{
					icon: 1,
					closeBtn: false
				}, function(){
				  window.location.reload()
				});
			}else{
				layer.alert(data.msg, {icon: 2})
			}
		},
		error:function(data){
			$('#save').prop('disabled', false).text('保存');
			layer.msg('服务器错误');
			return false;
		}
	});
}
$(document).on("click", ".pay-append", function (e) {
	e.preventDefault();
	var row = addRollRow(null, 1);
	if($('#form-info input[name="originalKind"]').val() != '1' && !$('#form-info').data('weightEditing')) row.find('.roll-weight').prop('readonly', true);
	row.find('.roll-channel').trigger('focus');
});
$(document).on("click", "dd .btn-remove", function () {
	var row = $(this).closest('dd');
	var focusTarget = row.next('.roll-row').find('.roll-channel').first();
	if(!focusTarget.length) focusTarget = row.prev('.roll-row').find('.roll-channel').first();
	row.remove();
	if(focusTarget.length) focusTarget.trigger('focus'); else $('.pay-append').first().trigger('focus');
});
$(document).on('click', '.roll-edit-weight', function(){
	$('#form-info').data('weightEditing', true).find('.roll-weight').prop('readonly', false);
	$(this).hide();
});
$(document).on('input', '#form-info .roll-weight', function(){
	$(this).attr('aria-invalid','false');
	$('#'+$(this).attr('aria-describedby').split(' ').pop()).prop('hidden',true).text('');
});
</script>
