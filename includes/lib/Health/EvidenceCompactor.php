<?php
namespace lib\Health;

class EvidenceCompactor
{
    const VERSION = 'compact-evidence-v1';
    const MAX_CHANNEL_PAYLOAD_BYTES = 65536;
    const MAX_NORMAL_SAMPLES = 80;
    const MAX_ANOMALY_EXAMPLES = 20;
    const MAX_COMPLAINT_EXAMPLES = 20;
    const MAX_MERCHANTS = 20;
    const MAX_CLUSTERS = 20;

    public function compact($dataset)
    {
        if(!is_array($dataset) || empty($dataset['report_date']) || !isset($dataset['orders']) || !is_array($dataset['orders'])){
            throw new \InvalidArgumentException('Compact evidence dataset is incomplete');
        }

        $ordersByChannel = [];
        foreach($dataset['orders'] as $order){
            $channelId = (string)intval(isset($order['channel']) ? $order['channel'] : 0);
            $ordersByChannel[$channelId][] = $order;
        }
        ksort($ordersByChannel, SORT_NUMERIC);

        $complaintsByChannel = [];
        foreach(isset($dataset['complaints']) && is_array($dataset['complaints']) ? $dataset['complaints'] : [] as $complaint){
            $channelId = (string)intval(isset($complaint['channel']) ? $complaint['channel'] : 0);
            $complaintsByChannel[$channelId][] = $complaint;
        }

        $channels = [];
        $sampleRows = 0;
        foreach($ordersByChannel as $channelId=>$orders){
            $channel = $this->compactChannel(
                $dataset,
                $channelId,
                $orders,
                isset($complaintsByChannel[$channelId]) ? $complaintsByChannel[$channelId] : []
            );
            $channels[$channelId] = $channel;
            $sampleRows += intval($channel['sample_rows']);
        }

        return [
            'version'=>self::VERSION,
            'report_date'=>$dataset['report_date'],
            'channels'=>$channels,
            'source_rows'=>count($dataset['orders']),
            'sample_rows'=>$sampleRows,
        ];
    }

    public function resolveDrilldown($channel, $requests, $maxPerRequest = 20, $maxTotal = 40)
    {
        $orders = [];
        $complaints = [];
        $seen = [];
        foreach(array_slice(is_array($requests) ? $requests : [], 0, 2) as $request){
            $ref = is_array($request) && isset($request['evidence_ref']) ? (string)$request['evidence_ref'] : '';
            if($ref === '' || !isset($channel['catalog'][$ref])) continue;
            $members = isset($channel['members'][$ref]) ? $channel['members'][$ref] : [$ref];
            $taken = 0;
            foreach($members as $memberRef){
                if($taken >= $maxPerRequest || count($seen) >= $maxTotal) break;
                if(isset($seen[$memberRef])) continue;
                if(isset($channel['orders'][$memberRef])){
                    $orders[] = $channel['orders'][$memberRef];
                    $seen[$memberRef] = true;
                    $taken++;
                }elseif(isset($channel['complaints'][$memberRef])){
                    $complaints[] = $channel['complaints'][$memberRef];
                    $seen[$memberRef] = true;
                    $taken++;
                }
            }
            if(count($seen) >= $maxTotal) break;
        }
        return ['orders'=>$orders, 'complaints'=>$complaints, 'source_rows'=>count($seen)];
    }

    public function citedCatalog($compacted, $channelResults, $finalResult)
    {
        $refs = [];
        foreach($channelResults as $result) $this->collectFindingRefs($result, $refs);
        $this->collectFindingRefs($finalResult, $refs);
        $catalog = [];
        foreach($compacted['channels'] as $channel){
            foreach($refs as $ref=>$_){
                if(isset($channel['catalog'][$ref])) $catalog[$ref] = $channel['catalog'][$ref];
            }
        }
        ksort($catalog, SORT_STRING);
        return $catalog;
    }

