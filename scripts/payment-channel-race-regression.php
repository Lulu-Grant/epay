#!/usr/bin/env php
<?php
namespace lib {
    class ApiHelper { public static function api_verify($user, $params){} }
    class Channel {
        public static $selections = 0;
        public static function submit($type, $uid, $gid, $money){
            return self::info(++self::$selections % 2 ? 101 : 102) + ['typename'=>$type, 'subchannel'=>0];
        }
        public static function submit2($type, $uid, $gid, $money){return self::submit('alipay', $uid, $gid, $money);}
        public static function get($id, $info=null){return ['plugin'=>'alipay', 'id'=>$id, 'apptype'=>'2'];}
        public static function getSub($id){return self::get($id);}
        public static function info($id){
            return ['typeid'=>1, 'channel'=>(int)$id, 'plugin'=>'alipay', 'rate'=>90, 'apptype'=>'2', 'mode'=>0, 'paymin'=>0, 'paymax'=>0];
        }
    }
    class Plugin {
        public static function loadForSubmit($plugin, $trade_no, $mapi=false){
            global $order;
            return ['type'=>'redirect', 'url'=>'https://sandbox.invalid/pay?app_id=app-'.$order['channel'], 'order'=>$order];
        }
        public static function loadClass($plugin, $method, $trade_no){return self::loadForSubmit($plugin, $trade_no);}
    }
    class Payment {
        public static function updateOrderProfits($order, $plugin){return 0;}
        public static function updateOrderProfits2($order, $plugin){return 0;}
        public static function echoDefault($result){throw new \RaceResponse($result);}
        public static function echoJson($result){throw new \RaceResponse($result);}
    }
}
namespace lib\Shop {
    class ConfigService { public static function shouldRecordMerchant($uid){return false;} }
    class OrderService { public static function syncPaymentRoute($trade_no){} }
}
namespace {
    if(PHP_SAPI !== 'cli'){http_response_code(404); exit;}
    // No bootstrap, credentials, configuration files, database connections or network calls.
    require dirname(__DIR__).'/includes/lib/PaymentRoute.php';
    require dirname(__DIR__).'/includes/lib/api/Pay.php';
    class RaceResponse extends \Error {
        public $result;
        public function __construct($result){$this->result = $result;}
    }
    function sysmsg($message, $title=null){throw new RaceResponse(['error'=>$message]);}
    function echojsonmsg($message, $code=-1){throw new RaceResponse(['error'=>$message]);}
    function showerrorjson($message){throw new RaceResponse(['error'=>$message]);}
    function checkRefererHost(){return true;}
    function daddslashes($value){return addslashes($value);}
    function getdomain($url){return parse_url($url, PHP_URL_HOST);}
    function checkPayVerifyOpen($uid){return false;}
    function invalidateListReadCache($kind, $uid=null){}
    function generate_trade_no(){static $sequence=0; return 'LP20261010120000'.sprintf('%05d', ++$sequence);}
    function randomFloat($min, $max){static $sequence=0; return (++$sequence % 2 ? 0.01 : 0.02);}
    class RaceStatement {
        private $count;
        public function __construct($count){$this->count=$count;}
        public function rowCount(){return $this->count;}
    }
    class RaceDb {
        public $row;
        public $beforeClaim;
        public $missOnce = false;
        public $insertMode = 'success';
        public $failClaim = false;
        public $insertCount = 0;
        public function getRow($sql, $params=null){
            if(strpos($sql, 'pre_user') !== false)return ['uid'=>1, 'gid'=>0, 'money'=>1000, 'mode'=>0, 'pay'=>1, 'status'=>1, 'cert'=>1, 'qq'=>'', 'channelinfo'=>null, 'ordername'=>''];
            if(strpos($sql, 'pre_order') === false)throw new \LogicException('Unexpected query');
            if($this->missOnce){$this->missOnce=false; return false;}
            return $this->row;
        }
        public function getColumn($sql, $params=null){return isset($params[':name']) ? ($params[':name'] === 'alipay' ? 1 : 2) : 'alipay';}
        public function find($table, $fields, $where, $sort=null, $limit=null){return false;}
        public function exec($sql, $params){
            $this->insertCount++;
            if($this->insertMode === 'false')return false;
            if($this->insertMode === 'exception'){
                $e = new \PDOException('Do not expose database details');
                $e->errorInfo = ['23000', 1062, 'duplicate'];
                throw $e;
            }
            if($this->insertMode === 'db-error')return false;
            $this->row = ['trade_no'=>$params[':trade_no'], 'out_trade_no'=>$params[':out_trade_no'], 'uid'=>$params[':uid'], 'addtime'=>date('Y-m-d H:i:s'),
                'name'=>$params[':name'], 'money'=>$params[':money'], 'notify_url'=>$params[':notify_url'], 'return_url'=>$params[':return_url'], 'param'=>$params[':param'],
                'channel'=>0, 'type'=>0, 'subchannel'=>0, 'realmoney'=>null, 'getmoney'=>null, 'status'=>0, 'tid'=>0];
            return true;
        }
        public function error(){return $this->insertMode === 'false' ? '[1062]duplicate' : '[1205]lock timeout';}
        public function query($sql, $params){
            if(strpos($sql, 'AND channel=0 AND status=0') === false)throw new \LogicException('Route update must be conditional');
            if($this->failClaim)return false;
            if($this->beforeClaim){
                $hook=$this->beforeClaim; $this->beforeClaim=null;
                // Only the database is shared by the simulated requests.
                $saved=[];
                foreach(['_GET','_POST','order','userrow','method','device','mdevice'] as $key)$saved[$key]=$GLOBALS[$key] ?? null;
                try{$hook();}finally{foreach($saved as $key=>$value)$GLOBALS[$key]=$value;}
            }
            if(!$this->row || $this->row['channel'] != 0 || $this->row['status'] != 0)return new RaceStatement(0);
            foreach(['type', 'channel', 'subchannel', 'realmoney', 'getmoney'] as $key)$this->row[$key]=$params[':'.$key];
            return new RaceStatement(1);
        }
    }
    $failures=[];
    function same($expected, $actual, $label){
        global $failures;
        if($expected !== $actual)$failures[]=$label.' expected='.var_export($expected,true).' actual='.var_export($actual,true);
    }
    $conf = array_fill_keys(['user_review','pay_maxmoney','pay_minmoney','cert_force','forceqq','pay_domain_forbid','pay_iplimit','payfee_lessthan','payfee_mincost'], 0);
    $conf += ['pay_payaddstart'=>1, 'pay_payaddmin'=>0.01, 'pay_payaddmax'=>0.02, 'ordername'=>''];
    $clientip='127.0.0.1';
    $siteurl='http://127.0.0.1/'; $date=date('Y-m-d H:i:s');
    $request=['pid'=>1, 'type'=>'alipay', 'out_trade_no'=>'RACE-TEST', 'money'=>'100.00', 'name'=>'Race test', 'notify_url'=>'https://merchant.invalid/notify', 'return_url'=>'https://merchant.invalid/return',
        'sitename'=>'test', 'clientip'=>'127.0.0.1', 'device'=>'pc', 'method'=>'web', 'sub_openid'=>'', 'sub_appid'=>'', 'auth_code'=>''];
    function invokePay($entry){
        global $request, $order, $DB, $conf, $userrow;
        $_GET=[]; $_POST=$request; $order=[];
        try{
            if($entry === 'cashier'){
                $_GET=['typeid'=>1, 'trade_no'=>$DB->row['trade_no']];
                $source = file_get_contents(dirname(__DIR__).'/submit2.php');
                $source = str_replace("require './includes/common.php';", '', $source);
                eval('?>'.$source); // Actual entry code; bootstrap deliberately removed.
            }else{
                \lib\api\Pay::$entry();
            }
        }catch(RaceResponse $response){return $response->result;}
        throw new \LogicException('Payment did not return a response');
    }
    foreach(['submit','create','cashier'] as $first){
        foreach(['submit','create','cashier'] as $second){
            $DB = new RaceDb();
            if($first === 'cashier'){
                $DB->row=['trade_no'=>'LP2026101012000000000', 'out_trade_no'=>$request['out_trade_no'], 'uid'=>1, 'addtime'=>date('Y-m-d H:i:s'), 'name'=>$request['name'], 'money'=>$request['money'],
                    'notify_url'=>$request['notify_url'], 'return_url'=>$request['return_url'], 'param'=>null, 'channel'=>0, 'type'=>0, 'subchannel'=>0, 'realmoney'=>null, 'getmoney'=>null, 'status'=>0, 'tid'=>0];
            }
            \lib\Channel::$selections=0;
            $winner=null;
            $DB->beforeClaim=function() use ($second, &$winner){$winner=invokePay($second);};
            $loser=invokePay($first);
            same($first === 'cashier' ? 0 : 1, $DB->insertCount, "$first/$second no duplicate order inserted");
            same(102, $DB->row['channel'], "$first/$second first committed route wins");
            same($winner['url'], $loser['url'], "$first/$second links use the same app");
            same($winner['order']['realmoney'], $loser['order']['realmoney'], "$first/$second random amount reused");
            same($DB->row['trade_no'], $loser['order']['trade_no'], "$first/$second order reused");
            $repeat=invokePay($first);
            same($winner['url'], $repeat['url'], "$first/$second repeat reuses route");
            same(2, \lib\Channel::$selections, "$first/$second loser never selects again");
        }
    }
    // The direct paypage writer can also meet a cashier request for its new order.
    $DB = new RaceDb();
    \lib\Channel::$selections=1; // Direct form uses 101; cashier selects 102.
    $winner=null;
    $DB->beforeClaim=function() use (&$winner){$winner=invokePay('cashier');};
    $_GET=[];
    $_POST=['uid'=>1, 'money'=>'100.00', 'payer'=>'', 'paytype'=>'alipay', 'direct'=>1, 'remark'=>'', 'token'=>'test'];
    $_SESSION=['paypage_token'=>'test', 'paypage_uid'=>1, 'paypage_typeid'=>1, 'paypage_channel'=>101, 'paypage_subchannel'=>0, 'paypage_rate'=>90, 'paypage_mode'=>0, 'paypage_paymin'=>0, 'paypage_paymax'=>0];
    $source=file_get_contents(dirname(__DIR__).'/paypage/ajax.php');
    $source=str_replace('include("./inc.php");', '', $source);
    $source=str_replace('exit(json_encode($result));', 'throw new RaceResponse($result);', $source);
    try{eval('?>'.$source);}catch(RaceResponse $response){$direct=$response->result;}
    same($winner['url'], $direct['url'], 'direct paypage adopts cashier app');
    same(102, $order['channel'], 'direct paypage uses stored receiving channel');
    same($DB->row['realmoney'], $order['realmoney'], 'direct paypage adopts stored amount');
    $stored=$DB->row;
    // Subsequent API cases use the original merchant request parameters.
    $stored['out_trade_no']=$request['out_trade_no']; $stored['name']=$request['name'];
    $stored['notify_url']=$request['notify_url']; $stored['return_url']=$request['return_url'];
    foreach(['submit','create'] as $entry){
        foreach(['false','exception'] as $mode){
            $DB=new RaceDb(); $DB->row=$stored; $DB->missOnce=true; $DB->insertMode=$mode;
            $count=\lib\Channel::$selections;
            $result=invokePay($entry);
            same($stored['trade_no'], $result['order']['trade_no'], "$entry duplicate $mode adopts existing order");
            same($count, \lib\Channel::$selections, "$entry duplicate $mode does not select again");
        }
        $DB=new RaceDb(); $DB->row=$stored; $DB->row['subchannel']=55; $DB->row['getmoney']=0;
        $result=invokePay($entry);
        same(55, $result['order']['subchannel'], "$entry existing subchannel retained");
        same($stored['realmoney'], $result['order']['realmoney'], "$entry zero net receipt still retains route");
        foreach(['paid','expired','changed-amount','changed-type','incomplete','db-error','claim-error'] as $scenario){
            $DB=new RaceDb(); $DB->row=$stored;
            $originalType=$request['type'];
            if($scenario==='paid')$DB->row['status']=1;
            if($scenario==='expired')$DB->row['addtime']=date('Y-m-d H:i:s', time()-864001);
            if($scenario==='changed-amount')$DB->row['money']='101.00';
            if($scenario==='changed-type')$request['type']='wxpay';
            if($scenario==='incomplete')$DB->row['realmoney']=null;
            if($scenario==='db-error'){$DB->row=null; $DB->insertMode='db-error';}
            if($scenario==='claim-error'){$DB->row['channel']=0; $DB->failClaim=true;}
            $result=invokePay($entry);
            same(true, isset($result['error']), "$entry $scenario rejects without link");
            $request['type']=$originalType;
        }
    }
    if(in_array('sqlite', \PDO::getAvailableDrivers(), true)){
        // Exercise real PDO affected-row semantics without a connection/configuration file.
        require dirname(__DIR__).'/includes/lib/PdoHelper.php';
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE pay_order (trade_no TEXT PRIMARY KEY, status INTEGER, type INTEGER, channel INTEGER, subchannel INTEGER, realmoney NUMERIC, getmoney NUMERIC)');
        $pdo->exec("INSERT INTO pay_order VALUES ('LP-PDO-TEST', 0, 0, 0, 0, NULL, NULL)");
        $reflection = new \ReflectionClass(\lib\PdoHelper::class);
        $DB = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('db')->setValue($DB, $pdo);
        $reflection->getProperty('prefix')->setValue($DB, 'pay_');
        $route = ['typeid'=>1, 'channel'=>101, 'subchannel'=>7, 'typename'=>'alipay'];
        $real=100; $net=90;
        same(true, \lib\PaymentRoute::claim('LP-PDO-TEST', $route, $real, $net), 'PDO first update affects one row');
        $route = ['typeid'=>1, 'channel'=>102, 'subchannel'=>8, 'typename'=>'alipay'];
        $real=101; $net=80;
        same(false, \lib\PaymentRoute::claim('LP-PDO-TEST', $route, $real, $net), 'PDO second update affects zero rows');
        same(101, $route['channel'], 'PDO loser adopts winner channel');
        same(7, $route['subchannel'], 'PDO loser adopts winner subchannel');
        same(100, $real, 'PDO loser adopts winner amount');
    }
    if($failures){fwrite(STDERR, implode(PHP_EOL,$failures).PHP_EOL); exit(1);}
    echo "payment channel race regression: ok (submit/mapi/cashier/direct paypage and duplicate inserts)\n";
}
