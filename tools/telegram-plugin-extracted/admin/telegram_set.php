<?php
include("./common.php");

if ($islogin !== 1) {
    header("Location: ./login.php");
    exit();
}

$title = '机器人相关配置';
include './head.php';

addon_update('telegram', '1000');
?>
<div class="card card-flush mb-7">
    <div class="card-header pt-5">
        <h3 class="card-title align-items-start flex-column">
            <span class="card-label fw-bold text-gray-900">Telegram 基础配置</span>
            <span class="text-gray-500 mt-1 fw-semibold fs-7">配置 Telegram 机器人基本信息</span>
        </h3>
    </div>
    <div class="card-body pt-5">
        <form onsubmit="return saveSetting(this)" method="post" class="form" role="form">
            <div class="row g-5">
                <div class="col-md-12">
                    <div class="fv-row mb-7">
                        <label class="fs-6 fw-semibold form-label">Bot Token</label>
                        <input type="text" name="telegram_bot_token" value="<?php echo $conf['telegram_bot_token']; ?>" class="form-control" placeholder="从 @BotFather 获取"/>
                        <div class="form-text">格式：123456789:ABCdefGHIjklMNOpqrsTUVwxyz</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="fv-row mb-7">
                        <label class="fs-6 fw-semibold form-label">管理员 Chat ID</label>
                        <input type="text" name="telegram_admin_chat_id" value="<?php echo $conf['telegram_admin_chat_id']; ?>" class="form-control" placeholder="管理员的 Telegram Chat ID"/>
                        <div class="form-text">从 @userinfobot 获取您的 Chat ID</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="fv-row mb-7">
                        <label class="fs-6 fw-semibold form-label">机器人用户名</label>
                        <input type="text" name="telegram_bot_name" value="<?php echo $conf['telegram_bot_name']; ?>" class="form-control" placeholder="机器人用户名，例如：@mybot"/>
                        <div class="form-text">从 @BotFather 获取您的机器人用户名</div>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end">
                <button type="submit" name="submit" class="btn btn-primary me-3">
                    保存设置
                </button>
                <button type="button" onclick="setCommands()" class="btn btn-success">
                    设置命令菜单
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card card-flush mb-7">
    <div class="card-header pt-5">
        <h3 class="card-title align-items-start flex-column">
            <span class="card-label fw-bold text-gray-900">使用说明</span>
            <span class="text-gray-500 mt-1 fw-semibold fs-7">Telegram 机器人配置指南</span>
        </h3>
    </div>
    <div class="card-body pt-5">
        <div class="mb-7">
            <h4 class="fw-bold text-gray-900 mb-3">1. 创建机器人</h4>
            <p class="text-gray-600">在 Telegram 中搜索 @BotFather，发送 /newbot 命令创建机器人，获取 Bot Token。</p>
        </div>
        <div class="mb-7">
            <h4 class="fw-bold text-gray-900 mb-3">2. 获取 Chat ID</h4>
            <p class="text-gray-600">在 Telegram 中搜索 @userinfobot，发送任意消息获取您的 Chat ID。</p>
        </div>
        <div class="mb-7">
            <h4 class="fw-bold text-gray-900 mb-3">3. 配置机器人</h4>
            <p class="text-gray-600">在本页面填写 Bot Token 和管理员 Chat ID，点击保存设置。</p>
        </div>
        <div class="mb-7">
            <h4 class="fw-bold text-gray-900 mb-3">4. 设置宝塔计划任务</h4>
            <p class="text-gray-600 mb-3">需要添加两个计划任务：</p>
            <ol class="ps-4 text-gray-600">
                <li class="mb-2"><strong>Telegram 机器人</strong>：每天 0 点执行，脚本内容：<code class="bg-light p-2 rounded">cd <?php echo realpath(dirname(__FILE__) . '/..'); ?> && php telegram_polling.php</code></li>
                <li class="mb-2"><strong>Telegram 机器人结束</strong>：每天 23 点 59 分 执行，脚本内容：<code class="bg-light p-2 rounded">cd <?php echo realpath(dirname(__FILE__) . '/..'); ?> && pkill -f telegram_polling.php</code></li>
                <li><strong>Telegram 通知队列</strong>：每秒钟执行，脚本内容：<code class="bg-light p-2 rounded">cd <?php echo realpath(dirname(__FILE__) . '/..'); ?> && php telegram_notify_cron.php</code></li>
            </ol>
        </div>
        <div class="mb-7">
            <h4 class="fw-bold text-gray-900 mb-3">5. 商户使用</h4>
            <p class="text-gray-600">商户在 Telegram 中搜索您的机器人，发送 /start 绑定商户账号。</p>
        </div>
        <div class="mb-7">
            <h4 class="fw-bold text-gray-900 mb-3">6. 管理员命令</h4>
            <p class="text-gray-600"><code class="bg-light p-2 rounded">/start /today /yesterday /week /month /channel /order 订单号</code></p>
        </div>
    </div>
