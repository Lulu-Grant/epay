<?php
namespace lib\Health;

class RawDataService
{
    const VERSION = 'raw-v1';
    const MAX_DAILY_ORDERS = 10000;

    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function collect($date)
    {
        $start = $date.' 00:00:00';
        $end = date('Y-m-d H:i:s', strtotime($start.' +1 day'));
        $baselineStart = date('Y-m-d H:i:s', strtotime($start.' -7 days'));

        $orderCount = $this->db->getColumn(
            'SELECT COUNT(*) FROM pre_order WHERE addtime>=:start AND addtime<:end',
            [':start'=>$start, ':end'=>$end]
        );
        if($orderCount === false) throw new \RuntimeException('Raw order capacity query failed: '.$this->db->error());
        if(intval($orderCount) > self::MAX_DAILY_ORDERS){
            throw new \RuntimeException('Raw order count exceeds the supported daily capacity of '.self::MAX_DAILY_ORDERS);
        }

        $orders = $this->db->getAll(
            "SELECT o.*,c.name AS _channel_name,c.plugin AS _channel_plugin,c.status AS _channel_status,
                    c.daystatus AS _channel_daystatus,c.paymin AS _channel_paymin,c.paymax AS _channel_paymax,
                    u.gid AS _merchant_group
             FROM pre_order o
             LEFT JOIN pre_channel c ON c.id=o.channel
             LEFT JOIN pre_user u ON u.uid=o.uid
             WHERE o.addtime>=:start AND o.addtime<:end
             ORDER BY o.channel ASC,o.addtime ASC,o.trade_no ASC",
            [':start'=>$start, ':end'=>$end]
        );
        if($orders === false) throw new \RuntimeException('Raw order query failed: '.$this->db->error());

        $channelRows = $this->db->getAll(
            'SELECT id,name,plugin,status,daystatus,paymin,paymax FROM pre_channel ORDER BY id ASC'
        );
        if($channelRows === false) throw new \RuntimeException('Channel metadata query failed: '.$this->db->error());

        $complaints = [];
        if($this->db->getColumn("SHOW TABLES LIKE 'pre_complain'") !== false){
            $complaints = $this->db->getAll(
                'SELECT * FROM pre_complain WHERE addtime>=:start AND addtime<:end ORDER BY addtime ASC,id ASC',
                [':start'=>$start, ':end'=>$end]
            );
            if($complaints === false) throw new \RuntimeException('Complaint data query failed: '.$this->db->error());
        }

        $baseline = $this->db->getAll(
            "SELECT DATE(addtime) report_day,channel,COUNT(*) total_orders,
                    COALESCE(SUM(status IN (1,2,3)),0) paid_orders,
                    COALESCE(SUM(status=0),0) unpaid_orders,
                    COALESCE(SUM(status=2),0) refunded_orders,
                    COALESCE(SUM(status=3),0) frozen_orders,
                    COALESCE(SUM(status IN (1,2,3) AND tid=0 AND notify_url IS NOT NULL AND TRIM(notify_url)<>'' AND notify<0),0) notify_failed,
                    COALESCE(SUM(money),0) total_money,
                    COALESCE(SUM(IF(status IN (1,2,3),realmoney,0)),0) paid_money
             FROM pre_order WHERE addtime>=:start AND addtime<:end
             GROUP BY DATE(addtime),channel ORDER BY report_day ASC,channel ASC",
            [':start'=>$baselineStart, ':end'=>$start]
        );
        if($baseline === false) throw new \RuntimeException('Raw baseline query failed: '.$this->db->error());

        $safeOrders = [];
        foreach($orders as $row){
            $row = $this->scrubSecrets($row);
            $row['_evidence_ref'] = 'order:'.(string)$row['trade_no'];
            $safeOrders[] = $row;
        }
        $safeComplaints = [];
        foreach($complaints as $row){
            $row = $this->scrubSecrets($row);
            $row['_evidence_ref'] = 'complaint:'.intval(isset($row['id']) ? $row['id'] : 0);
            $safeComplaints[] = $row;
        }

        return [
            'version'=>self::VERSION,
            'report_date'=>$date,
            'period_start'=>$start,
            'period_end'=>$end,
            'generated_at'=>date('Y-m-d H:i:s'),
            'orders'=>$safeOrders,
            'complaints'=>$safeComplaints,
            'baseline'=>$this->normalizeBaseline($baseline),
            'metrics'=>$this->buildMetrics($safeOrders, $safeComplaints, $channelRows, $start, $end),
        ];
    }

