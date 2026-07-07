<?php
include("./common.php");
if ($islogin2 !== 1) {
    header("Location: ./login.php");
    exit();
}

if (!empty($conf['telegram_bot_token'])) {
    header("HTTP/1.1 403 Forbidden");
    exit("No input file specified.");
}

$title = 'Telegram 机器人设置';
include './head.php';

$bind = $DB->getRow("SELECT * FROM pre_telegram_bind WHERE uid=:uid AND status=1", [':uid' => $uid]);
?>
<div class="alert alert-primary border border-dashed border-primary rounded p-5 mb-10">
    <div class="d-flex align-items-center">
        <i class="ki-duotone ki-information fs-2hx text-primary me-4">
            <span class="path1"></span>
            <span class="path2"></span>
            <span class="path3"></span>
        </i>
        <div class="d-flex flex-column">
            <h4 class="mb-1 text-primary">使用说明</h4>
            <span>
                绑定 Telegram 机器人后，您将能够接收订单通知、结算通知等重要消息提醒<br/>
                请在 Telegram 中搜索：<strong><?php echo $conf['telegram_bot_name'] ?: '未设置' ?></strong>
            </span>
        </div>
    </div>
</div>

<div class="card card-flush">
    <div class="card-header pt-8">
        <div class="card-title">
            <h2 class="d-flex align-items-center">
                <i class="ki-duotone ki-message-notify fs-2qx text-primary me-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
                Telegram 绑定状态
            </h2>
        </div>
    </div>
    <div class="card-body pt-5">
        <?php if($bind){ ?>
        <div class="row gx-9 gy-6 mb-10">
            <div class="col-xl-6" data-kt-billing-element="card">
                <div class="card card-dashed h-xl-100 flex-row flex-stack flex-wrap p-6">
                    <div class="d-flex flex-column py-2">
                        <div class="d-flex align-items-center fs-4 fw-bold mb-5">绑定信息
                            <span class="badge badge-light-success fs-7 ms-2">已绑定</span>
                        </div>
                        <div class="d-flex align-items-center">
                            <i class="ki-duotone ki-security-user fs-2hx text-success me-4">
                                <span class="path1"></span>
                                <span class="path2"></span>
                                <span class="path3"></span>
                                <span class="path4"></span>
                            </i>
                            <div>
                                <div class="fs-4 fw-bold">Chat ID: <?php echo $bind['chat_id'] ?></div>
                                <div class="fs-6 fw-semibold text-gray-500">绑定时间：<?php echo $bind['bindtime'] ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-6" data-kt-billing-element="card">
                <div class="card card-dashed h-xl-100 flex-row flex-stack flex-wrap p-6">
                    <div class="d-flex flex-column py-2">
                        <div class="d-flex align-items-center">
                            <i class="ki-duotone ki-robot fs-2hx text-primary me-4">
                                <span class="path1"></span>
                                <span class="path2"></span>
                                <span class="path3"></span>
                                <span class="path4"></span>
                            </i>
                            <div>
                                <div class="fs-4 fw-bold">机器人名称</div>
                                <div class="fs-2hx fw-bold text-primary"><?php echo $conf['telegram_bot_name'] ?: '未设置' ?></div>
                                <div class="fs-6 fw-semibold text-gray-500">状态：<?php echo !empty($conf['telegram_bot_token']) ? '<span class="badge badge-light-success">已配置</span>' : '<span class="badge badge-light-danger">未配置</span>'; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <form class="form">
            <div class="fv-row mb-10">
                <label class="d-flex align-items-center fs-5 fw-semibold mb-2">
                    <span class="required">通知设置</span>
                    <i class="ki-duotone ki-information fs-6 ms-2" data-bs-toggle="tooltip" title="选择您需要接收的通知类型">
                        <span class="path1"></span>
                        <span class="path2"></span>
                        <span class="path3"></span>
                    </i>
                </label>

                <div class="row g-5">
                    <div class="col-md-6">
                        <div class="form-check form-check-custom form-check-solid mb-3">
                            <input class="form-check-input" type="checkbox" name="notify_order" value="1" id="notify_order" default="<?php echo $bind['notify_order']?>"/>
                            <label class="form-check-label fw-semibold" for="notify_order">
                                新订单通知
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-check-custom form-check-solid mb-3">
                            <input class="form-check-input" type="checkbox" name="notify_settle" value="1" id="notify_settle" default="<?php echo $bind['notify_settle']?>"/>
                            <label class="form-check-label fw-semibold" for="notify_settle">
                                结算通知
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-check-custom form-check-solid mb-3">
                            <input class="form-check-input" type="checkbox" name="notify_login" value="1" id="notify_login" default="<?php echo $bind['notify_login']?>"/>
                            <label class="form-check-label fw-semibold" for="notify_login">
                                登录通知
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-check-custom form-check-solid mb-3">
                            <input class="form-check-input" type="checkbox" name="notify_complain" value="1" id="notify_complain" default="<?php echo $bind['notify_complain']?>"/>
                            <label class="form-check-label fw-semibold" for="notify_complain">
                                交易投诉通知
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-check-custom form-check-solid mb-3">
                            <input class="form-check-input" type="checkbox" name="notify_mchrisk" value="1" id="notify_mchrisk" default="<?php echo $bind['notify_mchrisk']?>"/>
                            <label class="form-check-label fw-semibold" for="notify_mchrisk">
                                渠道商户违规通知
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-check-custom form-check-solid mb-3">
                            <input class="form-check-input" type="checkbox" name="notify_balance" value="1" id="notify_balance" default="<?php echo $bind['notify_balance']?>"/>
                            <label class="form-check-label fw-semibold" for="notify_balance">
                                余额不足提醒
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end">
                <button type="button" id="saveNotify" class="btn btn-primary">
                    <span class="indicator-label">保存设置</span>
                </button>
            </div>
        </form>
        <?php } else { ?>
        <div class="text-center py-10">
            <i class="ki-duotone ki-security-user fs-3hx text-warning mb-5"></i>
            <h4 class="text-gray-700 fw-bold mb-3">暂未绑定 Telegram 账号</h4>
            <p class="text-gray-500 mb-7">请先在 Telegram 中搜索并关注我们的机器人进行绑定</p>
            <a href="javascript:;" class="btn btn-primary btn-lg px-8" data-bs-toggle="modal" data-bs-target="#bindModal">
                <i class="ki-duotone ki-plus-circle fs-2 me-2"><span class="path1"></span><span class="path2"></span></i>
                立即绑定
            </a>
        </div>
        <?php } ?>
    </div>
