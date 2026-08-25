<?php
namespace lib\Complain;

class MerchantViewService
{
    const DEFAULT_PAGE_SIZE = 20;
    const MAX_KEYWORD_LENGTH = 100;

    private $db;
    private $config;

    public function __construct($db, $config = [])
    {
        $this->db = $db;
        $this->config = is_array($config) ? $config : [];
    }

    public function isEnabled()
    {
        return isset($this->config['merchant_complain_view_enabled'])
            && (string)$this->config['merchant_complain_view_enabled'] === '1';
    }

    public function summaryForMerchant($uid)
    {
        $this->assertEnabled();
        $uid = $this->normalizeUid($uid);
        $row = $this->db->getRow(
            "SELECT COUNT(*) total_count,
                SUM(CASE WHEN status='0' THEN 1 ELSE 0 END) pending_count,
                SUM(CASE WHEN status='1' THEN 1 ELSE 0 END) processing_count,
                SUM(CASE WHEN status='2' THEN 1 ELSE 0 END) completed_count
             FROM pre_complain WHERE uid=:uid",
            [':uid'=>$uid]
        );
        if($row === false) throw new \RuntimeException('Merchant complaint summary query failed');
        return [
            'total'=>intval(isset($row['total_count']) ? $row['total_count'] : 0),
            'pending'=>intval(isset($row['pending_count']) ? $row['pending_count'] : 0),
            'processing'=>intval(isset($row['processing_count']) ? $row['processing_count'] : 0),
            'completed'=>intval(isset($row['completed_count']) ? $row['completed_count'] : 0),
        ];
    }

    public function pendingCount($uid)
    {
        $this->assertEnabled();
        $uid = $this->normalizeUid($uid);
        $count = $this->db->getColumn(
            "SELECT COUNT(*) FROM pre_complain WHERE uid=:uid AND status='0'",
            [':uid'=>$uid]
        );
        if($count === false) throw new \RuntimeException('Merchant complaint pending count query failed');
        return intval($count);
    }

