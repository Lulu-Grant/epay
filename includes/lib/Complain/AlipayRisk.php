<?php
namespace lib\Complain;

use Exception;

class AlipayRisk implements IComplain
{

    static $paytype = 'alipayrisk';

    private $channel;
    private $service;

    function __construct($channel){
		$this->channel = $channel;
        $alipay_config = require(PLUGIN_ROOT.$channel['plugin'].'/inc/config.php');
        $this->service = new \Alipay\AlipayComplainService($alipay_config);
	}

    //刷新最新投诉记录列表
    public function refreshNewList($num){
        $page_num = 1;
        $page_size = $num > 20 ? 20 : $num;
        $page_count = ceil($num / $page_size);

        $report = new SyncReport($this->channel);
        $count_fetched = 0;
        for($page_num = 1; $page_num <= $page_count; $page_num++){
            $started = microtime(true);
            try{
                $result = $this->service->riskbatchQuery(null, null, null, $page_num, $page_size);
            } catch (\Throwable $e) {
                return $report->failure('QUERY_FAILED', 'query', $e, $started);
            }
            if(!is_array($result) || !isset($result['total_size']) ||
                !is_scalar($result['total_size']) || !ctype_digit((string)$result['total_size']))
                return $report->failure('INVALID_RESPONSE', 'query_shape', null, $started);
            // A successful zero-total response may omit the optional list entirely.
            if((int)$result['total_size'] === 0 && !array_key_exists('complaint_list', $result)) break;
            if(!isset($result['complaint_list']) || !is_array($result['complaint_list']))
                return $report->failure('INVALID_RESPONSE', 'query_shape', null, $started);
            if(count($result['complaint_list']) === 0){
                if(($page_num - 1) * $page_size < (int)$result['total_size'])
                    return $report->failure('INVALID_RESPONSE', 'missing_page', null, $started);
                break;
            }
            if((int)$result['total_size'] === 0)
                return $report->failure('INVALID_RESPONSE', 'query_shape', null, $started);

            foreach($result['complaint_list'] as $info){
                if($count_fetched >= $num) break;
                $count_fetched = $report->fetched();
                $started = microtime(true);
                if(!self::validInfo($info)) return $report->failure('INVALID_RESPONSE', 'item_shape', null, $started);
                try { $retcode = $this->updateInfo($info, $report); }
                catch (SyncActionException $e) {
                    $report->saved($e->persistedCode());
                    return $report->failure('ACTION_FAILED', 'auto_handle', $e->getPrevious() ?? $e, $started);
                }
                catch (\Throwable $e) {
                    return $report->failure('SAVE_FAILED', 'save', $e, $started);
                }
                $report->saved($retcode);

                if(isset($_GET['key']) && self::getStatus($info['status']) < 2){ //监控模式
                    global $DB;
                    $msgtype = null;
                    if($retcode == 2){
                        $msgtype = '用户提交了新的反馈，请尽快处理';
                    }elseif($retcode == 1){
                        $msgtype = '您有新的支付交易投诉，请尽快处理';
                    }
                    if($msgtype){
                        $started = microtime(true);
                        try { CommUtil::sendMsg($msgtype, $info['id']); }
                        catch (\Throwable $e) { $report->warning('notify', $e, 0, $started); }
                    }
                }
            }
            if($count_fetched >= $num || $page_num * $page_size >= (int)$result['total_size']) break;
        }
        return $report->success();
    }

    //回调刷新单条投诉记录
    public function refreshNewInfo($thirdid, $type = null){
        return;
    }