    private function buildMetrics($orders, $complaints, $channelRows, $start, $end)
    {
        $platform = $this->emptyMetric();
        $channels = [];
        foreach($channelRows as $channel){
            $id = (string)intval($channel['id']);
            $channels[$id] = array_merge($this->emptyMetric(), [
                'channel_id'=>intval($channel['id']),
                'channel_name'=>(string)$channel['name'],
                'plugin'=>(string)$channel['plugin'],
                'channel_status'=>intval($channel['status']),
                'daystatus'=>intval($channel['daystatus']),
                'paymin'=>$channel['paymin'],
                'paymax'=>$channel['paymax'],
            ]);
        }
        $merchants = [];
        $hourly = [];
        for($hour=0;$hour<24;$hour++) $hourly[sprintf('%02d',$hour)] = $this->emptyMetric();

        foreach($orders as $order){
            $channelId = (string)intval(isset($order['channel']) ? $order['channel'] : 0);
            if(!isset($channels[$channelId])){
                $channels[$channelId] = array_merge($this->emptyMetric(), [
                    'channel_id'=>intval($channelId), 'channel_name'=>(string)(isset($order['_channel_name'])?$order['_channel_name']:''),
                    'plugin'=>(string)(isset($order['_channel_plugin'])?$order['_channel_plugin']:''),
                    'channel_status'=>intval(isset($order['_channel_status'])?$order['_channel_status']:0),
                    'daystatus'=>intval(isset($order['_channel_daystatus'])?$order['_channel_daystatus']:0),
                    'paymin'=>isset($order['_channel_paymin'])?$order['_channel_paymin']:null,
                    'paymax'=>isset($order['_channel_paymax'])?$order['_channel_paymax']:null,
                ]);
            }
            $uid = (string)intval(isset($order['uid']) ? $order['uid'] : 0);
            if(!isset($merchants[$uid])) $merchants[$uid] = array_merge($this->emptyMetric(), ['uid'=>intval($uid), 'group_id'=>intval(isset($order['_merchant_group'])?$order['_merchant_group']:0)]);
            $hour = substr((string)(isset($order['addtime'])?$order['addtime']:''), 11, 2);
            if(!isset($hourly[$hour])) $hour = '00';
            $this->addOrder($platform, $order);
            $this->addOrder($channels[$channelId], $order);
            $this->addOrder($merchants[$uid], $order);
            $this->addOrder($hourly[$hour], $order);
        }
        $this->finishMetric($platform);
        foreach($channels as &$metric) $this->finishMetric($metric);
        unset($metric);
        foreach($merchants as &$metric) $this->finishMetric($metric);
        unset($metric);
        foreach($hourly as &$metric) $this->finishMetric($metric);
        unset($metric);

        $platform['complaints'] = count($complaints);
        foreach($complaints as $complaint){
            $channelId = (string)intval(isset($complaint['channel']) ? $complaint['channel'] : 0);
            if(isset($channels[$channelId])) $channels[$channelId]['complaints']++;
            $uid = (string)intval(isset($complaint['uid']) ? $complaint['uid'] : 0);
            if(isset($merchants[$uid])) $merchants[$uid]['complaints']++;
        }

        return [
            'version'=>self::VERSION,
            'source'=>'raw_order_rows',
            'period_start'=>$start,
            'period_end'=>$end,
            'generated_at'=>date('Y-m-d H:i:s'),
            'notification_as_of'=>date('Y-m-d H:i:s'),
            'snapshot_hours'=>24,
            'expected_snapshot_hours'=>24,
            'data_complete'=>true,
            'platform'=>$platform,
            'channels'=>$channels,
            'merchants'=>$merchants,
            'hourly'=>$hourly,
            'source_row_count'=>count($orders),
            'complaint_row_count'=>count($complaints),
        ];
    }

    private function emptyMetric()
    {
        return [
            'total_orders'=>0, 'paid_orders'=>0, 'unpaid_orders'=>0, 'refunded_orders'=>0,
            'frozen_orders'=>0, 'preauth_orders'=>0, 'total_money'=>0.0, 'paid_money'=>0.0,
            'profit_money'=>0.0, 'notify_total'=>0, 'notify_success'=>0, 'notify_pending'=>0,
            'notify_failed'=>0, 'complaints'=>0, 'last_success_time'=>null, '_latencies'=>[],
        ];
    }

