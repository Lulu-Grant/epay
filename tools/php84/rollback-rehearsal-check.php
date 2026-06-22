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
    $dbName = 'epay_php84_rollback_' . date('Ymd_His') . '_' . random_int(1000, 9999);
}
$backupFile = getenv('EPAY_ROLLBACK_BACKUP_FILE');
if ($backupFile === false || $backupFile === '') {
    $backupFile = tempnam(sys_get_temp_dir(), 'epay-rollback-');
    if ($backupFile === false) {
        fail('Unable to allocate temporary backup file');
    }
    $backupFile .= '.sql';
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

function table_exists(PDO $pdo, $table)
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute(array($table));
    return (bool)$stmt->fetchColumn();
}

function export_database(PDO $pdo, $file)
{
    $tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM);
    if (!$tables) {
        fail('No base tables found for rollback backup');
    }

    $out = "-- epay php84 rollback rehearsal backup\n";
    $out .= "SET sql_mode = '';\n";
    $out .= "SET names utf8mb4;\n";
    $out .= "SET FOREIGN_KEY_CHECKS=0;\n";

    foreach ($tables as $tableRow) {
        $table = $tableRow[0];
        $create = $pdo->query('SHOW CREATE TABLE ' . quote_identifier($table))->fetch(PDO::FETCH_ASSOC);
        if (!$create || !isset($create['Create Table'])) {
            fail('Unable to read CREATE TABLE for ' . $table);
        }

        $out .= "\nDROP TABLE IF EXISTS " . quote_identifier($table) . ";\n";
        $out .= $create['Create Table'] . ";\n";

        $rows = $pdo->query('SELECT * FROM ' . quote_identifier($table))->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $columns = array();
            $values = array();
            foreach ($row as $column => $value) {
                $columns[] = quote_identifier($column);
                $values[] = $value === null ? 'NULL' : $pdo->quote($value);
            }
            $out .= 'INSERT INTO ' . quote_identifier($table) . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n";
        }
    }

    $out .= "\nSET FOREIGN_KEY_CHECKS=1;\n";
    if (file_put_contents($file, $out) === false) {
        fail('Unable to write rollback backup file: ' . $file);
    }
}

