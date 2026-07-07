<?php
/**
 * 收入统计看板
**/
include("../includes/common.php");
$title='收入统计看板';
include './head.php';
if($islogin==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");
?>
<style>
.income-stat{padding-top:70px;padding-bottom:30px;}
.income-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;}
.income-toolbar h3{margin:0;font-size:20px;font-weight:600;}
.income-meta{color:#777;font-size:12px;margin-top:4px;}
.income-actions{white-space:nowrap;}
.income-card .panel-heading{display:flex;align-items:center;justify-content:space-between;}
.income-card .panel-title{font-size:16px;font-weight:600;}
.income-card .table{margin-bottom:0;}
.income-card th,.income-card td{white-space:nowrap;vertical-align:middle!important;}
.income-card th:first-child,.income-card td:first-child{position:sticky;left:0;background:#fff;z-index:1;}
.income-card thead th:first-child{background:#f5f5f5;z-index:2;}
.income-loading,.income-empty{padding:22px;text-align:center;color:#777;}
.income-total{font-weight:600;color:#222;}
@media (max-width: 767px){
	.income-stat{padding-top:60px;}
	.income-toolbar{align-items:flex-start;}
	.income-toolbar h3{font-size:18px;}
	.income-card{margin-left:-1px;margin-right:-1px;}
	.income-card .panel-heading{padding:10px 12px;}
	.income-card .panel-title{font-size:15px;}
	.income-card th,.income-card td{font-size:13px;padding:9px 10px!important;}
}
</style>
<div class="container income-stat">
	<div class="income-toolbar">
		<div>
			<h3>收入统计看板</h3>
			<div class="income-meta" id="incomeMeta">正在加载</div>
		</div>
		<div class="income-actions">
			<button type="button" class="btn btn-primary btn-sm" id="refreshBtn">
				<i class="fa fa-refresh"></i> 刷新
			</button>
		</div>
	</div>
	<div id="incomeStatBody">
		<div class="panel panel-default">
			<div class="income-loading">正在加载数据</div>
		</div>
	</div>
</div>
<script src="<?php echo $cdnpublic?>layer/3.1.1/layer.min.js"></script>
<script>
(function(){
	var panelClasses = ['panel-success', 'panel-warning', 'panel-info', 'panel-default'];

	function escapeHtml(value){
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function setLoading(){
		$('#incomeStatBody').html('<div class="panel panel-default"><div class="income-loading">正在加载数据</div></div>');
		$('#incomeMeta').text('正在加载');
	}

	function showError(msg){
		$('#incomeStatBody').html('<div class="panel panel-danger"><div class="income-empty">'+escapeHtml(msg)+'</div></div>');
		if(window.layer){
			layer.alert(msg, {icon: 2});
		}
	}

	function renderModule(module, index){
		var panelClass = panelClasses[index % panelClasses.length];
		var html = '';
		html += '<div class="panel '+panelClass+' income-card">';
		html += '<div class="panel-heading"><h3 class="panel-title">'+escapeHtml(module.title)+'</h3><i class="fa fa-table"></i></div>';
		html += '<div class="table-responsive"><table class="table table-bordered table-striped table-hover">';
		html += '<thead><tr><th>日期</th>';
		$.each(module.columns, function(_, column){
			html += '<th>'+escapeHtml(column.label)+'</th>';
		});
		html += '</tr></thead><tbody>';
		if(!module.rows || module.rows.length === 0){
			html += '<tr><td colspan="'+(module.columns.length + 1)+'" class="text-center text-muted">暂无数据</td></tr>';
		}else{
			$.each(module.rows, function(_, row){
				html += '<tr><td>'+escapeHtml(row.label)+'</td>';
				$.each(module.columns, function(_, column){
					var value = row.values && row.values[column.key] != null ? row.values[column.key] : '0.00';
					var cls = column.key === 'total' ? ' class="income-total"' : '';
					html += '<td'+cls+'>'+escapeHtml(value)+'</td>';
				});
				html += '</tr>';
			});
		}
		html += '</tbody></table></div></div>';
		return html;
	}

	function renderDashboard(data){
		if(data.code !== 0){
			showError(data.msg || '统计数据加载失败');
			return;
		}
		var html = '';
		$.each(data.modules || [], function(index, module){
			html += renderModule(module, index);
		});
		if(html === ''){
			html = '<div class="panel panel-default"><div class="income-empty">暂无统计模块</div></div>';
		}
		$('#incomeStatBody').html(html);
		var source = data.source === 'cache' ? '缓存' : '实时';
		$('#incomeMeta').text('更新时间：'+data.generated_at+' ｜ 数据来源：'+source);
	}

	function loadDashboard(force){
		setLoading();
		$('#refreshBtn').prop('disabled', true);
		$.ajax({
			type: 'GET',
			url: 'ajax_income_stat.php?act=dashboard'+(force ? '&force=1' : ''),
			dataType: 'json',
			success: function(data){
				renderDashboard(data);
			},
			error: function(){
				showError('统计接口请求失败');
			},
			complete: function(){
				$('#refreshBtn').prop('disabled', false);
			}
		});
	}

	$('#refreshBtn').on('click', function(){
		loadDashboard(true);
	});
	$(function(){
		loadDashboard(false);
	});
})();
</script>
