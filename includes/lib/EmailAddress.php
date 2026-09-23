<?php
namespace lib;

use InvalidArgumentException;
use RuntimeException;

/** Shared validation and storage guards for merchant email identities. */
class EmailAddress
{
    public const MAX_BYTES = 254;
    public const MAX_LOCAL_BYTES = 64;
    private const LEGACY_FALLBACK_BYTES = 32;

    private static array $capacityCache = [];

    public static function normalize($value, bool $allowEmpty = false): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            throw new InvalidArgumentException('邮箱格式不正确');
        }

        $email = trim((string)$value);
        if ($email === '') {
            if ($allowEmpty) return '';
            throw new InvalidArgumentException('邮箱不能为空');
        }
        if (strlen($email) > self::MAX_BYTES) {
            throw new InvalidArgumentException('邮箱最多 254 个 ASCII 字节');
        }
        if (preg_match('/[^\x21-\x7E]/', $email)) {
            throw new InvalidArgumentException('邮箱暂仅支持 ASCII 字符，不能包含空格或控制字符');
        }

        $at = strrpos($email, '@');
        if ($at === false || $at === 0 || strlen(substr($email, 0, $at)) > self::MAX_LOCAL_BYTES ||
            filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('邮箱格式不正确');
        }
        return $email;
    }

    /**
     * Fail closed before MySQL can silently truncate a value. Logical columns
     * use names such as user.email and regcode.to.
     */
    public static function assertStorageCapacity($db, string $email, array $logicalColumns): void
    {
        if ($email === '') return;
        $bytes = strlen($email);
        foreach ($logicalColumns as $logicalColumn) {
            $parts = explode('.', (string)$logicalColumn, 2);
            if (count($parts) !== 2 || !preg_match('/^[a-z0-9_]+$/i', $parts[0]) || !preg_match('/^[a-z0-9_]+$/i', $parts[1])) {
                throw new RuntimeException('邮箱存储检查配置错误');
            }
            $capacity = self::columnCapacity($db, $parts[0], $parts[1]);
            if ($bytes > $capacity) {
                throw new RuntimeException('当前数据库邮箱字段最多支持 '.$capacity.' 字节，请先完成扩容迁移后重试');
            }
        }
    }

    public static function assertUserStored($db, int $uid, string $email): void
    {
        $stored = $db->getColumn('SELECT email FROM pre_user WHERE uid=:uid LIMIT 1', [':uid'=>$uid]);
        if (!is_string($stored) || !hash_equals($email, $stored)) {
            throw new RuntimeException('邮箱写入完整性校验失败，已取消本次保存');
        }
    }

    public static function assertRegcodeStored($db, int $id, string $email): void
    {
        $stored = $db->getColumn('SELECT `to` FROM pre_regcode WHERE id=:id LIMIT 1', [':id'=>$id]);
        if (!is_string($stored) || !hash_equals($email, $stored)) {
            throw new RuntimeException('验证码收件人写入完整性校验失败');
        }
    }

    /** Atomically update a user row and verify that MySQL kept the email bytes. */
    public static function updateUser($db, int $uid, $email, array $data = [], bool $allowEmpty = false): string
    {
        $email = self::normalize($email, $allowEmpty);
        self::assertStorageCapacity($db, $email, ['user.email']);
        $data['email'] = $email;

        if (!$db->beginTransaction()) throw new RuntimeException('无法开始邮箱保存事务');
        try {
            if ($db->update('user', $data, ['uid'=>$uid]) === false) {
                throw new RuntimeException('保存邮箱失败');
            }
            self::assertUserStored($db, $uid, $email);
            if (!$db->commit()) throw new RuntimeException('提交邮箱保存事务失败');
            return $email;
        } catch (\Throwable $e) {
            try { $db->rollBack(); } catch (\Throwable $ignored) {}
            if ($e instanceof RuntimeException || $e instanceof InvalidArgumentException) throw $e;
            throw new RuntimeException('保存邮箱失败', 0, $e);
        }
    }

    public static function clearCapacityCache(): void
    {
        self::$capacityCache = [];
    }

    private static function columnCapacity($db, string $logicalTable, string $column): int
    {
        $table = (defined('DBQZ') ? DBQZ.'_' : 'pre_').$logicalTable;
        $objectId = is_object($db) ? spl_object_id($db) : 0;
        $cacheKey = $objectId.':'.$table.'.'.$column;
        if (isset(self::$capacityCache[$cacheKey])) return self::$capacityCache[$cacheKey];

        $capacity = $db->getColumn(
            'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND COLUMN_NAME=:column LIMIT 1',
            [':table'=>$table, ':column'=>$column]
        );
        if ($capacity === false || $capacity === null || !is_numeric($capacity) || (int)$capacity < 1) {
            $capacity = self::LEGACY_FALLBACK_BYTES;
        }
        self::$capacityCache[$cacheKey] = (int)$capacity;
        return self::$capacityCache[$cacheKey];
    }
}