    //获取单条投诉记录
    public function getNewInfo($id){
        global $DB;
        $data = $DB->find('complain', '*', ['id'=>$id]);
        try{
            $info = $this->service->riskquery($data['thirdid']);
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
        
        $status = self::getStatus($info['status']);
        if($status != $data['status']){
            $data['edittime'] = $info['gmt_process'];
            $DB->update('complain', ['status'=>$status, 'edittime'=>$data['edittime']], ['id'=>$data['id']]);
            CommUtil::autoHandle($data['trade_no'], $status);
            $data['status'] = $status;
        }

        $data['money'] = $info['complain_amount'];
        $data['complain_url'] = $info['complain_url'] ?? '无';
        $data['images'] = [];
        $data['status_text'] = $info['status_description']; //投诉单明细状态
        $data['reply_detail_infos'] = []; //协商记录

        //商家处理进展
        $data['process_code'] = $info['process_code'];
        $data['process_message'] = $info['process_message'];
        $data['process_remark'] = $info['process_remark'];
        $data['process_img_url_list'] = $info['process_img_url_list'] ?? [];

        return ['code'=>0, 'showtype'=>self::$paytype, 'data'=>$data];
    }

    private function updateInfo($info, ?SyncReport $report = null){
        global $DB, $conf;
        $report ??= new SyncReport($this->channel);
        $thirdid = $info['id'];
        $trade_no = $info['complaint_trade_info_list'][0]['out_no'];
        $api_trade_no = $info['complaint_trade_info_list'][0]['trade_no'];
        $status = self::getStatus($info['status']);

        $row = $DB->find('complain', '*', ['thirdid'=>$thirdid], null, 1);
        if(!$row){
            $order = $DB->find('order', 'uid', ['trade_no'=>$trade_no]);
            if(!$order){
                $order = $DB->find('order', 'trade_no,uid', ['api_trade_no'=>$api_trade_no]);
                if($order) $trade_no = $order['trade_no'];
                if(!$order){
                    $order = $DB->find('order', 'trade_no,uid', ['bill_trade_no'=>$api_trade_no]);
                    if($order){
                        $trade_no = $order['trade_no'];
                    }else{
                        if(!$conf['complain_range']) return 3;
                    }
                }
            }
        }

        if($row){
            if($status != $row['status']){
                if($DB->update('complain', ['status'=>$status, 'edittime'=>$info['gmt_process'] ?? $info['gmt_complain']], ['id'=>$row['id']]) === false)
                    throw new \RuntimeException('投诉状态保存失败');
                try { CommUtil::autoHandle($trade_no, $status); }
                catch (\Throwable $e) { throw new SyncActionException(2, $e); }
                return 2;
            }
        }else{
            if($order || $conf['complain_range']==1){
                $complaintId = $DB->insert('complain', ['paytype'=>$this->channel['type'], 'channel'=>$this->channel['id'], 'source'=>1, 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>'交易投诉', 'title'=>'-', 'content'=>$info['complain_content'], 'status'=>$status, 'phone'=>$info['contact'] ?? '', 'addtime'=>$info['gmt_complain'], 'edittime'=>$info['gmt_process'] ?? $info['gmt_complain']]);
                if($complaintId === false)
                    throw new \RuntimeException('投诉记录保存失败');
                if($status == 0 && $conf['complain_auto_reply'] == 1 && !empty($conf['complain_auto_reply_con'])){
                    usleep(300000);
                    $started = microtime(true);
                    try {
                        // Preserve the existing action; let the report retain structured SDK errors.
                        if($this->service->riskfeedbackSubmit($thirdid, 'ORTHER', $conf['complain_auto_reply_con'], null) !== true)
                            throw new \UnexpectedValueException('Unconfirmed automatic reply result');
                    } catch (\Throwable $e) {
                        $report->warning('auto_reply', $e, (int)$complaintId, $started);
                    }
                }
                try { CommUtil::autoHandle($trade_no, $status); }
                catch (\Throwable $e) { throw new SyncActionException(1, $e); }
                return 1;
            }
        }
        return 0;
    }

    private static function validInfo($info): bool {
        if(!is_array($info)) return false;
        foreach(['id', 'status', 'gmt_complain', 'complain_content'] as $key){
            if(!isset($info[$key]) || !is_scalar($info[$key])) return false;
        }
        if((string)$info['id'] === '' || (string)$info['status'] === '') return false;
        $trade = $info['complaint_trade_info_list'][0] ?? null;
        if(!is_array($trade)) return false;
        foreach(['out_no', 'trade_no'] as $key){
            if(!isset($trade[$key]) || !is_scalar($trade[$key]) || (string)$trade[$key] === '') return false;
        }
        foreach(['contact', 'gmt_process'] as $key){
            if(isset($info[$key]) && !is_scalar($info[$key])) return false;
        }
        return true;
    }

    //上传图片
    public function uploadImage($thirdid, $filepath, $filename){
        try{
            $result = $this->service->riskimageUpload($filepath, $filename);
            $image_id = $result['file_key'] . '|' . $result['file_url'];
            return ['code'=>0, 'image_id'=>$image_id];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //处理投诉（仅支付宝）
    public function feedbackSubmit($thirdid, $code, $content, $images = []){
        if(empty($code)) $code = 'ORTHER';
        if($images && count($images) > 0){
            $img_file_list = [];
            foreach($images as $image){
                $arr = explode('|', $image);
                $img_file_list[] = ['img_url'=>$arr[1], 'img_url_key'=>$arr[0]];
            }
        }else{
            $img_file_list = null;
        }
        try{
            $this->service->riskfeedbackSubmit($thirdid, $code, $content, $img_file_list);
            return ['code'=>0];
        } catch (Exception $e) {
            return ['code'=>-1, 'msg'=>$e->getMessage()];
        }
    }

    //回复用户
    public function replySubmit($thirdid, $content, $images = []){
        return false;
    }

    //更新退款审批结果（仅微信）
    public function refundProgressSubmit($thirdid, $code, $content, $remark = null, $images = []){
        return false;
    }

    //处理完成（仅微信）
    public function complete($thirdid){
        return false;
    }

    //商家补充凭证（仅支付宝）
    public function supplementSubmit($thirdid, $content, $images = []){
        return false;
    }

    //下载图片（仅微信）
    public function getImage($media_id){
        return false;
    }

    private static function getStatus($status){
        if($status == 'WAIT_PROCESS' || $status == 'OVERDUE'){
            return 0;
        }elseif($status == 'PROCESSING' || $status == 'PART_OVERDUE'){
            return 1;
        }else{
            return 2;
        }
    }

}
