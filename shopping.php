<?php
$nosession = true;
include("./includes/common.php");

@header('Content-Type: text/html; charset=UTF-8');

function shop_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function shop_money($value)
{
    return number_format(floatval($value), 2, '.', '');
}

function shop_message($title, $message, $back = true)
{
    $backHtml = $back ? '<p><a class="btn" href="shopping.php">返回商城</a></p>' : '';
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.shop_h($title).'</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;background:#f5f7fb;color:#20242a;margin:0}.box{max-width:680px;margin:12vh auto;background:#fff;border:1px solid #e5e8ef;border-radius:6px;padding:28px}.btn{display:inline-block;background:#1f7ae0;color:#fff;text-decoration:none;border-radius:4px;padding:9px 16px}</style></head><body><div class="box"><h2>'.shop_h($title).'</h2><p>'.shop_h($message).'</p>'.$backHtml.'</div></body></html>';
    exit;
}

function shop_render_header($config)
{
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.shop_h($config['shop_name']).'</title><style>
body{margin:0;background:#f4f6f9;color:#1f2933;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",Arial,sans-serif}
a{color:#1769d2;text-decoration:none}.top{background:#1f7ae0;color:#fff}.top-inner{max-width:1120px;margin:0 auto;padding:18px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px}.brand{font-size:20px;font-weight:700}.nav a{color:#fff;margin-left:16px}.wrap{max-width:1120px;margin:24px auto;padding:0 16px}.hero{background:#fff;border:1px solid #e5e8ef;border-radius:6px;padding:22px;margin-bottom:18px}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:16px}.card{background:#fff;border:1px solid #e5e8ef;border-radius:6px;overflow:hidden}.thumb{height:150px;background:#eef2f7;display:flex;align-items:center;justify-content:center;color:#9aa4b2}.thumb img{width:100%;height:100%;object-fit:cover}.body{padding:14px}.price{font-size:20px;color:#d93025;font-weight:700}.muted{color:#687385}.btn,.button{display:inline-block;border:0;background:#1f7ae0;color:#fff;border-radius:4px;padding:9px 14px;cursor:pointer}.btn-default{background:#eef2f7;color:#1f2933}.form{background:#fff;border:1px solid #e5e8ef;border-radius:6px;padding:18px}.field{margin-bottom:13px}.field label{display:block;font-weight:600;margin-bottom:6px}.field input,.field textarea,.field select{box-sizing:border-box;width:100%;border:1px solid #d9dee7;border-radius:4px;padding:10px;font-size:14px}.detail{display:grid;grid-template-columns:minmax(260px,420px) 1fr;gap:20px}.detail-img{background:#eef2f7;border:1px solid #e5e8ef;border-radius:6px;min-height:260px;display:flex;align-items:center;justify-content:center}.detail-img img{max-width:100%;max-height:420px}.checkout-summary{display:flex;align-items:center;gap:10px;margin-bottom:16px}.checkout-product-icon{display:block;width:36px;height:36px;flex:0 0 36px;object-fit:cover;border-radius:4px}.checkout-order{min-width:0}.checkout-order-label{font-size:12px;color:#687385;margin-bottom:3px}.checkout-order-no{font-weight:600;overflow-wrap:anywhere}.pay-panel{background:#fff;border:1px solid #e5e8ef;border-radius:6px;padding:18px}.pay-options{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-top:14px}.pay-option{box-sizing:border-box;display:flex;align-items:center;justify-content:center;gap:9px;min-height:48px;border:1px solid #b9cbe2;border-radius:4px;background:#fff;color:#1769d2;font-weight:600;padding:10px 14px}.pay-option:hover{border-color:#1f7ae0;background:#f4f8fd}.pay-option img{width:24px;height:24px;object-fit:contain}.table{width:100%;border-collapse:collapse;background:#fff}.table th,.table td{border:1px solid #e5e8ef;padding:10px;text-align:left}.footer{text-align:center;color:#8b95a1;padding:30px 16px}@media(max-width:720px){.top-inner{display:block}.nav{margin-top:8px}.nav a{margin-left:0;margin-right:12px}.detail{grid-template-columns:1fr}.pay-options{grid-template-columns:1fr}}
	</style></head><body><div class="top"><div class="top-inner"><div class="brand">'.shop_h($config['shop_name']).'</div><div class="nav"><a href="shopping.php">商城展示</a><a href="shopping.php?act=query">订单查询</a><a href="/">返回首页</a></div></div></div><main class="wrap">';
}

function shop_render_footer($config)
{
    echo '</main><div class="footer">'.shop_h($config['shop_name']).'</div></body></html>';
}

$act = isset($_GET['act']) ? trim($_GET['act']) : 'list';
$config = \lib\Shop\ConfigService::all();

if ($act === 'notify') {
    $payTradeNo = isset($_GET['trade_no']) ? daddslashes($_GET['trade_no']) : '';
    if ($payTradeNo !== '') {
        $order = $DB->getRow("SELECT * FROM pre_order WHERE trade_no=:trade_no LIMIT 1", array(':trade_no' => $payTradeNo));
        if ($order && intval($order['status']) === 1 && \lib\Shop\OrderService::isShopPaymentOrder($order)) {
            try {
                \lib\Shop\OrderService::markPaidFromPaymentOrder($order);
                exit('success');
            } catch (Exception $e) {
                exit('fail');
            }
        }
    }
    exit('success');
}

if ($act === 'submit' || $act === 'create') {
    shop_message('请从商户订单进入', '商城购买记录必须关联原商户支付订单，不能独立创建金额和收款商户。');
}

if ($act === 'continue') {
    try {
        $result = \lib\Shop\OrderService::continueCheckout(
            isset($_GET['trade_no']) ? trim($_GET['trade_no']) : '',
            isset($_GET['token']) ? trim($_GET['token']) : '',
            isset($_GET['pay_type']) ? intval($_GET['pay_type']) : 0
        );
        header('Location: '.$result['pay_url']);
        exit;
    } catch (Exception $e) {
        shop_message('继续支付失败', $e->getMessage(), false);
    }
}

if ($act === 'repay') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        shop_message('请求错误', '重新支付请求方式不正确');
    }
    try {
        $result = \lib\Shop\OrderService::repay(
            isset($_POST['trade_no']) ? trim($_POST['trade_no']) : '',
            isset($_POST['token']) ? trim($_POST['token']) : ''
        );
        header('Location: '.$result['pay_url']);
        exit;
    } catch (Exception $e) {
        shop_message('重新支付失败', $e->getMessage());
    }
}

