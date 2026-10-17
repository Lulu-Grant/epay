<?php
namespace lib;

/** A single normalized contract for both SQL bindings and aggregate cache keys. */
final class ListQueryFilter
{
    public string $where;
    public array $bind = [];
    public string $searchField = '';
    public bool $needsPayment = false;
    public string $dateScope = '';
    public string $dateStart = '';
    public string $dateEnd = '';

    private const ADMIN_RECENT_DAYS = 7;
    private const ADMIN_EXACT_COLUMNS = ['trade_no', 'out_trade_no', 'api_trade_no'];

    private function __construct(string $where) { $this->where = $where; }

    public function key(): array { return ['where'=>$this->where, 'bind'=>$this->bind]; }

    private function equal(string $column, mixed $value): void
    {
        $parameter = ':f'.count($this->bind);
        $this->where .= ' AND '.$column.'='.$parameter;
        $this->bind[$parameter] = $value;
    }

    private function like(string $column, string $value): void
    {
        $parameter = ':f'.count($this->bind);
        $this->where .= ' AND '.$column.' LIKE '.$parameter;
        $this->bind[$parameter] = '%'.$value.'%';
    }

    public static function text(mixed $value, int $max = 255, bool $trim = false): string
    {
        if (!is_scalar($value) && $value !== null) throw new \InvalidArgumentException('筛选参数格式错误');
        $value = (string)$value;
        if ($trim) $value = trim($value);
        if (strlen($value) > $max * 4 || !preg_match('//u', $value)
            || preg_match_all('/./us', $value) > $max) throw new \InvalidArgumentException('筛选内容过长或编码错误');
        return $value;
    }

    public static function integer(mixed $value, int $min = 0, int $max = 2147483647): int
    {
        $text = self::text($value, 20);
        if (!preg_match('/^-?[0-9]+$/D', $text) || (float)$text < $min || (float)$text > $max) {
            throw new \InvalidArgumentException('筛选数字超出允许范围');
        }
        return (int)$text;
    }

    public static function pagination(mixed $offset, mixed $limit): array
    {
        return [max(0, self::integer($offset, -2147483647)),
            max(1, min(100, self::integer($limit, -2147483647)))];
    }

    public static function fresh(array $input): bool
    {
        return isset($input['fresh']) && self::text($input['fresh'], 1) === '1';
    }