    private function compactChannel($dataset, $channelId, $orders, $complaints)
    {
        $reportDate = (string)$dataset['report_date'];
        $periodEnd = isset($dataset['period_end']) ? strtotime($dataset['period_end']) : strtotime($reportDate.' +1 day');
        $catalog = [];
        $members = [];
        $compactOrders = [];
        $complaintOrderRefs = [];

        $compactComplaints = [];
        foreach($complaints as $complaint){
            $row = $this->compactComplaint($complaint);
            $ref = $row['_evidence_ref'];
            $compactComplaints[$ref] = $row;
            $catalog[$ref] = $row;
            $members[$ref] = [$ref];
            if(!empty($row['trade_no'])) $complaintOrderRefs['order:'.$row['trade_no']] = true;
        }

        $hourRows = [];
        $amountRows = [];
        $merchantRows = [];
        $clusterRows = [];
        $anomalyRows = [
            'refunded'=>[], 'frozen'=>[], 'preauthorized'=>[], 'callback_pending'=>[],
            'callback_failed'=>[], 'unpaid_over_6h'=>[], 'latency_over_5m'=>[],
        ];
        $sampleCells = [];

        foreach($orders as $order){
            $row = $this->compactOrder($order, $reportDate);
            $ref = $row['_evidence_ref'];
            $compactOrders[$ref] = $row;
            $catalog[$ref] = $row;
            $members[$ref] = [$ref];

            $hour = substr((string)$row['created_at'], 11, 2);
            if(!preg_match('/^[0-9]{2}$/D', $hour)) $hour = '00';
            $amountBand = $this->amountBand((float)$row['amount']);
            $uid = (string)$row['uid'];
            if(!isset($hourRows[$hour])) $hourRows[$hour] = [];
            if(!isset($amountRows[$amountBand])) $amountRows[$amountBand] = [];
            if(!isset($merchantRows[$uid])) $merchantRows[$uid] = [];
            $hourRows[$hour][] = $row;
            $amountRows[$amountBand][] = $row;
            $merchantRows[$uid][] = $row;

            $clusterKey = $uid."\0".$row['ip_group']."\0".$row['amount']."\0".$this->normalizeName($row['product_name']);
            if(!isset($clusterRows[$clusterKey])) $clusterRows[$clusterKey] = [];
            $clusterRows[$clusterKey][] = $row;

            $status = intval($row['status']);
            if($status === 2) $anomalyRows['refunded'][] = $row;
            if($status === 3) $anomalyRows['frozen'][] = $row;
            if($status === 4) $anomalyRows['preauthorized'][] = $row;
            if($row['notify_state'] === 'pending') $anomalyRows['callback_pending'][] = $row;
            if($row['notify_state'] === 'failed') $anomalyRows['callback_failed'][] = $row;
            $created = strtotime($row['created_at']);
            if($status === 0 && $created !== false && $periodEnd !== false && $periodEnd - $created >= 21600) $anomalyRows['unpaid_over_6h'][] = $row;
            if($row['latency_seconds'] !== null && intval($row['latency_seconds']) >= 300) $anomalyRows['latency_over_5m'][] = $row;

            $isAnomaly = $status >= 2 || $row['notify_state'] === 'pending' || $row['notify_state'] === 'failed'
                || isset($complaintOrderRefs[$ref])
                || ($status === 0 && $created !== false && $periodEnd !== false && $periodEnd - $created >= 21600)
                || ($row['latency_seconds'] !== null && intval($row['latency_seconds']) >= 300);
            if(!$isAnomaly){
                $timeBand = sprintf('%02d-%02d', intval($hour) - (intval($hour) % 6), intval($hour) - (intval($hour) % 6) + 5);
                $cell = ($status === 0 ? 'unpaid' : 'paid').'|'.$amountBand.'|'.$timeBand;
                $sampleCells[$cell][] = $row;
            }
        }

        $hourly = [];
        for($hour=0;$hour<24;$hour++){
            $key = sprintf('%02d', $hour);
            if(empty($hourRows[$key])) continue;
            $ref = 'agg:channel:'.intval($channelId).':hour:'.$key;
            $entry = array_merge(['evidence_ref'=>$ref, 'hour'=>$key], $this->metric($hourRows[$key]));
            $hourly[] = $entry;
            $catalog[$ref] = $entry;
            $members[$ref] = $this->refs($hourRows[$key]);
        }

        $amounts = [];
        foreach(['0-30','30.01-100','100.01-500','500.01-1000','1000+'] as $band){
            $rows = isset($amountRows[$band]) ? $amountRows[$band] : [];
            $ref = 'agg:channel:'.intval($channelId).':amount:'.$band;
            $entry = array_merge(['evidence_ref'=>$ref, 'band'=>$band], $this->metric($rows));
            $amounts[] = $entry;
            $catalog[$ref] = $entry;
            $members[$ref] = $this->refs($rows);
        }

        $merchants = $this->merchantEntries($channelId, $merchantRows, $catalog, $members);
        $clusters = $this->clusterEntries($reportDate, $clusterRows, $catalog, $members);
        $anomalies = [];
        foreach($anomalyRows as $kind=>$rows){
            $ref = 'agg:channel:'.intval($channelId).':anomaly:'.$kind;
            $entry = array_merge([
                'evidence_ref'=>$ref,
                'kind'=>$kind,
                'sample_refs'=>array_slice($this->refs($rows), 0, self::MAX_ANOMALY_EXAMPLES),
                'all_refs_sha256'=>$this->refsHash($rows),
            ], $this->metric($rows));
            $anomalies[] = $entry;
            $catalog[$ref] = $entry;
            $members[$ref] = $this->refs($rows);
        }

        $complaintRef = 'agg:channel:'.intval($channelId).':anomaly:complaints';
        $complaintRefs = array_keys($compactComplaints);
        $complaintSummary = [
            'evidence_ref'=>$complaintRef,
            'count'=>count($compactComplaints),
            'sample_refs'=>array_slice($complaintRefs, 0, self::MAX_COMPLAINT_EXAMPLES),
            'all_refs_sha256'=>$this->stringRefsHash($complaintRefs),
        ];
        $catalog[$complaintRef] = $complaintSummary;
        $members[$complaintRef] = $complaintRefs;

        $samples = [];
        ksort($sampleCells, SORT_STRING);
        foreach($sampleCells as $rows){
            usort($rows, function($a, $b)use($reportDate){
                return strcmp(hash('sha256', $reportDate."\0".$a['trade_no']), hash('sha256', $reportDate."\0".$b['trade_no']));
            });
            foreach(array_slice($rows, 0, 2) as $row){
                if(count($samples) >= self::MAX_NORMAL_SAMPLES) break 2;
                $samples[] = $row;
            }
        }

        $visibleComplaints = array_slice(array_values($compactComplaints), 0, self::MAX_COMPLAINT_EXAMPLES);
        $payload = [
            'evidence_version'=>self::VERSION,
            'report_date'=>$reportDate,
            'channel_id'=>intval($channelId),
            'channel_metrics'=>isset($dataset['metrics']['channels'][(string)$channelId]) ? $dataset['metrics']['channels'][(string)$channelId] : [],
            'seven_day_baseline'=>$this->channelBaseline(isset($dataset['baseline']) ? $dataset['baseline'] : [], $channelId),
            'hourly'=>$hourly,
            'amount_bands'=>$amounts,
            'merchants'=>$merchants,
            'clusters'=>$clusters,
            'anomaly_groups'=>$anomalies,
            'complaint_summary'=>$complaintSummary,
            'complaint_examples'=>$visibleComplaints,
            'normal_samples'=>$samples,
        ];
        $this->fitPayload($payload);

        $visibleRefs = [];
        $this->collectRefs($payload, $visibleRefs);
        return [
            'payload'=>$payload,
            'catalog'=>$catalog,
            'members'=>$members,
            'orders'=>$compactOrders,
            'complaints'=>$compactComplaints,
            'visible_refs'=>$visibleRefs,
            'source_rows'=>count($orders),
            'sample_rows'=>count($payload['normal_samples']) + $this->payloadAnomalyExampleCount($payload),
            'payload_bytes'=>$this->jsonBytes($payload),
        ];
    }

