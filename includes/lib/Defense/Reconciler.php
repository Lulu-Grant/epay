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
    public function run($limit = 100)
    {
        $limit=max(1,min(200,(int)$limit));
        $stats=['checked'=>0,'paid'=>0,'closed'=>0,'complaints'=>0,'failed'=>0];
        $lock=$this->store->row("SELECT GET_LOCK(CONCAT(DATABASE(),'.merchant_defense_worker'),0) acquired");
        if((int)$lock['acquired']!==1)return $stats;
        try{
            $orders=$this->store->rows("SELECT o.*,d.state defense_state,d.created_at defense_created_at,d.attempted_at defense_attempted_at,d.account_scope defense_account_scope FROM pre_merchant_defense_order d JOIN pre_order o ON o.trade_no=d.trade_no WHERE d.state IN ('reserved','ready','unknown','unmanaged') OR (d.state='paid' AND o.status IN (0,4)) ORDER BY d.checked_at ASC,d.created_at ASC LIMIT ".$limit);
            foreach($orders as $order){
                $stats['checked']++;
                try{
                    $quota=new QuotaService($this->store);
                    if($order['defense_state']==='reserved' && $order['defense_attempted_at']===null && $this->store->now()-(int)$order['defense_created_at']>=900){
                        if($quota->releaseUnattempted($order['trade_no']))$stats['closed']++;
                        continue;
                    }
                    if(in_array((int)$order['status'],[1,2,3],true) && !empty($order['endtime'])){
                        $quota->paid($order['trade_no'],strtotime($order['endtime']));continue;
                    }
                    $adapter=call_user_func($this->adapterFactory,$order);
                    $result=$adapter->query($order);
                    if($result['trade_status']==='WAIT_BUYER_PAY' && $this->store->now()-(int)$order['defense_created_at']>=900)$result=$adapter->close($order);
                    if(in_array($result['trade_status'],['TRADE_SUCCESS','TRADE_FINISHED'],true)){
                        call_user_func($this->confirm,$order,$result['trade_no']);
                        $quota->paid($order['trade_no']);$stats['paid']++;
                    }elseif($result['trade_status']==='TRADE_CLOSED'){
                        if(($result['_defense_closed_unpaid']??false)===true){
                            if($quota->closed($order['trade_no']))$stats['closed']++;
                        }else{
                            // CLOSED can also mean fully refunded. Do not erase a possible successful payment.
                            $this->store->noteOnce($order['uid'],$order['trade_no'],'closure_review','closed_without_unpaid_close_ack');
                            $stats['failed']++;
                        }
                    }
                }catch(\Throwable $e){
                    $stats['failed']++;
                    try{$this->store->noteOnce($order['uid'],$order['trade_no'],'reconcile_failure',$e instanceof DefenseException?$e->reason:'payment_reconciliation_error');}catch(\Throwable $ignored){}
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
