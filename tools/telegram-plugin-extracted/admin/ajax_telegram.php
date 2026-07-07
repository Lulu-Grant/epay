<?php
include("./common.php");
if ($islogin !== 1) exit('{"code":-3,"msg":"No Login"}');
$act = isset($_GET['act']) ? daddslashes($_GET['act']) : null;

if (!checkRefererHost()) exit('{"code":403}');

@header('Content-Type: application/json; charset=UTF-8');

switch ($act) {
case 'getBindList':
    $sql = " 1=1";
    if (isset($_POST['uid']) && !empty($_POST['uid'])) {
        $uid = intval($_POST['uid']);
        $sql .= " AND t.`uid`='$uid'";
    }
    $order = "t.id desc";
    if (isset($_POST['order']) && !empty($_POST['order'])) {
        $order = str_replace('_', ' ', $_POST['order']);
    }
    $offset = intval($_POST['offset']);
    $limit = intval($_POST['limit']);
    $total = $DB->getColumn("SELECT COUNT(*) FROM pre_telegram_bind t WHERE{$sql}");
    $list = $DB->getAll("SELECT t.*, u.username, u.account FROM pre_telegram_bind t LEFT JOIN pre_user u ON t.uid = u.uid WHERE{$sql} ORDER BY {$order} LIMIT $offset, $limit");

    exit(json_encode(['total' => $total, 'rows' => $list]));
break;

case 'unbind':
    $id = intval($_POST['id']);

    $row = $DB->getRow("SELECT * FROM pre_telegram_bind WHERE id=:id", [':id' => $id]);
    if (!$row) {
        exit(json_encode(['code' => -1, 'msg' => '记录不存在']));
    }

    $DB->exec("UPDATE pre_telegram_bind SET status=0 WHERE id=:id", [':id' => $id]);

    exit(json_encode(['code' => 0, 'msg' => '解除绑定成功']));
break;

case 'deleteBind':
    $id = intval($_POST['id']);

    $row = $DB->getRow("SELECT * FROM pre_telegram_bind WHERE id=:id", [':id' => $id]);
    if (!$row) {
        exit(json_encode(['code' => -1, 'msg' => '记录不存在']));
    }

    $DB->exec("DELETE FROM pre_telegram_bind WHERE id=:id", [':id' => $id]);

    exit(json_encode(['code' => 0, 'msg' => '删除成功']));
break;

case 'setCommands':
    if (empty($conf['telegram_bot_token'])) {
        exit(json_encode(['code' => -1, 'msg' => '请先设置Bot Token']));
    }

    $botAPI = new \lib\Telegram\BotAPI($conf['telegram_bot_token']);

    $commands = [
        ['command' => 'start', 'description' => '显示主菜单'],
        ['command' => 'bind', 'description' => '绑定商户'],
        ['command' => 'unbind', 'description' => '解绑商户'],
        ['command' => 'info', 'description' => '查看商户信息'],
        ['command' => 'today', 'description' => '今日流水统计'],
        ['command' => 'yesterday', 'description' => '昨日流水统计'],
        ['command' => 'order', 'description' => '查询订单'],
        ['command' => 'settings', 'description' => '通知设置'],
        ['command' => 'help', 'description' => '帮助信息']
    ];

    $result = $botAPI->setMyCommands($commands);

    if ($result) {
        exit(json_encode(['code' => 0, 'msg' => '命令菜单设置成功']));
    } else {
        exit(json_encode(['code' => -1, 'msg' => '命令菜单设置失败：' . $botAPI->getLastError()]));
    }
break;

default:
    exit('{"code":-4,"msg":"No Act"}');
break;
}