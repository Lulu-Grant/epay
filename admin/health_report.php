<?php
include("../includes/common.php");
if($islogin==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");
if(empty($conf['addon_health_report']) || intval($conf['addon_health_report']) < intval(\lib\Health\Installer::VERSION) || !\lib\Health\Installer::isInstalled()){
    exit('健康简报组件尚未完成命令行安装，请联系服务器管理员。');
}
if(empty($_SESSION['health_csrf_token'])) $_SESSION['health_csrf_token']=bin2hex(random_bytes(32));
$healthCsrfToken=$_SESSION['health_csrf_token'];
$healthReportDestination=trim(isset($conf['health_report_chat_id'])?(string)$conf['health_report_chat_id']:'');
if($healthReportDestination==='') $healthReportDestination=trim(isset($conf['telegram_admin_chat_id'])?(string)$conf['telegram_admin_chat_id']:'');
$healthReportReadiness=\lib\Health\AdminSupport::deliveryReadiness($conf);
$healthReportSendReady=!empty($healthReportReadiness['ok']);
$healthReportSendReason=$healthReportSendReady?'':$healthReportReadiness['message'];
$healthAiReady=\lib\Health\AdminSupport::aiEnabled($conf);
$healthAiReason=$healthAiReady?'':'请先在设置中启用 AI 订单健康分析。';
$title = '健康简报';
include './head.php';
?>
<style>.health-wrap{padding-top:70px;padding-bottom:30px}.health-actions{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:15px}.health-actions .health-queue-btn{color:#fff;background:#2e6b23;border-color:#275c1d}.health-actions .health-queue-btn:hover,.health-actions .health-queue-btn:focus{color:#fff;background:#24551b;border-color:#1e4817}.health-actions .health-queue-btn:focus-visible{outline:3px solid #153f75;outline-offset:2px}.health-level{font-weight:600}.health-detail h4{margin-top:18px}.health-muted{color:#777}.health-evidence{margin-top:4px;color:#666;font-size:12px}.health-channel-table th,.health-channel-table td{white-space:nowrap}.health-channel-table .health-bands{white-space:normal;min-width:220px}.health-wrap.health-text-zoom{font-size:200%}.health-wrap.health-text-zoom .btn,.health-wrap.health-text-zoom .form-control{font-size:1em;height:auto}.health-wrap.health-text-zoom .health-history-table{font-size:1em}@media(max-width:480px){.health-actions .form-control{width:100%!important}.health-actions>*{flex:1 1 100%}.health-history-table thead,.health-channel-table thead{display:none}.health-history-table,.health-history-table tbody,.health-history-table tr,.health-history-table td,.health-channel-table,.health-channel-table tbody,.health-channel-table tr,.health-channel-table td{display:block;width:100%}.health-history-table tr,.health-channel-table tr{border-bottom:1px solid #ddd;padding:8px 10px}.health-history-table td,.health-channel-table td{border:0!important;display:grid;grid-template-columns:minmax(88px,38%) minmax(0,1fr);gap:8px;padding:5px 0!important;white-space:normal;overflow-wrap:anywhere}.health-history-table td:before,.health-channel-table td:before{content:attr(data-label);font-weight:600;color:#555}.health-history-table td.health-empty{display:block;text-align:center!important}.health-history-table td.health-empty:before{content:none}.health-history-table td[data-label="操作"] .btn{width:100%;min-height:38px}.health-channel-table .health-bands{min-width:0}.health-detail .table-responsive{border:0;margin:0}}</style>
<div class="container health-wrap">
  <div class="alert alert-info">只允许分析已结束的自然日。系统按当前数据模式向 AI 提交订单证据；失败后每15分钟重试，最长6小时。AI 未生成最终稿时不会加入 Telegram 队列。</div>
  <div class="health-actions">
    <label class="sr-only" for="reportDate">报告日期</label><input type="date" id="reportDate" class="form-control" style="width:180px" value="<?php echo date('Y-m-d',strtotime('-1 day'));?>">
    <button class="btn btn-primary" onclick="generateReport(true,false,event.currentTarget)" <?php echo $healthAiReady?'':'disabled aria-disabled="true"';?> title="<?php echo htmlspecialchars($healthAiReason,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');?>"><i class="fa fa-refresh"></i> 生成 AI 健康简报</button>
    <button class="btn health-queue-btn" onclick="confirmSendReport(event.currentTarget)" <?php echo $healthReportSendReady?'':'disabled aria-disabled="true"';?> title="<?php echo htmlspecialchars($healthReportSendReason,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');?>"><i class="fa fa-paper-plane"></i> 加入发送队列</button>
	<button class="btn btn-default" onclick="loadReports()" title="刷新历史日报" aria-label="刷新历史日报"><i class="fa fa-refresh" aria-hidden="true"></i></button>
    <a class="btn btn-default" href="health_report_set.php"><i class="fa fa-cog"></i> 设置</a>
  </div>
  <div id="historyStatus" class="sr-only" role="status" aria-live="polite" aria-atomic="true">正在加载历史日报</div>
  <div class="panel panel-default" id="reportHistoryRegion" aria-busy="true"><div class="panel-heading"><h3 class="panel-title">历史日报</h3></div><div class="table-responsive"><table class="table table-striped table-hover health-history-table"><thead><tr><th>日期</th><th>状态</th><th>订单</th><th>曾支付成功率</th><th>AI</th><th>Telegram</th><th>更新时间</th><th>操作</th></tr></thead><tbody id="reportRows"><tr><td colspan="8" class="text-center health-muted health-empty">正在加载</td></tr></tbody></table></div></div>
</div>
<div class="modal fade" id="reportModal" role="dialog" aria-modal="true" aria-labelledby="reportModalTitle"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><button class="close" data-dismiss="modal" aria-label="关闭">&times;</button><h4 class="modal-title" id="reportModalTitle">健康简报详情</h4></div><div class="modal-body health-detail" id="reportDetail"></div></div></div></div>
<script>
function eh(v){return String(v==null?'':v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function loadReports(){
  $('#reportHistoryRegion').attr('aria-busy','true');
  $('#historyStatus').text('正在加载历史日报');
  $.getJSON('ajax_health_report.php?act=list',function(d){
    if(d.code!==0){$('#reportRows').html('<tr><td colspan="8" class="text-center text-danger health-empty">加载失败，请刷新重试</td></tr>');$('#reportHistoryRegion').attr('aria-busy','false');$('#historyStatus').text('历史日报加载失败，请刷新重试');return;}
    var h='';
    $.each(d.data,function(_,r){h+='<tr><td data-label="日期">'+eh(r.report_date)+'</td><td data-label="状态" class="health-level">'+eh(r.health_level)+'</td><td data-label="订单">'+eh(r.total_orders)+'</td><td data-label="曾支付成功率">'+eh(r.success_rate)+'</td><td data-label="AI">'+eh(r.ai_label)+'</td><td data-label="Telegram">'+eh(r.telegram_label)+'</td><td data-label="更新时间">'+eh(r.updated_at)+'</td><td data-label="操作"><button class="btn btn-xs btn-primary" onclick="showReport('+Number(r.id)+')">查看</button></td></tr>';});
    $('#reportRows').html(h||'<tr><td colspan="8" class="text-center health-empty">暂无报告</td></tr>');
    $('#reportHistoryRegion').attr('aria-busy','false');
    $('#historyStatus').text(h?'历史日报已更新，共 '+d.data.length+' 条':'历史日报为空');
  }).fail(function(){$('#reportRows').html('<tr><td colspan="8" class="text-center text-danger health-empty">加载失败，请刷新重试</td></tr>');$('#reportHistoryRegion').attr('aria-busy','false');$('#historyStatus').text('历史日报加载失败，请刷新重试');});
}
var healthCsrfToken=<?php echo json_encode($healthCsrfToken);?>;
var healthReportDestination=<?php echo json_encode($healthReportDestination, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);?>;
var healthReportSendReady=<?php echo $healthReportSendReady?'true':'false';?>;
var healthReportSendReason=<?php echo json_encode($healthReportSendReason, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);?>;
function generateReport(ai,send,invoker){
  var date=$('#reportDate').val();
  $('#reportHistoryRegion').attr('aria-busy','true');
  $('#historyStatus').text(send?'正在生成并加入发送队列':'正在生成健康简报');
  $.post('ajax_health_report.php?act=generate',{date:date,use_ai:ai?1:0,send:send?1:0,retry_terminal:1,csrf_token:healthCsrfToken},function(d){
    if(d.code===0||d.code===1){AdminDialog.alert(d.msg,{title:d.code===0?'操作成功':'操作提示',invoker:invoker});loadReports();}
    else{$('#reportHistoryRegion').attr('aria-busy','false');AdminDialog.alert(d.msg,{title:'操作失败',invoker:invoker});}
  },'json').fail(function(){$('#reportHistoryRegion').attr('aria-busy','false');$('#historyStatus').text('健康简报生成请求失败');AdminDialog.alert('生成请求失败',{title:'操作失败',invoker:invoker});});
}
function confirmSendReport(invoker){
  if(!healthReportSendReady){AdminDialog.alert(healthReportSendReason,{title:'暂不可发送',invoker:invoker});return;}
  var date=$('#reportDate').val(),destination=healthReportDestination||'未配置的接收方';
  AdminDialog.confirm('确认分析 '+date+' 的订单证据并在 AI 最终稿完成后加入 Telegram 发送队列（接收方 '+destination+'）？入队成功不代表已送达，请在历史日报中确认最终状态。',{title:'确认加入队列',invoker:invoker,confirmText:'开始分析'}).then(function(confirmed){if(confirmed)generateReport(true,true,invoker);});
}
function deliveryHtml(delivery){
  delivery=delivery||{};
  var h='<p><b>Telegram：</b>'+eh(delivery.label||'未加入队列');
  if(Number(delivery.queue_id||0)>0)h+='　<b>队列 ID：</b>'+eh(delivery.queue_id);
  if(delivery.sent_at)h+='　<b>送达时间：</b>'+eh(delivery.sent_at);
  else if(delivery.send_started_at)h+='　<b>开始发送：</b>'+eh(delivery.send_started_at);
  else if(delivery.queued_at)h+='　<b>入队时间：</b>'+eh(delivery.queued_at);
  h+='</p>';
  if(Number(delivery.retry_count||0)>0)h+='<p><b>重试次数：</b>'+eh(delivery.retry_count)+'</p>';
  if(delivery.error_summary||delivery.review_guidance){
    h+='<div class="alert alert-warning">';
    if(delivery.error_summary)h+='<b>发送摘要：</b>'+eh(delivery.error_summary)+'<br>';
    if(delivery.review_guidance)h+='<span class="health-muted">'+eh(delivery.review_guidance)+'</span>';
    h+='</div>';
  }
  return h;
}
var healthEvidenceLabels={frozen_orders:'冻结订单',paid_orders:'曾支付成功订单',baseline_rate:'7日基线成功率',current_rate:'当前成功率',sample:'样本数',min_sample:'最低样本数',snapshot_hours:'实际快照小时',expected_hours:'应有快照小时',baseline_days:'基线天数',baseline_complete:'基线完整',notify_failed:'最终回调失败',notify_pending:'回调重试中',total_orders:'订单数',success_rate:'成功率'};
function evidenceHtml(evidence){
  if(!evidence||typeof evidence!=='object') return eh(evidence||'-');
  var parts=[];
  Object.keys(evidence).sort().forEach(function(key){var value=evidence[key];if(value===true)value='是';else if(value===false)value='否';else if(value&&typeof value==='object')value=JSON.stringify(value);parts.push(eh(healthEvidenceLabels[key]||key)+'='+eh(value));});
  return parts.length?parts.join('，'):'-';
}
function evidenceTextHtml(value){
  var parts=String(value||'').split(',');
  return parts.map(function(part){part=$.trim(part);var pos=part.indexOf('=');if(pos<1)return eh(part);var key=$.trim(part.slice(0,pos)),val=$.trim(part.slice(pos+1));return eh(healthEvidenceLabels[key]||key)+'='+eh(val);}).join('，');
}
function reportLevelText(r,levels){
  var level=r&&r.rules?r.rules.level:'unknown',text=levels[level]||'未知',blocked=false;
  $.each(r&&r.rules&&Array.isArray(r.rules.issues)?r.rules.issues:[],function(_,issue){if(issue&&['data_incomplete','baseline_missing','no_traffic','sample_insufficient'].indexOf(issue.code)>=0)blocked=true;});
  return blocked&&level!=='unknown'?text+'（数据不足）':text;
}
function amountBandsHtml(bands){
  var labels={'0_30':'0-30','30_100':'30-100','100_500':'100-500','500_1000':'500-1000','1000_plus':'1000以上'},parts=[];
  $.each(bands||{},function(key,band){if(Number(band.total||0)>0)parts.push(eh(labels[key]||key)+'：'+eh(band.paid||0)+'/'+eh(band.total||0)+'（'+eh(band.success_rate==null?'-':band.success_rate+'%')+'）');});
  return parts.length?parts.join('<br>'):'-';
}
function channelTable(metrics){
  var channels=metrics.channels||{},ids=Object.keys(channels).sort(function(a,b){return Number(a)-Number(b);});
  if(!ids.length)return '<p class="health-muted">没有通道统计数据。</p>';
    var h='<div class="table-responsive"><table class="table table-condensed table-bordered health-channel-table"><thead><tr><th>通道</th><th>订单</th><th>曾支付</th><th>成功率</th><th>回调等待/失败</th><th>投诉</th></tr></thead><tbody>';
    ids.forEach(function(id){var c=channels[id]||{};if(Number(c.total_orders||0)===0&&!Number(c.channel_status||0))return;h+='<tr><td data-label="通道">'+eh(c.channel_id||id)+' '+eh(c.channel_name||'')+'</td><td data-label="订单">'+eh(c.total_orders||0)+'</td><td data-label="曾支付">'+eh(c.paid_orders||0)+'</td><td data-label="成功率">'+eh(c.success_rate==null?'-':c.success_rate+'%')+'</td><td data-label="回调等待/失败">'+eh(c.notify_pending||0)+' / '+eh(c.notify_failed||0)+'</td><td data-label="投诉">'+eh(c.complaints||0)+'</td></tr>';});
  return h+'</tbody></table></div>';
}
function showReport(id){
  $.getJSON('ajax_health_report.php?act=get&id='+encodeURIComponent(id),function(d){
    if(d.code!==0){AdminDialog.alert(d.msg,{title:'详情加载失败',invoker:document.activeElement});return;}
    var r=d.data,m=r.metrics.platform||{},levels={info:'提示',healthy:'正常',attention:'需关注',warning:'告警',critical:'严重',unknown:'数据不足'},asof=r.metrics.notification_as_of||r.metrics.snapshot_finalized_at||'-',oldest='-';
    if(m.notify_oldest_time&&asof!=='-'){var a=Date.parse(String(m.notify_oldest_time).replace(' ','T')),b=Date.parse(String(asof).replace(' ','T'));if(!isNaN(a)&&!isNaN(b))oldest=Math.max(0,Math.floor((b-a)/60000))+'分钟';}
    var source=Number(r.ai_source_rows||0),samples=Number(r.ai_sample_rows||0)+Number(r.ai_drilldown_rows||0),saved=source>0?Math.max(0,(1-samples/source)*100).toFixed(1)+'%':'-';
    var h='<p><b>日期：</b>'+eh(r.report_date)+'　<b>状态：</b>'+eh(levels[(r.ai&&r.ai.health_level)||r.rules.level]||'未知')+'</p>'+deliveryHtml(r.delivery)+'<p><b>统计窗口：</b>'+eh(r.metrics.period_start)+' 至 '+eh(r.metrics.period_end)+'（UTC+8）　<b>数据来源：</b>逐笔订单</p><p><b>订单：</b>'+eh(m.total_orders)+'　<b>曾支付成功：</b>'+eh(m.paid_orders)+'　<b>曾支付成功率：</b>'+eh(m.success_rate==null?'-':m.success_rate+'%')+'</p><p><b>退款：</b>'+eh(m.refunded_orders||0)+'　<b>冻结：</b>'+eh(m.frozen_orders||0)+'　<b>投诉：</b>'+eh(m.complaints||0)+'</p><p><b>商户回调：</b>符合条件 '+eh(m.notify_total||0)+'　成功 '+eh(m.notify_success||0)+'　重试中 '+eh(m.notify_pending||0)+'　最终失败 '+eh(m.notify_failed||0)+'</p><p><b>AI流水线：</b>'+eh(r.ai_pipeline_version||'-')+'　<b>模式：</b>'+eh(r.ai_payload_mode||'compact')+'　<b>调用：</b>'+eh(r.ai_calls||0)+' / 100</p><p><b>数据压缩：</b>原始 '+eh(source)+' 笔，样本 '+eh(r.ai_sample_rows||0)+' 笔，取证 '+eh(r.ai_drilldown_rows||0)+' 笔，行数缩减 '+eh(saved)+'</p><p><b>用量：</b>输入 '+eh(r.ai_input_tokens||0)+' Tokens，输出 '+eh(r.ai_output_tokens||0)+' Tokens（'+eh(r.ai_token_source||'未记录')+'）；请求 '+eh(r.ai_request_bytes||0)+' 字节，响应 '+eh(r.ai_response_bytes||0)+' 字节</p><h4>通道统计</h4>'+channelTable(r.metrics)+'<h4>AI 分析简报</h4>';
    if(r.ai){h+='<h5>'+eh(r.ai.headline||'')+'</h5><p>'+eh(r.ai.executive_summary||'')+'</p><ol>';$.each(r.ai.findings||[],function(_,x){h+='<li><b>'+eh(x.title||'')+'</b><br>证据：'+eh(x.evidence||'-')+'<br>可能原因：'+eh(x.likely_cause||'-')+'<br><span class="health-muted">建议：'+eh(x.action||'-')+'</span></li>';});h+='</ol><pre style="white-space:pre-wrap">'+eh(r.ai.telegram_brief||'')+'</pre>';}
    else h+='<div class="alert alert-warning">'+eh(r.ai_reason||'AI分析尚未完成')+(r.ai_next_retry_at?'，下次重试：'+eh(r.ai_next_retry_at):'')+'</div>';
    $('#reportDetail').html(h);$('#reportModal').modal('show');
  }).fail(function(){AdminDialog.alert('详情加载失败，请稍后重试',{title:'详情加载失败',invoker:document.activeElement});});
}
$(loadReports);
</script>
