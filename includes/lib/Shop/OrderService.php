<?php
namespace lib\Shop;

use Exception;

class OrderService
{
    const PAY_PENDING = 0;
    const PAY_PAID = 1;
    const PAY_CANCELLED = 2;
    const PAY_REFUNDED = 3;

    const ORDER_WAIT_PAY = 0;
    const ORDER_WAIT_SHIP = 1;
    const ORDER_SHIPPED = 2;
    const ORDER_SIGNED = 3;
    const ORDER_FINISHED = 4;
    const ORDER_CANCELLED = 5;

    public static function isShopPayment($param)
    {
        return self::parseShopTradeNo($param) !== false;
    }

    public static function isShopPaymentOrder($paymentOrder)
    {
        global $DB;
        if (is_array($paymentOrder) && !empty($paymentOrder['trade_no'])) {
            $exists = $DB->getColumn("SELECT id FROM pre_shop_orders WHERE pay_trade_no=:trade_no LIMIT 1", array(':trade_no' => $paymentOrder['trade_no']));
            if ($exists) {
                return true;
            }
        }
        return self::isShopPayment(is_array($paymentOrder) && isset($paymentOrder['param']) ? $paymentOrder['param'] : null);
    }

    public static function parseShopTradeNo($param)
    {
        if (!is_string($param) || substr($param, 0, 5) !== 'shop:') {
            return false;
        }
        $tradeNo = substr($param, 5);
        if (!preg_match('/^S[0-9]{21}$/', $tradeNo)) {
            return false;
        }
        return $tradeNo;
    }

    public static function makeShopParam($shopTradeNo)
    {
        return 'shop:'.$shopTradeNo;
    }

    public static function makeShopTradeNo()
    {
        global $DB;
        for ($i = 0; $i < 10; $i++) {
            $tradeNo = 'S'.date('YmdHis').mt_rand(1000000, 9999999);
            $exists = $DB->getColumn("SELECT id FROM pre_shop_orders WHERE shop_trade_no=:trade_no LIMIT 1", array(':trade_no' => $tradeNo));
            if (!$exists) {
                return $tradeNo;
            }
        }
        throw new Exception('生成商城订单号失败');
    }

    public static function makePayTradeNo()
    {
        global $DB;
        for ($i = 0; $i < 10; $i++) {
            $tradeNo = date('YmdHis').mt_rand(11111, 99999);
            $exists = $DB->getColumn("SELECT trade_no FROM pre_order WHERE trade_no=:trade_no LIMIT 1", array(':trade_no' => $tradeNo));
            if (!$exists) {
                return $tradeNo;
            }
        }
        throw new Exception('生成支付订单号失败');
    }

    public static function makeQueryToken()
    {
        if (function_exists('random_bytes')) {
            try {
                return bin2hex(random_bytes(16));
            } catch (Exception $e) {
            }
        }
        return md5(uniqid('', true).mt_rand());
    }

    public static function createOrder($payload)
    {
        throw new Exception('商城购买记录必须由原商户支付订单创建');
    }

