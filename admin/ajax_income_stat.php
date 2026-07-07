<?php
include("../includes/common.php");
if($islogin==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");
$act=isset($_GET['act'])?daddslashes($_GET['act']):null;

if(!checkRefererHost())exit('{"code":403}');

@header('Content-Type: application/json; charset=UTF-8');

function income_stat_money($value){
	return number_format(round((float)$value, 2), 2, '.', '');
}

function income_stat_day_list($days = 7){
	$list = [];
	for($i=0; $i<$days; $i++){
		$date = date("Y-m-d", strtotime("-{$i} day"));
		$list[] = [
			'date' => $date,
			'label' => $i === 0 ? '今日' : date("Ymd", strtotime($date)),
		];
	}
	return $list;
}

function income_stat_build_module($id, $title, $columns, $days, $data){
	$rows = [];
	foreach($days as $day){
		$row = ['date'=>$day['date'], 'label'=>$day['label'], 'values'=>[]];
		$total = 0;
		foreach($columns as $key=>$label){
			$value = isset($data[$day['date']][$key]) ? (float)$data[$day['date']][$key] : 0;
			$total += $value;
			$row['values'][$key] = income_stat_money($value);
		}
		$row['values']['total'] = income_stat_money($total);
		$rows[] = $row;
	}
	$outColumns = [];
	foreach($columns as $key=>$label){
		$outColumns[] = ['key'=>(string)$key, 'label'=>$label];
	}
	$outColumns[] = ['key'=>'total', 'label'=>'总计'];
	return ['id'=>$id, 'title'=>$title, 'columns'=>$outColumns, 'rows'=>$rows];
}

function income_stat_dashboard($force = false){
	global $DB, $CACHE;

	$cacheKey = 'income_stat_dashboard';
	$ttl = 3600;
	if($force){
		$CACHE->delete($cacheKey);
	}else{
		$cache = $CACHE->read($cacheKey);
		if($cache){
			$payload = @unserialize($cache);
			if(is_array($payload) && isset($payload['generated_ts']) && $payload['generated_ts'] + $ttl >= time()){
				$payload['source'] = 'cache';
				return $payload;
			}
		}
	}

	$days = income_stat_day_list(7);
	$startday = $days[count($days)-1]['date'];
	$endday = $days[0]['date'];

	$typeColumns = [];
	$typeNames = [];
	$typeRows = $DB->getAll("SELECT id,showname FROM pre_type WHERE status=1 ORDER BY id ASC");
	if($typeRows){
		foreach($typeRows as $row){
			$key = (string)$row['id'];
			$typeColumns[$key] = $row['showname'];
			$typeNames[$key] = $row['showname'];
		}
	}
	$allTypeRows = $DB->getAll("SELECT id,showname FROM pre_type ORDER BY id ASC");
	if($allTypeRows){
		foreach($allTypeRows as $row){
			$typeNames[(string)$row['id']] = $row['showname'];
		}
	}

	$typeIncome = [];
	$typeProfit = [];
	$rs = $DB->getAll(
		"SELECT date,type,ROUND(SUM(COALESCE(realmoney,0)),2) income,ROUND(SUM(COALESCE(profitmoney,0)),2) profit FROM pre_order WHERE status=1 AND date>=:startday AND date<=:endday GROUP BY date,type ORDER BY type ASC",
		[':startday'=>$startday, ':endday'=>$endday]
	);
	if($rs){
		foreach($rs as $row){
			$key = (string)$row['type'];
			if(!isset($typeColumns[$key])){
				$typeColumns[$key] = isset($typeNames[$key]) ? $typeNames[$key] : '支付方式'.$key;
			}
			$typeIncome[$row['date']][$key] = (float)$row['income'];
			$typeProfit[$row['date']][$key] = (float)$row['profit'];
		}
	}

	$channelIncome = [];
	$channelProfit = [];
	$channelColumns = [];
	$channelRows = $DB->getAll(
		"SELECT date,channel,ROUND(SUM(COALESCE(realmoney,0)),2) income,ROUND(SUM(COALESCE(profitmoney,0)),2) profit FROM pre_order WHERE status=1 AND date>=:startday AND date<=:endday GROUP BY date,channel ORDER BY channel ASC",
		[':startday'=>$startday, ':endday'=>$endday]
	);
	if($channelRows){
		$channelIds = [];
		foreach($channelRows as $row){
			$key = (string)$row['channel'];
			$channelIds[$key] = true;
			$channelIncome[$row['date']][$key] = (float)$row['income'];
			$channelProfit[$row['date']][$key] = (float)$row['profit'];
		}
		if($channelIds){
			$ids = array_map('intval', array_keys($channelIds));
			$channels = $DB->getAll("SELECT id,name FROM pre_channel WHERE id IN (".implode(',', $ids).") ORDER BY id ASC");
			if($channels){
				foreach($channels as $row){
					$channelColumns[(string)$row['id']] = $row['name'];
				}
			}
			foreach($ids as $id){
				$key = (string)$id;
				if(!isset($channelColumns[$key])){
					$channelColumns[$key] = '通道'.$key;
				}
			}
			ksort($channelColumns, SORT_NUMERIC);
		}
	}

	$payload = [
		'code' => 0,
		'source' => 'online',
		'generated_ts' => time(),
		'generated_at' => date("Y-m-d H:i:s"),
		'ttl' => $ttl,
		'days' => $days,
		'modules' => [
			income_stat_build_module('income_type', '支付方式收入统计（1小时更新一次）', $typeColumns, $days, $typeIncome),
			income_stat_build_module('income_channel', '支付通道收入统计（1小时更新一次）', $channelColumns, $days, $channelIncome),
			income_stat_build_module('profit_type', '支付方式手续费利润统计（1小时更新一次）', $typeColumns, $days, $typeProfit),
			income_stat_build_module('profit_channel', '支付通道手续费利润统计（1小时更新一次）', $channelColumns, $days, $channelProfit),
		],
	];

	$CACHE->save($cacheKey, serialize($payload), $ttl);
	return $payload;
}

switch($act){
case 'dashboard':
	$force = isset($_GET['force']) && $_GET['force'] == '1';
	exit(json_encode(income_stat_dashboard($force), JSON_UNESCAPED_UNICODE));
break;

default:
	exit('{"code":-4,"msg":"No Act"}');
break;
}
