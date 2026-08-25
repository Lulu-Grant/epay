<?php
namespace lib\Health;

class MetricsService
{
    const VERSION = '1203';
    const MAX_LATENCY_ROWS = 20000;
    const MAX_STREAK_ROWS = 20000;
    const MAX_SAFE_BACKFILL_HOURS = 12;

    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function collect($start, $end)
    {
        $this->assertDateTime($start);
        $this->assertDateTime($end);
        if(strtotime($start) >= strtotime($end)) throw new \InvalidArgumentException('Invalid metrics period');

        $bind = [
            ':start'=>$start,
            ':end'=>$end,
        ];
        $platform = $this->db->getRow(
            "SELECT COUNT(*) total_orders,
                SUM(status IN (1,2,3)) paid_orders,
                SUM(status=0) unpaid_orders,
                SUM(status=2) refunded_orders,
                SUM(status=3) frozen_orders,
                SUM(status=4) preauth_orders,
                COALESCE(SUM(money),0) total_money,
                COALESCE(SUM(IF(status IN (1,2,3),realmoney,0)),0) paid_money,
                COALESCE(SUM(IF(status=1,profitmoney,0)),0) profit_money,
                SUM(status IN (1,2,3) AND tid=0 AND notify_url IS NOT NULL AND TRIM(notify_url)<>'') notify_total,
                SUM(status IN (1,2,3) AND tid=0 AND notify_url IS NOT NULL AND TRIM(notify_url)<>'' AND notify=0) notify_success,
                SUM(status IN (1,2,3) AND tid=0 AND notify_url IS NOT NULL AND TRIM(notify_url)<>'' AND notify BETWEEN 1 AND 5) notify_pending,
                SUM(status IN (1,2,3) AND tid=0 AND notify_url IS NOT NULL AND TRIM(notify_url)<>'' AND notify<0) notify_failed,
                MAX(IF(status IN (1,2,3),endtime,NULL)) last_success_time,
                MIN(IF(status IN (1,2,3) AND tid=0 AND notify_url IS NOT NULL AND TRIM(notify_url)<>'' AND notify BETWEEN 1 AND 5,endtime,NULL)) notify_oldest_time
             FROM pre_order WHERE addtime>=:start AND addtime<:end",
            $bind
        );
        if($platform === false) throw new \RuntimeException('Platform metrics query failed: '.$this->db->error());
        $platform = $this->normalizeCounters($platform);

        $channelRows = $this->db->getAll(
            "SELECT c.id channel_id,c.name channel_name,c.plugin,c.status channel_status,c.daystatus,c.paymin,c.paymax,
                COUNT(o.trade_no) total_orders,
                COALESCE(SUM(o.status IN (1,2,3)),0) paid_orders,
                COALESCE(SUM(o.status=0),0) unpaid_orders,
                COALESCE(SUM(o.status=2),0) refunded_orders,
                COALESCE(SUM(o.status=3),0) frozen_orders,
                COALESCE(SUM(o.status=4),0) preauth_orders,
                COALESCE(SUM(IF(o.status IN (1,2,3),o.realmoney,0)),0) paid_money,
                COALESCE(SUM(IF(o.status=1,o.profitmoney,0)),0) profit_money,
                COALESCE(SUM(o.status IN (1,2,3) AND o.tid=0 AND o.notify_url IS NOT NULL AND TRIM(o.notify_url)<>''),0) notify_total,
                COALESCE(SUM(o.status IN (1,2,3) AND o.tid=0 AND o.notify_url IS NOT NULL AND TRIM(o.notify_url)<>'' AND o.notify=0),0) notify_success,
                COALESCE(SUM(o.status IN (1,2,3) AND o.tid=0 AND o.notify_url IS NOT NULL AND TRIM(o.notify_url)<>'' AND o.notify BETWEEN 1 AND 5),0) notify_pending,
                COALESCE(SUM(o.status IN (1,2,3) AND o.tid=0 AND o.notify_url IS NOT NULL AND TRIM(o.notify_url)<>'' AND o.notify<0),0) notify_failed,
                MAX(IF(o.status IN (1,2,3),o.endtime,NULL)) last_success_time,
                MIN(IF(o.status IN (1,2,3) AND o.tid=0 AND o.notify_url IS NOT NULL AND TRIM(o.notify_url)<>'' AND o.notify BETWEEN 1 AND 5,o.endtime,NULL)) notify_oldest_time,
                COALESCE(SUM(o.money<=30),0) band_0_30_total,
                COALESCE(SUM(o.money<=30 AND o.status IN (1,2,3)),0) band_0_30_paid,
                COALESCE(SUM(o.money>30 AND o.money<=100),0) band_30_100_total,
                COALESCE(SUM(o.money>30 AND o.money<=100 AND o.status IN (1,2,3)),0) band_30_100_paid,
                COALESCE(SUM(o.money>100 AND o.money<=500),0) band_100_500_total,
                COALESCE(SUM(o.money>100 AND o.money<=500 AND o.status IN (1,2,3)),0) band_100_500_paid,
                COALESCE(SUM(o.money>500 AND o.money<=1000),0) band_500_1000_total,
                COALESCE(SUM(o.money>500 AND o.money<=1000 AND o.status IN (1,2,3)),0) band_500_1000_paid,
                COALESCE(SUM(o.money>1000),0) band_1000_plus_total,
                COALESCE(SUM(o.money>1000 AND o.status IN (1,2,3)),0) band_1000_plus_paid
             FROM pre_channel c
             LEFT JOIN pre_order o ON o.channel=c.id AND o.addtime>=:start AND o.addtime<:end
             GROUP BY c.id,c.name,c.plugin,c.status,c.daystatus,c.paymin,c.paymax ORDER BY c.id",
            $bind
        );
        if($channelRows === false) throw new \RuntimeException('Channel metrics query failed: '.$this->db->error());

        $latencyRows = $this->db->getAll(
            "SELECT channel,TIMESTAMPDIFF(SECOND,addtime,endtime) latency
             FROM pre_order WHERE status IN (1,2,3) AND endtime IS NOT NULL AND addtime>=:start AND addtime<:end
             AND endtime>=addtime ORDER BY addtime DESC,trade_no DESC LIMIT ".(self::MAX_LATENCY_ROWS + 1),
            $bind
        );
        if($latencyRows === false) throw new \RuntimeException('Latency metrics query failed: '.$this->db->error());
        $latencyComplete = count($latencyRows) <= self::MAX_LATENCY_ROWS;
        if(!$latencyComplete) array_pop($latencyRows);
        $latencies = [];
        foreach($latencyRows as $row){
            $latencies[(string)$row['channel']][] = max(0, intval($row['latency']));
        }

        $streakRows = $this->db->getAll(
            "SELECT channel,status FROM pre_order WHERE addtime>=:start AND addtime<:end
             ORDER BY addtime ASC,trade_no ASC LIMIT ".(self::MAX_STREAK_ROWS + 1),
            $bind
        );
        if($streakRows === false) throw new \RuntimeException('Failure streak query failed: '.$this->db->error());
        $streakComplete = count($streakRows) <= self::MAX_STREAK_ROWS;
        if(!$streakComplete) array_pop($streakRows);
        $streaks = $this->streakSummaries($streakRows);

        $channels = [];
        foreach($channelRows as $row){
            $id = (string)$row['channel_id'];
            $channel = $this->normalizeCounters($row);
            $channel['channel_id'] = intval($row['channel_id']);
            $channel['channel_name'] = (string)$row['channel_name'];
            $channel['plugin'] = (string)$row['plugin'];
            $channel['channel_status'] = intval($row['channel_status']);
            $channel['daystatus'] = intval($row['daystatus']);
            $channel['paymin'] = $row['paymin'];
            $channel['paymax'] = $row['paymax'];
            $channel['amount_bands'] = $this->amountBands($row);
            $channel['latency'] = $this->latencySummary(isset($latencies[$id]) ? $latencies[$id] : []);
            $streak = isset($streaks[$id]) ? $streaks[$id] : $this->emptyStreak();
            $channel['failure_streak'] = $streak['trailing'];
            $channel['failure_streak_max'] = $streak['max'];
            $channel['streak'] = $streak;
            $channel['success_rate'] = $this->rate($channel['paid_orders'], $channel['total_orders']);
            $channels[$id] = $channel;
        }

        $platform['success_rate'] = $this->rate($platform['paid_orders'], $platform['total_orders']);
        $platform['latency'] = $this->latencySummary($this->flatten($latencies));

        return [
            'version' => self::VERSION,
            'period_start' => $start,
            'period_end' => $end,
            'generated_at' => date('Y-m-d H:i:s'),
            'platform' => $platform,
            'channels' => $channels,
            'merchants' => [],
            'data_complete' => $latencyComplete && $streakComplete,
            'merchant_data_complete' => true,
            'limits' => ['latency_rows'=>self::MAX_LATENCY_ROWS, 'streak_rows'=>self::MAX_STREAK_ROWS],
        ];
    }