    public static function recordPaymentOrder($payTradeNo, $requestedType = '')
    {
        global $DB, $siteurl;
        ConfigService::assertReady();
        $paymentOrder = $DB->getRow("SELECT * FROM pre_order WHERE trade_no=:trade_no LIMIT 1", array(':trade_no' => $payTradeNo));
        if (!$paymentOrder) {
            throw new Exception('原支付订单不存在');
        }
        if (intval($paymentOrder['tid']) !== 0) {
            throw new Exception('当前订单类型不支持商城购买流程');
        }
        if (!ConfigService::shouldRecordMerchant($paymentOrder['uid'])) {
            return false;
        }

        $user = $DB->getRow("SELECT uid,gid FROM pre_user WHERE uid=:uid LIMIT 1", array(':uid' => intval($paymentOrder['uid'])));
        if (!$user) {
            throw new Exception('原订单商户不存在');
        }
        $payType = intval($paymentOrder['type']);
        if ($payType <= 0 && trim((string)$requestedType) !== '') {
            try {
                $payType = self::resolveRequestedType($requestedType, intval($user['uid']), intval($user['gid']));
            } catch (Exception $e) {
                if (!ConfigService::isShadowMode()) {
                    throw $e;
                }
                $payType = 0;
            }
        }
        $existing = $DB->getRow("SELECT * FROM pre_shop_orders WHERE pay_trade_no=:trade_no LIMIT 1", array(':trade_no' => $payTradeNo));
        if ($existing) {
            if (intval($existing['pay_type']) === 0 && $payType > 0) {
                $DB->update('shop_orders', array('pay_type' => $payType, 'updatetime' => 'NOW()'), array('id' => intval($existing['id'])));
                $existing['pay_type'] = $payType;
            }
            return self::attachmentResult($existing);
        }

        $shopTradeNo = self::makeShopTradeNo();
        $queryToken = self::makeQueryToken();
        $money = number_format(floatval($paymentOrder['money']), 2, '.', '');
        $now = date('Y-m-d H:i:s');
        $catalogGoods = self::pickRandomCatalogGoods();
        $goodsId = $catalogGoods ? intval($catalogGoods['id']) : 0;
        $goodsName = $catalogGoods
            ? mb_substr((string)$catalogGoods['name'], 0, 120, 'UTF-8')
            : mb_substr(html_entity_decode((string)$paymentOrder['name'], ENT_QUOTES, 'UTF-8'), 0, 120, 'UTF-8');
        $goodsImage = $catalogGoods && !empty($catalogGoods['image']) ? (string)$catalogGoods['image'] : null;
        $statusTimes = array(
            'created' => $now,
            'source' => ConfigService::isShadowMode() ? 'merchant_order_shadow' : 'merchant_order',
        );
        if ($goodsId > 0) {
            $statusTimes['catalog_goods_id'] = $goodsId;
        } else {
            $statusTimes['catalog_fallback'] = 'original_order';
        }
        $shopId = $DB->insert('shop_orders', array(
            'shop_trade_no' => $shopTradeNo,
            'pay_trade_no' => $paymentOrder['trade_no'],
            'out_trade_no' => $paymentOrder['out_trade_no'],
            'goods_id' => $goodsId,
            'goods_name' => $goodsName,
            'goods_price' => $money,
            'goods_image' => $goodsImage,
            'quantity' => 1,
            'money' => $money,
            'pay_type' => $payType,
            'pay_status' => self::PAY_PENDING,
            'order_status' => self::ORDER_WAIT_PAY,
            'buyer_name' => '',
            'buyer_contact' => '',
            'buyer_remark' => '',
            'query_token' => $queryToken,
            'status_times' => json_encode($statusTimes, JSON_UNESCAPED_UNICODE),
            'deleted' => 0,
            'addtime' => 'NOW()',
            'updatetime' => 'NOW()',
        ));
        if (!$shopId) {
            $existing = $DB->getRow("SELECT * FROM pre_shop_orders WHERE pay_trade_no=:trade_no LIMIT 1", array(':trade_no' => $payTradeNo));
            if ($existing) {
                return self::attachmentResult($existing);
            }
            throw new Exception('创建商城购买记录失败：'.$DB->error());
        }

        return array(
            'shop_trade_no' => $shopTradeNo,
            'pay_trade_no' => $paymentOrder['trade_no'],
            'query_token' => $queryToken,
            'money' => $money,
            'checkout_url' => $siteurl.'shopping.php?act=checkout&trade_no='.rawurlencode($shopTradeNo).'&token='.$queryToken,
        );
    }

    public static function attachPaymentOrder($payTradeNo, $requestedType = '')
    {
        global $DB;
        if (!ConfigService::shouldUseCheckout($DB->getColumn("SELECT uid FROM pre_order WHERE trade_no=:trade_no LIMIT 1", array(':trade_no' => $payTradeNo)))) {
            return false;
        }
        $paymentStatus = $DB->getColumn("SELECT status FROM pre_order WHERE trade_no=:trade_no LIMIT 1", array(':trade_no' => $payTradeNo));
        if ($paymentStatus !== false && intval($paymentStatus) > 0) {
            throw new Exception('原支付订单已完成');
        }
        return self::recordPaymentOrder($payTradeNo, $requestedType);
    }

