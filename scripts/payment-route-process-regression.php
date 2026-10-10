#!/usr/bin/env php
<?php
namespace lib {
    class Channel {
        public static function info($id){return ['typeid'=>1, 'channel'=>(int)$id, 'plugin'=>'alipay', 'rate'=>90, 'mode'=>0];}
    }
}
namespace {
    if(PHP_SAPI !== 'cli'){http_response_code(404); exit;}
    require dirname(__DIR__).'/includes/functions.php';
    require dirname(__DIR__).'/includes/lib/PdoHelper.php';
    require dirname(__DIR__).'/includes/lib/PaymentRoute.php';
    require dirname(__DIR__).'/includes/lib/api/Pay.php';

    function waitFixture($file){
        $deadline=microtime(true)+10;
        while(!is_file($file)){
            if(microtime(true)>$deadline)throw new \RuntimeException('Fixture barrier timeout');
            usleep(10000);
        }
    }
    class ProcessFixtureDb extends \lib\PdoHelper {
        public $fixture;
        public $id;
        private $firstRead=true;
        public function getRow($sql, $_array=null){
            $row=parent::getRow($sql, $_array);
            if($this->firstRead && strpos($sql, 'uid=:uid AND out_trade_no=:out_trade_no') !== false){
                $this->firstRead=false;
                if($row)throw new \RuntimeException('Initial fixture order unexpectedly exists');
                touch($this->fixture.'/read-'.$this->id);
                waitFixture($this->fixture.'/insert-start');
            }
            return $row;
        }
        public function error(){
            $error=(string)parent::error();
            // SQLite's unique violation is translated only in this test adapter.
            return strpos($error, '[19]UNIQUE constraint failed:') === 0 ? '[1062]fixture duplicate' : $error;
        }
    }
    function fixturePdo($directory){
        $pdo=new \PDO('sqlite:'.$directory.'/orders.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->sqliteCreateFunction('NOW', function(){return date('Y-m-d H:i:s');});
        return $pdo;
    }
    if(($argv[1] ?? '') === '--worker'){
        $directory=$argv[2] ?? '';
        $id=(int)($argv[3] ?? 0);
        if(!is_dir($directory) || !in_array($id, [101,102], true))exit(2);
        $pdo=fixturePdo($directory);
        $reflect=new \ReflectionClass(ProcessFixtureDb::class);
        $DB=$reflect->newInstanceWithoutConstructor();
        $parent=new \ReflectionClass(\lib\PdoHelper::class);
        $parent->getProperty('db')->setValue($DB, $pdo);
        $parent->getProperty('prefix')->setValue($DB, 'pay_');
        $DB->fixture=$directory; $DB->id=$id;
        $params=[':uid'=>1, ':out_trade_no'=>'CONCURRENT-TEST', ':name'=>'Synthetic fixture', ':money'=>'100.00',
            ':notify_url'=>'https://fixture.invalid/notify', ':return_url'=>'https://fixture.invalid/return', ':param'=>null,
            ':domain'=>'fixture.invalid', ':clientip'=>'127.0.0.1', ':version'=>0];
        $method=new \ReflectionMethod(\lib\api\Pay::class, 'loadOrCreateOrder');
        $stored=$method->invoke(null, $params);
        touch($directory.'/route-'.$id);
        waitFixture($directory.'/route-start');
        $route=['typeid'=>1, 'channel'=>$id, 'subchannel'=>0, 'typename'=>'alipay'];
        $real=$id === 101 ? 100.01 : 100.02;
        $net=90;
        $winner=\lib\PaymentRoute::claim($stored['trade_no'], $route, $real, $net);
        echo json_encode(['trade_no'=>$stored['trade_no'], 'channel'=>$route['channel'], 'realmoney'=>$real, 'winner'=>$winner]);
        exit;
    }
    $loadExtension=in_array('--load-sqlite-extension', array_slice($argv,1), true);
    if(count(array_diff(array_slice($argv,1), ['--load-sqlite-extension'])) > 0){fwrite(STDERR, "Unsupported argument\n"); exit(1);}
    if(!in_array('sqlite', \PDO::getAvailableDrivers(), true)){fwrite(STDERR, "This local fixture requires pdo_sqlite\n"); exit(1);}
    $rounds=25;
    for($round=0; $round<$rounds; $round++){
        $directory=sys_get_temp_dir().'/epay-route-process-'.bin2hex(random_bytes(8));
        if(!mkdir($directory, 0700))throw new \RuntimeException('Cannot create fixture directory');
        $workers=[];
        try{
            $pdo=fixturePdo($directory);
            $pdo->exec('CREATE TABLE pay_order (trade_no TEXT PRIMARY KEY, uid INTEGER, out_trade_no TEXT, addtime TEXT, name TEXT, money TEXT, notify_url TEXT, return_url TEXT, param TEXT, domain TEXT, ip TEXT, status INTEGER DEFAULT 0, version INTEGER, type INTEGER DEFAULT 0, channel INTEGER DEFAULT 0, subchannel INTEGER DEFAULT 0, realmoney TEXT, getmoney TEXT, UNIQUE(uid,out_trade_no))');
            foreach([101,102] as $id){
                $command=[PHP_BINARY];
                if($loadExtension)array_push($command, '-d', 'extension_dir='.ini_get('extension_dir'), '-d', 'extension=pdo_sqlite');
                array_push($command, __FILE__, '--worker', $directory, (string)$id);
                $pipes=[];
                $process=proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
                if(!is_resource($process))throw new \RuntimeException('Cannot start fixture worker');
                fclose($pipes[0]);
                $workers[]=[$process,$pipes];
            }
            waitFixture($directory.'/read-101'); waitFixture($directory.'/read-102');
            touch($directory.'/insert-start');
            waitFixture($directory.'/route-101'); waitFixture($directory.'/route-102');
            touch($directory.'/route-start');
            $results=[];
            foreach($workers as [$process,$pipes]){
                $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                if(proc_close($process) !== 0 || $error !== '')throw new \RuntimeException('Fixture worker failed: '.$error);
                $result=json_decode($output, true);
                if(!is_array($result))throw new \RuntimeException('Invalid worker response');
                $results[]=$result;
            }
            $workers=[];
            $rows=$pdo->query('SELECT * FROM pay_order')->fetchAll(\PDO::FETCH_ASSOC);
            if(count($rows) !== 1 || $results[0]['trade_no'] !== $results[1]['trade_no']
                || $results[0]['channel'] !== $results[1]['channel'] || $results[0]['channel'] != $rows[0]['channel']
                || (float)$results[0]['realmoney'] !== (float)$results[1]['realmoney']
                || (float)$results[0]['realmoney'] !== (float)$rows[0]['realmoney']
                || (int)$results[0]['winner'] + (int)$results[1]['winner'] !== 1){
                throw new \RuntimeException('Concurrent route invariant failed');
            }
            $pdo=null;
        }finally{
            foreach($workers as [$process,$pipes]){
                if(is_resource($process))proc_terminate($process);
                foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
                if(is_resource($process))proc_close($process);
            }
            $pdo=null;
            // Only known files inside this invocation's own temporary directory.
            foreach(['orders.sqlite','orders.sqlite-journal','read-101','read-102','insert-start','route-101','route-102','route-start'] as $file){
                if(is_file($directory.'/'.$file))unlink($directory.'/'.$file);
            }
            rmdir($directory);
        }
    }
    echo "payment route process regression: ok ($rounds rounds, 2 independent processes; SQLite fixture, not MySQL)\n";
}
