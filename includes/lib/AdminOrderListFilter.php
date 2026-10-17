<?php
namespace lib;

/**
 * 管理后台订单列表的创建时间条件。
 * 商户订单列表（user/ajax2.php）不走这里。
 */
class AdminOrderListFilter
{
	const RECENT_DAYS = 7;

	const EXACT_COLUMNS = ['trade_no', 'out_trade_no', 'api_trade_no'];

	const SEARCH_COLUMNS = [
		'trade_no', 'out_trade_no', 'api_trade_no', 'name', 'money',
		'realmoney', 'getmoney', 'domain', 'buyer', 'ip',
	];

	/**
	 * 未指定日期时默认最近 7 天（含今天）。
	 * 条件加在 addtime 上，线上若已有 idx_health_addtime 一类的 addtime 索引可以直接用。
	 * install.sql 里的 KEY `date` 是完成日期，不能代替创建时间，所以这里不改用 date，也不另加迁移。
	 *
	 * @param array $post
	 * @param int|null $now unix 时间戳，缺省为当前时间
	 * @return array{sql:string,scope:string,start:string,end:string,exact:bool}
	 */
	static public function timeFilter($post, $now = null)
	{
		if(!is_array($post)) $post = [];
		$column = self::postString($post, 'column');
		$value = self::postString($post, 'value');
		$exact = ($value !== '' && in_array($column, self::EXACT_COLUMNS, true));

		$scope = self::postString($post, 'date_scope');
		if(!in_array($scope, ['recent', 'all', 'custom'], true)){
			$scope = '';
		}

		$start = self::normalizeDate(self::postString($post, 'starttime'));
		$end = self::normalizeDate(self::postString($post, 'endtime'));
		$hasDate = ($start !== '' || $end !== '');

		if($scope === 'all'){
			return self::result('', 'all', '', '', $exact);
		}

		// date_scope=recent 是表单预填的默认窗口。精确订单号不能被它挡住。
		// 用户改过的自定义区间，以及旧请求里直接带上的日期，仍然生效。
		$honorExplicit = ($hasDate && $scope !== 'recent');
		if($exact && !$honorExplicit){
			return self::result('', 'exact', '', '', true);
		}
		if(!$honorExplicit){
			$window = self::recentWindow($now);
			$start = $window['start'];
			$end = $window['end'];
			$scope = 'recent';
		}else{
			$scope = 'custom';
		}

		return self::result(self::sqlForRange($start, $end), $scope, $start, $end, $exact);
	}

	static public function recentWindow($now = null)
	{
		$tz = new \DateTimeZone(date_default_timezone_get() ?: 'Asia/Shanghai');
		if($now === null){
			$end = new \DateTimeImmutable('now', $tz);
		}else{
			$end = (new \DateTimeImmutable('@'.(int)$now))->setTimezone($tz);
		}
		$start = $end->modify('-'.(self::RECENT_DAYS - 1).' days');
		return ['start'=>$start->format('Y-m-d'), 'end'=>$end->format('Y-m-d')];
	}

	static public function normalizeDate($value)
	{
		if(!is_string($value)) return '';
		$value = trim($value);
		if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
		$dt = \DateTime::createFromFormat('!Y-m-d', $value);
		if(!$dt || $dt->format('Y-m-d') !== $value) return '';
		return $value;
	}

	static public function sanitizeRequestId($rid)
	{
		if(!is_string($rid)) return '';
		if(!preg_match('/^[A-Za-z0-9]{8,40}$/', $rid)) return '';
		return $rid;
	}

	static private function postString($post, $key)
	{
		if(!isset($post[$key]) || !is_string($post[$key])) return '';
		return trim($post[$key]);
	}

	static private function sqlForRange($start, $end)
	{
		$sql = '';
		if($start !== ''){
			$sql .= " AND A.addtime>='{$start} 00:00:00'";
		}
		if($end !== ''){
			$sql .= " AND A.addtime<='{$end} 23:59:59'";
		}
		return $sql;
	}

	static private function result($sql, $scope, $start, $end, $exact)
	{
		return [
			'sql' => $sql,
			'scope' => $scope,
			'start' => $start,
			'end' => $end,
			'exact' => $exact,
		];
	}
}