    public static function syncPaymentRoute($payTradeNo)
    {
        global $DB;
        $paymentOrder = $DB->getRow("SELECT trade_no,type FROM pre_order WHERE trade_no=:trade_no LIMIT 1", array(':trade_no' => $payTradeNo));
        if (!$paymentOrder || intval($paymentOrder['type']) <= 0) {
            return false;
        }
        $order = $DB->getRow("SELECT id,pay_type,status_times FROM pre_shop_orders WHERE pay_trade_no=:trade_no LIMIT 1", array(':trade_no' => $payTradeNo));
        if (!$order) {
            return false;
        }
        $times = self::decodeStatusTimes($order['status_times']);
        $changed = intval($order['pay_type']) !== intval($paymentOrder['type']) || empty($times['payment_started']);
        if (!$changed) {
            return true;
        }
        if (empty($times['payment_started'])) {
            $times['payment_started'] = date('Y-m-d H:i:s');
        }
        $ok = $DB->update('shop_orders', array(
            'pay_type' => intval($paymentOrder['type']),
            'status_times' => json_encode($times, JSON_UNESCAPED_UNICODE),
            'updatetime' => 'NOW()',
        ), array('id' => intval($order['id'])));
        if ($ok === false) {
            throw new Exception('同步商城支付方式失败：'.$DB->error());
        }
        return true;
    }

    public static function reconcilePaymentOrder($payTradeNo)
    {
        global $DB;
        $paymentOrder = $DB->getRow("SELECT * FROM pre_order WHERE trade_no=:trade_no LIMIT 1", array(':trade_no' => $payTradeNo));
        if (!$paymentOrder || intval($paymentOrder['tid']) !== 0) {
            return false;
        }
        $exists = $DB->getColumn("SELECT id FROM pre_shop_orders WHERE pay_trade_no=:trade_no LIMIT 1", array(':trade_no' => $payTradeNo));
        if (!$exists) {
            if (!ConfigService::shouldRecordMerchant($paymentOrder['uid'])) {
                return false;
            }
            self::recordPaymentOrder($payTradeNo);
        }
        self::syncPaymentRoute($payTradeNo);
        if (intval($paymentOrder['status']) > 0) {
            self::markPaidFromPaymentOrder($paymentOrder);
        }
        return true;
    }

    public static function reconcilePending($limit = 200)
    {
        global $DB;
        $result = array('scanned' => 0, 'reconciled' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => array());
        if (!ConfigService::isEnabled() || !ConfigService::isShadowMode()) {
            $result['disabled'] = true;
            return $result;
        }
        $startedAt = trim((string)ConfigService::get('shop_shadow_started_at', ''));
        if ($startedAt === '') {
            $result['missing_started_at'] = true;
            return $result;
        }
        $limit = max(1, min(1000, intval($limit)));
        $excludedUids = ConfigService::getExcludedUids();
        $missingCondition = 'S.id IS NULL';
        if (!empty($excludedUids)) {
            $missingCondition = '(S.id IS NULL AND P.uid NOT IN ('.implode(',', array_map('intval', $excludedUids)).'))';
        }
        $rows = $DB->getAll("SELECT P.trade_no,P.uid,S.id shop_id FROM pre_order P LEFT JOIN pre_shop_orders S ON S.pay_trade_no=P.trade_no WHERE P.tid=0 AND P.addtime>=:started_at AND (".$missingCondition." OR (S.id IS NOT NULL AND P.type>0 AND S.pay_type<>P.type) OR (S.id IS NOT NULL AND P.status>0 AND (S.pay_status<>1 OR S.order_status IN (0,1)))) ORDER BY P.addtime ASC LIMIT ".$limit, array(':started_at' => $startedAt));
        if (!is_array($rows)) {
            throw new Exception('扫描商城影子订单失败：'.$DB->error());
        }
        foreach ($rows as $row) {
            $result['scanned']++;
            if (empty($row['shop_id']) && !ConfigService::shouldRecordMerchant($row['uid'])) {
                $result['skipped']++;
                continue;
            }
            try {
                if (self::reconcilePaymentOrder($row['trade_no'])) {
                    $result['reconciled']++;
                } else {
                    $result['skipped']++;
                }
            } catch (Exception $e) {
                $result['failed']++;
                if (count($result['errors']) < 20) {
                    $result['errors'][] = array('trade_no' => $row['trade_no'], 'message' => $e->getMessage());
                }
            }
        }
        return $result;
    }

