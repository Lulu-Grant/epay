#!/usr/bin/env php
<?php
if(PHP_SAPI !== 'cli') exit(1);
$nosession = true;
$_SERVER['HTTP_HOST'] = 'localhost';
require dirname(__DIR__).'/includes/common.php';

try {
    $date = isset($argv[1]) ? trim((string)$argv[1]) : date('Y-m-d', strtotime('-1 day'));
    $parsed = \DateTime::createFromFormat('Y-m-d', $date);
    if(!$parsed || $parsed->format('Y-m-d') !== $date || $date >= date('Y-m-d')) throw new \InvalidArgumentException('A completed YYYY-MM-DD date is required');

    $dataset = (new \lib\Health\RawDataService($DB))->collect($date);
    $compacted = (new \lib\Health\EvidenceCompactor())->compact($dataset);
    $rawBytes = 0;
    $compactBytes = 0;
    $channels = [];
    foreach($compacted['channels'] as $channelId=>$channel){
        $orders = [];
        foreach($dataset['orders'] as $order) if((string)intval($order['channel']) === (string)intval($channelId)) $orders[] = $order;
        $complaints = [];
        foreach($dataset['complaints'] as $complaint) if((string)intval(isset($complaint['channel']) ? $complaint['channel'] : 0) === (string)intval($channelId)) $complaints[] = $complaint;
        $rawPayload = [
            'stage'=>'channel_raw_orders', 'report_date'=>$date, 'channel_id'=>intval($channelId),
            'channel_metrics'=>isset($dataset['metrics']['channels'][$channelId]) ? $dataset['metrics']['channels'][$channelId] : [],
            'seven_day_baseline'=>array_values(array_filter($dataset['baseline'], function($row)use($channelId){
                return (string)intval($row['channel']) === (string)intval($channelId);
            })),
            'orders'=>$orders, 'complaints'=>$complaints,
        ];
        $rawJson = json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $compactJson = json_encode($channel['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if($rawJson === false || $compactJson === false) throw new \RuntimeException('Payload comparison encoding failed');
        $channelRaw = strlen($rawJson);
        $channelCompact = strlen($compactJson);
        $rawBytes += $channelRaw;
        $compactBytes += $channelCompact;
        $channels[$channelId] = [
            'orders'=>intval($channel['source_rows']),
            'samples'=>intval($channel['sample_rows']),
            'raw_bytes'=>$channelRaw,
            'compact_bytes'=>$channelCompact,
            'reduction_percent'=>$channelRaw > 0 ? round((1 - $channelCompact / $channelRaw) * 100, 2) : 0,
        ];
    }
    echo json_encode([
        'ok'=>true, 'date'=>$date, 'source_rows'=>count($dataset['orders']),
        'raw_bytes'=>$rawBytes, 'compact_bytes'=>$compactBytes,
        'reduction_percent'=>$rawBytes > 0 ? round((1 - $compactBytes / $rawBytes) * 100, 2) : 0,
        'channels'=>$channels,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch(\Throwable $e){
    fwrite(STDERR, 'compact payload comparison failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}