    public function collectNotificationMetrics($start, $end)
    {
        $this->assertDateTime($start);
        $this->assertDateTime($end);
        if(strtotime($start) >= strtotime($end)) throw new \InvalidArgumentException('Invalid notification metrics period');

        $bind = [
            ':start'=>$start,
            ':end'=>$end,
        ];
        $eligibility = "status IN (1,2,3) AND tid=0 AND notify_url IS NOT NULL AND TRIM(notify_url)<>''";
        $platform = $this->db->getRow(
            "SELECT COUNT(*) notify_total,
                COALESCE(SUM(notify=0),0) notify_success,
                COALESCE(SUM(notify BETWEEN 1 AND 5),0) notify_pending,
                COALESCE(SUM(notify<0),0) notify_failed,
                MIN(IF(notify BETWEEN 1 AND 5,endtime,NULL)) notify_oldest_time
             FROM pre_order WHERE addtime>=:start AND addtime<:end AND {$eligibility}",
            $bind
        );
        if($platform === false) throw new \RuntimeException('Notification metrics query failed: '.$this->db->error());
        $platform = $this->normalizeCounters($platform);

        $rows = $this->db->getAll(
            "SELECT channel channel_id,COUNT(*) notify_total,
                COALESCE(SUM(notify=0),0) notify_success,
                COALESCE(SUM(notify BETWEEN 1 AND 5),0) notify_pending,
                COALESCE(SUM(notify<0),0) notify_failed,
                MIN(IF(notify BETWEEN 1 AND 5,endtime,NULL)) notify_oldest_time
             FROM pre_order WHERE addtime>=:start AND addtime<:end AND {$eligibility}
             GROUP BY channel",
            $bind
        );
        if($rows === false) throw new \RuntimeException('Channel notification metrics query failed: '.$this->db->error());
        $channels = [];
        foreach($rows as $row){
            $id = (string)intval($row['channel_id']);
            $channels[$id] = $this->normalizeCounters($row);
            $channels[$id]['channel_id'] = intval($row['channel_id']);
        }
        return ['platform'=>$platform, 'channels'=>$channels, 'as_of'=>date('Y-m-d H:i:s')];
    }

