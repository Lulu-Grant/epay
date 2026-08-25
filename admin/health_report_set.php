<?php
include("../includes/common.php");
if($islogin==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");
if(empty($conf['addon_health_report']) || intval($conf['addon_health_report']) < intval(\lib\Health\Installer::VERSION) || !\lib\Health\Installer::isInstalled()){
    exit('健康简报组件尚未完成命令行安装，请联系服务器管理员。');
}
if(empty($_SESSION['health_csrf_token'])) $_SESSION['health_csrf_token']=bin2hex(random_bytes(32));
$healthCsrfToken=$_SESSION['health_csrf_token'];
$title = '健康简报设置';
include './head.php';
function health_set_h($value){ return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$secretEnv = \lib\Health\AiClient::loadSecretEnvironment();
$keyReady = !empty($secretEnv['HEALTH_AI_API_KEY']) || getenv('HEALTH_AI_API_KEY');
$hosts = isset($secretEnv['HEALTH_AI_ALLOWED_HOSTS']) ? $secretEnv['HEALTH_AI_ALLOWED_HOSTS'] : getenv('HEALTH_AI_ALLOWED_HOSTS');
$models = isset($secretEnv['HEALTH_AI_ALLOWED_MODELS']) ? $secretEnv['HEALTH_AI_ALLOWED_MODELS'] : getenv('HEALTH_AI_ALLOWED_MODELS');
?>
<style>.health-settings-wrap.health-text-zoom{font-size:200%}.health-settings-wrap.health-text-zoom .btn,.health-settings-wrap.health-text-zoom .form-control,.health-settings-wrap.health-text-zoom .help-block{font-size:1em;height:auto}.health-settings-wrap.health-text-zoom .control-label{font-size:1em}</style>
<div class="container health-settings-wrap" style="padding-top:70px;padding-bottom:30px;">
  <div class="panel panel-primary">
    <div class="panel-heading"><h3 class="panel-title">每日订单与通道健康简报</h3></div>
    <div class="panel-body">
      <div class="alert alert-warning">紧凑模式只向 AI 发送客观聚合、脱敏证据样本和经校验的按需取证数据；原始 IP、回调地址、密钥、Token、密码和 Cookie 不会发送。本地不执行健康诊断，AI 未完成时不会发送日报。</div>
      <form id="healthSettingForm" class="form-horizontal" aria-busy="false">
        <input type="hidden" name="csrf_token" value="<?php echo health_set_h($healthCsrfToken);?>">
        <div class="form-group"><label class="col-sm-3 control-label">小时快照</label><div class="col-sm-9"><select name="health_snapshot_enabled" class="form-control"><option value="0" <?php echo empty($conf['health_snapshot_enabled'])?'selected':'';?>>关闭</option><option value="1" <?php echo !empty($conf['health_snapshot_enabled'])?'selected':'';?>>开启</option></select><p class="help-block">首次上线保持关闭，完成生产 EXPLAIN 与负载验证后再开启。</p></div></div>
        <div class="form-group"><label class="col-sm-3 control-label">滚动回算小时</label><div class="col-sm-9"><input type="number" min="<?php echo max(4,intval(isset($conf['health_snapshot_settle_hours'])?$conf['health_snapshot_settle_hours']:3)+1);?>" max="<?php echo \lib\Health\MetricsService::MAX_SAFE_BACKFILL_HOURS;?>" name="health_snapshot_reconcile_hours" class="form-control" value="<?php echo intval(isset($conf['health_snapshot_reconcile_hours'])?$conf['health_snapshot_reconcile_hours']:6);?>"><p class="help-block">必须大于结算成熟小时数，且最多回算12小时；更早数据可能已受订单清理影响，不会被认证为完整快照。</p></div></div>
        <div class="form-group"><label class="col-sm-3 control-label">每日简报</label><div class="col-sm-9"><select name="health_report_enabled" class="form-control"><option value="0" <?php echo empty($conf['health_report_enabled'])?'selected':'';?>>关闭</option><option value="1" <?php echo !empty($conf['health_report_enabled'])?'selected':'';?>>开启</option></select><p class="help-block">关闭只停止未来自动生成和新入队；已排队或正在发送的任务仍会继续处理。</p></div></div>
        <div class="form-group"><label class="col-sm-3 control-label">发送时间</label><div class="col-sm-9"><p class="form-control-static">每日 09:05（Asia/Shanghai，由 systemd 固定调度）</p></div></div>
        <div class="form-group"><label class="col-sm-3 control-label">专用 Chat ID</label><div class="col-sm-9"><input name="health_report_chat_id" class="form-control" value="<?php echo health_set_h(isset($conf['health_report_chat_id'])?$conf['health_report_chat_id']:'');?>" placeholder="留空使用 Telegram 管理员 Chat ID"></div></div>
        <hr>
        <div class="form-group"><label class="col-sm-3 control-label">AI 订单健康分析</label><div class="col-sm-9"><select name="health_ai_enabled" class="form-control"><option value="0" <?php echo empty($conf['health_ai_enabled'])?'selected':'';?>>关闭</option><option value="1" <?php echo !empty($conf['health_ai_enabled'])?'selected':'';?>>开启</option></select></div></div>
        <div class="form-group"><label class="col-sm-3 control-label">AI 数据模式</label><div class="col-sm-9"><select name="health_ai_payload_mode" class="form-control"><option value="raw" <?php echo isset($conf['health_ai_payload_mode'])&&$conf['health_ai_payload_mode']==='raw'?'selected':'';?>>逐笔原始数据（回滚模式）</option><option value="compact" <?php echo !isset($conf['health_ai_payload_mode'])||$conf['health_ai_payload_mode']!=='raw'?'selected':'';?>>紧凑证据 + 按需取证</option></select><p class="help-block">模式只影响新生成或明确重试的未发送报告，已完成报告不会被重写。</p></div></div>
        <div class="form-group"><label class="col-sm-3 control-label">兼容 API 基址</label><div class="col-sm-9"><input name="health_ai_base_url" class="form-control" value="<?php echo health_set_h(isset($conf['health_ai_base_url'])?$conf['health_ai_base_url']:'');?>" placeholder="https://provider.example/v1"></div></div>
        <div class="form-group"><label class="col-sm-3 control-label">模型</label><div class="col-sm-9"><input name="health_ai_model" class="form-control" value="<?php echo health_set_h(isset($conf['health_ai_model'])?$conf['health_ai_model']:'');?>"></div></div>
        <div class="form-group"><label class="col-sm-3 control-label">AI 流式时限</label><div class="col-sm-3"><label for="healthAiTimeout">绝对上限（秒）</label><input id="healthAiTimeout" type="number" min="60" max="600" name="health_ai_timeout" class="form-control" value="<?php echo intval(isset($conf['health_ai_timeout'])?$conf['health_ai_timeout']:600);?>"></div><div class="col-sm-3"><label for="healthAiFirstByteTimeout">首包等待（秒）</label><input id="healthAiFirstByteTimeout" type="number" min="30" max="600" name="health_ai_first_byte_timeout" class="form-control" value="<?php echo intval(isset($conf['health_ai_first_byte_timeout'])?$conf['health_ai_first_byte_timeout']:300);?>"></div><div class="col-sm-3"><label for="healthAiIdleTimeout">流式空闲（秒）</label><input id="healthAiIdleTimeout" type="number" min="30" max="300" name="health_ai_idle_timeout" class="form-control" value="<?php echo intval(isset($conf['health_ai_idle_timeout'])?$conf['health_ai_idle_timeout']:120);?>"></div></div>
        <div class="form-group"><label class="col-sm-3 control-label">AI 输出限制</label><div class="col-sm-9"><label for="healthAiTokens">最大输出 Tokens</label><input id="healthAiTokens" type="number" min="300" max="5000" name="health_ai_max_tokens" class="form-control" value="<?php echo intval(isset($conf['health_ai_max_tokens'])?$conf['health_ai_max_tokens']:3000);?>"><p class="help-block">分析使用SSE流式传输；只有完整结束且通过JSON和证据校验后才会发送简报。</p></div></div>
        <div class="form-group"><label class="col-sm-3 control-label">每日调用上限</label><div class="col-sm-9"><input id="healthAiDailyLimit" type="number" min="1" max="100" name="health_ai_daily_limit" class="form-control" value="<?php echo intval(isset($conf['health_ai_daily_limit'])?$conf['health_ai_daily_limit']:100);?>"><p class="help-block">按通道分批分析、通道汇总、平台汇总及失败重试合计最多100次。</p></div></div>
        <div class="form-group"><label class="col-sm-3 control-label">服务器密钥状态</label><div class="col-sm-9"><p class="form-control-static"><span class="label label-<?php echo $keyReady?'success':'default';?>"><?php echo $keyReady?'API Key 已配置':'API Key 未配置';?></span>　允许主机：<?php echo health_set_h($hosts?:'未配置');?>　允许模型：<?php echo health_set_h($models?:'未配置');?></p><p class="help-block">密钥只从 /etc/epay/ai-health.env 或进程环境读取，后台不提供录入和回显。</p></div></div>
        <div class="form-group"><div class="col-sm-offset-3 col-sm-9"><button class="btn btn-primary" type="submit"><i class="fa fa-save"></i> 保存设置</button> <a href="health_report.php" class="btn btn-default"><i class="fa fa-list"></i> 查看简报</a></div></div>
      </form>
    </div>
  </div>
</div>
<script>
var healthControlLabels={health_snapshot_enabled:'小时快照',health_snapshot_reconcile_hours:'滚动回算小时',health_report_enabled:'每日简报',health_report_chat_id:'专用 Chat ID',health_ai_enabled:'AI 订单健康分析',health_ai_payload_mode:'AI 数据模式',health_ai_base_url:'兼容 API 基址',health_ai_model:'模型',health_ai_timeout:'AI 绝对上限',health_ai_first_byte_timeout:'AI 首包等待',health_ai_idle_timeout:'AI 流式空闲',health_ai_max_tokens:'AI 最大 Tokens',health_ai_daily_limit:'AI 每日调用'};
$.each(healthControlLabels,function(name,label){$('#healthSettingForm [name="'+name+'"]').attr('aria-label',label);});
$('#healthSettingForm').on('submit', function(e){
  e.preventDefault();
  var invoker=document.activeElement;
  $('#healthSettingForm').attr('aria-busy','true');
  $.post('ajax_health_report.php?act=save', $(this).serialize(), function(data){
    $('#healthSettingForm').attr('aria-busy','false');
    if(data.code===0) AdminDialog.alert(data.msg,{title:'保存成功',invoker:invoker}).then(function(){location.reload();});
    else AdminDialog.alert(data.msg,{title:'保存失败',invoker:invoker});
  },'json').fail(function(){$('#healthSettingForm').attr('aria-busy','false');AdminDialog.alert('请求失败',{title:'保存失败',invoker:invoker});});
});
</script>
