<?php
namespace lib\Shop;

use Exception;

class GoodsService
{
    public static function normalize($data, $partial = false)
    {
        $result = array();

        if (!$partial || isset($data['name'])) {
            $name = isset($data['name']) ? trim((string)$data['name']) : '';
            if ($name === '') {
                throw new Exception('商品名称不能为空');
            }
            $result['name'] = mb_substr($name, 0, 120, 'UTF-8');
        }

        if (!$partial || isset($data['description'])) {
            $result['description'] = isset($data['description']) ? trim((string)$data['description']) : '';
        }

        if (!$partial || isset($data['price'])) {
            $price = isset($data['price']) ? trim((string)$data['price']) : '';
            if ($price === '' || !is_numeric($price) || !preg_match('/^[0-9]+(\.[0-9]{1,2})?$/', $price) || floatval($price) < 0) {
                throw new Exception('商品价格必须为0或正数，且最多保留2位小数');
            }
            $result['price'] = number_format(floatval($price), 2, '.', '');
        }

        if (!$partial || isset($data['stock'])) {
            $stock = isset($data['stock']) ? trim((string)$data['stock']) : '-1';
            if (!preg_match('/^-?[0-9]+$/', $stock)) {
                throw new Exception('库存必须为整数');
            }
            $stock = intval($stock);
            if ($stock < -1) {
                throw new Exception('库存只能为 -1 或大于等于 0');
            }
            $result['stock'] = $stock;
        }

        if (!$partial || isset($data['image'])) {
            $image = isset($data['image']) ? trim((string)$data['image']) : '';
            if ($image !== '' && !preg_match('#^(/|https?://)#i', $image)) {
                throw new Exception('商品图片只能填写本地路径或 http(s) 地址');
            }
            if (stripos($image, 'javascript:') !== false) {
                throw new Exception('商品图片地址不合法');
            }
            $result['image'] = mb_substr($image, 0, 500, 'UTF-8');
        }

        if (!$partial || isset($data['sort'])) {
            $result['sort'] = isset($data['sort']) ? intval($data['sort']) : 0;
        }

        if (!$partial || isset($data['status'])) {
            $result['status'] = isset($data['status']) && intval($data['status']) === 1 ? 1 : 0;
        }

        return $result;
    }

    public static function get($id, $includeDeleted = false)
    {
        global $DB;
        $id = intval($id);
        if ($id <= 0) {
            return false;
        }
        $sql = "SELECT * FROM pre_shop_goods WHERE id=:id";
        if (!$includeDeleted) {
            $sql .= " AND deleted=0";
        }
        $sql .= " LIMIT 1";
        return $DB->getRow($sql, array(':id' => $id));
    }

    public static function getPublic($id)
    {
        global $DB;
        return $DB->getRow("SELECT * FROM pre_shop_goods WHERE id=:id AND status=1 AND deleted=0 LIMIT 1", array(':id' => intval($id)));
    }

    public static function publicList($limit = 20)
    {
        global $DB;
        $limit = max(1, min(100, intval($limit)));
        return $DB->getAll("SELECT * FROM pre_shop_goods WHERE status=1 AND deleted=0 ORDER BY sort DESC,id DESC LIMIT ".$limit);
    }

    public static function adminList($filters, $offset, $limit)
    {
        global $DB;
        $where = "deleted=0";
        $bind = array();
        if (isset($filters['status']) && $filters['status'] !== '' && intval($filters['status']) > -1) {
            $where .= " AND status=:status";
            $bind[':status'] = intval($filters['status']);
        }
        if (isset($filters['keyword']) && trim($filters['keyword']) !== '') {
            $where .= " AND name LIKE :keyword";
            $bind[':keyword'] = '%'.trim($filters['keyword']).'%';
        }
        $offset = max(0, intval($offset));
        $limit = max(1, min(100, intval($limit)));
        $total = intval($DB->getColumn("SELECT COUNT(*) FROM pre_shop_goods WHERE ".$where, $bind));
        $rows = $DB->getAll("SELECT * FROM pre_shop_goods WHERE ".$where." ORDER BY sort DESC,id DESC LIMIT ".$offset.",".$limit, $bind);
        return array('total' => $total, 'rows' => is_array($rows) ? $rows : array());
    }

    public static function create($data)
    {
        global $DB;
        $payload = self::normalize($data);
        $payload['deleted'] = 0;
        $payload['addtime'] = 'NOW()';
        $payload['updatetime'] = 'NOW()';
        $id = $DB->insert('shop_goods', $payload);
        if (!$id) {
            throw new Exception('新增商品失败：'.$DB->error());
        }
        return intval($id);
    }

    public static function update($id, $data)
    {
        global $DB;
        $id = intval($id);
        if (!self::get($id, true)) {
            throw new Exception('商品不存在');
        }
        $payload = self::normalize($data);
        $payload['updatetime'] = 'NOW()';
        $ok = $DB->update('shop_goods', $payload, array('id' => $id));
        if ($ok === false) {
            throw new Exception('更新商品失败：'.$DB->error());
        }
        return true;
    }

    public static function setStatus($id, $status)
    {
        global $DB;
        $id = intval($id);
        if (!self::get($id)) {
            throw new Exception('商品不存在');
        }
        $ok = $DB->update('shop_goods', array('status' => intval($status) === 1 ? 1 : 0, 'updatetime' => 'NOW()'), array('id' => $id));
        if ($ok === false) {
            throw new Exception('修改商品状态失败：'.$DB->error());
        }
        return true;
    }

    public static function softDelete($id)
    {
        global $DB;
        $id = intval($id);
        if (!self::get($id)) {
            throw new Exception('商品不存在');
        }
        $ok = $DB->update('shop_goods', array('deleted' => 1, 'status' => 0, 'updatetime' => 'NOW()'), array('id' => $id));
        if ($ok === false) {
            throw new Exception('删除商品失败：'.$DB->error());
        }
        return true;
    }
}
