<?php
include("../includes/common.php");
if($islogin2==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");
$title='资金明细';
include './head.php';
?>
<style>
.fixed-table-toolbar,.fixed-table-pagination{padding: 15px;}
</style>
 <div id="content" class="app-content" role="main">
    <div class="app-content-body ">

<div class="bg-light lter b-b wrapper-md hidden-print">
  <h1 class="m-n font-thin h3">资金明细</h1>
</div>
<div class="wrapper-md control">
<?php if(isset($msg)){?>
<div class="alert alert-info">
	<?php echo $msg?>
</div>
<?php }?>
	<div class="panel panel-default">
		<div class="panel-heading font-bold">
			<h3 class="panel-title">资金明细</h3>
		</div>
	    <form onsubmit="return searchSubmit()" method="GET" class="form-inline filter-toolbar" id="searchToolbar">
	      <div class="form-group">
			<label class="filter-label" for="record-search-type">搜索字段</label>
			<select id="record-search-type" class="form-control" name="type">
			  <option value="1">操作类型</option>
			  <option value="2">变更金额</option>
			  <option value="3">关联订单号</option>
			</select>
		  </div>
		  <div class="form-group" id="searchword">
			<label class="filter-label" for="record-search-keyword">关键词</label>
			<input id="record-search-keyword" type="text" class="form-control filter-keyword" name="kw" placeholder="输入要搜索的内容">
		  </div>
		  <div class="form-group">
			<button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> 搜索</button>
			<a href="javascript:searchClear()" class="btn btn-default"><i class="fa fa-refresh"></i> 重置</a>
		  </div>
		</form>
      <div id="record-list-state" class="table-state sr-only" role="status" aria-live="polite">正在加载资金明细</div>
      <table id="listTable">
	  </table>
	</div>
</div>
    </div>
  </div>

<?php include 'foot.php';?>
<script src="<?php echo $cdnpublic?>layer/3.1.1/layer.min.js"></script>
<script src="../assets/js/bootstrap-table.min.js"></script>
<script src="../assets/js/bootstrap-table-page-jump-to.min.js"></script>
<script src="../assets/js/custom.js"></script>
<script>
$(document).ready(function(){
	updateToolbar();
	const defaultPageSize = 30;
	const pageNumber = typeof window.$_GET['pageNumber'] != 'undefined' ? parseInt(window.$_GET['pageNumber']) : 1;
	const pageSize = typeof window.$_GET['pageSize'] != 'undefined' ? parseInt(window.$_GET['pageSize']) : defaultPageSize;

	$("#listTable").bootstrapTable({
		url: 'ajax2.php?act=recordList',
		pageNumber: pageNumber,
		pageSize: pageSize,
		classes: 'table table-striped table-hover table-bordered',
		formatLoadingMessage: function(){ return '正在加载资金明细…'; },
		formatNoMatches: function(){ return window.location.search ? '没有符合当前筛选条件的资金明细，请调整条件后重试。' : '暂无资金明细，余额发生变动后会显示在这里。'; },
		onLoadSuccess: function(){ $('#record-list-state').text('资金明细已加载'); },
		onLoadError: function(){ $('#record-list-state').removeClass('sr-only').addClass('alert alert-danger').text('资金明细加载失败，请稍后重试。'); },
		columns: [
			{
				field: 'type',
				title: '操作类型',
				formatter: function(value, row, index) {
					return row.action==2?'<font color="red">'+value+'</font>':'<font color="green">'+value+'</font>';
				}
			},
			{
				field: 'money',
				title: '变更金额',
				formatter: function(value, row, index) {
					return (row.action==2?'- ':'+ ')+value;
				}
			},
			{
				field: 'oldmoney',
				title: '变更前金额'
			},
			{
				field: 'newmoney',
				title: '变更后金额'
			},
			{
				field: 'date',
				title: '时间'
			},
			{
				field: 'trade_no',
				title: '关联订单号',
				formatter: function(value, row, index) {
					return value?'<a href="./order.php?type=1&kw='+value+'">'+value+'</a>':'无';
				}
			},
		],
	})
})
</script>