    public function saveHourlySnapshot($snapshotTime = null)
    {
        $bundles = $this->saveHourlySnapshots($snapshotTime, 1);
        return end($bundles);
    }

    public function saveHourlySnapshots($snapshotTime = null, $hours = 6)
    {
        $snapshotTs = $snapshotTime === null ? time() : strtotime($snapshotTime);
        if($snapshotTs === false) throw new \InvalidArgumentException('Invalid snapshot time');
        $hours = max(1, min(self::MAX_SAFE_BACKFILL_HOURS, intval($hours)));
        $boundary = strtotime(date('Y-m-d H:00:00', $snapshotTs));
        $bundles = [];
        if($this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false) throw new \RuntimeException('Snapshot isolation setup failed');
        if(!$this->db->beginTransaction()) throw new \RuntimeException('Snapshot read transaction failed to start');
        try {
            for($offset = $hours; $offset >= 1; $offset--){
                $periodStart = $boundary - $offset * 3600;
                $periodEnd = $periodStart + 3600;
                $bundles[] = $this->collect(date('Y-m-d H:i:s', $periodStart), date('Y-m-d H:i:s', $periodEnd));
            }
            if(!$this->db->commit()) throw new \RuntimeException('Snapshot read transaction commit failed');
        } catch(\Throwable $e){
            $this->db->rollBack();
            throw $e;
        }

        if(!$this->db->beginTransaction()) throw new \RuntimeException('Snapshot write transaction failed to start');
        try {
            foreach($bundles as $bundle){
                $snapshot = date('Y-m-d H:00:00', strtotime($bundle['period_end']));
                $this->replaceSnapshot($snapshot, $bundle['period_start'], $bundle['period_end'], 'platform', '0', $bundle);
                foreach($bundle['channels'] as $id => $metrics){
                    $this->replaceSnapshot($snapshot, $bundle['period_start'], $bundle['period_end'], 'channel', $id, $metrics);
                }
            }
            if(!$this->db->commit()) throw new \RuntimeException('Snapshot write transaction commit failed');
        } catch(\Throwable $e){
            $this->db->rollBack();
            throw $e;
        }
        return $bundles;
    }

