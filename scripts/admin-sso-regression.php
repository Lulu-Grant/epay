#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') exit(1);

if (!defined('SYS_KEY')) define('SYS_KEY', 'admin-sso-regression-key');
require dirname(__DIR__).'/includes/lib/AdminSso.php';

use lib\AdminSso;
use lib\AdminSsoException;

function ssoAssert($actual, $expected, string $label): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, $label.' expected='.var_export($expected, true).' actual='.var_export($actual, true).PHP_EOL);
        exit(1);
    }
}

function ssoReject(callable $callback, string $label): string
{
    try {
        $callback();
    } catch (AdminSsoException $e) {
        return $e->getMessage();
    }
    fwrite(STDERR, $label.' was accepted'.PHP_EOL);
    exit(1);
}

final class SsoFakeStatement
{
    public function __construct(private int $count) {}
    public function rowCount(): int { return $this->count; }
}

final class SsoFakeDb
{
    public array $users = [];
    public array $tickets = [];
    public array $sessions = [];
    public array $audits = [];
    public bool $failAudit = false;
    public bool $failSessionInsert = false;
    private int $ticketId = 0;
    private int $auditId = 0;
    private ?array $snapshot = null;

    public function __construct()
    {
        $this->users[1001] = ['uid'=>1001, 'status'=>1, 'email'=>'fixture@example.com'];
        $this->users[1002] = ['uid'=>1002, 'status'=>0, 'email'=>'disabled@example.com'];
    }

    public function beginTransaction(): bool
    {
        if ($this->snapshot !== null) throw new RuntimeException('nested fake transaction');
        $this->snapshot = [$this->tickets, $this->sessions, $this->audits, $this->ticketId, $this->auditId];
        return true;
    }

    public function commit(): bool
    {
        $this->snapshot = null;
        return true;
    }

    public function rollBack(): bool
    {
        if ($this->snapshot !== null) {
            [$this->tickets, $this->sessions, $this->audits, $this->ticketId, $this->auditId] = $this->snapshot;
            $this->snapshot = null;
        }
        return true;
    }

    public function insert(string $table, array $data)
    {
        if ($table !== 'admin_sso_audit') throw new RuntimeException('unexpected fake insert '.$table);
        if ($this->failAudit) return false;
        $data['id'] = ++$this->auditId;
        $this->audits[] = $data;
        return $data['id'];
    }

    public function getRow(string $sql, array $params = [])
    {
        if (str_contains($sql, 'FROM pre_user')) {
            $uid = (int)($params[':uid'] ?? 0);
            return $this->users[$uid] ?? false;
        }
        if (str_contains($sql, 'FROM pre_admin_sso_ticket')) {
            $hash = (string)($params[':ticket_hash'] ?? '');
            return $this->tickets[$hash] ?? false;
        }
        if (str_contains($sql, 'FROM pre_admin_sso_session')) {
            $hash = (string)($params[':session_hash'] ?? '');
            return $this->sessions[$hash] ?? false;
        }
        throw new RuntimeException('unexpected fake select: '.$sql);
    }

