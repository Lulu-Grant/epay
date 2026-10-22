<?php
namespace lib\Defense;

class AdminController
{
    public static function handle($action, array $input, array $session, $isAdmin, $actor, array $conf)
    {
        if(!$isAdmin)throw new DefenseException('DEFENSE_VERIFY_FAILED','需要管理员登录');
        if(!Runtime::installed())throw new DefenseException('DEFENSE_TEMP_UNAVAILABLE','商户防御数据库尚未安装');
        $uid=filter_var($input['uid']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($uid===false)throw new DefenseException('DEFENSE_VERIFY_FAILED');
        $store=Runtime::store();
        if($action==='getMerchantDefense'){
            $merchant=$store->merchant($uid);
            $policy=new PolicyService($store,$conf);
            return ['code'=>0,'merchant'=>$merchant,'blockers'=>$policy->blockers($merchant),'readiness'=>$policy->readiness($merchant),
                'bans'=>$store->rows('SELECT event_key,complaint_id,trade_no,ip,observed_at,expires_at,released_at,release_reason FROM pre_merchant_defense_complaint WHERE uid=:uid ORDER BY observed_at DESC LIMIT 100',[':uid'=>$uid]),
                'audit'=>$store->rows('SELECT action,reason,actor,created_at FROM pre_merchant_defense_audit WHERE uid=:uid ORDER BY id DESC LIMIT 100',[':uid'=>$uid])];
        }
        $csrf=$input['csrf_token']??null;$expected=$session['merchant_defense_csrf']??null;
        if(!is_string($csrf)||!is_string($expected)||strlen($expected)!==64||!hash_equals($expected,$csrf))throw new DefenseException('DEFENSE_VERIFY_FAILED','请求防伪校验失败，请刷新页面');
        if($action==='releaseMerchantDefense'){
            (new ComplaintService($store))->release($uid,$input['event_key']??null,$actor,$input['reason']??'');
            return ['code'=>0,'msg'=>'已解除该投诉事件的封禁；其他有效封禁仍按各自期限执行'];
        }
        if($action!=='setMerchantDefense' || !in_array($input['target']??null,['0','1',0,1],true))throw new DefenseException('DEFENSE_VERIFY_FAILED');
        $revision=filter_var($input['revision']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if($revision===false)throw new DefenseException('DEFENSE_VERIFY_FAILED');
        $merchant=(new PolicyService($store,$conf))->set($uid,(int)$input['target'],$revision,$actor);
        return ['code'=>0,'msg'=>'商户防御状态已保存','merchant'=>$merchant];
    }
}
