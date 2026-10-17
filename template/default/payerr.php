<?php
if(!defined('IN_CRONLITE'))exit();
?><html class="weui-msg lp-dark" data-lp-theme="pay">
<head>
    <meta charset="UTF-8">
    <meta id="viewport" name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">
    <title>错误提示</title>
    <style id="lp-dark-boot">html.lp-dark,html.lp-dark body{background:#0B0F14!important;color:#E8EEF5}</style>
    <link href="/assets/css/weui.min.css" rel="stylesheet">
    <style>.page{position:absolute;top:0;right:0;bottom:0;left:0;overflow-y:auto;-webkit-overflow-scrolling:touch;box-sizing:border-box}</style>
    <link rel="stylesheet" href="/assets/css/lp-dark/tokens.css?v=lp18">
    <link rel="stylesheet" href="/assets/css/lp-dark/weui.css?v=lp18">
</head>
<body>
<div class="container">
<div class="page">
<div class="weui-msg">
    <div class="weui-msg__icon-area">
        <i class="weui-icon-warn weui-icon_msg"></i>
    </div>
    <div class="weui-msg__text-area">
        <h2 class="weui-msg__title">该订单处理异常，已自动退款！</h2>
    </div>
    <div class="weui-msg__opr-area">
        <p class="weui-btn-area">
            <a href="javascript:;" class="weui-btn weui-btn_default" id="Close">关闭</a>
        </p>
    </div>
    <div class="weui-msg__extra-area">
        <div class="weui-footer"><p class="weui-footer__links"></p></div>
    </div>
</div>
</div>
</div>
<script src="/assets/cdn/jquery/1.12.4/jquery.min.js"></script>
<script src="/paypage/js/close.js"></script>
<script>
document.body.addEventListener('touchmove', function (event) {
	event.preventDefault();
},{ passive: false });
</script>
</body>
</html>
