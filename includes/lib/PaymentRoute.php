<?php
namespace lib;

use Exception;

class PaymentRoute
{
    // Only the request that changes channel=0 owns the route and fee calculation.
    public static function claim($trade_no, &$submitData, &$realmoney, &$getmoney)
    {
        global $DB;
        if($submitData['channel'] <= 0 || $submitData['typeid'] <= 0 || !is_numeric($realmoney) || $realmoney <= 0 || !is_numeric($getmoney) || $getmoney < 0){
            throw new Exception('当前支付通道信息无效，请返回重试！');
        }
        $stmt = $DB->query('UPDATE pre_order SET type=:type, channel=:channel, subchannel=:subchannel, realmoney=:realmoney, getmoney=:getmoney WHERE trade_no=:trade_no AND channel=0 AND status=0', [
            ':type'=>$submitData['typeid'], ':channel'=>$submitData['channel'], ':subchannel'=>$submitData['subchannel'],
            ':realmoney'=>$realmoney, ':getmoney'=>$getmoney, ':trade_no'=>$trade_no,
        ]);
        if($stmt === false) throw new Exception('保存支付通道失败，请返回重试！');
        if($stmt->rowCount() === 1) return true;

        $stored = $DB->getRow('SELECT * FROM pre_order WHERE trade_no=:trade_no LIMIT 1', [':trade_no'=>$trade_no]);
        self::restore($stored, $submitData, $realmoney, $getmoney);
        return false;
    }

    public static function restore($stored, &$submitData, &$realmoney, &$getmoney)
    {
        global $DB;
        if(!$stored || $stored['status'] != 0 || $stored['channel'] <= 0 || $stored['type'] <= 0
            || !is_numeric($stored['realmoney']) || $stored['realmoney'] <= 0
            || !is_numeric($stored['getmoney']) || $stored['getmoney'] < 0){
            throw new Exception('订单支付状态已变化，请返回重新发起支付！');
        }
        if(isset($submitData['typeid']) && $submitData['typeid'] != $stored['type']){
            throw new Exception('该订单已绑定其他支付方式，请更换订单号重新发起支付！');
        }
        $typename = $submitData['typename'] ?? $DB->getColumn('SELECT name FROM pre_type WHERE id=:id LIMIT 1', [':id'=>$stored['type']]);
        $submitData = Channel::info($stored['channel']);
        if(empty($submitData['channel']) || $submitData['typeid'] != $stored['type']){
            throw new Exception('当前支付通道信息不存在');
        }
        $submitData['typename'] = $typename;
        $submitData['subchannel'] = $stored['subchannel'];
        $realmoney = $stored['realmoney'];
        $getmoney = $stored['getmoney'];
    }
}