    public static function getCheckout($shopTradeNo, $queryToken)
    {
        global $DB;
        $order = self::checkoutRow($shopTradeNo, $queryToken);
        $payTypes = \lib\Channel::getTypes(intval($order['merchant_uid']), intval($order['merchant_gid']));
        if (intval($order['pay_type']) > 0) {
            $fixed = array();
            if (isset($payTypes[$order['pay_type']])) {
                $fixed[$order['pay_type']] = $payTypes[$order['pay_type']];
            }
            $payTypes = $fixed;
        }
        $order['pay_types'] = is_array($payTypes) ? $payTypes : array();
        return $order;
    }

    public static function continueCheckout($shopTradeNo, $queryToken, $payType)
    {
        global $DB, $siteurl;
        $payType = intval($payType);

        $transactionOpen = false;
        $DB->beginTransaction();
        $transactionOpen = true;
        try {
            $order = self::checkoutRow($shopTradeNo, $queryToken, true);
            if (intval($order['payment_status']) > 0 || intval($order['pay_status']) !== self::PAY_PENDING) {
                throw new Exception('当前订单不允许继续支付');
            }
            $payTypes = \lib\Channel::getTypes(intval($order['merchant_uid']), intval($order['merchant_gid']));
            $payType = intval($order['pay_type']) > 0 ? intval($order['pay_type']) : $payType;
            if ($payType <= 0 || !isset($payTypes[$payType])) {
                throw new Exception('请选择当前商户可用的支付方式');
            }
            $times = json_decode((string)$order['status_times'], true);
            if (!is_array($times)) {
                $times = array();
            }
            $times['confirmed'] = date('Y-m-d H:i:s');
            $ok = $DB->update('shop_orders', array(
                'pay_type' => $payType,
                'status_times' => json_encode($times, JSON_UNESCAPED_UNICODE),
                'updatetime' => 'NOW()',
            ), array('id' => intval($order['id'])));
            if ($ok === false) {
                throw new Exception('保存支付方式失败：'.$DB->error());
            }
            $DB->commit();
            $transactionOpen = false;
            return array(
                'pay_url' => self::buildPayUrl($order['pay_trade_no'], $payType),
                'query_url' => $siteurl.'shopping.php?act=query&trade_no='.rawurlencode($order['shop_trade_no']).'&token='.$order['query_token'],
            );
        } catch (Exception $e) {
            if ($transactionOpen) {
                $DB->rollBack();
            }
            throw $e;
        }
    }

    public static function confirmCheckout($shopTradeNo, $queryToken, $payload)
    {
        $payType = isset($payload['pay_type']) ? intval($payload['pay_type']) : 0;
        return self::continueCheckout($shopTradeNo, $queryToken, $payType);
    }

