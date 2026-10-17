# 管理后台订单列表：超时排查日志

这份说明只给出建议配置，不修改站点正在使用的 Nginx 或数据库配置文件。示例里的路径、站点名都是占位，落地前改成自己的值。不要把密码、密钥或真实连接信息写进仓库。

## 现象

管理后台订单列表（源码 `admin/order.php`，接口 `admin/ajax_order.php?act=orderList`）在浏览器侧最多等 15 秒。超时或失败时，提示里会带上：

- 耗时（秒）
- 请求号（`rid`，同时是发起时刻）
- 发起时间（`YYYY-MM-DD HH:MM:SS`）

请求号会出现在两处，方便和访问日志对上：

- 查询字符串：`ajax_order.php?act=orderList&rid=20261017150400123abcd`
- 请求头 `X-Request-Id`。源站收到合法 `rid` 后，会在响应头里原样返回 `X-Request-Id`

源码目录是 `admin/`。如果线上把后台目录改名或反代到别的路径，日志里的 URI 以线上路径为准，`rid` 仍然在查询字符串里。

## Nginx

在 `http` 块增加一种带耗时的日志格式。`$request` 里已经包含查询字符串，因此包含 `rid`。`$request_time` 是 Nginx 从收齐请求到发出响应的整段时间。`$upstream_response_time` 是上游（PHP）回包耗时；请求没到上游时这里通常是 `-`。

```nginx
log_format manage_timed '$remote_addr - $remote_user [$time_local] '
    '"$request" $status $body_bytes_sent '
    'rt=$request_time urt=$upstream_response_time '
    'upstream="$upstream_addr" '
    'reqid="$sent_http_x_request_id"';

# 只挂到管理后台这个 server，不要替换支付回调站点已有的 access_log。
access_log /var/log/nginx/manage.access.log manage_timed;
```

对照方式：

1. 用提示里的请求号搜 `manage.access.log`。
2. 没有这一行：请求没到这台 Nginx（网络或更外层的 CDN/边缘）。
3. 有这一行，但 `urt=-` 且 `rt` 接近 15 秒：Nginx 接到了，上游没在超时前返回。
4. `rt` 和 `urt` 都很大：时间耗在源站。再按提示里的时间去看 MariaDB 慢查询日志。

改的是日志格式示例，不是仓库里的 `nginx.txt`。`nginx.txt` 仍只放伪静态规则。

## MariaDB 慢查询

把慢查询阈值设为 1 秒，用来确认订单列表的 `COUNT` / 分页有没有真的慢。下面是配置示例，不代表已经在生产执行。

```ini
[mysqld]
slow_query_log = 1
slow_query_log_file = /var/log/mysql/mariadb-slow.log
long_query_time = 1
log_queries_not_using_indexes = 0
```

`log_queries_not_using_indexes` 保持关闭，避免小表上的未走索引语句把慢日志打满。需要当时生效、又暂时不想改配置文件时，可以在数据库里执行（同样只是操作建议）：

```sql
SET GLOBAL slow_query_log = 1;
SET GLOBAL long_query_time = 1;
SET GLOBAL slow_query_log_file = '/var/log/mysql/mariadb-slow.log';
```

重启后以配置文件为准。日志目录需要数据库用户可写。

## 和订单列表 SQL 的关系

默认列表限制 `pre_order.addtime` 的最近 7 天（含今天）。这个范围条件可以走已有的 addtime 索引。部署库上如果已经有类似 `idx_health_addtime` 的索引，不需要再加。

本仓库 `install/install.sql` 里的 `pre_order` 索引是：

- `PRIMARY (trade_no)`
- `KEY uid (uid)`
- `KEY out_trade_no (out_trade_no, uid)`
- `KEY api_trade_no (api_trade_no)`
- `KEY date (date)`

这里没有 `idx_health_addtime`，也没有 `addtime` 索引。`date` 是完成日期，不是创建时间，默认窗口不能改用它。当前数据量下不为这个改动加迁移。若以后 `EXPLAIN` 显示大表在扫全表，并且库上确实没有 addtime 索引，再单独加 `KEY idx_health_addtime (addtime)`。

按订单号、商户订单号、接口订单号精确查询时不加默认时间条件，分别走主键、`out_trade_no`、`api_trade_no`。