</div>

<div class="card card-flush mt-7">
    <div class="card-header pt-5">
        <h3 class="card-title align-items-start flex-column">
            <span class="card-label fw-bold text-gray-900">机器人命令说明</span>
            <span class="text-gray-500 mt-1 fw-semibold fs-7">可用的 Telegram 机器人命令</span>
        </h3>
    </div>
    <div class="card-body pt-5">
        <div class="mb-7">
            <h4 class="fw-bold text-gray-900 mb-3">常用命令</h4>
            <div class="table-responsive">
                <table class="table table-row-dashed fs-6 gy-5">
                    <thead>
                        <tr class="text-start text-gray-700 fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-150px">命令</th>
                            <th>说明</th>
                        </tr>
                    </thead>
                    <tbody class="fw-semibold text-gray-600">
                        <tr>
                            <td><code class="bg-light p-2 rounded">/start</code></td>
                            <td>显示主菜单</td>
                        </tr>
                        <tr>
                            <td><code class="bg-light p-2 rounded">/bind</code></td>
                            <td>绑定商户账号</td>
                        </tr>
                        <tr>
                            <td><code class="bg-light p-2 rounded">/unbind</code></td>
                            <td>解绑商户账号</td>
                        </tr>
                        <tr>
                            <td><code class="bg-light p-2 rounded">/info</code></td>
                            <td>查看商户信息</td>
                        </tr>
                        <tr>
                            <td><code class="bg-light p-2 rounded">/today</code></td>
                            <td>今日流水统计</td>
                        </tr>
                        <tr>
                            <td><code class="bg-light p-2 rounded">/yesterday</code></td>
                            <td>昨日流水统计</td>
                        </tr>
                        <tr>
                            <td><code class="bg-light p-2 rounded">/order 订单号</code></td>
                            <td>查询订单详情</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mb-7">
            <h4 class="fw-bold text-gray-900 mb-3">通知类型</h4>
            <p class="text-gray-600">绑定后，您将收到以下通知：</p>
            <ul class="text-gray-600 ps-4">
                <li class="mb-2">新订单通知 - 实时推送收款订单信息</li>
                <li class="mb-2">结算完成通知 - 资金结算到账提醒</li>
                <li class="mb-2">账号登录通知 - 账户安全动态监控</li>
                <li class="mb-2">交易投诉通知 - 及时处理客户投诉</li>
                <li class="mb-2">商户余额不足提醒 - 避免影响正常收款</li>
                <li class="mb-2">会员用户组到期提醒 - 会员服务到期预警</li>
            </ul>
        </div>
    </div>
</div>

<?php include 'foot.php';?>
<script src="<?php echo $cdnpublic?>layer/3.1.1/layer.js"></script>
<script>
    $(document).ready(function(){
        var items = $('select[default], input[default]');
        for (i = 0; i < items.length; i++) {
            if ($(items[i]).attr('type') === 'checkbox') {
                if ($(items[i]).attr('default') == '1') {
                    $(items[i]).prop('checked', true);
                }
            } else {
                $(items[i]).val($(items[i]).attr('default')||0);
            }
        }

        $('#saveNotify').click(function(){
            var notify_order = $('input[name="notify_order"]:checked').val() || 0;
            var notify_settle = $('input[name="notify_settle"]:checked').val() || 0;
            var notify_login = $('input[name="notify_login"]:checked').val() || 0;
            var notify_complain = $('input[name="notify_complain"]:checked').val() || 0;
            var notify_mchrisk = $('input[name="notify_mchrisk"]:checked').val() || 0;
            var notify_balance = $('input[name="notify_balance"]:checked').val() || 0;

            var ii = layer.load(2, {shade:[0.1,'#fff']});
            $.ajax({
                type : 'POST',
                url : 'ajax2.php?act=saveTelegramNotify',
                data : {
                    notify_order: notify_order,
                    notify_settle: notify_settle,
                    notify_login: notify_login,
                    notify_complain: notify_complain,
                    notify_mchrisk: notify_mchrisk,
                    notify_balance: notify_balance
                },
                dataType : 'json',
                success : function(data) {
                    layer.close(ii);
                    if(data.code == 1){
                        layer.alert('设置保存成功！', {icon:1});
                    }else{
                        layer.alert(data.msg);
                    }
                },
                error:function(data){
                    layer.close(ii);
                    layer.msg('服务器错误');
                }
            });
        });
    });
</script>