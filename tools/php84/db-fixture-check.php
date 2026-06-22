<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$root = dirname(__DIR__, 2);
$host = getenv('EPAY_DB_HOST') ?: '127.0.0.1';
$port = getenv('EPAY_DB_PORT') ?: '3306';
$socket = getenv('EPAY_DB_SOCKET') ?: '';
$user = getenv('EPAY_DB_USER') ?: 'root';
$pass = getenv('EPAY_DB_PASSWORD');
if ($pass === false) {
    $pass = '';
}
$prefix = getenv('EPAY_DB_PREFIX') ?: 'pay';
$keep = getenv('EPAY_DB_KEEP') === '1';
$dbName = getenv('EPAY_DB_NAME');
if ($dbName === false || $dbName === '') {
    $dbName = 'epay_php84_' . date('Ymd_His') . '_' . random_int(1000, 9999);
}

function fail($message, $exitCode = 1)
{
    fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
    exit($exitCode);
}

function ok($message)
{
    echo '[OK] ' . $message . PHP_EOL;
}

function quote_identifier($name)
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        fail('Unsafe identifier: ' . $name);
    }
    return '`' . $name . '`';
}

function split_sql_statements($sql)
{
    $statements = array();
    $buffer = '';
    $quote = null;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];

        if ($quote !== null) {
            $buffer .= $char;
            if ($char === '\\' && $i + 1 < $length) {
                $i++;
                $buffer .= $sql[$i];
                continue;
            }
            if ($char === $quote) {
                $quote = null;
            }
            continue;
        }

        if ($char === '\'' || $char === '"' || $char === '`') {
            $quote = $char;
            $buffer .= $char;
            continue;
        }

        if ($char === ';') {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $statement = trim($buffer);
    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
}

function exec_sql_file(PDO $pdo, $file, $prefix)
{
    $sql = file_get_contents($file);
    if ($sql === false) {
        fail('Unable to read SQL file: ' . $file);
    }

    $sql = str_replace('`pre_', '`' . $prefix . '_', $sql);
    $count = 0;
    foreach (split_sql_statements($sql) as $statement) {
        $pdo->exec($statement);
        $count++;
    }

    return $count;
}

function table_name($prefix, $table)
{
    return $prefix . '_' . $table;
}

function assert_table(PDO $pdo, $prefix, $table)
{
    $name = table_name($prefix, $table);
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute(array($name));
    if (!$stmt->fetchColumn()) {
        fail('Missing table: ' . $name);
    }
    ok('table exists: ' . $name);
}

function assert_column_value(PDO $pdo, $prefix, $table, $column, $whereColumn, $whereValue, $expected)
{
    $sql = 'SELECT ' . quote_identifier($column) . ' FROM ' . quote_identifier(table_name($prefix, $table)) .
        ' WHERE ' . quote_identifier($whereColumn) . ' = ? LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array($whereValue));
    $actual = $stmt->fetchColumn();
    if ((string)$actual !== (string)$expected) {
        fail('Unexpected value for ' . $table . '.' . $column . ': expected ' . $expected . ', got ' . var_export($actual, true));
    }
    ok('value verified: ' . $table . '.' . $column . ' = ' . $expected);
}

if ($socket !== '') {
    $adminDsn = 'mysql:unix_socket=' . $socket . ';charset=utf8mb4';
} else {
    $adminDsn = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';
}
$created = false;

