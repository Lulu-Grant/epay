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

        $count_add = 0;
        $count_update = 0;
        $count_unchanged = 0;
        $count_skipped = 0;
        $count_fetched = 0;
        for($page_num = 1; $page_num <= $page_count; $page_num++){
            try{
                $result = $this->service->riskbatchQuery(null, null, null, $page_num, $page_size);
            } catch (\Throwable $e) {
                return CommUtil::syncFailure('QUERY_FAILED', $count_fetched, $count_add, $count_update, $count_unchanged, $count_skipped);
            }
            if(!is_array($result) || !isset($result['total_size'], $result['complaint_list']) || !is_array($result['complaint_list']))
                return CommUtil::syncFailure('INVALID_RESPONSE', $count_fetched, $count_add, $count_update, $count_unchanged, $count_skipped);
            if($result['total_size'] == 0 || count($result['complaint_list']) == 0) break;

            foreach($result['complaint_list'] as $info){
				$count_fetched++;
				try { $retcode = $this->updateInfo($info); }
				catch (SyncActionException $e) {
					if($e->persistedCode() == 1) $count_add++;
					else $count_update++;
					return CommUtil::syncFailure('ACTION_FAILED', $count_fetched, $count_add, $count_update, $count_unchanged, $count_skipped);
				}
				catch (\Throwable $e) {
					return CommUtil::syncFailure('SAVE_FAILED', $count_fetched, $count_add, $count_update, $count_unchanged, $count_skipped);
				}
                if($retcode == 2) $count_update++;
                elseif($retcode == 1) $count_add++;
                elseif($retcode == 3) $count_skipped++;
                else $count_unchanged++;

                if(isset($_GET['key']) && self::getStatus($info['status']) < 2){ //监控模式
                    global $DB;
                    $msgtype = null;
                    if($retcode == 2){
                        $msgtype = '用户提交了新的反馈，请尽快处理';
                    }elseif($retcode == 1){
                        $msgtype = '您有新的支付交易投诉，请尽快处理';
                    }
                    if($msgtype){
                        CommUtil::sendMsg($msgtype, $info['id']);
                    }
                }
            }
        }
        return ['code'=>0, 'msg'=>'成功添加'.$count_add.'条、更新'.$count_update.'条；未变'.$count_unchanged.'条，未匹配订单'.$count_skipped.'条', 'counts'=>['fetched'=>$count_fetched,'inserted'=>$count_add,'updated'=>$count_update,'unchanged'=>$count_unchanged,'skipped_unmatched'=>$count_skipped]];
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

    private function updateInfo($info){
        global $DB, $conf;
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
                if($DB->update('complain', ['status'=>$status, 'edittime'=>$info['gmt_process']], ['id'=>$row['id']]) === false)
                    throw new \RuntimeException('投诉状态保存失败');
                try { CommUtil::autoHandle($trade_no, $status); }
                catch (\Throwable $e) { throw new SyncActionException(2, $e); }
                return 2;
            }
        }else{
            if($order || $conf['complain_range']==1){
                if($DB->insert('complain', ['paytype'=>$this->channel['type'], 'channel'=>$this->channel['id'], 'source'=>1, 'uid'=>$order['uid'] ?? 0, 'trade_no'=>$trade_no, 'thirdid'=>$thirdid, 'type'=>'交易投诉', 'title'=>'-', 'content'=>$info['complain_content'], 'status'=>$status, 'phone'=>$info['contact'], 'addtime'=>$info['gmt_complain'], 'edittime'=>$info['gmt_process']]) === false)
                    throw new \RuntimeException('投诉记录保存失败');
                try {
                    if($status == 0 && $conf['complain_auto_reply'] == 1 && !empty($conf['complain_auto_reply_con'])){
                        usleep(300000);
                        $reply = $this->feedbackSubmit($thirdid, 'ORTHER', $conf['complain_auto_reply_con']);
                        if(!is_array($reply) || !isset($reply['code']) || $reply['code'] != 0)
                            throw new \RuntimeException('自动回复失败');
                    }
                    CommUtil::autoHandle($trade_no, $status);
                } catch (\Throwable $e) { throw new SyncActionException(1, $e); }
                return 1;
            }
        }
        return 0;
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
