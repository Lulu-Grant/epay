<?php
include("../includes/common.php");

@header('Content-Type: application/json; charset=UTF-8');

if ($islogin != 1) {
    exit(json_encode(array('code' => -1, 'msg' => 'No Login'), JSON_UNESCAPED_UNICODE));
}

$act = isset($_GET['act']) ? daddslashes($_GET['act']) : '';

function shop_json($data)
{
    exit(json_encode($data, JSON_UNESCAPED_UNICODE));
}

function shop_csrf_token()
{
    if (empty($_SESSION['shop_csrf_token'])) {
        $_SESSION['shop_csrf_token'] = random(32);
    }
    return $_SESSION['shop_csrf_token'];
}

function shop_check_csrf()
{
    if (!checkRefererHost()) {
        shop_json(array('code' => 403, 'msg' => 'Referer Error'));
    }
    $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : (isset($_GET['csrf_token']) ? $_GET['csrf_token'] : '');
    if ($token === '' || empty($_SESSION['shop_csrf_token']) || !hash_equals($_SESSION['shop_csrf_token'], $token)) {
        shop_json(array('code' => 403, 'msg' => 'CSRF Token Error'));
    }
}

function shop_close_read_session()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

try {
    switch ($act) {
        case 'saveConfig':
            shop_check_csrf();
            $config = \lib\Shop\ConfigService::save($_POST);
            shop_json(array('code' => 0, 'msg' => '保存成功', 'data' => $config));
            break;

        case 'goodsList':
            shop_close_read_session();
            $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
            $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 20;
            $filters = array(
                'keyword' => isset($_POST['keyword']) ? trim($_POST['keyword']) : '',
                'status' => isset($_POST['status']) ? $_POST['status'] : '-1',
            );
            shop_json(\lib\Shop\GoodsService::adminList($filters, $offset, $limit));
            break;

        case 'getGoods':
            shop_close_read_session();
            $row = \lib\Shop\GoodsService::get(isset($_GET['id']) ? intval($_GET['id']) : 0, true);
            if (!$row) {
                shop_json(array('code' => -1, 'msg' => '商品不存在'));
            }
            shop_json(array('code' => 0, 'data' => $row));
            break;

        case 'saveGoods':
            shop_check_csrf();
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            if ($id > 0) {
                \lib\Shop\GoodsService::update($id, $_POST);
                shop_json(array('code' => 0, 'msg' => '商品已更新'));
            }
            $newId = \lib\Shop\GoodsService::create($_POST);
            shop_json(array('code' => 0, 'msg' => '商品已新增', 'id' => $newId));
            break;

        case 'setGoodsStatus':
            shop_check_csrf();
            \lib\Shop\GoodsService::setStatus(isset($_POST['id']) ? intval($_POST['id']) : 0, isset($_POST['status']) ? intval($_POST['status']) : 0);
            shop_json(array('code' => 0, 'msg' => '状态已更新'));
            break;

        case 'deleteGoods':
            shop_check_csrf();
            \lib\Shop\GoodsService::softDelete(isset($_POST['id']) ? intval($_POST['id']) : 0);
            shop_json(array('code' => 0, 'msg' => '商品已删除'));
            break;

        case 'orderList':
            shop_close_read_session();
            $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
            $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 20;
            $filters = array(
                'keyword' => isset($_POST['keyword']) ? trim($_POST['keyword']) : '',
                'pay_status' => isset($_POST['pay_status']) ? $_POST['pay_status'] : '-1',
                'order_status' => isset($_POST['order_status']) ? $_POST['order_status'] : '-1',
            );
            shop_json(\lib\Shop\OrderService::adminList($filters, $offset, $limit));
            break;

        case 'getOrder':
            shop_close_read_session();
            $row = \lib\Shop\OrderService::adminGet(isset($_GET['id']) ? intval($_GET['id']) : 0);
            if (!$row) {
                shop_json(array('code' => -1, 'msg' => '订单不存在'));
            }
            $row['pay_status_text'] = \lib\Shop\OrderService::payStatusText($row['pay_status']);
            $row['order_status_text'] = \lib\Shop\OrderService::orderStatusText($row['order_status']);
            shop_json(array('code' => 0, 'data' => $row));
            break;

        case 'saveLogistics':
            shop_check_csrf();
            \lib\Shop\OrderService::updateLogistics(
                isset($_POST['id']) ? intval($_POST['id']) : 0,
                isset($_POST['logistics_company']) ? $_POST['logistics_company'] : '',
                isset($_POST['tracking_no']) ? $_POST['tracking_no'] : '',
                isset($_POST['order_status']) ? intval($_POST['order_status']) : \lib\Shop\OrderService::ORDER_WAIT_SHIP
            );
            shop_json(array('code' => 0, 'msg' => '物流信息已保存'));
            break;

        case 'deleteOrder':
            shop_check_csrf();
            \lib\Shop\OrderService::softDelete(isset($_POST['id']) ? intval($_POST['id']) : 0);
            shop_json(array('code' => 0, 'msg' => '订单已删除'));
            break;

        case 'summary':
            shop_close_read_session();
            shop_json(array('code' => 0, 'data' => \lib\Shop\OrderService::summary()));
            break;

        default:
            shop_json(array('code' => -1, 'msg' => 'Unknown Action'));
    }
} catch (Exception $e) {
    shop_json(array('code' => -1, 'msg' => $e->getMessage()));
}
