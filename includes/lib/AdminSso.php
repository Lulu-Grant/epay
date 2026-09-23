<?php
namespace lib;

use RuntimeException;

class AdminSsoException extends RuntimeException
{
}

/** One-time, cross-origin administrator handoff with revocable merchant sessions. */
class AdminSso
{
    public const TICKET_TTL = 60;
    public const SESSION_TTL = 900;
    public const SESSION_COOKIE = 'admin_sso_session';
    public const NONCE_COOKIE = 'admin_sso_nonce';
    public const FLOW_COOKIE = 'admin_sso_flow';

    public static function enabled(array $conf): bool
    {
        return isset($conf['admin_sso_enabled']) && (string)$conf['admin_sso_enabled'] === '1';
    }

    public static function origin(array $conf, string $side): string
    {
        $key = $side === 'admin' ? 'admin_sso_admin_origin' : 'admin_sso_merchant_origin';
        $origin = rtrim(trim((string)($conf[$key] ?? '')), '/');
        $parts = parse_url($origin);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) ||
            (isset($parts['path']) && $parts['path'] !== '')) {
            throw new AdminSsoException('管理员代登录固定域名未正确配置');
        }
        return 'https://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.(int)$parts['port'] : '');
    }

    public static function path(array $conf, string $side): string
    {
        $key = $side === 'admin' ? 'admin_sso_admin_path' : 'admin_sso_merchant_path';
        $default = $side === 'admin' ? '/admin/sso.php' : '/user/sso.php';
        $path = trim((string)($conf[$key] ?? $default));
        if (!preg_match('#^/[A-Za-z0-9_./-]+$#D', $path) || str_contains($path, '//') || str_contains($path, '..')) {
            throw new AdminSsoException('管理员代登录固定路径未正确配置');
        }
        return $path;
    }

    public static function endpoint(array $conf, string $side): string
    {
        return self::origin($conf, $side).self::path($conf, $side);
    }

    public static function cookiePath(array $conf, string $side): string
    {
        $path = self::path($conf, $side);
        $directory = str_replace('\\', '/', dirname($path));
        return $directory === '/' ? '/' : rtrim($directory, '/').'/';
    }

    public static function currentOrigin(): string
    {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if (!preg_match('/^[a-z0-9.-]+(?::[0-9]{1,5})?$/D', $host)) return '';
        $secure = function_exists('is_https') ? is_https() : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        return ($secure ? 'https://' : 'http://').$host;
    }

    public static function requestOriginMatches(string $expected): bool
    {
        $origin = rtrim(strtolower((string)($_SERVER['HTTP_ORIGIN'] ?? '')), '/');
        return $origin !== '' && hash_equals(strtolower($expected), $origin);
    }

    public static function newOpaqueToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function validOpaqueToken($token): bool
    {
        return is_string($token) && preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) === 1;
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function issuerHash(string $adminToken): string
    {
        return hash_hmac('sha256', $adminToken, SYS_KEY);
    }

    public static function issuerVersion(array $conf): string
    {
        return hash_hmac('sha256', (string)($conf['admin_user'] ?? '')."\0".(string)($conf['admin_pwd'] ?? ''), SYS_KEY);
    }

    public static function prepare($db, array $conf, int $uid, string $adminToken, string $flowToken, int $issuerExpiresAt): array
    {
        self::assertEnabled($conf);
        if (!self::validOpaqueToken($flowToken)) throw new AdminSsoException('代登录流程绑定无效');
        if ($issuerExpiresAt <= time()) throw new AdminSsoException('管理员会话已失效，请重新登录后再试');
        $user = $db->getRow('SELECT uid,status FROM pre_user WHERE uid=:uid LIMIT 1', [':uid'=>$uid]);
        if (!$user) throw new AdminSsoException('目标商户不存在');
        if ((int)$user['status'] !== 1) throw new AdminSsoException('目标商户已停用，不能代登录');

        $ticket = self::newOpaqueToken();
        $correlation = bin2hex(random_bytes(8));
        $issuerHash = self::issuerHash($adminToken);
        if (!$db->beginTransaction()) throw new AdminSsoException('无法开始代登录签发事务');
        try {
            $stmt = $db->query(
                'INSERT INTO pre_admin_sso_ticket (ticket_hash,correlation_id,target_uid,issuer_hash,issuer_version,flow_hash,audience,status,expires_at,created_at) VALUES (:ticket_hash,:correlation_id,:target_uid,:issuer_hash,:issuer_version,:flow_hash,:audience,0,:expires_at,NOW())',
                [
                    ':ticket_hash'=>self::tokenHash($ticket),
                    ':correlation_id'=>$correlation,
                    ':target_uid'=>$uid,
                    ':issuer_hash'=>$issuerHash,
                    ':issuer_version'=>self::issuerVersion($conf),
                    ':flow_hash'=>self::tokenHash($flowToken),
                    ':audience'=>self::origin($conf, 'merchant'),
                    ':expires_at'=>date('Y-m-d H:i:s', min(time()+self::TICKET_TTL, $issuerExpiresAt)),
                ]
            );
            if ($stmt === false || $stmt->rowCount() !== 1) throw new AdminSsoException('创建代登录交接票据失败');
            self::audit($db, $correlation, $issuerHash, $uid, 'ticket_created', 'ok', true);
            if (!$db->commit()) throw new AdminSsoException('提交代登录签发事务失败');
            return ['ticket'=>$ticket, 'correlation_id'=>$correlation, 'uid'=>$uid];
        } catch (\Throwable $e) {
            try { $db->rollBack(); } catch (\Throwable $ignored) {}
            throw $e;
        }
    }

    public static function bootstrap($db, array $conf, string $ticket): array
    {
        self::assertEnabled($conf);
        self::assertTicketShape($ticket);
        $row = $db->getRow('SELECT correlation_id,target_uid,audience,status,expires_at FROM pre_admin_sso_ticket WHERE ticket_hash=:ticket_hash LIMIT 1', [':ticket_hash'=>self::tokenHash($ticket)]);
        self::assertPendingTicket($row, $conf);
        $user = $db->getRow('SELECT uid,status FROM pre_user WHERE uid=:uid LIMIT 1', [':uid'=>$row['target_uid']]);
        if (!$user || (int)$user['status'] !== 1) throw new AdminSsoException('目标商户已停用或不存在');
        $nonce = self::newOpaqueToken();
        return ['nonce'=>$nonce, 'nonce_hash'=>self::tokenHash($nonce), 'uid'=>(int)$row['target_uid'], 'correlation_id'=>$row['correlation_id']];
    }

    public static function bind($db, array $conf, string $ticket, string $nonceHash, string $flowToken): array
    {
        self::assertEnabled($conf);
        self::assertTicketShape($ticket);
        if (!preg_match('/^[a-f0-9]{64}$/D', $nonceHash) || !self::validOpaqueToken($flowToken)) throw new AdminSsoException('代登录浏览器绑定无效');
        $ticketHash = self::tokenHash($ticket);
        if (!$db->beginTransaction()) throw new AdminSsoException('无法开始代登录绑定事务');
        try {
            $stmt = $db->query(
                'UPDATE pre_admin_sso_ticket SET nonce_hash=:nonce_hash,status=1,bound_at=NOW() WHERE ticket_hash=:ticket_hash AND status=0 AND expires_at>NOW() AND issuer_version=:issuer_version AND flow_hash=:flow_hash AND audience=:audience',
                [
                    ':nonce_hash'=>$nonceHash,
                    ':ticket_hash'=>$ticketHash,
                    ':issuer_version'=>self::issuerVersion($conf),
                    ':flow_hash'=>self::tokenHash($flowToken),
                    ':audience'=>self::origin($conf, 'merchant'),
                ]
            );
            if ($stmt === false || $stmt->rowCount() !== 1) throw new AdminSsoException('代登录交接已过期、已绑定或与当前管理员不匹配');
            $row = $db->getRow('SELECT correlation_id,target_uid,issuer_hash FROM pre_admin_sso_ticket WHERE ticket_hash=:ticket_hash LIMIT 1', [':ticket_hash'=>$ticketHash]);
            if (!$row) throw new AdminSsoException('代登录交接记录不可用');
            self::audit($db, $row['correlation_id'], $row['issuer_hash'], (int)$row['target_uid'], 'ticket_bound', 'ok', true);
            if (!$db->commit()) throw new AdminSsoException('提交代登录绑定事务失败');
            return ['uid'=>(int)$row['target_uid'], 'correlation_id'=>$row['correlation_id']];
        } catch (\Throwable $e) {
            try { $db->rollBack(); } catch (\Throwable $ignored) {}
            throw $e;
        }
    }

    public static function consume($db, array $conf, string $ticket, string $nonce): array
    {
        self::assertEnabled($conf);
        self::assertTicketShape($ticket);
        if (!self::validOpaqueToken($nonce)) throw new AdminSsoException('代登录浏览器绑定已失效');
        if (!$db->beginTransaction()) throw new AdminSsoException('无法开始代登录消费事务');
        try {
            $ticketHash = self::tokenHash($ticket);
            $row = $db->getRow('SELECT * FROM pre_admin_sso_ticket WHERE ticket_hash=:ticket_hash FOR UPDATE', [':ticket_hash'=>$ticketHash]);
            if (!$row || (int)$row['status'] !== 1 || strtotime((string)$row['expires_at']) <= time()) throw new AdminSsoException('代登录交接已过期或已使用');
            if (!hash_equals((string)$row['audience'], self::origin($conf, 'merchant')) ||
                !hash_equals((string)$row['issuer_version'], self::issuerVersion($conf)) ||
                !is_string($row['nonce_hash']) || !hash_equals((string)$row['nonce_hash'], self::tokenHash($nonce))) {
                throw new AdminSsoException('代登录交接目标或浏览器绑定不匹配');
            }
            $user = $db->getRow('SELECT * FROM pre_user WHERE uid=:uid LIMIT 1', [':uid'=>$row['target_uid']]);
            if (!$user || (int)$user['status'] !== 1) throw new AdminSsoException('目标商户已停用或不存在');

            $stmt = $db->query('UPDATE pre_admin_sso_ticket SET status=2,consumed_at=NOW() WHERE id=:id AND status=1', [':id'=>$row['id']]);
            if ($stmt === false || $stmt->rowCount() !== 1) throw new AdminSsoException('代登录交接已被其他请求使用');

            $session = self::newOpaqueToken();
            $expiresAt = date('Y-m-d H:i:s', time()+self::SESSION_TTL);
            $stmt = $db->query(
                'INSERT INTO pre_admin_sso_session (session_hash,correlation_id,uid,issuer_hash,issuer_version,created_at,expires_at) VALUES (:session_hash,:correlation_id,:uid,:issuer_hash,:issuer_version,NOW(),:expires_at)',
                [':session_hash'=>self::tokenHash($session), ':correlation_id'=>$row['correlation_id'], ':uid'=>$row['target_uid'], ':issuer_hash'=>$row['issuer_hash'], ':issuer_version'=>$row['issuer_version'], ':expires_at'=>$expiresAt]
            );
            if ($stmt === false || $stmt->rowCount() !== 1) throw new AdminSsoException('创建代登录会话失败');
            self::audit($db, $row['correlation_id'], $row['issuer_hash'], (int)$row['target_uid'], 'ticket_consumed', 'ok', true);
            if (!$db->commit()) throw new AdminSsoException('提交代登录消费事务失败');
            return ['session'=>$session, 'uid'=>(int)$row['target_uid'], 'expires_at'=>$expiresAt, 'correlation_id'=>$row['correlation_id']];
        } catch (\Throwable $e) {
            try { $db->rollBack(); } catch (\Throwable $ignored) {}
            throw $e;
        }
    }

    public static function authenticate($db, array $conf, $sessionToken): ?array
    {
        if (!self::enabled($conf) || !self::validOpaqueToken($sessionToken)) return null;
        $row = $db->getRow('SELECT * FROM pre_admin_sso_session WHERE session_hash=:session_hash LIMIT 1', [':session_hash'=>self::tokenHash($sessionToken)]);
        if (!$row || !empty($row['revoked_at']) || strtotime((string)$row['expires_at']) <= time() ||
            !hash_equals((string)$row['issuer_version'], self::issuerVersion($conf))) return null;
        $user = $db->getRow('SELECT * FROM pre_user WHERE uid=:uid LIMIT 1', [':uid'=>$row['uid']]);
        if (!$user || (int)$user['status'] !== 1) return null;
        return ['session'=>$row, 'user'=>$user];
    }

    public static function revokeSession($db, $sessionToken, string $result = 'logout'): bool
    {
        if (!self::validOpaqueToken($sessionToken)) return false;
        $row = $db->getRow('SELECT correlation_id,uid,issuer_hash FROM pre_admin_sso_session WHERE session_hash=:session_hash LIMIT 1', [':session_hash'=>self::tokenHash($sessionToken)]);
        if (!$row) return false;
        $stmt = $db->query('UPDATE pre_admin_sso_session SET revoked_at=NOW() WHERE session_hash=:session_hash AND revoked_at IS NULL', [':session_hash'=>self::tokenHash($sessionToken)]);
        if ($stmt !== false && $stmt->rowCount() === 1) self::audit($db, $row['correlation_id'], $row['issuer_hash'], (int)$row['uid'], 'session_revoked', $result, false);
        return $stmt !== false;
    }

    public static function revokeIssuer($db, string $adminToken): void
    {
        if ($adminToken === '') return;
        $issuerHash = self::issuerHash($adminToken);
        $db->query('UPDATE pre_admin_sso_ticket SET status=3 WHERE issuer_hash=:issuer_hash AND status IN (0,1)', [':issuer_hash'=>$issuerHash]);
        $db->query('UPDATE pre_admin_sso_session SET revoked_at=NOW() WHERE issuer_hash=:issuer_hash AND revoked_at IS NULL', [':issuer_hash'=>$issuerHash]);
        self::audit($db, null, $issuerHash, null, 'issuer_revoked', 'logout', false);
    }

    public static function revokeUid($db, int $uid, string $adminToken): void
    {
        $issuerHash = self::issuerHash($adminToken);
        $db->query('UPDATE pre_admin_sso_ticket SET status=3 WHERE target_uid=:uid AND status IN (0,1)', [':uid'=>$uid]);
        $db->query('UPDATE pre_admin_sso_session SET revoked_at=NOW() WHERE uid=:uid AND revoked_at IS NULL', [':uid'=>$uid]);
        self::audit($db, null, $issuerHash, $uid, 'target_revoked', 'admin', false);
    }

    private static function assertEnabled(array $conf): void
    {
        if (!self::enabled($conf)) throw new AdminSsoException('管理员代登录尚未启用');
        self::origin($conf, 'admin');
        self::origin($conf, 'merchant');
        self::path($conf, 'admin');
        self::path($conf, 'merchant');
    }

    private static function assertTicketShape($ticket): void
    {
        if (!self::validOpaqueToken($ticket)) throw new AdminSsoException('代登录交接票据无效');
    }

    private static function assertPendingTicket($row, array $conf): void
    {
        if (!$row || (int)$row['status'] !== 0 || strtotime((string)$row['expires_at']) <= time()) throw new AdminSsoException('代登录交接已过期或已使用');
        if (!hash_equals((string)$row['audience'], self::origin($conf, 'merchant'))) throw new AdminSsoException('代登录目标域名不匹配');
    }

    private static function audit($db, ?string $correlation, ?string $issuerHash, ?int $uid, string $event, string $result, bool $strict): void
    {
        $ok = $db->insert('admin_sso_audit', [
            'correlation_id'=>$correlation,
            'issuer_hash'=>$issuerHash,
            'uid'=>$uid,
            'event'=>$event,
            'result'=>$result,
            'created_at'=>'NOW()',
        ]);
        if ($strict && $ok === false) throw new AdminSsoException('写入代登录审计记录失败');
    }
}