    private function compactOrder($order, $reportDate)
    {
        $status = intval(isset($order['status']) ? $order['status'] : 0);
        $notify = intval(isset($order['notify']) ? $order['notify'] : 0);
        $notifyUrl = trim((string)(isset($order['notify_url']) ? $order['notify_url'] : ''));
        $notifyEligible = in_array($status, [1,2,3], true) && intval(isset($order['tid']) ? $order['tid'] : 0) === 0 && $notifyUrl !== '';
        $notifyState = 'not_applicable';
        if($notifyEligible){
            if($notify === 0) $notifyState = 'success';
            elseif($notify >= 1 && $notify <= 5) $notifyState = 'pending';
            elseif($notify < 0) $notifyState = 'failed';
            else $notifyState = 'unknown';
        }
        $start = !empty($order['addtime']) ? strtotime($order['addtime']) : false;
        $end = !empty($order['endtime']) ? strtotime($order['endtime']) : false;
        $latency = $start !== false && $end !== false && $end >= $start ? $end - $start : null;
        $tradeNo = (string)(isset($order['trade_no']) ? $order['trade_no'] : '');
        $ip = trim((string)(isset($order['ip']) ? $order['ip'] : ''));
        return [
            '_evidence_ref'=>'order:'.$tradeNo,
            'trade_no'=>$tradeNo,
            'uid'=>intval(isset($order['uid']) ? $order['uid'] : 0),
            'merchant_group'=>intval(isset($order['_merchant_group']) ? $order['_merchant_group'] : 0),
            'channel'=>intval(isset($order['channel']) ? $order['channel'] : 0),
            'status'=>$status,
            'amount'=>round((float)(isset($order['money']) ? $order['money'] : 0), 2),
            'paid_amount'=>isset($order['realmoney']) && $order['realmoney'] !== null ? round((float)$order['realmoney'], 2) : null,
            'refund_amount'=>isset($order['refundmoney']) && $order['refundmoney'] !== null ? round((float)$order['refundmoney'], 2) : null,
            'product_name'=>$this->text(isset($order['name']) ? $order['name'] : '', 64),
            'created_at'=>(string)(isset($order['addtime']) ? $order['addtime'] : ''),
            'completed_at'=>!empty($order['endtime']) ? (string)$order['endtime'] : null,
            'latency_seconds'=>$latency,
            'notify_state'=>$notifyState,
            'notify_attempt'=>$notifyEligible ? $notify : null,
            'ip_group'=>$ip === '' ? 'none' : substr(hash('sha256', $reportDate."\0".$ip), 0, 12),
        ];
    }