    public function query(string $sql, array $params = [])
    {
        if (str_starts_with($sql, 'INSERT INTO pre_admin_sso_ticket')) {
            $hash = $params[':ticket_hash'];
            $this->tickets[$hash] = [
                'id'=>++$this->ticketId,
                'ticket_hash'=>$hash,
                'correlation_id'=>$params[':correlation_id'],
                'target_uid'=>(int)$params[':target_uid'],
                'issuer_hash'=>$params[':issuer_hash'],
                'issuer_version'=>$params[':issuer_version'],
                'flow_hash'=>$params[':flow_hash'],
                'audience'=>$params[':audience'],
                'nonce_hash'=>null,
                'status'=>0,
                'expires_at'=>$params[':expires_at'],
                'created_at'=>date('Y-m-d H:i:s'),
                'bound_at'=>null,
                'consumed_at'=>null,
            ];
            return new SsoFakeStatement(1);
        }
        if (str_starts_with($sql, 'UPDATE pre_admin_sso_ticket SET nonce_hash=')) {
            $hash = $params[':ticket_hash'];
            $row = $this->tickets[$hash] ?? null;
            $matches = $row && (int)$row['status'] === 0 && strtotime($row['expires_at']) > time()
                && hash_equals($row['issuer_version'], $params[':issuer_version'])
                && hash_equals($row['flow_hash'], $params[':flow_hash'])
                && hash_equals($row['audience'], $params[':audience']);
            if (!$matches) return new SsoFakeStatement(0);
            $this->tickets[$hash]['nonce_hash'] = $params[':nonce_hash'];
            $this->tickets[$hash]['status'] = 1;
            $this->tickets[$hash]['bound_at'] = date('Y-m-d H:i:s');
            return new SsoFakeStatement(1);
        }
        if (str_starts_with($sql, 'UPDATE pre_admin_sso_ticket SET status=2')) {
            foreach ($this->tickets as $hash=>$row) {
                if ((int)$row['id'] === (int)$params[':id'] && (int)$row['status'] === 1) {
                    $this->tickets[$hash]['status'] = 2;
                    $this->tickets[$hash]['consumed_at'] = date('Y-m-d H:i:s');
                    return new SsoFakeStatement(1);
                }
            }
            return new SsoFakeStatement(0);
        }
        if (str_starts_with($sql, 'INSERT INTO pre_admin_sso_session')) {
            if ($this->failSessionInsert) return false;
            $hash = $params[':session_hash'];
            $this->sessions[$hash] = [
                'id'=>count($this->sessions)+1,
                'session_hash'=>$hash,
                'correlation_id'=>$params[':correlation_id'],
                'uid'=>(int)$params[':uid'],
                'issuer_hash'=>$params[':issuer_hash'],
                'issuer_version'=>$params[':issuer_version'],
                'created_at'=>date('Y-m-d H:i:s'),
                'expires_at'=>$params[':expires_at'],
                'revoked_at'=>null,
            ];
            return new SsoFakeStatement(1);
        }
        if (str_starts_with($sql, 'UPDATE pre_admin_sso_ticket SET status=3 WHERE issuer_hash=')) {
            $count = 0;
            foreach ($this->tickets as $hash=>$row) {
                if (hash_equals($row['issuer_hash'], $params[':issuer_hash']) && in_array((int)$row['status'], [0,1], true)) {
                    $this->tickets[$hash]['status'] = 3;
                    $count++;
                }
            }
            return new SsoFakeStatement($count);
        }
        if (str_starts_with($sql, 'UPDATE pre_admin_sso_ticket SET status=3 WHERE target_uid=')) {
            $count = 0;
            foreach ($this->tickets as $hash=>$row) {
                if ((int)$row['target_uid'] === (int)$params[':uid'] && in_array((int)$row['status'], [0,1], true)) {
                    $this->tickets[$hash]['status'] = 3;
                    $count++;
                }
            }
            return new SsoFakeStatement($count);
        }
        if (str_starts_with($sql, 'UPDATE pre_admin_sso_session SET revoked_at=NOW() WHERE session_hash=')) {
            $hash = $params[':session_hash'];
            if (!isset($this->sessions[$hash]) || $this->sessions[$hash]['revoked_at'] !== null) return new SsoFakeStatement(0);
            $this->sessions[$hash]['revoked_at'] = date('Y-m-d H:i:s');
            return new SsoFakeStatement(1);
        }
        if (str_starts_with($sql, 'UPDATE pre_admin_sso_session SET revoked_at=NOW() WHERE issuer_hash=')) {
            $count = 0;
            foreach ($this->sessions as $hash=>$row) {
                if (hash_equals($row['issuer_hash'], $params[':issuer_hash']) && $row['revoked_at'] === null) {
                    $this->sessions[$hash]['revoked_at'] = date('Y-m-d H:i:s');
                    $count++;
                }
            }
            return new SsoFakeStatement($count);
        }
        if (str_starts_with($sql, 'UPDATE pre_admin_sso_session SET revoked_at=NOW() WHERE uid=')) {
            $count = 0;
            foreach ($this->sessions as $hash=>$row) {
                if ((int)$row['uid'] === (int)$params[':uid'] && $row['revoked_at'] === null) {
                    $this->sessions[$hash]['revoked_at'] = date('Y-m-d H:i:s');
                    $count++;
                }
            }
            return new SsoFakeStatement($count);
        }
        throw new RuntimeException('unexpected fake query: '.$sql);
    }
}

function ssoConfig(array $changes = []): array
{
    return array_replace([
        'admin_sso_enabled'=>'1',
        'admin_sso_admin_origin'=>'https://manage.example.test',
        'admin_sso_merchant_origin'=>'https://merchant.example.test',
        'admin_sso_admin_path'=>'/secure-admin/sso.php',
        'admin_sso_merchant_path'=>'/user/sso.php',
        'admin_user'=>'fixture-admin',
        'admin_pwd'=>'fixture-password-v1',
    ], $changes);
}

