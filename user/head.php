<?php
@header('Content-Type: text/html; charset=UTF-8');
if($userrow['status']==0){
	sysmsg('该商户账户已停用，请联系平台管理员。');
}
switch($conf['user_style']){
	case 1: $style=['bg-black','bg-black','bg-white']; break;
	case 2: $style=['bg-dark','bg-white','bg-dark']; break;
	case 3: $style=['bg-dark','bg-dark','bg-light']; break;
	case 4: $style=['bg-info','bg-info','bg-black']; break;
	case 5: $style=['bg-info','bg-info','bg-white']; break;
	case 6: $style=['bg-primary','bg-primary','bg-dark']; break;
	case 7: $style=['bg-primary','bg-primary','bg-white']; break;
	default: $style=['bg-black','bg-white','bg-black']; break;
}
$groupconfig = getGroupConfig($userrow['gid']);
$conf = array_merge($conf, $groupconfig);
if(!empty($admin_sso_session)){
	if(empty($_SESSION['admin_sso_logout_csrf'])) $_SESSION['admin_sso_logout_csrf'] = bin2hex(random_bytes(32));
	$adminSsoExpires = strtotime((string)$admin_sso_session['expires_at']);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8" />
  <title><?php echo $title?> | <?php echo $conf['sitename']?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1" />
  <link rel="stylesheet" href="<?php echo $cdnpublic?>twitter-bootstrap/3.3.7/css/bootstrap.min.css" type="text/css" />
  <link rel="stylesheet" href="<?php echo $cdnpublic?>animate.css/3.5.2/animate.min.css" type="text/css" />
  <link rel="stylesheet" href="<?php echo $cdnpublic?>font-awesome/4.7.0/css/font-awesome.min.css" type="text/css" />
  <link rel="stylesheet" href="<?php echo $cdnpublic?>simple-line-icons/2.4.1/css/simple-line-icons.min.css" type="text/css" />
  <link rel="stylesheet" href="./assets/css/font.css" type="text/css" />
  <link rel="stylesheet" href="./assets/css/app.css" type="text/css" />
  <link rel="stylesheet" href="../assets/css/bootstrap-table.css?v=1"/>
  <link rel="stylesheet" href="../assets/css/dashboard-responsive.css?v=2"/>
  <style>.admin-sso-indicator{display:flex!important;align-items:center;gap:10px;padding:7px 12px;color:#111827;background:#f7c948}.admin-sso-indicator form{margin:0}.admin-sso-indicator__exit{border:1px solid #111827;border-radius:4px;background:transparent;color:#111827;font-weight:600;padding:4px 9px}.admin-sso-indicator__exit:focus-visible{outline:3px solid #fff;outline-offset:2px}@media(max-width:767px){.admin-sso-indicator{flex-wrap:wrap;font-size:12px}}</style>
</head>
<body class="dashboard-responsive">
<div class="app app-header-fixed  ">
  <!-- header -->
  <header id="header" class="app-header navbar" role="menu">
          <!-- navbar header -->
      <div class="navbar-header <?php echo $style[0]?>">
        <button type="button" id="merchant-account-toggle" class="pull-right visible-xs dk" ui-toggle="show" target="#merchant-account-menu" aria-label="账户菜单" aria-controls="merchant-account-menu" aria-expanded="false">
          <i class="glyphicon glyphicon-cog" aria-hidden="true"></i>
        </button>
        <button type="button" id="merchant-nav-toggle" class="pull-right visible-xs" ui-toggle="off-screen" target="#merchant-primary-nav" aria-label="主导航" aria-controls="merchant-primary-nav" aria-expanded="false">
          <i class="glyphicon glyphicon-align-justify" aria-hidden="true"></i>
        </button>
        <!-- brand -->
        <a href="./" class="navbar-brand text-lt">
          <i class="fa fa-btc"></i>
          <span class="hidden-folded m-l-xs"><?php echo $conf['sitename']?></span>
        </a>
        <!-- / brand -->
      </div>
      <!-- / navbar header -->

      <!-- navbar collapse -->
      <div id="merchant-account-menu" class="collapse pos-rlt navbar-collapse box-shadow <?php echo $style[1]?>">
        <!-- buttons -->
        <div class="nav navbar-nav hidden-xs">
          <a href="#" class="btn no-shadow navbar-btn" ui-toggle="app-aside-folded" target=".app">
            <i class="fa fa-dedent fa-fw text"></i>
            <i class="fa fa-indent fa-fw text-active"></i>
          </a>
        </div>
        <!-- / buttons -->

        <!-- nabar right -->
        <ul class="nav navbar-nav navbar-right">
          <?php if(!empty($admin_sso_session)){?>
          <li class="admin-sso-indicator" role="status">
            <span>管理员代登录：UID <?php echo intval($uid)?>，有效至 <?php echo date('H:i', $adminSsoExpires)?></span>
            <form id="admin-sso-exit-menu" method="post" action="<?php echo htmlspecialchars(\lib\AdminSso::endpoint($conf, 'merchant'), ENT_QUOTES, 'UTF-8')?>">
              <input type="hidden" name="action" value="logout">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['admin_sso_logout_csrf'], ENT_QUOTES, 'UTF-8')?>">
              <button type="submit" class="admin-sso-indicator__exit">退出代登录</button>
            </form>
          </li>
          <?php }?>
          <li class="dropdown">
            <a href="#" data-toggle="dropdown" class="dropdown-toggle clear" aria-label="打开商户账户菜单">
              <span class="thumb-sm avatar pull-right m-t-n-sm m-b-n-sm m-l-sm">
                <img src="<?php echo ($userrow['qq'])?'//q2.qlogo.cn/headimg_dl?bs=qq&dst_uin='.$userrow['qq'].'&src_uin='.$userrow['qq'].'&fid='.$userrow['qq'].'&spec=100&url_enc=0&referer=bu_interface&term_type=PC':'assets/img/user.png'?>" alt="商户头像">
                <i class="on md b-white bottom"></i>
              </span>
              <span class="hidden-sm hidden-md" style="text-transform:uppercase;"><?php echo $uid?></span> <b class="caret"></b>
            </a>
            <!-- dropdown -->
            <ul class="dropdown-menu animated fadeInRight w">
              <li>
                <a href="index.php">
                  <span>用户中心</span>
                </a>
              </li>
              <li>
                <a href="editinfo.php">
                  <span>修改资料</span>
                </a>
              </li>
			  <li>
                <a href="userinfo.php?mod=account">
                  <span>修改密码</span>
                </a>
              </li>
              <li class="divider"></li>
              <li>
                <?php if(!empty($admin_sso_session)){?>
                <button type="submit" form="admin-sso-exit-menu" class="btn btn-link">退出代登录</button>
                <?php }else{?>
                <a ui-sref="access.signin" href="login.php?logout">退出登录</a>
                <?php }?>
              </li>
            </ul>
            <!-- / dropdown -->
          </li>
        </ul>
        <!-- / navbar right -->
      </div>
      <!-- / navbar collapse -->
  </header>
  <!-- / header -->
  <!-- aside -->
  <aside id="merchant-primary-nav" class="app-aside hidden-xs <?php echo $style[2]?>">
      <div class="aside-wrap">
        <div class="navi-wrap">

          <!-- nav -->
          <nav ui-nav class="navi clearfix">
            <ul class="nav">
              <li class="hidden-folded padder m-t m-b-sm text-muted text-xs">
                <span>导航</span>
              </li>
              <li class="<?php echo checkIfActive('index,')?>">
                <a href="./">
                  <i class="glyphicon glyphicon-home icon text-primary-dker"></i>
				  <b class="label bg-info pull-right">N</b>
                  <span class="font-bold">用户中心</span>
                </a>
              </li>
              <li class="<?php echo checkIfActive('userinfo,editinfo,certificate,telegram')?>">
                <a href class="auto">      
                  <span class="pull-right text-muted">
                    <i class="fa fa-fw fa-angle-right text"></i>
                    <i class="fa fa-fw fa-angle-down text-active"></i>
                  </span>
                  <i class="glyphicon glyphicon-leaf icon text-success-lter"></i>
                  <span>个人资料</span>
                </a>
                <ul class="nav nav-sub dk">
				  <li>
                    <a href="userinfo.php?mod=api">
                      <span>API信息</span>
                    </a>
                  </li>
                  <li>
                    <a href="editinfo.php">
                      <span>修改资料</span>
                    </a>
                  </li>
				  <li>
                    <a href="userinfo.php?mod=account">
                      <span>修改密码</span>
                    </a>
                  </li>
				  <li>
                    <a href="telegram.php">
                      <span>Telegram通知</span>
                    </a>
                  </li>
				  <?php if($conf['cert_open']>0){?>
				  <li>
                    <a href="certificate.php">
                      <span>实名认证</span>
                    </a>
                  </li>
				  <?php }?>
                </ul>
              </li>
              <li class="line dk"></li>
              <li class="hidden-folded padder m-t m-b-sm text-muted text-xs">
                <span>查询</span>
              </li>
			  <li class="<?php echo checkIfActive('order')?>">
                <a href="order.php">
                  <i class="glyphicon glyphicon-list-alt"></i>
                  <span>订单记录</span>
                </a>
              </li>
			  <li class="<?php echo checkIfActive('settle')?>">
                <a href="settle.php">
                  <i class="glyphicon glyphicon-check"></i>
                  <span>结算记录</span>
                </a>
              </li>
			  <li class="<?php echo checkIfActive('record')?>">
                <a href="record.php">
                  <i class="glyphicon glyphicon-calendar"></i>
                  <span>资金明细</span>
                </a>
              </li>
			  <?php if($conf['settle_open']==2||$conf['settle_open']==3){?>
			  <li class="<?php echo checkIfActive('apply')?>">
                <a href="apply.php">
                  <i class="glyphicon glyphicon-edit"></i>
                  <span>申请提现<?php if((int)$userrow['settle']!==1){?> <small class="text-warning">结算受限</small><?php }?></span>
                </a>
              </li>
			  <?php }?>
			  <?php if($conf['recharge']==1){?>
			  <li class="<?php echo checkIfActive('recharge')?>">
                <a href="recharge.php">
                  <i class="glyphicon glyphicon-yen"></i>
                  <span>余额充值</span>
                </a>
              </li>
			  <?php }?>
			  <?php if($conf['group_buy']==1){?>
			  <li class="<?php echo checkIfActive('groupbuy')?>">
                <a href="groupbuy.php">
                  <i class="glyphicon glyphicon-shopping-cart"></i>
                  <span>购买会员</span>
                </a>
              </li>
			  <?php }?>
        <?php if($conf['pay_domain_open']==1){?>
			  <li class="<?php echo checkIfActive('domain')?>">
                <a href="domain.php">
                  <i class="glyphicon glyphicon-globe"></i>
                  <span>授权域名</span>
                </a>
              </li>
			  <?php }?>
        <?php if(!empty($conf['merchant_complain_view_enabled'])){?>
              <li class="<?php echo checkIfActive('complain,complain_info')?>">
                <a href="complain.php">
                  <?php
                  $complain_total = 0;
                  try{
                    $complain_total = (new \lib\Complain\MerchantViewService($DB, $conf))->pendingCount($uid);
                  }catch(Throwable $e){
                    error_log('Merchant complaint menu count failed: '.$e->getMessage());
                  }
                  if($complain_total>0) echo '<b class="label bg-danger pull-right">'.intval($complain_total).'</b>';
                  ?>
                  <i class="fa fa-commenting fa-fw"></i>
                  <span>交易投诉</span>
                </a>
              </li>
			  <?php }?>
              <li class="line dk hidden-folded"></li>

              <li class="hidden-folded padder m-t m-b-sm text-muted text-xs">          
                <span>其他</span>
              </li>
			  <?php if($conf['user_transfer']==1){?>
              <li class="<?php echo checkIfActive('transfer,transfer_add')?>">
                <a href="transfer.php">
                  <i class="fa fa-send-o fa-fw"></i>
                  <span>代付管理</span>
                </a>
              </li>
			  <?php }?>
        <?php if($conf['onecode']==1){?>
              <li class="<?php echo checkIfActive('onecode')?>">
                <a href="onecode.php">
                  <i class="fa fa-qrcode fa-fw"></i>
                  <span>聚合收款</span>
                </a>
              </li>
			  <?php }?>
        <?php if($conf['invite_open']==1){?>
              <li class="<?php echo checkIfActive('invite')?>">
                <a href="invite.php">
                  <i class="fa fa-share-alt fa-fw"></i>
                  <span>邀请返现</span>
                </a>
              </li>
			  <?php }?>
              <li>
                <a href="/doc.html" target="_blank">
                  <i class="fa fa-book"></i>
                  <span>开发文档</span>
                </a>
              </li>
              <li>
                <a href="help_center.php?doc=merchant-quickstart" target="_blank">
                  <i class="fa fa-question-circle"></i>
                  <span>使用帮助</span>
                </a>
              </li>
			  <?php if(!empty($conf['qqqun'])){?>
              <li>
                <a href="<?php echo $conf['qqqun']?>" target="blank">
                  <i class="fa fa-qq"></i>
                  <span>产品QQ群</span>
                </a>
              </li>
			  <?php }?>
			  <?php if(!empty($conf['appurl'])){?>
              <li>
                <a href="<?php echo $conf['appurl']?>" target="blank">
                  <i class="fa fa-android"></i>
                  <span>APP下载</span>
                </a>
              </li>
			  <?php }?>
            </ul>
          </nav>
          <!-- nav -->

          <!-- aside footer -->
          <div class="wrapper m-t">
            <div class="text-center-folded">
              <span class="pull-right pull-none-folded">60%</span>
              <span class="hidden-folded">Milestone</span>
            </div>
            <div class="progress progress-xxs m-t-sm dk">
              <div class="progress-bar progress-bar-info" style="width: 60%;">
              </div>
            </div>
            <div class="text-center-folded">
              <span class="pull-right pull-none-folded">35%</span>
              <span class="hidden-folded">Release</span>
            </div>
            <div class="progress progress-xxs m-t-sm dk">
              <div class="progress-bar progress-bar-primary" style="width: 35%;">
              </div>
            </div>
          </div>
          <!-- / aside footer -->
        </div>
      </div>
  </aside>
  <!-- / aside -->
  <!-- content -->