    private function compactComplaint($complaint)
    {
        $id = intval(isset($complaint['id']) ? $complaint['id'] : 0);
        return [
            '_evidence_ref'=>'complaint:'.$id,
            'id'=>$id,
            'trade_no'=>(string)(isset($complaint['trade_no']) ? $complaint['trade_no'] : ''),
            'uid'=>intval(isset($complaint['uid']) ? $complaint['uid'] : 0),
            'channel'=>intval(isset($complaint['channel']) ? $complaint['channel'] : 0),
            'type'=>$this->text(isset($complaint['type']) ? $complaint['type'] : '', 80),
            'title'=>$this->text(isset($complaint['title']) ? $complaint['title'] : '', 120),
            'status'=>intval(isset($complaint['status']) ? $complaint['status'] : 0),
            'created_at'=>(string)(isset($complaint['addtime']) ? $complaint['addtime'] : ''),
        ];
    }

    private function merchantEntries($channelId, $rowsByMerchant, &$catalog, &$members)
    {
        $entries = [];
        foreach($rowsByMerchant as $uid=>$rows){
            $ref = 'agg:channel:'.intval($channelId).':merchant:'.intval($uid);
            $entry = array_merge(['evidence_ref'=>$ref, 'uid'=>intval($uid)], $this->metric($rows));
            $entry['sample_refs'] = array_slice($this->refs($rows), 0, 5);
            $entries[] = $entry;
            $catalog[$ref] = $entry;
            $members[$ref] = $this->refs($rows);
        }
        usort($entries, function($a, $b){
            if($a['total_orders'] !== $b['total_orders']) return $b['total_orders'] <=> $a['total_orders'];
            if($a['notify_failed'] !== $b['notify_failed']) return $b['notify_failed'] <=> $a['notify_failed'];
            if($a['success_rate'] !== $b['success_rate']) return ($a['success_rate'] === null ? 1 : ($b['success_rate'] === null ? -1 : ($a['success_rate'] <=> $b['success_rate'])));
            return $a['uid'] <=> $b['uid'];
        });
        return array_slice($entries, 0, self::MAX_MERCHANTS);
    }