function ssoBoundFixture(?SsoFakeDb $db = null): array
{
    $db ??= new SsoFakeDb();
    $conf = ssoConfig();
    $adminToken = AdminSso::newOpaqueToken();
    $flow = AdminSso::newOpaqueToken();
    $handoff = AdminSso::prepare($db, $conf, 1001, $adminToken, $flow, time()+3600);
    $bootstrap = AdminSso::bootstrap($db, $conf, $handoff['ticket']);
    AdminSso::bind($db, $conf, $handoff['ticket'], $bootstrap['nonce_hash'], $flow);
    return [$db, $conf, $adminToken, $flow, $handoff, $bootstrap];
}

$conf = ssoConfig();
ssoAssert(AdminSso::origin($conf, 'admin'), 'https://manage.example.test', 'fixed admin origin');
ssoAssert(AdminSso::endpoint($conf, 'merchant'), 'https://merchant.example.test/user/sso.php', 'fixed merchant endpoint');
ssoAssert(AdminSso::cookiePath($conf, 'admin'), '/secure-admin/', 'admin cookie path');
ssoReject(fn()=>AdminSso::origin(ssoConfig(['admin_sso_admin_origin'=>'http://manage.example.test']), 'admin'), 'non-HTTPS origin');
ssoReject(fn()=>AdminSso::origin(ssoConfig(['admin_sso_admin_origin'=>'https://manage.example.test/path']), 'admin'), 'origin with path');
ssoReject(fn()=>AdminSso::path(ssoConfig(['admin_sso_admin_path'=>'/secure/../sso.php']), 'admin'), 'traversal path');
ssoAssert(AdminSso::SESSION_COOKIE !== 'user_token', true, 'ordinary merchant cookie is independent');

$db = new SsoFakeDb();
$adminToken = AdminSso::newOpaqueToken();
$flow = AdminSso::newOpaqueToken();
$handoff = AdminSso::prepare($db, $conf, 1001, $adminToken, $flow, time()+3600);
ssoAssert(AdminSso::validOpaqueToken($handoff['ticket']), true, 'opaque handoff shape');
$ticketHash = AdminSso::tokenHash($handoff['ticket']);
ssoAssert(isset($db->tickets[$ticketHash]), true, 'server stores ticket digest');
ssoAssert(str_contains(json_encode($db->tickets), $handoff['ticket']), false, 'raw ticket is not stored');
ssoAssert($db->tickets[$ticketHash]['flow_hash'], AdminSso::tokenHash($flow), 'browser flow digest');
ssoAssert($db->tickets[$ticketHash]['issuer_hash'], AdminSso::issuerHash($adminToken), 'issuer digest');
ssoAssert(count($db->audits), 1, 'creation is audited');

$bootstrap = AdminSso::bootstrap($db, $conf, $handoff['ticket']);
ssoAssert(AdminSso::validOpaqueToken($bootstrap['nonce']), true, 'merchant nonce shape');
ssoAssert($db->tickets[$ticketHash]['nonce_hash'], null, 'bootstrap cannot bind ticket itself');
ssoReject(fn()=>AdminSso::bind($db, $conf, $handoff['ticket'], $bootstrap['nonce_hash'], AdminSso::newOpaqueToken()), 'wrong browser flow');
ssoAssert($db->tickets[$ticketHash]['status'], 0, 'failed binding keeps ticket pending');
AdminSso::bind($db, $conf, $handoff['ticket'], $bootstrap['nonce_hash'], $flow);
ssoAssert($db->tickets[$ticketHash]['status'], 1, 'administrator binds merchant nonce once');
ssoReject(fn()=>AdminSso::bind($db, $conf, $handoff['ticket'], $bootstrap['nonce_hash'], $flow), 'second bind');

ssoReject(fn()=>AdminSso::consume($db, $conf, $handoff['ticket'], AdminSso::newOpaqueToken()), 'wrong merchant browser nonce');
ssoAssert($db->tickets[$ticketHash]['status'], 1, 'failed consume rolls ticket back');
$session = AdminSso::consume($db, $conf, $handoff['ticket'], $bootstrap['nonce']);
$sessionHash = AdminSso::tokenHash($session['session']);
ssoAssert(isset($db->sessions[$sessionHash]), true, 'server stores session digest');
ssoAssert(str_contains(json_encode($db->sessions), $session['session']), false, 'raw session is not stored');
ssoAssert($db->tickets[$ticketHash]['status'], 2, 'ticket consumed atomically');
ssoAssert(AdminSso::authenticate($db, $conf, $session['session'])['user']['uid'], 1001, 'active session authenticates target');
$sessionCount = count($db->sessions);
ssoReject(fn()=>AdminSso::consume($db, $conf, $handoff['ticket'], $bootstrap['nonce']), 'ticket replay');
ssoAssert(count($db->sessions), $sessionCount, 'replay cannot create another session');
ssoAssert(AdminSso::revokeSession($db, $session['session']), true, 'merchant can revoke session');
ssoAssert(AdminSso::authenticate($db, $conf, $session['session']), null, 'revoked session is rejected');