function restore_database(PDO $pdo, $file)
{
    $sql = file_get_contents($file);
    if ($sql === false || $sql === '') {
        fail('Rollback backup file is empty or unreadable: ' . $file);
    }

    $count = 0;
    foreach (split_sql_statements($sql) as $statement) {
        $pdo->exec($statement);
        $count++;
    }
    return $count;
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
    ok('created rollback rehearsal database: ' . $dbName);

    $pdo = new PDO($adminDsn . ';dbname=' . $dbName, $user, $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $pdo->exec("SET sql_mode = ''");
    $pdo->exec('SET names utf8mb4');

    $statementCount = exec_sql_file($pdo, $root . '/install/install.sql', $prefix);
    ok('imported install SQL statements: ' . $statementCount);

    $common = file_get_contents($root . '/includes/common.php');
    if (!preg_match("/define\\('DB_VERSION', '([0-9]+)'\\)/", $common, $matches)) {
        fail('Unable to read DB_VERSION from includes/common.php');
    }
    $dbVersion = $matches[1];

    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');
    $tradeNo = date('YmdHis') . random_int(10000, 99999);

    $pdo->prepare('REPLACE INTO ' . quote_identifier(table_name($prefix, 'config')) . ' (`k`, `v`) VALUES (?, ?)')
        ->execute(array('php84_rollback_marker', 'before-rollback'));
    $pdo->prepare('INSERT INTO ' . quote_identifier(table_name($prefix, 'user')) .
        ' (`uid`, `gid`, `key`, `money`, `account`, `username`, `addtime`, `pay`, `settle`, `refund`, `transfer`, `keylogin`, `status`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(array(1000, 0, md5('php84-rollback-fixture'), '99.88', 'rollback@example.com', 'rollback-user', $now, 1, 1, 1, 1, 1, 1));
    $pdo->prepare('INSERT INTO ' . quote_identifier(table_name($prefix, 'channel')) .
        ' (`type`, `plugin`, `name`, `rate`, `status`) VALUES (?, ?, ?, ?, ?)')
        ->execute(array(1, 'epay', 'Rollback channel', '100.00', 1));
    $channelId = $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO ' . quote_identifier(table_name($prefix, 'order')) .
        ' (`trade_no`, `out_trade_no`, `uid`, `type`, `channel`, `name`, `money`, `realmoney`, `getmoney`, `addtime`, `date`, `status`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(array($tradeNo, 'rollback-' . $tradeNo, 1000, 1, $channelId, 'Rollback fixture order', '5.67', '5.67', '5.67', $now, $today, 0));

    assert_column_value($pdo, $prefix, 'config', 'v', 'k', 'version', $dbVersion);
    assert_column_value($pdo, $prefix, 'config', 'v', 'k', 'php84_rollback_marker', 'before-rollback');
    assert_column_value($pdo, $prefix, 'user', 'money', 'uid', 1000, '99.88');
    assert_column_value($pdo, $prefix, 'order', 'status', 'trade_no', $tradeNo, '0');
    ok('rollback rehearsal fixture prepared');

    export_database($pdo, $backupFile);
    $backupSize = filesize($backupFile);
    if ($backupSize === false || $backupSize <= 0) {
        fail('Rollback backup file is empty: ' . $backupFile);
    }
    ok('created rollback backup file: ' . $backupFile . ' (' . $backupSize . ' bytes)');

    $pdo->prepare('UPDATE ' . quote_identifier(table_name($prefix, 'config')) . ' SET `v`=? WHERE `k`=?')
        ->execute(array('after-broken-change', 'php84_rollback_marker'));
    $pdo->prepare('UPDATE ' . quote_identifier(table_name($prefix, 'user')) . ' SET `money`=? WHERE `uid`=?')
        ->execute(array('0.01', 1000));
    $pdo->prepare('UPDATE ' . quote_identifier(table_name($prefix, 'order')) . ' SET `status`=?, `api_trade_no`=? WHERE `trade_no`=?')
        ->execute(array(1, 'broken-gateway-order', $tradeNo));
    $pdo->prepare('DELETE FROM ' . quote_identifier(table_name($prefix, 'channel')) . ' WHERE `id`=?')
        ->execute(array($channelId));
    ok('simulated broken post-upgrade database state');

    assert_column_value($pdo, $prefix, 'config', 'v', 'k', 'php84_rollback_marker', 'after-broken-change');
    assert_column_value($pdo, $prefix, 'user', 'money', 'uid', 1000, '0.01');
    assert_column_value($pdo, $prefix, 'order', 'status', 'trade_no', $tradeNo, '1');

    $restoreCount = restore_database($pdo, $backupFile);
    ok('restored rollback backup statements: ' . $restoreCount);

    assert_column_value($pdo, $prefix, 'config', 'v', 'k', 'version', $dbVersion);
    assert_column_value($pdo, $prefix, 'config', 'v', 'k', 'php84_rollback_marker', 'before-rollback');
    assert_column_value($pdo, $prefix, 'user', 'money', 'uid', 1000, '99.88');
    assert_column_value($pdo, $prefix, 'order', 'status', 'trade_no', $tradeNo, '0');
    assert_column_value($pdo, $prefix, 'order', 'api_trade_no', 'trade_no', $tradeNo, '');
    if (!table_exists($pdo, table_name($prefix, 'channel'))) {
        fail('Channel table missing after restore');
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . quote_identifier(table_name($prefix, 'channel')) . ' WHERE `id`=? AND `plugin`=?');
    $stmt->execute(array($channelId, 'epay'));
    if ((int)$stmt->fetchColumn() !== 1) {
        fail('Rollback channel fixture was not restored');
    }
    ok('rollback rehearsal restored fixture data');
    ok('rollback rehearsal checks passed');
} catch (Exception $e) {
    fail($e->getMessage(), 2);
} finally {
    if ($created && !$keep) {
        try {
            $admin->exec('DROP DATABASE ' . quote_identifier($dbName));
            ok('dropped rollback rehearsal database: ' . $dbName);
        } catch (Exception $dropError) {
            fwrite(STDERR, '[WARN] unable to drop rollback rehearsal database ' . $dbName . ': ' . $dropError->getMessage() . PHP_EOL);
        }
    } elseif ($created) {
        ok('kept rollback rehearsal database: ' . $dbName);
    }

    if (!$keep && is_file($backupFile)) {
        unlink($backupFile);
    }
}