    public function listForMerchant($uid, $input)
    {
        $this->assertEnabled();
        $uid = $this->normalizeUid($uid);
        $input = is_array($input) ? $input : [];
        $pageSize = $this->normalizePageSize(isset($input['pageSize']) ? $input['pageSize'] : (isset($input['limit']) ? $input['limit'] : null));
        $pageNumber = max(1, intval(isset($input['pageNumber']) ? $input['pageNumber'] : 1));
        $offset = ($pageNumber - 1) * $pageSize;

        $where = ['A.uid=:uid'];
        $params = [':uid'=>$uid];

        $paytype = intval(isset($input['paytype']) ? $input['paytype'] : 0);
        if($paytype > 0){
            $where[] = 'A.paytype=:paytype';
            $params[':paytype'] = $paytype;
        }

        $status = isset($input['dstatus']) ? (string)$input['dstatus'] : (isset($input['status']) ? (string)$input['status'] : '-1');
        if(in_array($status, ['0','1','2'], true)){
            $where[] = 'A.status=:status';
            $params[':status'] = $status;
        }

        $startDate = $this->normalizeDate(isset($input['starttime']) ? $input['starttime'] : (isset($input['start_date']) ? $input['start_date'] : ''), '开始日期');
        $endDate = $this->normalizeDate(isset($input['endtime']) ? $input['endtime'] : (isset($input['end_date']) ? $input['end_date'] : ''), '结束日期');
        if($startDate !== null && $endDate !== null){
            if($startDate > $endDate) throw new \InvalidArgumentException('开始日期不能晚于结束日期');
            $days = intval((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;
            if($days > 90) throw new \InvalidArgumentException('日期范围不能超过90天');
        }
        if($startDate !== null){
            $where[] = 'A.addtime>=:starttime';
            $params[':starttime'] = $startDate.' 00:00:00';
        }
        if($endDate !== null){
            $where[] = 'A.addtime<=:endtime';
            $params[':endtime'] = $endDate.' 23:59:59';
        }

        $keyword = trim(isset($input['kw']) ? (string)$input['kw'] : (isset($input['keyword']) ? (string)$input['keyword'] : ''));
        if($keyword !== ''){
            if(mb_strlen($keyword, 'UTF-8') > self::MAX_KEYWORD_LENGTH) throw new \InvalidArgumentException('搜索内容不能超过100个字符');
            $column = isset($input['type']) ? (string)$input['type'] : (isset($input['column']) ? (string)$input['column'] : '1');
            $columns = [
                '1'=>'A.trade_no', 'trade_no'=>'A.trade_no',
                '2'=>'B.out_trade_no', 'out_trade_no'=>'B.out_trade_no',
                '3'=>'A.thirdid', 'thirdid'=>'A.thirdid',
            ];
            if(isset($columns[$column])){
                $where[] = $columns[$column].'=:keyword';
                $params[':keyword'] = $keyword;
            }elseif(in_array($column, ['4','complaint_type'], true)){
                $where[] = "A.type LIKE :keyword ESCAPE '='";
                $params[':keyword'] = '%'.$this->escapeLike($keyword).'%';
            }elseif(in_array($column, ['5','complaint_title'], true)){
                $where[] = "A.title LIKE :keyword ESCAPE '='";
                $params[':keyword'] = '%'.$this->escapeLike($keyword).'%';
            }else{
                throw new \InvalidArgumentException('搜索字段不受支持');
            }
        }

        $whereSql = implode(' AND ', $where);
        $joinSql = ' FROM pre_complain A'
            .' LEFT JOIN pre_order B ON B.trade_no=A.trade_no AND B.uid=A.uid'
            .' LEFT JOIN pre_type T ON T.id=A.paytype';
        $total = $this->db->getColumn('SELECT COUNT(*)'.$joinSql.' WHERE '.$whereSql, $params);
        if($total === false) throw new \RuntimeException('Merchant complaint count query failed');

        $fields = 'A.id,A.trade_no,A.thirdid,A.type complaint_type,A.title complaint_title,A.status,'
            .'A.addtime,A.edittime,B.out_trade_no,B.name order_name,B.money,B.status order_status,'
            .'T.name pay_type_code,T.showname pay_type_name';
        $rows = $this->db->getAll('SELECT '.$fields.$joinSql.' WHERE '.$whereSql
            .' ORDER BY A.addtime DESC,A.id DESC LIMIT '.$offset.','.$pageSize, $params);
        if($rows === false) throw new \RuntimeException('Merchant complaint list query failed');

        $result = [];
        foreach($rows as $row) $result[] = $this->summaryDto($row);
        return [
            'total'=>intval($total),
            'rows'=>$result,
            'pageNumber'=>$pageNumber,
            'pageSize'=>$pageSize,
        ];
    }

    public function detailForMerchant($uid, $id)
    {
        $this->assertEnabled();
        $uid = $this->normalizeUid($uid);
        $id = intval($id);
        if($id <= 0) return null;
        $fields = 'A.id,A.trade_no,A.thirdid,A.type complaint_type,A.title complaint_title,'
            .'A.content complaint_content,A.status,A.phone,A.addtime,A.edittime,'
            .'B.out_trade_no,B.name order_name,B.money,B.status order_status,'
            .'T.name pay_type_code,T.showname pay_type_name';
        $row = $this->db->getRow(
            'SELECT '.$fields.' FROM pre_complain A'
            .' LEFT JOIN pre_order B ON B.trade_no=A.trade_no AND B.uid=A.uid'
            .' LEFT JOIN pre_type T ON T.id=A.paytype'
            .' WHERE A.id=:id AND A.uid=:uid LIMIT 1',
            [':id'=>$id, ':uid'=>$uid]
        );
        if(!$row) return null;
        $result = $this->summaryDto($row);
        $result['thirdid'] = isset($row['thirdid']) ? (string)$row['thirdid'] : '';
        $result['complaint_content'] = isset($row['complaint_content']) ? (string)$row['complaint_content'] : '';
        $result['phone_masked'] = self::maskPhone(isset($row['phone']) ? $row['phone'] : '');
        return $result;
    }

    public static function maskPhone($phone)
    {
        $phone = trim((string)$phone);
        if($phone === '') return '-';
        $length = mb_strlen($phone, 'UTF-8');
        if($length === 11 && preg_match('/^[0-9]{11}$/D', $phone)) return substr($phone, 0, 3).'****'.substr($phone, -4);
        if($length <= 4) return str_repeat('*', $length);
        $visible = min(3, max(1, intval(floor($length / 4))));
        return mb_substr($phone, 0, $visible, 'UTF-8')
            .str_repeat('*', max(4, $length - $visible * 2))
            .mb_substr($phone, -$visible, null, 'UTF-8');
    }

    public static function statusText($status)
    {
        $status = (string)$status;
        if($status === '1') return '处理中';
        if($status === '2') return '处理完成';
        return '待处理';
    }

    public static function orderStatusText($status)
    {
        if($status === null || $status === '') return '关联订单不可用';
        $status = (string)$status;
        if($status === '1') return '已支付';
        if($status === '2') return '已退款';
        if($status === '3') return '已冻结';
        if($status === '4') return '预授权';
        return '未支付';
    }

    private function summaryDto($row)
    {
        $money = isset($row['money']) && $row['money'] !== null ? number_format((float)$row['money'], 2, '.', '') : null;
        $payTypeCode = isset($row['pay_type_code']) && preg_match('/^[A-Za-z0-9_-]{1,40}$/D', (string)$row['pay_type_code'])
            ? (string)$row['pay_type_code'] : '';
        return [
            'id'=>intval(isset($row['id']) ? $row['id'] : 0),
            'trade_no'=>isset($row['trade_no']) ? (string)$row['trade_no'] : '',
            'out_trade_no'=>isset($row['out_trade_no']) && $row['out_trade_no'] !== null ? (string)$row['out_trade_no'] : '',
            'order_name'=>isset($row['order_name']) && $row['order_name'] !== null ? (string)$row['order_name'] : '',
            'money'=>$money,
            'pay_type_code'=>$payTypeCode,
            'pay_type_name'=>isset($row['pay_type_name']) && $row['pay_type_name'] !== null ? (string)$row['pay_type_name'] : '',
            'complaint_type'=>isset($row['complaint_type']) ? (string)$row['complaint_type'] : '',
            'complaint_title'=>isset($row['complaint_title']) ? (string)$row['complaint_title'] : '',
            'status'=>intval(isset($row['status']) ? $row['status'] : 0),
            'status_text'=>self::statusText(isset($row['status']) ? $row['status'] : 0),
            'order_status'=>isset($row['order_status']) && $row['order_status'] !== null ? intval($row['order_status']) : null,
            'order_status_text'=>self::orderStatusText(isset($row['order_status']) ? $row['order_status'] : null),
            'created_at'=>isset($row['addtime']) ? (string)$row['addtime'] : '',
            'updated_at'=>!empty($row['edittime']) ? (string)$row['edittime'] : (isset($row['addtime']) ? (string)$row['addtime'] : ''),
        ];
    }

    private function normalizeUid($uid)
    {
        $uid = intval($uid);
        if($uid <= 0) throw new \InvalidArgumentException('Merchant identity is invalid');
        return $uid;
    }

    private function normalizePageSize($value)
    {
        $value = intval($value);
        return in_array($value, [10,20,50], true) ? $value : self::DEFAULT_PAGE_SIZE;
    }

    private function normalizeDate($value, $label)
    {
        $value = trim((string)$value);
        if($value === '') return null;
        $date = \DateTime::createFromFormat('!Y-m-d', $value);
        if(!$date || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException($label.'格式不正确');
        return $value;
    }

    private function escapeLike($value)
    {
        return str_replace(['=','%','_'], ['==','=%','=_'], $value);
    }

    private function assertEnabled()
    {
        if(!$this->isEnabled()) throw new \RuntimeException('Merchant complaint view is disabled');
    }
}