    private function clusterEntries($reportDate, $clusterRows, &$catalog, &$members)
    {
        $entries = [];
        foreach($clusterRows as $key=>$rows){
            if(count($rows) < 3) continue;
            $ref = 'cluster:'.substr(hash('sha256', $reportDate."\0".$key), 0, 12);
            $first = $rows[0];
            $entry = array_merge([
                'evidence_ref'=>$ref,
                'uid'=>$first['uid'],
                'ip_group'=>$first['ip_group'],
                'amount'=>$first['amount'],
                'product_name'=>$first['product_name'],
                'sample_refs'=>array_slice($this->refs($rows), 0, 5),
            ], $this->metric($rows));
            $entries[] = $entry;
            $catalog[$ref] = $entry;
            $members[$ref] = $this->refs($rows);
        }
        usort($entries, function($a, $b){
            if($a['total_orders'] !== $b['total_orders']) return $b['total_orders'] <=> $a['total_orders'];
            return strcmp($a['evidence_ref'], $b['evidence_ref']);
        });
        return array_slice($entries, 0, self::MAX_CLUSTERS);
    }

    private function metric($rows)
    {
        $metric = [
            'total_orders'=>0, 'paid_orders'=>0, 'unpaid_orders'=>0, 'refunded_orders'=>0,
            'frozen_orders'=>0, 'preauth_orders'=>0, 'total_money'=>0.0, 'paid_money'=>0.0,
            'notify_total'=>0, 'notify_success'=>0, 'notify_pending'=>0, 'notify_failed'=>0,
            'success_rate'=>null, 'latency'=>['count'=>0,'avg'=>null,'p50'=>null,'p95'=>null],
        ];
        $latencies = [];
        foreach($rows as $row){
            $status = intval($row['status']);
            $paid = in_array($status, [1,2,3], true);
            $metric['total_orders']++;
            $metric[$paid ? 'paid_orders' : 'unpaid_orders']++;
            if($status === 2) $metric['refunded_orders']++;
            if($status === 3) $metric['frozen_orders']++;
            if($status === 4) $metric['preauth_orders']++;
            $metric['total_money'] += (float)$row['amount'];
            if($paid) $metric['paid_money'] += (float)(isset($row['paid_amount']) ? $row['paid_amount'] : 0);
            if($row['notify_state'] !== 'not_applicable'){
                $metric['notify_total']++;
                if($row['notify_state'] === 'success') $metric['notify_success']++;
                elseif($row['notify_state'] === 'pending') $metric['notify_pending']++;
                elseif($row['notify_state'] === 'failed') $metric['notify_failed']++;
            }
            if($row['latency_seconds'] !== null) $latencies[] = intval($row['latency_seconds']);
        }
        $metric['total_money'] = round($metric['total_money'], 2);
        $metric['paid_money'] = round($metric['paid_money'], 2);
        $metric['success_rate'] = $metric['total_orders'] ? round($metric['paid_orders'] * 100 / $metric['total_orders'], 2) : null;
        sort($latencies, SORT_NUMERIC);
        $count = count($latencies);
        $metric['latency'] = [
            'count'=>$count,
            'avg'=>$count ? round(array_sum($latencies) / $count, 2) : null,
            'p50'=>$count ? $latencies[(int)floor(($count - 1) * 0.5)] : null,
            'p95'=>$count ? $latencies[(int)floor(($count - 1) * 0.95)] : null,
        ];
        return $metric;
    }