    public static function markPaidFromPaymentOrder($paymentOrder)
    {
        global $DB;
        $shopTradeNo = self::parseShopTradeNo(isset($paymentOrder['param']) ? $paymentOrder['param'] : null);

        $transactionOpen = false;
        $DB->beginTransaction();
        $transactionOpen = true;
        try {
            if ($shopTradeNo) {
                $order = $DB->getRow("SELECT * FROM pre_shop_orders WHERE shop_trade_no=:trade_no LIMIT 1 FOR UPDATE", array(':trade_no' => $shopTradeNo));
            } else {
                $order = $DB->getRow("SELECT * FROM pre_shop_orders WHERE pay_trade_no=:trade_no LIMIT 1 FOR UPDATE", array(':trade_no' => isset($paymentOrder['trade_no']) ? $paymentOrder['trade_no'] : ''));
            }
            if (!$order) {
                throw new Exception('非商城支付订单');
            }
            $shopTradeNo = $order['shop_trade_no'];
            if ($order['pay_trade_no'] !== $paymentOrder['trade_no']) {
                throw new Exception('商城订单与支付订单不匹配');
            }
            $actualPayType = isset($paymentOrder['type']) ? intval($paymentOrder['type']) : 0;
            if ($actualPayType > 0 && intval($order['pay_type']) !== $actualPayType) {
                $ok = $DB->update('shop_orders', array(
                    'pay_type' => $actualPayType,
                    'updatetime' => 'NOW()',
                ), array('id' => intval($order['id'])));
                if ($ok === false) {
                    throw new Exception('同步商城支付方式失败：'.$DB->error());
                }
                $order['pay_type'] = $actualPayType;
            }
            if (intval($order['pay_status']) === self::PAY_PAID) {
                if (in_array(intval($order['order_status']), array(self::ORDER_WAIT_PAY, self::ORDER_WAIT_SHIP), true)) {
                    $times = json_decode((string)$order['status_times'], true);
                    if (!is_array($times)) {
                        $times = array();
                    }
                    $shippedAt = !empty($order['paytime']) ? $order['paytime'] : date('Y-m-d H:i:s');
                    if (empty($times['paid'])) {
                        $times['paid'] = $shippedAt;
                    }
                    if (empty($times['shipped'])) {
                        $times['shipped'] = $shippedAt;
                    }
                    $ok = $DB->update('shop_orders', array(
                        'order_status' => self::ORDER_SHIPPED,
                        'status_times' => json_encode($times, JSON_UNESCAPED_UNICODE),
                        'updatetime' => 'NOW()',
                    ), array('id' => intval($order['id'])));
                    if ($ok === false) {
                        throw new Exception('更新商城自动发货状态失败：'.$DB->error());
                    }
                }
                $DB->commit();
                $transactionOpen = false;
                return array('code' => 0, 'msg' => '商城订单已支付并发货');
            }

            $times = array();
            if (!empty($order['status_times'])) {
                $decoded = json_decode($order['status_times'], true);
                if (is_array($decoded)) {
                    $times = $decoded;
                }
            }
            $paidAt = date('Y-m-d H:i:s');
            $times['paid'] = $paidAt;
            $times['shipped'] = $paidAt;

            $goods = $DB->getRow("SELECT id,stock FROM pre_shop_goods WHERE id=:id LIMIT 1 FOR UPDATE", array(':id' => intval($order['goods_id'])));
            if ($goods && intval($goods['stock']) > -1) {
                $stock = intval($goods['stock']);
                $quantity = intval($order['quantity']);
                if ($stock >= $quantity) {
                    $ok = $DB->exec("UPDATE pre_shop_goods SET stock=stock-:quantity,updatetime=NOW() WHERE id=:id", array(':quantity' => $quantity, ':id' => intval($goods['id'])));
                    if ($ok === false) {
                        throw new Exception('扣减商品库存失败：'.$DB->error());
                    }
                } else {
                    $times['stock_warning'] = 'paid_but_stock_insufficient';
                }
            }

            $ok = $DB->update('shop_orders', array(
                'pay_status' => self::PAY_PAID,
                'order_status' => self::ORDER_SHIPPED,
                'paytime' => 'NOW()',
                'pay_api_trade_no' => isset($paymentOrder['api_trade_no']) ? $paymentOrder['api_trade_no'] : null,
                'status_times' => json_encode($times, JSON_UNESCAPED_UNICODE),
                'updatetime' => 'NOW()',
            ), array('shop_trade_no' => $shopTradeNo));
            if ($ok === false) {
                throw new Exception('更新商城订单状态失败：'.$DB->error());
            }

            $DB->commit();
            $transactionOpen = false;
            return array('code' => 0, 'msg' => '商城订单已支付并自动发货');
        } catch (Exception $e) {
            if ($transactionOpen) {
                $DB->rollBack();
            }
            throw $e;
        }
    }