    public function cleanSnapshots($days)
    {
        $days = max(7, min(730, intval($days)));
        return $this->db->exec("DELETE FROM pre_health_snapshot WHERE snapshot_time<DATE_SUB(NOW(),INTERVAL {$days} DAY)");
    }

    private function replaceSnapshot($time, $start, $end, $scopeType, $scopeId, $metrics)
    {
        $json = json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if($json === false) throw new \RuntimeException('Snapshot JSON encoding failed');
        $result = $this->db->exec(
            "REPLACE INTO pre_health_snapshot
             (snapshot_time,period_start,period_end,scope_type,scope_id,metrics_json,metrics_version,created_at)
             VALUES(:snapshot_time,:period_start,:period_end,:scope_type,:scope_id,:metrics_json,:version,NOW())",
            [':snapshot_time'=>$time, ':period_start'=>$start, ':period_end'=>$end, ':scope_type'=>$scopeType,
             ':scope_id'=>(string)$scopeId, ':metrics_json'=>$json, ':version'=>self::VERSION]
        );
        if($result === false) throw new \RuntimeException('Snapshot write failed: '.$this->db->error());
    }

    private function normalizeCounters($row)
    {
        foreach($row as $key => $value){
            if(preg_match('/(_orders|_total|_success|_pending|_failed)$/', $key)) $row[$key] = intval($value);
            elseif(preg_match('/(_money)$/', $key)) $row[$key] = round((float)$value, 2);
        }
        return $row;
    }

    private function amountBands($row)
    {
        $definitions = [
            '0_30'=>['band_0_30_total','band_0_30_paid'],
            '30_100'=>['band_30_100_total','band_30_100_paid'],
            '100_500'=>['band_100_500_total','band_100_500_paid'],
            '500_1000'=>['band_500_1000_total','band_500_1000_paid'],
            '1000_plus'=>['band_1000_plus_total','band_1000_plus_paid'],
        ];
        $result = [];
        foreach($definitions as $name => $keys){
            $total = intval($row[$keys[0]]);
            $paid = intval($row[$keys[1]]);
            $result[$name] = ['total'=>$total, 'paid'=>$paid, 'success_rate'=>$this->rate($paid, $total)];
        }
        return $result;
    }

    private function latencySummary($values)
    {
        if(!$values) return ['count'=>0, 'avg'=>null, 'p50'=>null, 'p95'=>null];
        sort($values, SORT_NUMERIC);
        return [
            'count'=>count($values),
            'avg'=>round(array_sum($values) / count($values), 2),
            'p50'=>$this->percentile($values, 0.50),
            'p95'=>$this->percentile($values, 0.95),
        ];
    }

    private function percentile($values, $fraction)
    {
        $index = (int)ceil(count($values) * $fraction) - 1;
        return intval($values[max(0, min(count($values) - 1, $index))]);
    }

    private function streakSummaries($rows)
    {
        $result = [];
        foreach($rows as $row){
            $id = (string)$row['channel'];
            if(!isset($result[$id])) $result[$id] = $this->emptyStreak();
            $result[$id]['count']++;
            if(intval($row['status']) === 0){
                if(!$result[$id]['has_break']) $result[$id]['leading']++;
                $result[$id]['trailing']++;
                $result[$id]['max'] = max($result[$id]['max'], $result[$id]['trailing']);
            }else{
                $result[$id]['has_break'] = true;
                $result[$id]['trailing'] = 0;
            }
        }
        foreach($result as &$summary){
            $summary['all_unpaid'] = !$summary['has_break'] && $summary['count'] > 0;
            unset($summary['has_break']);
        }
        unset($summary);
        return $result;
    }

    private function emptyStreak()
    {
        return ['count'=>0, 'leading'=>0, 'trailing'=>0, 'max'=>0, 'all_unpaid'=>false, 'has_break'=>false];
    }

    private function flatten($groups)
    {
        $result = [];
        foreach($groups as $values) foreach($values as $value) $result[] = $value;
        return $result;
    }

    private function rate($paid, $total)
    {
        return intval($total) > 0 ? round(intval($paid) * 100 / intval($total), 2) : null;
    }

    private function assertDateTime($value)
    {
        $date = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
        if(!$date || $date->format('Y-m-d H:i:s') !== $value) throw new \InvalidArgumentException('Invalid datetime');
    }
}
