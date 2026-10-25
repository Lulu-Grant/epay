<?php
namespace lib\Defense;

class Reconciler
{
    private $store;
    private $adapterFactory;
    private $confirm;
    public function __construct(Repository $store, callable $adapterFactory, callable $confirm)
    {
        $this->store=$store;$this->adapterFactory=$adapterFactory;$this->confirm=$confirm;
    }
    private static function failureReason(\Throwable $error,$stage)
    {
        if($error instanceof DefenseException)return $error->reason;
        if($error instanceof \Alipay\Aop\AlipayResponseException && in_array($stage,['query','close'],true)){
            // Only protocol error codes are retained. Never persist raw SDK messages or responses.
            $code=(string)$error->getRetCode();$sub=(string)$error->getErrCode();
            return 'alipay_'.$stage.'_failed|'.(preg_match('/^[0-9]{1,10}$/D',$code)?$code:'UNKNOWN').'|'.(preg_match('/^[A-Z0-9_.-]{1,80}$/Di',$sub)?$sub:'UNKNOWN');
        }
        return 'reconcile_'.$stage.'_error';
    }
    public function run($limit = 100)
    {
        $limit=max(1,min(200,(int)$limit));
        $stats=['checked'=>0,'paid'=>0,'closed'=>0,'complaints'=>0,'failed'=>0,'held'=>0,'waiting'=>0,'review'=>0];
        $lock=$this->store->row("SELECT GET_LOCK(CONCAT(DATABASE(),'.merchant_defense_worker'),0) acquired");
        if((int)$lock['acquired']!==1)return $stats;
        try{
            $now=$this->store->now();
            // Age-based polling needs no schema change. Trusted local confirmations bypass the
            // delay; interrupted financial completion retries every minute regardless of order age.
            $orders=$this->store->rows("SELECT o.*,d.state defense_state,d.created_at defense_created_at,d.attempted_at defense_attempted_at,d.account_scope defense_account_scope FROM pre_merchant_defense_order d JOIN pre_order o ON o.trade_no=d.trade_no
                WHERE (d.state IN ('reserved','ready','unknown','unmanaged') OR (d.state='paid' AND o.status IN (0,4)))
                AND ((o.status IN (1,2,3) AND o.endtime IS NOT NULL) OR (
                    NOT (d.state='reserved' AND d.attempted_at IS NULL AND d.created_at>:unattempted)
                    AND (d.checked_at IS NULL OR d.checked_at<=:now-CASE WHEN d.state='paid' THEN 60 WHEN d.created_at>:fresh THEN 60 WHEN d.created_at>:hour THEN 300 WHEN d.created_at>:day THEN 1800 ELSE 21600 END)))
                ORDER BY CASE WHEN o.status IN (1,2,3) THEN 0 WHEN d.state='paid' THEN 1 ELSE 2 END,d.checked_at ASC,d.created_at ASC LIMIT ".$limit,
                [':unattempted'=>$now-900,':now'=>$now,':fresh'=>$now-900,':hour'=>$now-3600,':day'=>$now-86400]);
            foreach($orders as $order){
                $stats['checked']++;
                $stage='accounting';
                try{
                    $quota=new QuotaService($this->store);
                    if(in_array((int)$order['status'],[1,2,3],true) && !empty($order['endtime'])){
                        if($quota->paid($order['trade_no'],strtotime($order['endtime'])))$stats['paid']++;
                        continue;
                    }
                    if($order['defense_state']==='reserved' && $order['defense_attempted_at']===null && $this->store->now()-(int)$order['defense_created_at']>=900){
                        if($quota->releaseUnattempted($order['trade_no']))$stats['closed']++;
                        continue;
                    }
                    $stage='adapter';
                    $adapter=call_user_func($this->adapterFactory,$order);
                    if(method_exists($adapter,'localHold')&&$adapter->localHold()){
                        $this->store->noteOnce($order['uid'],$order['trade_no'],'local_hold','issued_link_without_close_keep_capacity');
                        $stats['held']++;continue;
                    }
                    $stage='query';$result=$adapter->query($order);
                    if($result['trade_status']==='WAIT_BUYER_PAY' && $this->store->now()-(int)$order['defense_created_at']>=900){$stage='close';$result=$adapter->close($order);}
                    if(in_array($result['trade_status'],['TRADE_SUCCESS','TRADE_FINISHED'],true)){
                        $stage='confirm';
                        call_user_func($this->confirm,$order,$result['trade_no']);
                        $quota->paid($order['trade_no']);$stats['paid']++;
                    }elseif($result['trade_status']==='TRADE_CLOSED'){
                        if(($result['_defense_closed_unpaid']??false)===true){
                            if($quota->closed($order['trade_no']))$stats['closed']++;
                        }else{
                            // CLOSED can also mean fully refunded. Do not erase a possible successful payment.
                            $this->store->noteOnce($order['uid'],$order['trade_no'],'closure_review','closed_without_unpaid_close_ack');
                            $stats['review']++;
                        }
                    }elseif(in_array($result['trade_status'],['NOT_CREATED','WAIT_BUYER_PAY'],true)){
                        if($order['defense_state']==='paid')throw new DefenseException('DEFENSE_QUERY_MISMATCH');
                        $this->store->noteOnce($order['uid'],$order['trade_no'],'reconcile_waiting',$result['trade_status']==='NOT_CREATED'?'upstream_trade_not_created':'awaiting_buyer_payment');
                        $stats['waiting']++;
                    }else{
                        throw new DefenseException('DEFENSE_QUERY_MISMATCH');
                    }
                }catch(\Throwable $e){
                    $stats['failed']++;
                    try{$this->store->noteOnce($order['uid'],$order['trade_no'],'reconcile_failure',self::failureReason($e,$stage));}catch(\Throwable $ignored){}
                }
                finally{$this->store->query('UPDATE pre_merchant_defense_order SET checked_at=:now WHERE trade_no=:trade', [':now'=>$this->store->now(),':trade'=>$order['trade_no']]);}
            }
            $cursor=$this->store->row("SELECT v FROM pre_config WHERE k='merchant_defense_cursor'");
            if(!$cursor || !ctype_digit((string)$cursor['v']))throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE');
            // Optional complaint module may be installed after the core application.
            $module=$this->store->row("SHOW TABLES LIKE 'pre_complain'");
            if($module){
                $rows=$this->store->rows('SELECT * FROM pre_complain WHERE id>:cursor ORDER BY id ASC LIMIT '.$limit, [':cursor'=>(int)$cursor['v']]);
                foreach($rows as $row){
                    try{if(Runtime::complaint($row,$this->store))$stats['complaints']++;}
                    catch(\Throwable $e){$stats['failed']++;break;}
                    $this->store->query("UPDATE pre_config SET v=:cursor WHERE k='merchant_defense_cursor'", [':cursor'=>(string)$row['id']]);
                }
            }
            $cutoff=$this->store->now()-86400;
            $this->store->query('DELETE FROM pre_merchant_defense_challenge WHERE expires_at<:cutoff LIMIT 500', [':cutoff'=>$cutoff]);
            $this->store->query('DELETE FROM pre_merchant_defense_entry WHERE expires_at<:cutoff LIMIT 500', [':cutoff'=>$cutoff]);
            $this->store->query('DELETE FROM pre_merchant_defense_audit WHERE created_at<:cutoff LIMIT 500', [':cutoff'=>$this->store->now()-90*86400]);
            return $stats;
        }finally{$this->store->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(),'.merchant_defense_worker'))");}
    }
}