</div>

<div class="card card-flush mb-7">
    <div class="card-header pt-5">
        <h3 class="card-title align-items-start flex-column">
            <span class="card-label fw-bold text-gray-900">绑定商户列表</span>
            <span class="text-gray-500 mt-1 fw-semibold fs-7">已绑定 Telegram 的商户</span>
        </h3>
    </div>
    <div class="card-body pt-0">
        <form onsubmit="return searchSubmit()" method="GET" id="searchToolbar">
            <button type="button" class="btn btn-light-primary me-3" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                <i class="ki-outline ki-filter fs-2"></i>搜索
            </button>
            <div class="menu menu-sub menu-sub-dropdown w-300px w-md-325px" data-kt-menu="true" id="kt-toolbar-filter">
                <div class="px-7 py-5">
                    <div class="fs-4 text-gray-900 fw-bold">商户搜索</div>
                </div>
                <div class="separator border-gray-200"></div>
                <div class="px-7 py-5 row">
                    <div class="col-12 mb-5">
                        <label class="form-label fs-5 fw-semibold mb-3">商户号</label>
                        <input type="text" class="form-control" name="uid" placeholder="输入商户号">
                    </div>
                    <div class="col-12 d-flex justify-content-end mb-2">
                        <button type="button" class="btn btn-light btn-active-light-primary me-2" onclick="searchClear()">重置
                        </button>
                        <button type="submit" class="btn btn-primary">搜索</button>
                    </div>
                </div>
            </div>
        </form>
        <div class="table-responsive mt-4">
            <table id="bindTable"></table>
        </div>
    </div>
</div>