try {
    $admin = new PDO($adminDsn, $user, $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $admin->exec('CREATE DATABASE ' . quote_identifier($dbName) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    $created = true;
    ok('created temporary database: ' . $dbName);

    $pdo = new PDO($adminDsn . ';dbname=' . $dbName, $user, $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $pdo->exec("SET sql_mode = ''");
    $pdo->exec("SET names utf8mb4");

    $statementCount = exec_sql_file($pdo, $root . '/install/install.sql', $prefix);
    ok('imported install SQL statements: ' . $statementCount);

    foreach (array('config', 'order', 'user', 'channel', 'settle', 'transfer', 'refundorder', 'record') as $table) {
        assert_table($pdo, $prefix, $table);
    }

    $common = file_get_contents($root . '/includes/common.php');
    if (!preg_match("/define\\('DB_VERSION', '([0-9]+)'\\)/", $common, $matches)) {
        fail('Unable to read DB_VERSION from includes/common.php');
    }
    assert_column_value($pdo, $prefix, 'config', 'v', 'k', 'version', $matches[1]);

    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');
    $merchantKey = md5('php84-fixture');
    $stmt = $pdo->prepare('INSERT INTO ' . quote_identifier(table_name($prefix, 'user')) .
        ' (`key`, `pwd`, `money`, `addtime`, `status`) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute(array($merchantKey, md5('123456'), '10.00', $now, 1));
    $uid = $pdo->lastInsertId();
    ok('inserted user uid: ' . $uid);

    $stmt = $pdo->prepare('UPDATE ' . quote_identifier(table_name($prefix, 'user')) . ' SET `money` = ? WHERE `uid` = ?');
    $stmt->execute(array('12.34', $uid));
    assert_column_value($pdo, $prefix, 'user', 'money', 'uid', $uid, '12.34');

    $stmt = $pdo->prepare('INSERT INTO ' . quote_identifier(table_name($prefix, 'channel')) .
        ' (`type`, `plugin`, `name`, `rate`, `status`) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute(array(1, 'alipay', 'PHP84 fixture channel', '100.00', 1));
    $channelId = $pdo->lastInsertId();
    ok('inserted channel id: ' . $channelId);

    $tradeNo = date('YmdHis') . random_int(10000, 99999);
    $stmt = $pdo->prepare('INSERT INTO ' . quote_identifier(table_name($prefix, 'order')) .
        ' (`trade_no`, `out_trade_no`, `uid`, `type`, `channel`, `name`, `money`, `realmoney`, `getmoney`, `addtime`, `date`, `status`) ' .
        'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute(array($tradeNo, 'fixture-' . $tradeNo, $uid, 1, $channelId, 'PHP84 fixture order', '1.23', '1.23', '1.23', $now, $today, 0));
    ok('inserted order trade_no: ' . $tradeNo);

    $stmt = $pdo->prepare('UPDATE ' . quote_identifier(table_name($prefix, 'order')) .
        ' SET `status` = ?, `api_trade_no` = ?, `buyer` = ? WHERE `trade_no` = ?');
    $stmt->execute(array(1, 'api-' . $tradeNo, 'fixture-buyer', $tradeNo));
    assert_column_value($pdo, $prefix, 'order', 'status', 'trade_no', $tradeNo, '1');

    $refundNo = date('YmdHis') . random_int(10000, 99999);
    $refundMoney = '0.23';
    $oldMoney = '12.34';
    $newMoney = '12.11';
    $pdo->prepare('UPDATE ' . quote_identifier(table_name($prefix, 'user')) . ' SET `money` = ? WHERE `uid` = ?')
        ->execute(array($newMoney, $uid));
    $pdo->prepare('UPDATE ' . quote_identifier(table_name($prefix, 'order')) .
        ' SET `status` = ?, `refundmoney` = ? WHERE `trade_no` = ?')
        ->execute(array(2, $refundMoney, $tradeNo));
    $stmt = $pdo->prepare('INSERT INTO ' . quote_identifier(table_name($prefix, 'refundorder')) .
        ' (`refund_no`, `out_refund_no`, `trade_no`, `uid`, `money`, `reducemoney`, `addtime`, `endtime`, `status`) ' .
        'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute(array($refundNo, 'out-' . $refundNo, $tradeNo, $uid, $refundMoney, $refundMoney, $now, $now, 1));
    $stmt = $pdo->prepare('INSERT INTO ' . quote_identifier(table_name($prefix, 'record')) .
        ' (`uid`, `action`, `money`, `oldmoney`, `newmoney`, `type`, `trade_no`, `date`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute(array($uid, 2, $refundMoney, $oldMoney, $newMoney, '订单退款', $tradeNo, $now));
    assert_column_value($pdo, $prefix, 'refundorder', 'status', 'refund_no', $refundNo, '1');
    assert_column_value($pdo, $prefix, 'order', 'refundmoney', 'trade_no', $tradeNo, $refundMoney);
    assert_column_value($pdo, $prefix, 'user', 'money', 'uid', $uid, $newMoney);
    ok('refund fixture verified: ' . $refundNo);

    $stmt = $pdo->prepare('INSERT INTO ' . quote_identifier(table_name($prefix, 'settle')) .
        ' (`uid`, `batch`, `account`, `username`, `money`, `realmoney`, `addtime`, `status`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute(array($uid, 'B' . substr($tradeNo, -8), 'fixture@example.com', 'fixture', '2.00', '1.99', $now, 0));
    $settleId = $pdo->lastInsertId();
    $pdo->prepare('UPDATE ' . quote_identifier(table_name($prefix, 'settle')) . ' SET `status` = ? WHERE `id` = ?')
        ->execute(array(1, $settleId));
    assert_column_value($pdo, $prefix, 'settle', 'status', 'id', $settleId, '1');

    $bizNo = date('YmdHis') . random_int(10000, 99999);
    $stmt = $pdo->prepare('INSERT INTO ' . quote_identifier(table_name($prefix, 'transfer')) .
        ' (`biz_no`, `uid`, `type`, `channel`, `account`, `username`, `money`, `status`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute(array($bizNo, $uid, 'alipay', $channelId, 'fixture@example.com', 'fixture', '3.00', 0));
    $pdo->prepare('UPDATE ' . quote_identifier(table_name($prefix, 'transfer')) . ' SET `status` = ? WHERE `biz_no` = ?')
        ->execute(array(1, $bizNo));
    assert_column_value($pdo, $prefix, 'transfer', 'status', 'biz_no', $bizNo, '1');

    ok('database fixture checks passed');
} catch (Exception $e) {
    fail($e->getMessage(), 2);
} finally {
    if ($created && !$keep) {
        try {
            $admin->exec('DROP DATABASE ' . quote_identifier($dbName));
            ok('dropped temporary database: ' . $dbName);
        } catch (Exception $dropError) {
            fwrite(STDERR, '[WARN] unable to drop temporary database ' . $dbName . ': ' . $dropError->getMessage() . PHP_EOL);
        }
    } elseif ($created) {
        ok('kept temporary database: ' . $dbName);
    }
}