    public static function repay($shopTradeNo, $queryToken)
    {
        global $DB, $siteurl;
        if (!preg_match('/^S[0-9]{21}$/', (string)$shopTradeNo)) {
            throw new Exception('订单号格式不正确');
        }
        if ($queryToken === '') {
            throw new Exception('缺少查询凭证');
        }

        $order = $DB->getRow("SELECT * FROM pre_shop_orders WHERE shop_trade_no=:trade_no AND deleted=0 LIMIT 1", array(':trade_no' => $shopTradeNo));
        if (!$order || !hash_equals($order['query_token'], $queryToken)) {
            throw new Exception('订单查询凭证错误');
        }
        if (intval($order['pay_status']) !== self::PAY_PENDING) {
            throw new Exception('当前订单不允许重新支付');
        }
        $payOrder = $DB->getRow("SELECT * FROM pre_order WHERE trade_no=:trade_no LIMIT 1", array(':trade_no' => $order['pay_trade_no']));
        if (!$payOrder) {
            throw new Exception('原支付订单不存在，不能重新创建');
        }
        if (intval($payOrder['status']) === 1) {
            self::markPaidFromPaymentOrder($payOrder);
            return array(
                'code' => 0,
                'msg' => '支付订单已完成，商城订单已同步',
                'pay_url' => $siteurl.'shopping.php?act=query&trade_no='.rawurlencode($order['shop_trade_no']).'&token='.$order['query_token'],
            );
        }
        return array('code' => 0, 'msg' => '继续支付', 'pay_url' => self::buildPayUrl($order['pay_trade_no'], intval($order['pay_type'])));
    }

    public static function publicQuery($shopTradeNo, $token, $contactLast4 = '')
    {
        global $DB;
        if (!preg_match('/^S[0-9]{21}$/', (string)$shopTradeNo)) {
            throw new Exception('订单号格式不正确');
        }
        $order = $DB->getRow("SELECT * FROM pre_shop_orders WHERE shop_trade_no=:trade_no AND deleted=0 LIMIT 1", array(':trade_no' => $shopTradeNo));
        if (!$order) {
            throw new Exception('订单不存在');
        }
        $tokenOk = $token !== '' && hash_equals($order['query_token'], $token);
        $last4Ok = false;
        if ($contactLast4 !== '' && mb_strlen($order['buyer_contact'], 'UTF-8') >= 4) {
            $last4Ok = mb_substr($order['buyer_contact'], -4, 4, 'UTF-8') === $contactLast4;
        }
        if (intval(ConfigService::get('shop_query_verify', 1)) === 1 && !$tokenOk && !$last4Ok) {
            throw new Exception('订单查询凭证错误');
        }
        $order['buyer_contact_masked'] = self::maskContact($order['buyer_contact']);
        unset($order['buyer_contact']);
        unset($order['buyer_remark']);
        unset($order['query_token']);
        return $order;
    }

    public static function adminList($filters, $offset, $limit)
    {
        global $DB;
        $where = "A.deleted=0";
        $bind = array();
        $hasKeyword = isset($filters['keyword']) && trim($filters['keyword']) !== '';
        if (isset($filters['pay_status']) && $filters['pay_status'] !== '' && intval($filters['pay_status']) > -1) {
            $where .= " AND A.pay_status=:pay_status";
            $bind[':pay_status'] = intval($filters['pay_status']);
        }
        if (isset($filters['order_status']) && $filters['order_status'] !== '' && intval($filters['order_status']) > -1) {
            $where .= " AND A.order_status=:order_status";
            $bind[':order_status'] = intval($filters['order_status']);
        }
        if ($hasKeyword) {
            $where .= " AND (A.shop_trade_no=:keyword OR A.pay_trade_no=:keyword OR A.buyer_contact=:keyword OR P.uid=:merchant_uid OR A.goods_name LIKE :keyword_like)";
            $bind[':keyword'] = trim($filters['keyword']);
            $bind[':merchant_uid'] = intval($filters['keyword']);
            $bind[':keyword_like'] = '%'.trim($filters['keyword']).'%';
        }
        $offset = max(0, intval($offset));
        $limit = max(1, min(100, intval($limit)));
        $countJoin = $hasKeyword ? " LEFT JOIN pre_order P ON P.trade_no=A.pay_trade_no" : '';
        $total = intval($DB->getColumn("SELECT COUNT(*) FROM pre_shop_orders A".$countJoin." WHERE ".$where, $bind));
        $rows = $DB->getAll("SELECT A.*,P.uid merchant_uid FROM pre_shop_orders A LEFT JOIN pre_order P ON P.trade_no=A.pay_trade_no WHERE ".$where." ORDER BY A.id DESC LIMIT ".$offset.",".$limit, $bind);
        if (is_array($rows)) {
            foreach ($rows as &$row) {
                $row['record_source_text'] = self::recordSourceText($row['status_times']);
            }
            unset($row);
        }
        return array('total' => $total, 'rows' => is_array($rows) ? $rows : array());
    }