    private function fitPayload(&$payload)
    {
        while($this->jsonBytes($payload) > self::MAX_CHANNEL_PAYLOAD_BYTES && count($payload['normal_samples']) > 0) array_pop($payload['normal_samples']);
        while($this->jsonBytes($payload) > self::MAX_CHANNEL_PAYLOAD_BYTES && count($payload['clusters']) > 5) array_pop($payload['clusters']);
        while($this->jsonBytes($payload) > self::MAX_CHANNEL_PAYLOAD_BYTES && count($payload['merchants']) > 5) array_pop($payload['merchants']);
        if($this->jsonBytes($payload) > self::MAX_CHANNEL_PAYLOAD_BYTES){
            foreach($payload['anomaly_groups'] as &$group) $group['sample_refs'] = array_slice($group['sample_refs'], 0, 5);
            unset($group);
            $payload['complaint_examples'] = array_slice($payload['complaint_examples'], 0, 5);
            $payload['complaint_summary']['sample_refs'] = array_slice($payload['complaint_summary']['sample_refs'], 0, 5);
        }
        if($this->jsonBytes($payload) > self::MAX_CHANNEL_PAYLOAD_BYTES) throw new \RuntimeException('Compact channel payload exceeded the supported size');
    }

    private function amountBand($money)
    {
        if($money <= 30) return '0-30';
        if($money <= 100) return '30.01-100';
        if($money <= 500) return '100.01-500';
        if($money <= 1000) return '500.01-1000';
        return '1000+';
    }

    private function channelBaseline($baseline, $channelId)
    {
        return array_values(array_filter(is_array($baseline) ? $baseline : [], function($row)use($channelId){
            return (string)intval(isset($row['channel']) ? $row['channel'] : 0) === (string)intval($channelId);
        }));
    }

    private function refs($rows)
    {
        $refs = [];
        foreach($rows as $row) if(!empty($row['_evidence_ref'])) $refs[] = (string)$row['_evidence_ref'];
        return $refs;
    }

    private function refsHash($rows)
    {
        return $this->stringRefsHash($this->refs($rows));
    }

    private function stringRefsHash($refs)
    {
        return hash('sha256', implode("\n", $refs));
    }

    private function normalizeName($value)
    {
        $value = mb_strtolower($this->text($value, 64), 'UTF-8');
        return preg_replace('/\s+/u', ' ', $value);
    }

    private function text($value, $limit)
    {
        $value = trim(strip_tags((string)$value));
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
        return mb_substr($value, 0, $limit, 'UTF-8');
    }

    private function collectRefs($value, &$refs)
    {
        if(!is_array($value)) return;
        foreach($value as $key=>$child){
            if(($key === '_evidence_ref' || $key === 'evidence_ref') && is_string($child) && $child !== '') $refs[$child] = true;
            elseif($key === 'sample_refs' && is_array($child)){
                foreach($child as $ref) if(is_string($ref) && $ref !== '') $refs[$ref] = true;
            }else $this->collectRefs($child, $refs);
        }
    }

    private function collectFindingRefs($result, &$refs)
    {
        foreach(isset($result['findings']) && is_array($result['findings']) ? $result['findings'] : [] as $finding){
            foreach(isset($finding['evidence_refs']) && is_array($finding['evidence_refs']) ? $finding['evidence_refs'] : [] as $ref){
                if(is_string($ref) && $ref !== '') $refs[$ref] = true;
            }
        }
    }

    private function payloadAnomalyExampleCount($payload)
    {
        $refs = [];
        foreach($payload['anomaly_groups'] as $group){
            foreach($group['sample_refs'] as $ref) $refs[$ref] = true;
        }
        foreach($payload['complaint_examples'] as $row) $refs[$row['_evidence_ref']] = true;
        return count($refs);
    }

    private function jsonBytes($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if($json === false) throw new \RuntimeException('Compact evidence JSON encoding failed');
        return strlen($json);
    }
}