    private function addOrder(&$metric, $order)
    {
        $status = intval(isset($order['status']) ? $order['status'] : 0);
        $paid = in_array($status, [1,2,3], true);
        $metric['total_orders']++;
        $metric[$paid ? 'paid_orders' : 'unpaid_orders']++;
        if($status === 2) $metric['refunded_orders']++;
        if($status === 3) $metric['frozen_orders']++;
        if($status === 4) $metric['preauth_orders']++;
        $metric['total_money'] += (float)(isset($order['money']) ? $order['money'] : 0);
        if($paid){
            $metric['paid_money'] += (float)(isset($order['realmoney']) ? $order['realmoney'] : 0);
            if($status === 1) $metric['profit_money'] += (float)(isset($order['profitmoney']) ? $order['profitmoney'] : 0);
            $endtime = isset($order['endtime']) ? $order['endtime'] : null;
            if($endtime && (!$metric['last_success_time'] || $endtime > $metric['last_success_time'])) $metric['last_success_time'] = $endtime;
            $startTs = !empty($order['addtime']) ? strtotime($order['addtime']) : false;
            $endTs = !empty($order['endtime']) ? strtotime($order['endtime']) : false;
            if($startTs !== false && $endTs !== false && $endTs >= $startTs) $metric['_latencies'][] = $endTs - $startTs;
        }
        $notifyUrl = trim((string)(isset($order['notify_url']) ? $order['notify_url'] : ''));
        if($paid && intval(isset($order['tid'])?$order['tid']:0) === 0 && $notifyUrl !== ''){
            $metric['notify_total']++;
            $notify = intval(isset($order['notify']) ? $order['notify'] : 0);
            if($notify === 0) $metric['notify_success']++;
            elseif($notify >= 1 && $notify <= 5) $metric['notify_pending']++;
            elseif($notify < 0) $metric['notify_failed']++;
        }
    }

    private function finishMetric(&$metric)
    {
        $metric['total_money'] = round($metric['total_money'], 2);
        $metric['paid_money'] = round($metric['paid_money'], 2);
        $metric['profit_money'] = round($metric['profit_money'], 2);
        $metric['success_rate'] = $metric['total_orders'] > 0 ? round($metric['paid_orders'] * 100 / $metric['total_orders'], 2) : null;
        $latencies = $metric['_latencies'];
        sort($latencies, SORT_NUMERIC);
        $count = count($latencies);
        $metric['latency'] = [
            'count'=>$count,
            'avg'=>$count ? round(array_sum($latencies) / $count, 2) : null,
            'p50'=>$count ? $latencies[(int)floor(($count - 1) * 0.50)] : null,
            'p95'=>$count ? $latencies[(int)floor(($count - 1) * 0.95)] : null,
        ];
        unset($metric['_latencies']);
    }

    private function normalizeBaseline($rows)
    {
        foreach($rows as &$row){
            foreach(['channel','total_orders','paid_orders','unpaid_orders','refunded_orders','frozen_orders','notify_failed'] as $key) $row[$key] = intval($row[$key]);
            foreach(['total_money','paid_money'] as $key) $row[$key] = round((float)$row[$key], 2);
            $row['success_rate'] = $row['total_orders'] > 0 ? round($row['paid_orders'] * 100 / $row['total_orders'], 2) : null;
        }
        unset($row);
        return $rows;
    }

    private function scrubSecrets($value, $key = '')
    {
        if(preg_match('/(?:password|passwd|secret|token|api[_-]?key|private[_-]?key|authorization|cookie)/i', (string)$key)) return '[REDACTED]';
        if(is_array($value)){
            $clean = [];
            foreach($value as $childKey=>$child) $clean[$childKey] = $this->scrubSecrets($child, (string)$childKey);
            return $clean;
        }
        if(is_string($value) && in_array($key, ['param','ext'], true)){
            $decoded = json_decode($value, true);
            if(is_array($decoded)) return json_encode($this->scrubSecrets($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return preg_replace('/((?:password|passwd|secret|token|api[_-]?key|authorization|cookie)\s*[=:]\s*)[^&\s,;]+/i', '$1[REDACTED]', $value);
        }
        if(is_string($value) && preg_match('/(?:^|_)url$/i',(string)$key)) return preg_replace('/[?#].*$/','',$value);
        return $value;
    }
}