    public static function adminGet($id)
    {
        global $DB;
        $row = $DB->getRow("SELECT A.*,P.uid merchant_uid,P.param merchant_param FROM pre_shop_orders A LEFT JOIN pre_order P ON P.trade_no=A.pay_trade_no WHERE A.id=:id AND A.deleted=0 LIMIT 1", array(':id' => intval($id)));
        if ($row) {
            $row['record_source_text'] = self::recordSourceText($row['status_times']);
        }
        return $row;
    }

    public static function updateLogistics($id, $company, $trackingNo, $orderStatus)
    {
        global $DB;
        $order = self::adminGet($id);
        if (!$order) {
            throw new Exception('订单不存在');
        }
        $company = mb_substr(trim((string)$company), 0, 80, 'UTF-8');
        $trackingNo = mb_substr(trim((string)$trackingNo), 0, 120, 'UTF-8');
        $orderStatus = intval($orderStatus);
        if (!in_array($orderStatus, array(self::ORDER_WAIT_SHIP, self::ORDER_SHIPPED, self::ORDER_SIGNED, self::ORDER_FINISHED, self::ORDER_CANCELLED), true)) {
            throw new Exception('订单履约状态不合法');
        }
        if (intval($order['pay_status']) !== self::PAY_PAID && $orderStatus !== self::ORDER_CANCELLED) {
            throw new Exception('未支付订单不能维护物流');
        }
        $times = array();
        if (!empty($order['status_times'])) {
            $decoded = json_decode($order['status_times'], true);
            if (is_array($decoded)) {
                $times = $decoded;
            }
        }
        $now = date('Y-m-d H:i:s');
        $times['logistics'] = $now;
        if ($orderStatus === self::ORDER_SHIPPED) {
            $times['shipped'] = $now;
        } elseif ($orderStatus === self::ORDER_SIGNED) {
            $times['signed'] = $now;
        } elseif ($orderStatus === self::ORDER_FINISHED) {
            $times['finished'] = $now;
        } elseif ($orderStatus === self::ORDER_CANCELLED) {
            $times['cancelled'] = $now;
        }
        $ok = $DB->update('shop_orders', array(
            'logistics_company' => $company,
            'tracking_no' => $trackingNo,
            'order_status' => $orderStatus,
            'status_times' => json_encode($times, JSON_UNESCAPED_UNICODE),
            'updatetime' => 'NOW()',
        ), array('id' => intval($id)));
        if ($ok === false) {
            throw new Exception('保存物流信息失败：'.$DB->error());
        }
        return true;
    }

    public static function softDelete($id)
    {
        global $DB;
        $order = self::adminGet($id);
        if (!$order) {
            throw new Exception('订单不存在');
        }
        $ok = $DB->update('shop_orders', array('deleted' => 1, 'updatetime' => 'NOW()'), array('id' => intval($id)));
        if ($ok === false) {
            throw new Exception('删除商城订单失败：'.$DB->error());
        }
        return true;
    }

    public static function summary()
    {
        global $DB;
        $row = $DB->getRow("SELECT COUNT(*) total_count, SUM(CASE WHEN pay_status=1 THEN 1 ELSE 0 END) paid_count, ROUND(COALESCE(SUM(money),0),2) total_money, ROUND(COALESCE(SUM(CASE WHEN pay_status=1 THEN money ELSE 0 END),0),2) paid_money FROM pre_shop_orders WHERE deleted=0");
        if (!$row) {
            $row = array('total_count' => 0, 'paid_count' => 0, 'total_money' => '0.00', 'paid_money' => '0.00');
        }
        return $row;
    }

    public static function payStatusText($status)
    {
        $map = array(self::PAY_PENDING => '未支付', self::PAY_PAID => '已支付', self::PAY_CANCELLED => '已取消', self::PAY_REFUNDED => '已退款');
        return isset($map[intval($status)]) ? $map[intval($status)] : '未知';
    }

