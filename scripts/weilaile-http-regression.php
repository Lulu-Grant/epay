#!/usr/bin/env php
<?php
// Local loopback fixture: exercise cURL POST, encoding and error handling without the live gateway.
if(PHP_SAPI !== 'cli'){http_response_code(404);exit;}
if(PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80500 || !extension_loaded('curl')){
    fwrite(STDERR, "PHP 8.4.x with cURL required\n");exit(1);
}
require dirname(__DIR__).'/plugins/weilaile/inc/WeilaileClient.php';

class WeilaileHttpFixture extends WeilaileClient {
    public $target;
    protected function endpoint(){return $this->target;}
}
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if(!$listener)throw new RuntimeException('Local fixture socket unavailable');
$address = stream_socket_get_name($listener, false);fclose($listener);
$directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'epay-weilaile-http-'.bin2hex(random_bytes(8));
if(!mkdir($directory, 0700))throw new RuntimeException('Fixture directory unavailable');
$router = $directory.DIRECTORY_SEPARATOR.'router.php';
$log = $directory.DIRECTORY_SEPARATOR.'server.log';
$source = <<<'PHP'
<?php
$mode = $_GET['fixture'] ?? 'ok';
if($mode === 'health'){echo 'ready';return;}
if($mode === 'http'){http_response_code(503);echo 'private fixture detail';return;}
if($mode === 'html'){echo '<html>unexpected gateway page</html>';return;}
if($mode === 'null'){echo 'null';return;}
if($mode === 'large'){echo str_repeat('x',1048577);return;}
$valid = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/x-www-form-urlencoded')
    && ($_POST['name'] ?? '') === '本地测试 & A+B'
    && ($_POST['money'] ?? '') === '12.30'
    && ($_POST['clientip'] ?? '') === '203.0.113.10'
    && ($_POST['pid'] ?? '') === '1001'
    && ($_POST['type'] ?? '') === 'alipay'
    && ($_POST['sign_type'] ?? '') === 'MD5';
$fields = $_POST;ksort($fields,SORT_STRING);$parts=[];
foreach($fields as $k=>$v){if($k==='sign'||$k==='sign_type'||$v==='')continue;$parts[]=$k.'='.$v;}
$valid = $valid && hash_equals(md5(implode('&',$parts).'local-http-fixture-key'),$_POST['sign']??'');
header('Content-Type: application/json');
echo json_encode($valid?['code'=>1,'trade_no'=>'FIXTURE001','payurl'=>'https://pay.example.test/fixture']:['code'=>-1]);
PHP;
file_put_contents($router, $source);
$process = null;
$checks = 0;
try{
    $process = proc_open([PHP_BINARY,'-S',$address,$router], [0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']], $pipes);
    if(!is_resource($process))throw new RuntimeException('Fixture server could not start');
    fclose($pipes[0]);
    $ready = false;
    for($i=0;$i<40;$i++){
        $socket=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);
        if($socket){fclose($socket);$ready=true;break;}
        usleep(50000);
    }
    if(!$ready)throw new RuntimeException('Fixture server startup failed');
    $client = new WeilaileHttpFixture('1001','local-http-fixture-key');
    $params = ['type'=>'alipay','out_trade_no'=>'LP2026101209000012345','notify_url'=>'https://merchant.example.test/notify','return_url'=>'https://merchant.example.test/return','name'=>'本地测试 & A+B','money'=>'12.3','clientip'=>'203.0.113.10'];
    $client->target='http://'.$address.'/mapi.php?fixture=ok';
    $r=$client->pay($params);
    if($r['type']!=='jump'||$r['trade_no']!=='FIXTURE001')throw new RuntimeException('HTTP POST or signature fixture failed');
    $checks++;
    foreach(['http','html','null','large'] as $mode){
        $client->target='http://'.$address.'/mapi.php?fixture='.$mode;
        $rejected=false;
        try{$client->pay($params);}catch(WeilaileException $e){
            if(str_contains($e->getMessage(),'private fixture detail'))throw new RuntimeException('Gateway detail leaked');
            $rejected=true;
        }
        if(!$rejected)throw new RuntimeException('HTTP fixture did not reject '.$mode);
        $checks++;
    }
    // A non-TLS local fixture cannot serve a valid TLS handshake; verification stays enabled.
    $client->target='https://'.$address.'/mapi.php';$rejected=false;
    try{$client->pay($params);}catch(WeilaileException $e){$rejected=true;}
    if(!$rejected)throw new RuntimeException('Invalid TLS endpoint was accepted');$checks++;
    echo "Weilaile HTTP regression: $checks cases passed; local loopback only.\n";
}finally{
    if(is_resource($process)){proc_terminate($process);proc_close($process);}
    foreach([$router,$log] as $file)if(is_file($file))unlink($file);
    rmdir($directory);
}
