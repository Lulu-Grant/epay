<?php
include("../includes/common.php");
if($islogin==1){}else exit("<script language='javascript'>window.location.href='./login.php';</script>");
$act=isset($_GET['act'])?daddslashes($_GET['act']):null;

if(!checkRefererHost())exit('{"code":403}');

@header('Content-Type: application/json; charset=UTF-8');

switch($act){
case 'getcount':
	$thtime=date("Y-m-d").' 00:00:00';
	$count1=$DB->getColumn("SELECT count(*) from pre_order");
	$count2=$DB->getColumn("SELECT count(*) from pre_user");
	$plugincount=$DB->getColumn("SELECT count(*) from pre_plugin");
	if($plugincount<1){
		\lib\Plugin::updateAll();
	}
	$isConvert = $DB->getRow("SELECT * FROM pre_channel WHERE status=1 AND config IS NULL LIMIT 1");
	if($isConvert){
		convert_channel_data();
		\lib\Plugin::updateAll();
	}

	$orderrow=$DB->getRow("SELECT COUNT(*) allnum,COUNT(IF(status>0, 1, NULL)) sucnum FROM pre_order WHERE addtime>='$thtime'");
	$success_rate = 100;
	if($orderrow){
		if($orderrow['allnum'] > 0){
			$success_rate = round($orderrow['sucnum']/$orderrow['allnum']*100,2);
		}
	}

	$paytype = [];
	$rs = $DB->getAll("SELECT id,name,showname FROM pre_type WHERE status=1");
	foreach($rs as $row){
		$paytype[$row['id']] = $row['showname'];
	}
	unset($rs);

	$channel = [];
	$rs = $DB->getAll("SELECT id,name FROM pre_channel WHERE status=1");
	foreach($rs as $row){
		$channel[$row['id']] = $row['name'];
	}
	unset($rs);

		$all_paytype = [];
		$rs = $DB->getAll("SELECT id,name,showname FROM pre_type ORDER BY id ASC");
		foreach($rs as $row){
			$all_paytype[$row['id']] = $row['showname'];
		}
		unset($rs);
		$all_channel = [];
		$rs = $DB->getAll("SELECT id,name FROM pre_channel ORDER BY id ASC");
		foreach($rs as $row){
			$all_channel[$row['id']] = $row['name'];
		}
		unset($rs);

		$init_stat = function() use ($paytype, $channel) {
			$stat = ['all'=>0, 'profit_all'=>0, 'paytype'=>[], 'channel'=>[], 'profit_paytype'=>[]];
			foreach($paytype as $id=>$name){
				$stat['paytype'][$id] = 0;
				$stat['profit_paytype'][$id] = 0;
			}
			foreach($channel as $id=>$name){
				$stat['channel'][$id] = 0;
			}
			return $stat;
		};

		$days = [];
		for($i=0;$i<7;$i++){
			$date_key = date("Y-m-d", strtotime("-{$i} day"));
			$days[$date_key] = $init_stat();
		}
		$startday = date("Y-m-d", strtotime("-6 day"));
		$today = date("Y-m-d");
		$rs=$DB->getAll("SELECT date,type,channel,ROUND(SUM(COALESCE(realmoney,0)),2) realmoney,ROUND(SUM(COALESCE(profitmoney,0)),2) profitmoney FROM pre_order WHERE status=1 AND date>=:startday AND date<=:today GROUP BY date,type,channel ORDER BY date DESC,type ASC,channel ASC", [':startday'=>$startday, ':today'=>$today]);
		if($rs){
			foreach($rs as $row){
				if(!isset($days[$row['date']]))continue;
				$typeid = $row['type'];
				$channelid = $row['channel'];
				if(!isset($paytype[$typeid]))$paytype[$typeid] = isset($all_paytype[$typeid]) ? $all_paytype[$typeid] : '支付方式'.$typeid;
				if(!isset($channel[$channelid]))$channel[$channelid] = isset($all_channel[$channelid]) ? $all_channel[$channelid] : '通道'.$channelid;
				$realmoney = round((float)$row['realmoney'], 2);
				$profitmoney = round((float)$row['profitmoney'], 2);
				if(!isset($days[$row['date']]['paytype'][$typeid]))$days[$row['date']]['paytype'][$typeid] = 0;
				if(!isset($days[$row['date']]['profit_paytype'][$typeid]))$days[$row['date']]['profit_paytype'][$typeid] = 0;
				if(!isset($days[$row['date']]['channel'][$channelid]))$days[$row['date']]['channel'][$channelid] = 0;
				$days[$row['date']]['paytype'][$typeid] += $realmoney;
				$days[$row['date']]['channel'][$channelid] += $realmoney;
				$days[$row['date']]['profit_paytype'][$typeid] += $profitmoney;
				$days[$row['date']]['all'] += $realmoney;
				$days[$row['date']]['profit_all'] += $profitmoney;
			}
		}
		foreach($days as $date_key=>$stat){
			$days[$date_key]['all'] = round($stat['all'], 2);
			$days[$date_key]['profit_all'] = round($stat['profit_all'], 2);
			foreach($stat['paytype'] as $k=>$v)$days[$date_key]['paytype'][$k] = round($v, 2);
			foreach($stat['channel'] as $k=>$v)$days[$date_key]['channel'][$k] = round($v, 2);
			foreach($stat['profit_paytype'] as $k=>$v)$days[$date_key]['profit_paytype'][$k] = round($v, 2);
		}

		$tongji_cachetime=getSetting('tongji_cachetime', true);
		$tongji_cache = $CACHE->read('tongji');
		if($tongji_cachetime+3600>=time() && $tongji_cache && !isset($_GET['getnew'])){
			$array = unserialize($tongji_cache);
			$usermoney = $array['usermoney'];
			$settlemoney = $array['settlemoney'];
			$result_type = 'cache';
		}else{
			$usermoney=$DB->getColumn("SELECT SUM(money) FROM pre_user WHERE money!='0.00'");
			$settlemoney=$DB->getColumn("SELECT SUM(money) FROM pre_settle");
			saveSetting('tongji_cachetime',time());
			$CACHE->save('tongji',serialize(["usermoney"=>$usermoney,"settlemoney"=>$settlemoney,"order_today"=>$days[$today]]));
			$result_type = 'online';
		}
		$result=["code"=>0,"type"=>$result_type,"paytype"=>$paytype,"channel"=>$channel,"count1"=>$count1,"count2"=>$count2,"usermoney"=>round($usermoney,2),"settlemoney"=>round($settlemoney,2),"success_rate"=>$success_rate,"order_today"=>$days[$today],"order"=>[]];
		for($i=1;$i<7;$i++){
			$date_key = date("Y-m-d", strtotime("-{$i} day"));
			$day = date("Ymd", strtotime($date_key));
			$result["order"][$day] = $days[$date_key];
		}
		exit(json_encode($result));
break;

case 'set':
	if(isset($_POST['localurl'])){
		if(!empty($_POST['localurl']) && (substr($_POST['localurl'],0,4)!='http' || substr($_POST['localurl'],-1)!='/'))exit('{"code":-1,"msg":"回调专用网址格式错误"}');
	}
	if(isset($_POST['apiurl'])){
		if(!empty($_POST['apiurl']) && (substr($_POST['apiurl'],0,4)!='http' || substr($_POST['apiurl'],-1)!='/'))exit('{"code":-1,"msg":"用户对接网址格式错误"}');
	}
	if(isset($_POST['login_apiurl'])){
		if(!empty($_POST['login_apiurl']) && (substr($_POST['login_apiurl'],0,4)!='http' || substr($_POST['login_apiurl'],-1)!='/'))exit('{"code":-1,"msg":"聚合登录API接口地址格式错误"}');
	}
	foreach($_POST as $k=>$v){
		saveSetting($k, $v);
	}
	$ad=$CACHE->clear();
	if($ad)exit('{"code":0,"msg":"succ"}');
	else exit('{"code":-1,"msg":"修改设置失败['.$DB->error().']"}');
break;
case 'setGonggao':
	$id=intval($_GET['id']);
	$status=intval($_GET['status']);
	$sql = "UPDATE pre_anounce SET status='$status' WHERE id='$id'";
	if($DB->exec($sql))exit('{"code":0,"msg":"修改状态成功！"}');
	else exit('{"code":-1,"msg":"修改状态失败['.$DB->error().']"}');
break;
case 'delGonggao':
	$id=intval($_GET['id']);
	$sql = "DELETE FROM pre_anounce WHERE id='$id'";
	if($DB->exec($sql))exit('{"code":0,"msg":"删除公告成功！"}');
	else exit('{"code":-1,"msg":"删除公告失败['.$DB->error().']"}');
break;
case 'iptype':
	$result = [
	['name'=>'0_X_FORWARDED_FOR', 'ip'=>real_ip(0), 'city'=>get_ip_city(real_ip(0))],
	['name'=>'1_X_REAL_IP', 'ip'=>real_ip(1), 'city'=>get_ip_city(real_ip(1))],
	['name'=>'2_REMOTE_ADDR', 'ip'=>real_ip(2), 'city'=>get_ip_city(real_ip(2))]
	];
	exit(json_encode($result));
break;

case 'setArticle': //文章状态
	$id=intval($_GET['id']);
	$active=intval($_GET['active']);
	$DB->exec("update pre_article set active='$active' where id='{$id}'");
	exit('{"code":0,"msg":"succ"}');
break;
case 'article_upload':
	$file_name = $_FILES['imgFile']['name'];
	$tmp_name = $_FILES['imgFile']['tmp_name'];
	//获得文件扩展名
	$temp_arr = explode(".", $file_name);
	$file_ext = array_pop($temp_arr);
	$file_ext = strtolower(trim($file_ext));
	if (in_array($file_ext, array('gif', 'jpg', 'jpeg', 'png', 'bmp', 'webp')) === false) {
		exit('{"error":1,"message":"上传文件扩展名是不允许的扩展名。"}');
	}
	$filename = md5_file($tmp_name).'.'.$file_ext;
	$fileurl = '/assets/img/article/'.$filename;
	if(copy($tmp_name, ROOT.'assets/img/article/'.$filename)){
		exit('{"error":0,"url":"'.$fileurl.'"}');
	}else{
		exit('{"error":1,"message":"上传失败，请确保有本地写入权限"}');
	}
break;

default:
	exit('{"code":-4,"msg":"No Act"}');
break;
}
