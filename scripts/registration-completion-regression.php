#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') exit(1);

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SERVER['HTTP_HOST'] = 'regression.invalid';

require dirname(__DIR__).'/includes/lib/EmailAddress.php';
require dirname(__DIR__).'/includes/functions.php';

function registrationAssert($actual, $expected, string $label): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, $label.' expected='.var_export($expected, true).' actual='.var_export($actual, true).PHP_EOL);
        exit(1);
    }
}

final class RegistrationFakeStatement
{
    public function __construct(private int $affectedRows) {}
    public function rowCount(): int { return $this->affectedRows; }
}

final class RegistrationFakeCache
{
    public array $values = [];
    public array $deleted = [];

    public function read(string $key)
    {
        return $this->values[$key] ?? false;
    }

    public function delete(string $key): bool
    {
        $this->deleted[] = $key;
        unset($this->values[$key]);
        return true;
    }
}

final class RegistrationFakeDb
{
    public array $users = [
        10 => ['uid'=>10, 'money'=>100.00, 'email'=>'', 'phone'=>''],
    ];
    public array $completions = [];
    public array $records = [];
    public array $inviteCodes = [];
    public bool $failUserInsert = false;
    private int $nextUid = 1001;
    private ?string $snapshot = null;

    public function beginTransaction(): bool
    {
        if ($this->snapshot !== null) return false;
        $this->snapshot = serialize([
            $this->users,
            $this->completions,
            $this->records,
            $this->inviteCodes,
            $this->nextUid,
        ]);
        return true;
    }

    public function commit(): bool
    {
        if ($this->snapshot === null) return false;
        $this->snapshot = null;
        return true;
    }

    public function rollBack(): bool
    {
        if ($this->snapshot === null) return false;
        [$this->users, $this->completions, $this->records, $this->inviteCodes, $this->nextUid] = unserialize($this->snapshot, ['allowed_classes'=>false]);
        $this->snapshot = null;
        return true;
    }

    public function query(string $sql, array $params = [])
    {
        if (str_contains($sql, 'INSERT IGNORE INTO pre_registration_completion')) {
            $tradeNo = (string)$params[':trade_no'];
            if (isset($this->completions[$tradeNo])) return new RegistrationFakeStatement(0);
            $this->completions[$tradeNo] = ['trade_no'=>$tradeNo, 'uid'=>null, 'status'=>0];
            return new RegistrationFakeStatement(1);
        }
        if (str_contains($sql, 'UPDATE pre_user SET money=')) {
            $uid = (int)$params[':uid'];
            if (!isset($this->users[$uid])) return new RegistrationFakeStatement(0);
            $this->users[$uid]['money'] = (float)$params[':money'];
            return new RegistrationFakeStatement(1);
        }
        if (str_contains($sql, 'UPDATE pre_user SET pwd=')) {
            $uid = (int)$params[':uid'];
            if (!isset($this->users[$uid])) return new RegistrationFakeStatement(0);
            $this->users[$uid]['pwd'] = (string)$params[':pwd'];
            return new RegistrationFakeStatement(1);
        }
        if (str_contains($sql, 'UPDATE pre_registration_completion SET')) {
            $tradeNo = (string)$params[':trade_no'];
            if (!isset($this->completions[$tradeNo]) || $this->completions[$tradeNo]['status'] !== 0) {
                return new RegistrationFakeStatement(0);
            }
            $this->completions[$tradeNo]['uid'] = (int)$params[':uid'];
            $this->completions[$tradeNo]['status'] = 1;
            return new RegistrationFakeStatement(1);
        }
        return false;
    }

    public function getRow(string $sql, array $params = [])
    {
        if (str_contains($sql, 'FROM pre_registration_completion')) {
            return $this->completions[(string)$params[':trade_no']] ?? null;
        }
        if (str_contains($sql, 'FROM pre_user WHERE email=')) {
            foreach ($this->users as $row) if (($row['email'] ?? '') === $params[':email']) return ['uid'=>$row['uid']];
            return null;
        }
        if (str_contains($sql, 'FROM pre_user WHERE phone=')) {
            foreach ($this->users as $row) if (($row['phone'] ?? '') === $params[':phone']) return ['uid'=>$row['uid']];
            return null;
        }
        return null;
    }