shop_render_header($config);

if ($act === 'checkout') {
    try {
        $checkout = \lib\Shop\OrderService::getCheckout(
            isset($_GET['trade_no']) ? trim($_GET['trade_no']) : '',
            isset($_GET['token']) ? trim($_GET['token']) : ''
        );
        $checkoutImage = !empty($checkout['goods_image']) ? '<img class="checkout-product-icon" src="'.shop_h($checkout['goods_image']).'" alt="" aria-hidden="true">' : '';
        echo '<div class="hero"><h1>确认购买</h1><p class="muted">请确认订单金额并选择支付方式。</p></div>';
        echo '<div class="detail"><div class="hero"><div class="checkout-summary">'.$checkoutImage.'<div class="checkout-order"><div class="checkout-order-label">订单号</div><div class="checkout-order-no">'.shop_h($checkout['out_trade_no']).'</div></div></div><p class="price">￥'.shop_money($checkout['original_money']).'</p><p class="muted">订单金额和收款商户不可修改。</p></div><div class="pay-panel">';
        if (intval($checkout['payment_status']) > 0 || intval($checkout['pay_status']) === \lib\Shop\OrderService::PAY_PAID) {
            echo '<h2>订单已支付</h2><p><a class="btn" href="shopping.php?act=query&amp;trade_no='.rawurlencode($checkout['shop_trade_no']).'&amp;token='.shop_h($checkout['query_token']).'">查看商城订单</a></p>';
        } elseif (empty($checkout['pay_types'])) {
            echo '<h2>暂无可用支付方式</h2><p class="muted">请联系原订单商户检查支付通道配置。</p>';
        } else {
            echo '<h2>选择支付方式</h2><div class="pay-options">';
            foreach ($checkout['pay_types'] as $type) {
                $continueUrl = 'shopping.php?act=continue&amp;trade_no='.rawurlencode($checkout['shop_trade_no']).'&amp;token='.rawurlencode($checkout['query_token']).'&amp;pay_type='.intval($type['id']);
                $icon = isset($type['name']) && $type['name'] !== '' ? '<img src="assets/icon/'.shop_h($type['name']).'.ico" alt="">' : '';
                echo '<a class="pay-option" href="'.$continueUrl.'">'.$icon.'<span>'.shop_h($type['showname']).'</span></a>';
            }
            echo '</div><p class="muted">选择后将继续支付当前原订单。</p>';
        }
        echo '</div></div>';
    } catch (Exception $e) {
        echo '<div class="hero"><h2>无法打开购买页面</h2><p>'.shop_h($e->getMessage()).'</p></div>';
    }
} elseif ($act === 'detail') {
    if (!\lib\Shop\ConfigService::isEnabled()) {
        echo '<div class="hero"><h2>商城暂未开启</h2><p class="muted">请稍后再试。</p></div>';
        shop_render_footer($config);
        exit;
    }
    $goods = \lib\Shop\GoodsService::getPublic(isset($_GET['id']) ? intval($_GET['id']) : 0);
    if (!$goods) {
        echo '<div class="hero"><h2>商品不存在或已下架</h2><p><a class="btn" href="shopping.php">返回商品列表</a></p></div>';
        shop_render_footer($config);
        exit;
    }
    echo '<div class="detail"><div class="detail-img">';
    if (!empty($goods['image'])) {
        echo '<img src="'.shop_h($goods['image']).'" alt="'.shop_h($goods['name']).'">';
    } else {
        echo '<span>暂无图片</span>';
    }
    echo '</div><div class="form"><h2>'.shop_h($goods['name']).'</h2><p class="muted">'.nl2br(shop_h($goods['description'])).'</p><p class="muted">具体服务内容与金额以实际订单为准。</p><p><a class="btn btn-default" href="shopping.php">返回商城展示</a></p>';
    echo '</div></div>';
} elseif ($act === 'query') {
    $tradeNo = isset($_REQUEST['trade_no']) ? trim($_REQUEST['trade_no']) : '';
    $token = isset($_REQUEST['token']) ? trim($_REQUEST['token']) : '';
    $last4 = isset($_REQUEST['contact_last4']) ? trim($_REQUEST['contact_last4']) : '';
    echo '<div class="hero"><h2>订单查询</h2><form method="post" action="shopping.php?act=query" class="form" style="padding:0;border:0">
      <div class="field"><label>商城订单号</label><input type="text" name="trade_no" value="'.shop_h($tradeNo).'" placeholder="S 开头的商城订单号"></div>
      <div class="field"><label>查询凭证</label><input type="text" name="token" value="'.shop_h($token).'" placeholder="下单后生成的查询 token"></div>
      <div class="field"><label>手机号后四位</label><input type="text" name="contact_last4" value="'.shop_h($last4).'" placeholder="没有 token 时填写"></div>
      <button class="button" type="submit">查询订单</button>
    </form></div>';
    if ($tradeNo !== '') {
        try {
            $order = \lib\Shop\OrderService::publicQuery($tradeNo, $token, $last4);
            echo '<table class="table"><tr><th>商城订单号</th><td>'.shop_h($order['shop_trade_no']).'</td></tr><tr><th>商品</th><td>'.shop_h($order['goods_name']).' × '.intval($order['quantity']).'</td></tr><tr><th>金额</th><td>￥'.shop_money($order['money']).'</td></tr><tr><th>支付状态</th><td>'.shop_h(\lib\Shop\OrderService::payStatusText($order['pay_status'])).'</td></tr><tr><th>履约状态</th><td>'.shop_h(\lib\Shop\OrderService::orderStatusText($order['order_status'])).'</td></tr><tr><th>联系方式</th><td>'.shop_h($order['buyer_contact_masked']).'</td></tr><tr><th>物流</th><td>'.shop_h($order['logistics_company']).' '.shop_h($order['tracking_no']).'</td></tr></table>';
            if (intval($order['pay_status']) === \lib\Shop\OrderService::PAY_PENDING && $token !== '') {
                echo '<form method="post" action="shopping.php?act=repay"><input type="hidden" name="trade_no" value="'.shop_h($order['shop_trade_no']).'"><input type="hidden" name="token" value="'.shop_h($token).'"><button class="button" type="submit">继续支付</button></form>';
            }
        } catch (Exception $e) {
            echo '<div class="hero"><p>'.shop_h($e->getMessage()).'</p></div>';
        }
    }
} else {
    echo '<div class="hero"><h1>'.shop_h($config['shop_name']).'</h1><p class="muted">'.shop_h($config['shop_desc']).'</p></div>';
    if (!\lib\Shop\ConfigService::isEnabled()) {
        echo '<div class="hero"><h2>商城暂未开启</h2><p class="muted">当前页面仅保留展示入口。</p></div>';
    } else {
        $goodsList = \lib\Shop\GoodsService::publicList(60);
        if (empty($goodsList)) {
            echo '<div class="hero"><p class="muted">暂无上架商品。</p></div>';
        } else {
            echo '<div class="grid">';
            foreach ($goodsList as $goods) {
                echo '<div class="card"><div class="thumb">';
                if (!empty($goods['image'])) {
                    echo '<img src="'.shop_h($goods['image']).'" alt="'.shop_h($goods['name']).'">';
                } else {
                    echo '<span>暂无图片</span>';
                }
                $summary = mb_substr(trim((string)$goods['description']), 0, 42, 'UTF-8');
                echo '</div><div class="body"><h3>'.shop_h($goods['name']).'</h3><p class="muted">'.shop_h($summary).'</p><p><a class="btn" href="shopping.php?act=detail&id='.intval($goods['id']).'">查看详情</a></p></div></div>';
            }
            echo '</div>';
        }
    }
}

shop_render_footer($config);
