<?php
namespace lib\Shop;

use Exception;

class ConfigService
{
    const FLOW_CHECKOUT = 'checkout';
    const FLOW_SHADOW = 'shadow';

    private static $configCache;

    public static function defaults()
    {
        return array(
            'shop_status' => '0',
            'shop_flow_mode' => self::FLOW_CHECKOUT,
            'shop_shadow_started_at' => '',
            'shop_name' => '商城',
            'shop_desc' => '',
            'shop_excluded_uids' => '',
            'shop_query_verify' => '1',
        );
    }

    public static function all()
    {
        global $DB;
        if (is_array(self::$configCache)) {
            return self::$configCache;
        }
        $config = self::defaults();
        $rows = $DB->getAll("SELECT `k`,`v` FROM pre_shop_config");
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $config[$row['k']] = $row['v'];
            }
        }
        self::$configCache = $config;
        return self::$configCache;
    }

    public static function get($key, $default = null)
    {
        $config = self::all();
        if (array_key_exists($key, $config)) {
            return $config[$key];
        }
        return $default;
    }

    public static function isEnabled()
    {
        return intval(self::get('shop_status', 0)) === 1;
    }

    public static function getFlowMode()
    {
        $mode = trim((string)self::get('shop_flow_mode', self::FLOW_CHECKOUT));
        return $mode === self::FLOW_SHADOW ? self::FLOW_SHADOW : self::FLOW_CHECKOUT;
    }

    public static function isShadowMode()
    {
        return self::getFlowMode() === self::FLOW_SHADOW;
    }

    public static function getExcludedUids()
    {
        $value = trim((string)self::get('shop_excluded_uids', ''));
        if ($value === '') {
            return array();
        }
        $uids = array();
        foreach (preg_split('/[,;|，\s]+/', $value) as $uid) {
            $uid = intval($uid);
            if ($uid > 0) {
                $uids[$uid] = $uid;
            }
        }
        return array_values($uids);
    }

    public static function isMerchantExcluded($uid)
    {
        return in_array(intval($uid), self::getExcludedUids(), true);
    }

    public static function shouldRecordMerchant($uid)
    {
        $uid = intval($uid);
        return $uid > 0 && self::isEnabled() && !self::isMerchantExcluded($uid);
    }

    public static function shouldUseCheckout($uid)
    {
        return self::shouldRecordMerchant($uid) && self::getFlowMode() === self::FLOW_CHECKOUT;
    }

    public static function shouldWrapMerchant($uid)
    {
        return self::shouldUseCheckout($uid);
    }

    public static function assertReady()
    {
        if (!self::isEnabled()) {
            throw new Exception('商城暂未开启');
        }
        return true;
    }

    public static function save($data)
    {
        global $DB;
        $defaults = self::defaults();
        $current = self::all();
        $config = array();
        foreach ($defaults as $key => $default) {
            if (isset($data[$key])) {
                $config[$key] = trim((string)$data[$key]);
            } elseif (array_key_exists($key, $current)) {
                $config[$key] = (string)$current[$key];
            } else {
                $config[$key] = $default;
            }
        }

        $config['shop_status'] = isset($config['shop_status']) && intval($config['shop_status']) === 1 ? '1' : '0';
        $flowMode = isset($config['shop_flow_mode']) ? trim((string)$config['shop_flow_mode']) : self::FLOW_CHECKOUT;
        if (!in_array($flowMode, array(self::FLOW_CHECKOUT, self::FLOW_SHADOW), true)) {
            throw new Exception('商城流程模式不合法');
        }
        $config['shop_flow_mode'] = $flowMode;
        $startedAt = isset($config['shop_shadow_started_at']) ? trim((string)$config['shop_shadow_started_at']) : '';
        if ($startedAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $startedAt)) {
            throw new Exception('无感模式起始时间格式不正确');
        }
        $currentMode = isset($current['shop_flow_mode']) && $current['shop_flow_mode'] === self::FLOW_SHADOW ? self::FLOW_SHADOW : self::FLOW_CHECKOUT;
        if ($flowMode === self::FLOW_SHADOW && ($startedAt === '' || $currentMode !== self::FLOW_SHADOW)) {
            $startedAt = date('Y-m-d H:i:s');
        }
        $config['shop_shadow_started_at'] = $startedAt;
        // Order lookup protection is mandatory for the first release.
        $config['shop_query_verify'] = '1';
        $config['shop_name'] = isset($config['shop_name']) && $config['shop_name'] !== '' ? mb_substr($config['shop_name'], 0, 60, 'UTF-8') : '商城';
        $config['shop_desc'] = isset($config['shop_desc']) ? mb_substr($config['shop_desc'], 0, 255, 'UTF-8') : '';
        $excluded = isset($config['shop_excluded_uids']) ? trim($config['shop_excluded_uids']) : '';
        $uids = array();
        if ($excluded !== '') {
            foreach (preg_split('/[,;|，\s]+/', $excluded) as $uid) {
                if ($uid === '' || !ctype_digit($uid) || intval($uid) <= 0) {
                    throw new Exception('排除商户 UID 只能填写正整数，并使用逗号或换行分隔');
                }
                $uids[intval($uid)] = intval($uid);
            }
        }
        if (count($uids) > 1000) {
            throw new Exception('排除商户 UID 不能超过1000个');
        }
        $config['shop_excluded_uids'] = implode(',', array_values($uids));

        foreach ($config as $key => $value) {
            $ok = $DB->exec("INSERT INTO pre_shop_config (`k`,`v`,`remark`,`addtime`,`updatetime`) VALUES (:k,:v,'',NOW(),NOW()) ON DUPLICATE KEY UPDATE `v`=:v2,`updatetime`=NOW()", array(':k' => $key, ':v' => $value, ':v2' => $value));
            if ($ok === false) {
                throw new Exception('保存商城配置失败：'.$DB->error());
            }
        }

        self::$configCache = $config;
        return $config;
    }
}