    public static function orderStatusText($status)
    {
        $map = array(
            self::ORDER_WAIT_PAY => '待支付',
            self::ORDER_WAIT_SHIP => '待发货',
            self::ORDER_SHIPPED => '已发货',
            self::ORDER_SIGNED => '已签收',
            self::ORDER_FINISHED => '已完成',
            self::ORDER_CANCELLED => '已取消',
        );
        return isset($map[intval($status)]) ? $map[intval($status)] : '未知';
    }

    public static function recordSourceText($statusTimes)
    {
        $times = self::decodeStatusTimes($statusTimes);
        return isset($times['source']) && $times['source'] === 'merchant_order_shadow' ? '无感影子订单' : '购买确认页';
    }

    private static function attachmentResult($order)
    {
        global $siteurl;
        return array(
            'shop_trade_no' => $order['shop_trade_no'],
            'pay_trade_no' => $order['pay_trade_no'],
            'query_token' => $order['query_token'],
            'money' => $order['money'],
            'checkout_url' => $siteurl.'shopping.php?act=checkout&trade_no='.rawurlencode($order['shop_trade_no']).'&token='.$order['query_token'],
        );
    }

    private static function decodeStatusTimes($value)
    {
        if (!empty($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return array();
    }

    private static function pickRandomCatalogGoods()
    {
        global $DB;
        $rows = $DB->getAll("SELECT id,name,image FROM pre_shop_goods WHERE status=1 AND deleted=0 AND (stock=-1 OR stock>0) ORDER BY id ASC");
        if (!is_array($rows) || count($rows) === 0) {
            return false;
        }
        try {
            $index = random_int(0, count($rows) - 1);
        } catch (Exception $e) {
            $index = mt_rand(0, count($rows) - 1);
        }
        return $rows[$index];
    }

    private static function resolveRequestedType($requestedType, $uid, $gid)
    {
        $requestedType = trim((string)$requestedType);
        if ($requestedType === '') {
            return 0;
        }
        $payTypes = \lib\Channel::getTypes(intval($uid), intval($gid));
        if (is_array($payTypes)) {
            foreach ($payTypes as $type) {
                if ((ctype_digit($requestedType) && intval($type['id']) === intval($requestedType)) || $type['name'] === $requestedType) {
                    return intval($type['id']);
                }
            }
        }
        throw new Exception('原订单指定的支付方式当前不可用');
    }

    private static function checkoutRow($shopTradeNo, $queryToken, $forUpdate = false)
    {
        global $DB;
        if (!preg_match('/^S[0-9]{21}$/', (string)$shopTradeNo) || $queryToken === '') {
            throw new Exception('购买确认凭证不正确');
        }
        $sql = "SELECT A.*,P.uid merchant_uid,P.status payment_status,P.param merchant_param,P.notify_url merchant_notify_url,P.return_url merchant_return_url,P.name original_name,P.money original_money,U.gid merchant_gid FROM pre_shop_orders A INNER JOIN pre_order P ON P.trade_no=A.pay_trade_no LEFT JOIN pre_user U ON U.uid=P.uid WHERE A.shop_trade_no=:trade_no AND A.deleted=0 LIMIT 1";
        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }
        $order = $DB->getRow($sql, array(':trade_no' => $shopTradeNo));
        if (!$order || !hash_equals((string)$order['query_token'], (string)$queryToken)) {
            throw new Exception('购买确认凭证不正确');
        }
        return $order;
    }

    private static function buildPayUrl($payTradeNo, $payType)
    {
        global $siteurl;
        $payType = intval($payType);
        if ($payType > 0) {
            return $siteurl.'submit2.php?typeid='.$payType.'&trade_no='.$payTradeNo;
        }
        return $siteurl.'cashier.php?trade_no='.$payTradeNo.'&sitename='.urlencode(base64_encode(ConfigService::get('shop_name', '商城')));
    }

    private static function maskContact($value)
    {
        $value = (string)$value;
        $len = mb_strlen($value, 'UTF-8');
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        if ($len <= 7) {
            return mb_substr($value, 0, 1, 'UTF-8').'***'.mb_substr($value, -1, 1, 'UTF-8');
        }
        return mb_substr($value, 0, 3, 'UTF-8').'****'.mb_substr($value, -4, 4, 'UTF-8');
    }
}