<script src="<?php echo $cdnpublic ?>layer/3.1.1/layer.js"></script>
<script src="../assets/js/bootstrap-table.min.js"></script>
<script src="../assets/js/bootstrap-table-page-jump-to.min.js"></script>
<script src="../assets/js/custom.js"></script>
<script>
    function saveSetting(obj) {
        var ii = layer.load(2, {shade: [0.1, '#fff']});
        $.ajax({
            type: 'POST',
            url: 'ajax.php?act=set',
            data: $(obj).serialize(),
            dataType: 'json',
            success: function (data) {
                layer.close(ii);
                if (data.code == 0) {
                    layer.alert('设置保存成功！', {
                        icon: 1,
                        closeBtn: false
                    }, function () {
                        window.location.reload()
                    });
                } else {
                    layer.alert(data.msg, {icon: 2})
                }
            },
            error: function (data) {
                layer.close(ii);
                layer.msg('服务器错误');
            }
        });
        return false;
    }

    function setCommands() {
        var ii = layer.load(2, {shade: [0.1, '#fff']});
        $.ajax({
            type: 'POST',
            url: 'ajax_telegram.php?act=setCommands',
            dataType: 'json',
            success: function (data) {
                layer.close(ii);
                if (data.code == 0) {
                    layer.alert('命令菜单设置成功！\n\n请在 Telegram 中重新打开与机器人的聊天窗口，即可看到左下角的命令菜单。', {
                        icon: 1,
                        closeBtn: false
                    });
                } else {
                    layer.alert(data.msg, {icon: 2})
                }
            },
            error: function (data) {
                layer.close(ii);
                layer.msg('服务器错误');
            }
        });
    }

    $(document).ready(function () {
        function preventHorizontalScroll() {
            $('body').css('overflow-x', 'hidden');
            $('.card').css('max-width', '100vw');
            $('#searchToolbar').css('max-width', '100vw');
        }

        setTimeout(preventHorizontalScroll, 100);
        $(window).resize(preventHorizontalScroll);

        updateToolbar();
        const defaultPageSize = 30;
        const pageNumber = typeof window.$_GET['pageNumber'] != 'undefined' ? parseInt(window.$_GET['pageNumber']) : 1;
        const pageSize = typeof window.$_GET['pageSize'] != 'undefined' ? parseInt(window.$_GET['pageSize']) : defaultPageSize;

        $("#bindTable").bootstrapTable({
            url: 'ajax_telegram.php?act=getBindList',
            pageNumber: pageNumber,
            pageSize: pageSize,
            classes: 'table align-middle table-row-dashed fs-6 gy-5',
            columns: [
                {
                    field: 'id',
                    title: 'ID'
                },
                {
                    field: 'chat_id',
                    title: 'Chat ID'
                },
                {
                    field: 'uid',
                    title: '商户号',
                    formatter: function (value, row, index) {
                        return '<b><a href="./ulist.php?column=uid&value=' + value + '" target="_blank">' + value + '</a></b>';
                    }
                },
                {
                    field: 'username',
                    title: '商户名',
                    formatter: function (value, row, index) {
                        return value || '-';
                    }
                },
                {
                    field: 'bindtime',
                    title: '绑定时间'
                },
                {
                    field: 'status',
                    title: '状态',
                    formatter: function (value, row, index) {
                        return value == 1 ? '<span class="badge badge-light-success fw-bold fs-7 py-2 px-3">正常</span>' : '<span class="badge badge-light-secondary fw-bold fs-7 py-2 px-3">解绑</span>';
                    }
                },
                {
                    field: '',
                    title: '操作',
                    formatter: function (value, row, index) {
                        if (row.status == 1) {
                            return '<a class="btn btn-sm btn-light-danger" onclick="unbind(' + row.id + ')">解除绑定</a>';
                        } else {
                            return '<a class="btn btn-sm btn-light-danger" onclick="deleteBind(' + row.id + ')">删除</a>';
                        }
                    }
                },
            ],
            onPostBody: function () {
                $('#bindTable thead tr').addClass('text-start text-gray-700 fw-bold fs-7 text-uppercase gs-0');
                $('#bindTable tbody').addClass('fw-semibold text-gray-600');
            }
        })
    });

    function unbind(id) {
        layer.confirm('确定要解除该绑定记录吗？', {
            btn: ['确定', '取消']
        }, function () {
            $.ajax({
                type: 'POST',
                url: 'ajax_telegram.php?act=unbind',
                data: {id: id},
                dataType: 'json',
                success: function (data) {
                    if (data.code == 0) {
                        layer.msg('解除绑定成功', {icon: 1});
                        $('#bindTable').bootstrapTable('refresh');
                    } else {
                        layer.alert(data.msg, {icon: 2});
                    }
                }
            });
        });
    }

    function deleteBind(id) {
        layer.confirm('确定要删除该绑定记录吗？', {
            btn: ['确定', '取消']
        }, function () {
            $.ajax({
                type: 'POST',
                url: 'ajax_telegram.php?act=deleteBind',
                data: {id: id},
                dataType: 'json',
                success: function (data) {
                    if (data.code == 0) {
                        layer.msg('删除成功', {icon: 1});
                        $('#bindTable').bootstrapTable('refresh');
                    } else {
                        layer.alert(data.msg, {icon: 2});
                    }
                }
            });
        });
    }
</script>