    public function getColumn(string $sql, array $params = [])
    {
        if (str_contains($sql, 'information_schema.COLUMNS')) return 254;
        if (str_contains($sql, 'SELECT money FROM pre_user')) return $this->users[(int)$params[':uid']]['money'] ?? null;
        if (str_contains($sql, 'SELECT email FROM pre_user')) return $this->users[(int)$params[':uid']]['email'] ?? null;
        return false;
    }

    public function insert(string $table, array $data)
    {
        if ($table === 'record') {
            $this->records[] = $data;
            return count($this->records);
        }
        if ($table === 'user') {
            if ($this->failUserInsert) return false;
            $uid = $this->nextUid++;
            $this->users[$uid] = array_merge(['uid'=>$uid], $data);
            return $uid;
        }
        return false;
    }

    public function update(string $table, array $data, array $where): bool
    {
        if ($table !== 'invitecode') return false;
        $this->inviteCodes[(int)$where['id']] = $data;
        return true;
    }
}

function registrationFixture(string $tradeNo): array
{
    $cache = new RegistrationFakeCache();
    $cache->values['reg_'.$tradeNo] = serialize([
        'verifytype'=>1,
        'email'=>'merchant.long-address@example.com',
        'phone'=>'',
        'pwd'=>'test-password',
        'upid'=>0,
    ]);
    return [new RegistrationFakeDb(), $cache];
}

$conf = [
    'user_review'=>0,
    'sitename'=>'回归测试',
    'mail_cloud'=>0,
    'mail_name'=>'',
    'mail_port'=>'',
    'mail_smtp'=>'',
    'mail_pwd'=>'',
];

$tradeNo = '2026092300000000001';
[$DB, $CACHE] = registrationFixture($tradeNo);
$order = ['trade_no'=>$tradeNo, 'getmoney'=>12.50, 'uid'=>10];
$firstUid = completePaidRegistration($order);
$secondUid = completePaidRegistration($order);

registrationAssert($firstUid, 1001, 'first completion uid');
registrationAssert($secondUid, $firstUid, 'duplicate callback returns original uid');
registrationAssert(count($DB->users), 2, 'duplicate callback creates one merchant');
registrationAssert($DB->users[10]['money'], 112.50, 'duplicate callback credits payer once');
registrationAssert(count($DB->records), 1, 'duplicate callback creates one ledger entry');
registrationAssert($DB->completions[$tradeNo]['status'], 1, 'completion is durable');
registrationAssert($DB->completions[$tradeNo]['uid'], $firstUid, 'completion stores merchant uid');
registrationAssert($CACHE->deleted, ['reg_'.$tradeNo], 'registration cache deleted once after commit');

$failedTradeNo = '2026092300000000002';
[$DB, $CACHE] = registrationFixture($failedTradeNo);
$DB->failUserInsert = true;
try {
    completePaidRegistration(['trade_no'=>$failedTradeNo, 'getmoney'=>8.00, 'uid'=>10]);
    fwrite(STDERR, "failed registration unexpectedly completed\n");
    exit(1);
} catch (RuntimeException $e) {
    registrationAssert(str_contains($e->getMessage(), '创建失败'), true, 'failure reason');
}
registrationAssert(count($DB->users), 1, 'failed completion creates no merchant');
registrationAssert($DB->users[10]['money'], 100.00, 'failed completion rolls back payer credit');
registrationAssert(count($DB->records), 0, 'failed completion rolls back ledger entry');
registrationAssert(isset($DB->completions[$failedTradeNo]), false, 'failed completion rolls back idempotency marker');
registrationAssert(isset($CACHE->values['reg_'.$failedTradeNo]), true, 'failed completion keeps retry data');

echo "registration completion regression: ok\n";
