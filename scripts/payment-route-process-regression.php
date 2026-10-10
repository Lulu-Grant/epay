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
        if(is_file($directory.'/mysql-database')){
            assertMysqlFixture();
            $database=trim(file_get_contents($directory.'/mysql-database'));
            if(!preg_match('/^epay_route_fixture_[a-f0-9]{16}$/D', $database))throw new \RuntimeException('Unsafe fixture database');
            $pdo=new \PDO('mysql:host=127.0.0.1;port=3306;dbname='.$database.';charset=utf8mb3', 'root', 'epay_test');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
            $pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
            return $pdo;
        }
        $pdo=new \PDO('sqlite:'.$directory.'/orders.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->sqliteCreateFunction('NOW', function(){return date('Y-m-d H:i:s');});
        return $pdo;
    }
    function assertMysqlFixture(){
        // This mode can only address the disposable GitHub Actions service.
        if(getenv('GITHUB_ACTIONS') !== 'true' || getenv('CI') !== 'true'
            || getenv('EPAY_DB_HOST') !== '127.0.0.1' || getenv('EPAY_DB_PORT') !== '3306'
            || getenv('EPAY_DB_USER') !== 'root' || getenv('EPAY_DB_PASSWORD') !== 'epay_test'){
            throw new \RuntimeException('MySQL mode is restricted to the isolated CI fixture');
        }
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
    $mysql=in_array('--mysql-fixture', array_slice($argv,1), true);
    if(count(array_diff(array_slice($argv,1), ['--load-sqlite-extension','--mysql-fixture'])) > 0 || ($mysql && $loadExtension)){fwrite(STDERR, "Unsupported argument\n"); exit(1);}
    if(!in_array($mysql ? 'mysql' : 'sqlite', \PDO::getAvailableDrivers(), true)){fwrite(STDERR, "Required fixture PDO driver unavailable\n"); exit(1);}
    $admin=null; $database=null;
    if($mysql){
        assertMysqlFixture();
        $admin=new \PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb3', 'root', 'epay_test', [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $database='epay_route_fixture_'.bin2hex(random_bytes(8));
        $admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci');
    }
    $rounds=25;
    try{
    for($round=0; $round<$rounds; $round++){
        $directory=sys_get_temp_dir().'/epay-route-process-'.bin2hex(random_bytes(8));
        if(!mkdir($directory, 0700))throw new \RuntimeException('Cannot create fixture directory');
        $workers=[];
        try{
            if($mysql)file_put_contents($directory.'/mysql-database', $database);
            $pdo=fixturePdo($directory);
            if($mysql){
                $pdo->exec('DROP TABLE IF EXISTS pay_order');
                if($pdo->exec('CREATE TABLE pay_order (trade_no VARCHAR(32) PRIMARY KEY, uid INT UNSIGNED NOT NULL, out_trade_no VARCHAR(150) NOT NULL, addtime DATETIME, name TEXT, money DECIMAL(10,2), notify_url TEXT, return_url TEXT, param TEXT, domain TEXT, ip TEXT, status TINYINT DEFAULT 0, version INTEGER, type INTEGER DEFAULT 0, channel INTEGER DEFAULT 0, subchannel INTEGER DEFAULT 0, realmoney DECIMAL(10,2), getmoney DECIMAL(10,2)) ENGINE=InnoDB') === false)throw new \RuntimeException('Fixture table creation failed');
                // Rehearse the production-length/collation index on synthetic rows only.
                $pdo->exec("INSERT INTO pay_order (trade_no,uid,out_trade_no) VALUES ('fixture-a',1,'duplicate'),('fixture-b',1,'duplicate')");
                if($pdo->exec('ALTER TABLE pay_order ADD UNIQUE KEY uniq_uid_outtradeno (uid,out_trade_no), ALGORITHM=INPLACE, LOCK=NONE') !== false || (int)$pdo->errorInfo()[1] !== 1062)throw new \RuntimeException('Duplicate migration must fail with real 1062');
                $pdo->exec('DELETE FROM pay_order');
                if($pdo->exec('ALTER TABLE pay_order ADD UNIQUE KEY uniq_uid_outtradeno (uid,out_trade_no), ALGORITHM=INPLACE, LOCK=NONE') === false)throw new \RuntimeException('Clean fixture index creation failed');
                $matches=$pdo->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID('{\"appid\":\"2021000000000001\"}'),'{\"appid\":\"2021000000000001\"}','{}'),'$.appid'))")->fetchColumn();
                if($matches !== '2021000000000001')throw new \RuntimeException('Fixture app JSON extraction failed');
            }elseif($pdo->exec('CREATE TABLE pay_order (trade_no TEXT PRIMARY KEY, uid INTEGER, out_trade_no TEXT, addtime TEXT, name TEXT, money TEXT, notify_url TEXT, return_url TEXT, param TEXT, domain TEXT, ip TEXT, status INTEGER DEFAULT 0, version INTEGER, type INTEGER DEFAULT 0, channel INTEGER DEFAULT 0, subchannel INTEGER DEFAULT 0, realmoney TEXT, getmoney TEXT, UNIQUE(uid,out_trade_no))') === false){
                throw new \RuntimeException('SQLite fixture creation failed');
            }
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
            foreach(['orders.sqlite','orders.sqlite-journal','mysql-database','read-101','read-102','insert-start','route-101','route-102','route-start'] as $file){
                if(is_file($directory.'/'.$file))unlink($directory.'/'.$file);
            }
            rmdir($directory);
        }
    }
    }finally{
        if($admin && $database)$admin->exec('DROP DATABASE `'.$database.'`');
    }
    $driver=$mysql ? 'isolated MariaDB CI fixture; native 1062, JSON and online index' : 'SQLite fixture, not MySQL';
    echo "payment route process regression: ok ($rounds rounds, 2 independent processes; $driver)\n";
}