    /** Client request id echoed by the list endpoints. Rejects header-injection characters. */
    public static function requestId(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9]{8,40}$/', $value)) return '';
        return $value;
    }

    /** Inclusive calendar window ending on $now's local date. */
    public static function recentWindow(?int $now = null): array
    {
        $tz = new \DateTimeZone(date_default_timezone_get() ?: 'Asia/Shanghai');
        $end = $now === null
            ? new \DateTimeImmutable('now', $tz)
            : (new \DateTimeImmutable('@'.$now))->setTimezone($tz);
        $start = $end->modify('-'.(self::ADMIN_RECENT_DAYS - 1).' days');
        return ['start'=>$start->format('Y-m-d'), 'end'=>$end->format('Y-m-d')];
    }

    private static function date(mixed $value): string
    {
        $text = self::text($value, 10);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if (!$date || $date->format('Y-m-d') !== $text) throw new \InvalidArgumentException('日期格式错误');
        return $text;
    }

    public static function payment(array $input, ?int $merchantUid = null): self
    {
        $filter = new self('1=1');
        if ($merchantUid !== null) {
            if ($merchantUid < 1) throw new \InvalidArgumentException('商户身份无效');
            $filter->equal('A.uid', $merchantUid);
        } elseif (!empty($input['uid'])) $filter->equal('A.uid', self::integer($input['uid'], 1));
        foreach ([$merchantUid === null ? 'type' : 'paytype'=>'type', 'channel'=>'channel', 'subchannel'=>'subchannel'] as $parameter=>$column) {
            if (!empty($input[$parameter])) {
                $filter->equal('A.'.$column, self::integer($input[$parameter], 1));
                break; // Preserve the existing type/channel/subchannel priority.
            }
        }
        if (isset($input['dstatus']) && $input['dstatus'] !== '') {
            $status = self::integer($input['dstatus'], -1, 4);
            if ($status >= 0) $filter->equal('A.status', $status);
        }
        $value = self::text($input[$merchantUid === null ? 'value' : 'kw'] ?? '');
        $column = '';
        if ($value !== '') {
            if ($merchantUid === null) {
                $column = self::text($input['column'] ?? '', 32);
                $allowed = ['trade_no', 'out_trade_no', 'api_trade_no', 'name', 'money', 'realmoney', 'getmoney', 'domain', 'buyer', 'ip'];
                if (!in_array($column, $allowed, true)) throw new \InvalidArgumentException('不支持的搜索字段');
            } else {
                $columns = [1=>'trade_no', 2=>'out_trade_no', 3=>'name', 4=>'money', 5=>'realmoney', 6=>'domain', 7=>'ip', 8=>'buyer'];
                $column = $columns[self::integer($input['type'] ?? 1, 1, 8)];
            }
        }
        if ($merchantUid === null) {
            // Range on addtime so idx_health_addtime(addtime, channel, status) can use its leading column.
            // Merchant lists keep the previous "empty dates mean all time" behavior.
            $filter->applyAdminAddtimeWindow($input, $value !== '' && in_array($column, self::ADMIN_EXACT_COLUMNS, true));
        } else {
            $start = !empty($input['starttime']) ? self::date($input['starttime']) : null;
            $end = !empty($input['endtime']) ? self::date($input['endtime']) : null;
            if ($start !== null && $end !== null && $start > $end) throw new \InvalidArgumentException('开始日期不能晚于结束日期');
            $filter->bindAddtime($start, $end);
        }
        if ($value !== '') {
            $maximum = ['trade_no'=>32, 'out_trade_no'=>150, 'api_trade_no'=>150, 'name'=>64, 'domain'=>64, 'buyer'=>30, 'ip'=>45][$column] ?? 255;
            self::text($value, $maximum);
            if (in_array($column, ['money', 'realmoney', 'getmoney'], true)
                && !preg_match('/^-?[0-9]{1,10}(\.[0-9]{1,2})?$/D', $value)) throw new \InvalidArgumentException('金额格式错误');
            if ($column === 'name') $filter->like('A.name', $value);
            else $filter->equal('A.'.$column, $value);
        }
        return $filter;
    }

    /**
     * Empty admin dates default to the last 7 days. date_scope=all is the explicit full scan.
     * An exact order number is not limited by that default; a custom range still applies.
     */
    private function applyAdminAddtimeWindow(array $input, bool $exactOrder): void
    {
        $scope = self::text($input['date_scope'] ?? '', 16);
        if (!in_array($scope, ['recent', 'all', 'custom'], true)) $scope = '';
        if ($scope === 'all') {
            $this->dateScope = 'all';
            return;
        }
        $startProvided = isset($input['starttime']) && $input['starttime'] !== '' && $input['starttime'] !== null;
        $endProvided = isset($input['endtime']) && $input['endtime'] !== '' && $input['endtime'] !== null;
        $honorExplicit = ($startProvided || $endProvided) && $scope !== 'recent';
        if ($exactOrder && !$honorExplicit) {
            $this->dateScope = 'exact';
            return;
        }
        if (!$honorExplicit) {
            $window = self::recentWindow();
            $this->dateScope = 'recent';
            $this->dateStart = $window['start'];
            $this->dateEnd = $window['end'];
            $this->bindAddtime($window['start'], $window['end']);
            return;
        }
        $start = $startProvided ? self::date($input['starttime']) : null;
        $end = $endProvided ? self::date($input['endtime']) : null;
        if ($start !== null && $end !== null && $start > $end) throw new \InvalidArgumentException('开始日期不能晚于结束日期');
        $this->dateScope = 'custom';
        $this->dateStart = $start ?? '';
        $this->dateEnd = $end ?? '';
        $this->bindAddtime($start, $end);
    }

    private function bindAddtime(?string $start, ?string $end): void
    {
        if ($start !== null && $start !== '') {
            $this->where .= ' AND A.addtime>=:starttime';
            $this->bind[':starttime'] = $start.' 00:00:00';
        }
        if ($end !== null && $end !== '') {
            $this->where .= ' AND A.addtime<=:endtime';
            $this->bind[':endtime'] = $end.' 23:59:59';
        }
    }

    public static function shop(array $input): self
    {
        $filter = new self('A.deleted=0');
        foreach (['pay_status'=>3, 'order_status'=>5] as $field=>$max) {
            if (isset($input[$field]) && $input[$field] !== '') {
                $value = self::integer($input[$field], -1, $max);
                if ($value >= 0) $filter->equal('A.'.$field, $value);
            }
        }
        $field = self::text($input['search_field'] ?? 'all', 32);
        if (!in_array($field, ['all', 'shop_trade_no', 'pay_trade_no', 'merchant_uid', 'goods_name', 'buyer_contact'], true)) {
            throw new \InvalidArgumentException('不支持的搜索字段');
        }
        $filter->searchField = $field;
        $keyword = self::text($input['keyword'] ?? '', 255, true);
        if ($keyword === '') return $filter;
        if ($field === 'all') {
            // Preserve legacy API OR semantics, including its numeric conversion.
            $filter->where .= ' AND (A.shop_trade_no=:keyword OR A.pay_trade_no=:keyword OR A.buyer_contact=:keyword OR P.uid=:merchant_uid OR A.goods_name LIKE :keyword_like)';
            $filter->bind += [':keyword'=>$keyword, ':merchant_uid'=>(int)$keyword, ':keyword_like'=>'%'.$keyword.'%'];
            $filter->needsPayment = true;
        } elseif ($field === 'merchant_uid') {
            $filter->equal('P.uid', self::integer($keyword, 1));
            $filter->needsPayment = true;
        } elseif ($field === 'goods_name') $filter->like('A.goods_name', $keyword);
        else {
            self::text($keyword, ['buyer_contact'=>64, 'shop_trade_no'=>22, 'pay_trade_no'=>32][$field]);
            $filter->equal('A.'.$field, $keyword);
        }
        return $filter;
    }

    public static function goods(array $input): self
    {
        $filter = new self('A.deleted=0');
        if (isset($input['status']) && $input['status'] !== '') {
            $status = self::integer($input['status'], -1, 1);
            if ($status >= 0) $filter->equal('A.status', $status);
        }
        $keyword = self::text($input['keyword'] ?? '', 255, true);
        if ($keyword !== '') $filter->like('A.name', $keyword);
        return $filter;
    }

    public static function ledger(array $input, int $merchantUid): self
    {
        if ($merchantUid < 1) throw new \InvalidArgumentException('商户身份无效');
        $filter = new self('1=1');
        $filter->equal('A.uid', $merchantUid);
        $keyword = self::text($input['kw'] ?? '', 64);
        if ($keyword !== '') {
            $column = [1=>'type', 2=>'money', 3=>'trade_no'][self::integer($input['type'] ?? 1, 1, 3)];
            if ($column === 'type') self::text($keyword, 20);
            if ($column === 'money' && !preg_match('/^-?[0-9]{1,10}(\.[0-9]{1,2})?$/D', $keyword)) throw new \InvalidArgumentException('金额格式错误');
            $filter->equal('A.'.$column, $keyword);
        }
        return $filter;
    }
}
