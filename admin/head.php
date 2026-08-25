<?php
@header('Content-Type: text/html; charset=UTF-8');

$cdnpublic = '/assets/cdn/';
?>
<!DOCTYPE html>
<html lang="zh-cn">
<head>
  <meta charset="utf-8"/>
  <meta name="renderer" content="webkit">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $title ?></title>
  <link href="<?php echo $cdnpublic?>twitter-bootstrap/3.4.1/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="../assets/css/bootstrap-table.css?v=1" rel="stylesheet"/>
  <link href="<?php echo $cdnpublic?>font-awesome/4.7.0/css/font-awesome.min.css" rel="stylesheet"/>
  <script src="<?php echo $cdnpublic?>modernizr/2.8.3/modernizr.min.js"></script>
  <script src="<?php echo $cdnpublic?>jquery/2.1.4/jquery.min.js"></script>
  <script>window.jQuery || document.write('<script src="/assets/cdn/jquery/2.1.4/jquery.min.js"><\/script>')</script>
  <script src="<?php echo $cdnpublic?>twitter-bootstrap/3.4.1/js/bootstrap.min.js"></script>
  <script>window.jQuery && window.jQuery.fn && window.jQuery.fn.modal || document.write('<script src="/assets/cdn/twitter-bootstrap/3.4.1/js/bootstrap.min.js"><\/script>')</script>
  <script src="../assets/js/admin-layer-fallback.js"></script>
  <style>
    .admin-accessible-dialog{width:min(520px,calc(100% - 32px));max-width:520px;padding:0;border:1px solid #b8c2cc;border-radius:6px;background:#fff;color:#222;box-shadow:0 12px 36px rgba(0,0,0,.28)}
    .admin-accessible-dialog::backdrop{background:rgba(0,0,0,.48)}
    .admin-accessible-dialog__header{padding:15px 18px;border-bottom:1px solid #ddd;font-size:18px;font-weight:600}
    .admin-accessible-dialog__body{padding:18px;line-height:1.65;white-space:pre-wrap;overflow-wrap:anywhere}
    .admin-accessible-dialog__footer{display:flex;justify-content:flex-end;gap:10px;padding:12px 18px;border-top:1px solid #ddd;background:#f7f7f7}
    .admin-accessible-dialog__footer .btn{min-width:84px;min-height:40px}
    .admin-accessible-dialog__footer [hidden]{display:none!important}
    .admin-accessible-dialog :focus-visible{outline:3px solid #1b6ec2;outline-offset:2px}
  </style>
  <script>
  (function(window,document){
    'use strict';
    var dialog,titleNode,messageNode,cancelButton,confirmButton,pending,lastFocus;
    function build(){
      if(dialog) return;
      dialog=document.createElement('dialog');
      dialog.className='admin-accessible-dialog';
      dialog.setAttribute('aria-labelledby','adminDialogTitle');
      dialog.setAttribute('aria-describedby','adminDialogMessage');
      dialog.innerHTML='<div class="admin-accessible-dialog__header" id="adminDialogTitle"></div><div class="admin-accessible-dialog__body" id="adminDialogMessage"></div><div class="admin-accessible-dialog__footer"><button type="button" class="btn btn-default" data-dialog-cancel>取消</button><button type="button" class="btn btn-primary" data-dialog-confirm>确定</button></div>';
      document.body.appendChild(dialog);
      titleNode=dialog.querySelector('#adminDialogTitle');
      messageNode=dialog.querySelector('#adminDialogMessage');
      cancelButton=dialog.querySelector('[data-dialog-cancel]');
      confirmButton=dialog.querySelector('[data-dialog-confirm]');
      cancelButton.addEventListener('click',function(){finish(false);});
      confirmButton.addEventListener('click',function(){finish(true);});
      dialog.addEventListener('cancel',function(event){event.preventDefault();finish(false);});
      dialog.addEventListener('keydown',function(event){
        if(event.key!=='Tab') return;
        var first=cancelButton.hidden?confirmButton:cancelButton,last=confirmButton;
        if(event.shiftKey&&document.activeElement===first){event.preventDefault();event.stopPropagation();last.focus();}
        else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();event.stopPropagation();first.focus();}
      },true);
    }
    function finish(value){
      if(!dialog||!dialog.open) return;
      dialog.close();
      var resolve=pending;
      var focusTarget=lastFocus;
      pending=null;
      lastFocus=null;
      if(focusTarget&&document.contains(focusTarget)&&typeof focusTarget.focus==='function') focusTarget.focus();
      if(resolve) resolve(value);
    }
    function open(message,options,confirming){
      build();
      if(dialog.open) finish(false);
      options=options||{};
      lastFocus=options.invoker&&document.contains(options.invoker)?options.invoker:document.activeElement;
      titleNode.textContent=options.title||(confirming?'请确认':'操作结果');
      messageNode.textContent=String(message==null?'':message);
      dialog.setAttribute('role',confirming?'dialog':'alertdialog');
      cancelButton.hidden=!confirming;
      cancelButton.textContent=options.cancelText||'取消';
      confirmButton.textContent=options.confirmText||'确定';
      dialog.showModal();
      window.setTimeout(function(){if(dialog.open)(confirming?cancelButton:confirmButton).focus();},0);
      return new Promise(function(resolve){pending=resolve;});
    }
    window.AdminDialog={
      alert:function(message,options){return open(message,options,false);},
      confirm:function(message,options){return open(message,options,true);}
    };
  })(window,document);
  </script>
  <!--[if lt IE 9]>
    <script src="<?php echo $cdnpublic?>html5shiv/3.7.3/html5shiv.min.js"></script>
    <script src="<?php echo $cdnpublic?>respond.js/1.4.2/respond.min.js"></script>
  <![endif]-->
</head>
<body>
<?php if($islogin==1){?>
  <nav class="navbar navbar-fixed-top navbar-default">
    <div class="container">
      <div class="navbar-header">
        <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#navbar" aria-expanded="false" aria-controls="navbar">
          <span class="sr-only">导航按钮</span>
          <span class="icon-bar"></span>
          <span class="icon-bar"></span>
          <span class="icon-bar"></span>
        </button>
        <a class="navbar-brand" href="./">支付管理中心</a>
      </div><!-- /.navbar-header -->
      <div id="navbar" class="collapse navbar-collapse">
        <ul class="nav navbar-nav navbar-right">
          <li class="<?php echo checkIfActive('index,')?>">
            <a href="./"><i class="fa fa-home"></i> 平台首页</a>
          </li>
		  <li class="<?php echo checkIfActive('order')?>">
            <a href="./order.php"><i class="fa fa-list"></i> 订单管理</a>
          </li>
		  <li class="<?php echo checkIfActive('settle,slist')?>">
            <a href="./slist.php"><i class="fa fa-cloud"></i> 结算管理</a>
          </li>
		  <li class="<?php echo checkIfActive('ulist,glist,group,record,uset,domain,ustat,income_stat,invitecode')?>">
            <a href="#" class="dropdown-toggle" data-toggle="dropdown"><i class="fa fa-user"></i> 商户管理<b class="caret"></b></a>
            <ul class="dropdown-menu">
              <li><a href="./ulist.php">用户列表</a></li>
			  <li><a href="./glist.php">用户组设置</a></li>
			  <li><a href="./group.php">用户组购买</a></li>
			  <li><a href="./record.php">资金明细</a></li>
        <li><a href="./ustat.php">支付统计</a></li>
        <li><a href="./income_stat.php">收入看板</a></li>
        <?php if($conf['pay_domain_forbid']==1 || $conf['pay_domain_open']==1){?><li><a href="./domain.php">授权域名</a></li><?php }?>
        <?php if($conf['reg_open']==2){?><li><a href="./invitecode.php">邀请码管理</a></li><?php }?>
            </ul>
          </li>
		  <li class="<?php echo checkIfActive('pay_channel,pay_roll,pay_type,pay_plugin,pay_weixin')?>">
            <a href="#" class="dropdown-toggle" data-toggle="dropdown"><i class="fa fa-credit-card"></i> 支付接口<b class="caret"></b></a>
            <ul class="dropdown-menu">
              <li><a href="./pay_channel.php">支付通道</a></li>
			  <li><a href="./pay_type.php">支付方式</a></li>
			  <li><a href="./pay_plugin.php">支付插件</a></li>
        <li><a href="./pay_roll.php">支付通道轮询</a></li>
        <li><a href="./pay_weixin.php">公众号小程序</a></li>
            </ul>
          </li>
		  <li class="<?php echo checkIfActive('shop_config,shop_goods,shop_orders')?>">
            <a href="#" class="dropdown-toggle" data-toggle="dropdown"><i class="fa fa-shopping-cart"></i> 商城管理<b class="caret"></b></a>
            <ul class="dropdown-menu">
              <li><a href="./shop_config.php">商城配置</a></li>
              <li><a href="./shop_goods.php">商品管理</a></li>
              <li><a href="./shop_orders.php">商城订单</a></li>
            </ul>
          </li>
			  <li class="<?php echo checkIfActive('set,gonggao,set_wxkf,telegram_set,health_report,health_report_set')?>">
            <a href="#" class="dropdown-toggle" data-toggle="dropdown"><i class="fa fa-cog"></i> 系统设置<b class="caret"></b></a>
            <ul class="dropdown-menu">
              <li><a href="./set.php?mod=site">网站信息配置</a></li>
			  <li><a href="./set.php?mod=pay">支付相关配置</a><li>
        <li><a href="./set.php?mod=settle">结算规则配置</a><li>
			  <li><a href="./set.php?mod=transfer">企业付款配置</a><li>
			  <li><a href="./set.php?mod=oauth">快捷登录配置</a><li>
        <li><a href="./set.php?mod=notice">消息提醒配置</a><li>
			  <li><a href="./set.php?mod=certificate">实名认证配置</a><li>
			  <li><a href="./gonggao.php">网站公告配置</a></li>
			  <li><a href="./set.php?mod=template">首页模板配置</a><li>
			  <li><a href="./set.php?mod=mail">邮箱与短信配置</a><li>
			  <li><a href="./set.php?mod=upimg">网站Logo上传</a><li>
			  <li><a href="./set.php?mod=cron">计划任务配置</a><li>
	        <li><a href="./set_wxkf.php">H5跳转微信客服支付</a></li>
	        <li><a href="./telegram_set.php">Telegram通知设置</a></li>
	        <li><a href="./health_report.php">健康简报</a></li>
	        <li><a href="./health_report_set.php">健康简报设置</a></li>
	            </ul>
	          </li>
		  <li class="<?php echo checkIfActive('clean,log,transfer,transfer_add,risk,alipayrisk,export,ps_receiver,ps_order,gettoken,blacklist,complain,complain_info')?>">
            <a href="#" class="dropdown-toggle" data-toggle="dropdown"><i class="fa fa-cube"></i> 其他功能<b class="caret"></b></a>
            <ul class="dropdown-menu">
        <li><a href="./export.php">导出订单</a><li>
			  <li><a href="./transfer_add.php">企业付款</a><li>
        <li><a href="./transfer.php">付款记录</a><li>
			  <li><a href="./risk.php">风控记录</a><li>
			  <li><a href="./log.php">登录日志</a><li>
			  <li><a href="./clean.php">数据清理</a><li>
        <li><a href="./ps_receiver.php">分账规则</a><li>
        <li><a href="./ps_order.php">分账记录</a><li>
        <li><a href="./gettoken.php">获取用户标识</a><li>
        <li><a href="./blacklist.php">黑名单管理</a></li>
        <li><a href="./complain.php">交易投诉</a></li>
        <li><a href="./help.php?doc=user-manual" target="_blank">使用文档</a></li>
            </ul>
          </li>
          <li><a href="./login.php?logout" onclick="return confirm('是否确定退出登录？')"><i class="fa fa-power-off"></i> 退出登录</a></li>
        </ul>
      </div><!-- /.navbar-collapse -->
    </div><!-- /.container -->
  </nav><!-- /.navbar -->
<?php }?>