[$storageDb, $storageConf, $storageAdmin, $storageFlow, $storageTicket, $storageBootstrap] = ssoBoundFixture();
$storageHash = AdminSso::tokenHash($storageTicket['ticket']);
$storageDb->failSessionInsert = true;
ssoReject(fn()=>AdminSso::consume($storageDb, $storageConf, $storageTicket['ticket'], $storageBootstrap['nonce']), 'session storage failure');
ssoAssert($storageDb->tickets[$storageHash]['status'], 1, 'session storage failure rolls ticket back');
ssoAssert(count($storageDb->sessions), 0, 'session storage failure creates no session');

$auditDb = new SsoFakeDb();
$auditDb->failAudit = true;
ssoReject(fn()=>AdminSso::prepare($auditDb, $conf, 1001, $adminToken, $flow, time()+3600), 'audit storage failure');
ssoAssert(count($auditDb->tickets), 0, 'audit storage failure rolls ticket back');

$expiredDb = new SsoFakeDb();
$expired = AdminSso::prepare($expiredDb, $conf, 1001, $adminToken, $flow, time()+3600);
$expiredHash = AdminSso::tokenHash($expired['ticket']);
$expiredDb->tickets[$expiredHash]['expires_at'] = date('Y-m-d H:i:s', time()-1);
ssoReject(fn()=>AdminSso::bootstrap($expiredDb, $conf, $expired['ticket']), 'expired ticket');
ssoReject(fn()=>AdminSso::prepare(new SsoFakeDb(), ssoConfig(['admin_sso_enabled'=>'0']), 1001, $adminToken, $flow, time()+3600), 'disabled feature');
ssoReject(fn()=>AdminSso::prepare(new SsoFakeDb(), $conf, 1002, $adminToken, $flow, time()+3600), 'disabled target at prepare');
ssoReject(fn()=>AdminSso::prepare(new SsoFakeDb(), $conf, 1001, $adminToken, $flow, time()-1), 'expired administrator session');

[$disabledDb, $disabledConf, $disabledAdmin, $disabledFlow, $disabledTicket, $disabledBootstrap] = ssoBoundFixture();
$disabledDb->users[1001]['status'] = 0;
ssoReject(fn()=>AdminSso::consume($disabledDb, $disabledConf, $disabledTicket['ticket'], $disabledBootstrap['nonce']), 'disabled target at consume');
ssoAssert(count($disabledDb->sessions), 0, 'disabled target gets no session');

[$versionDb, $versionConf, $versionAdmin, $versionFlow, $versionTicket, $versionBootstrap] = ssoBoundFixture();
ssoReject(fn()=>AdminSso::consume($versionDb, ssoConfig(['admin_pwd'=>'fixture-password-v2']), $versionTicket['ticket'], $versionBootstrap['nonce']), 'changed administrator credential version');

[$issuerDb, $issuerConf, $issuerAdmin, $issuerFlow, $issuerTicket, $issuerBootstrap] = ssoBoundFixture();
$issuerSession = AdminSso::consume($issuerDb, $issuerConf, $issuerTicket['ticket'], $issuerBootstrap['nonce']);
AdminSso::revokeIssuer($issuerDb, $issuerAdmin);
ssoAssert(AdminSso::authenticate($issuerDb, $issuerConf, $issuerSession['session']), null, 'administrator logout revokes issued sessions');

[$uidDb, $uidConf, $uidAdmin, $uidFlow, $uidTicket, $uidBootstrap] = ssoBoundFixture();
$uidSession = AdminSso::consume($uidDb, $uidConf, $uidTicket['ticket'], $uidBootstrap['nonce']);
AdminSso::revokeUid($uidDb, 1001, $uidAdmin);
ssoAssert(AdminSso::authenticate($uidDb, $uidConf, $uidSession['session']), null, 'target revocation closes active session');

echo "admin sso regression: ok\n";
