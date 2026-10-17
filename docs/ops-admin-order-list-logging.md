# 管理后台订单列表：超时对照日志

后台订单列表页面是 `admin/order.php`。部署如果把后台目录改名或反代到别的路径（例如 `/lpao/`），浏览器请求的仍是同一套列表接口，失败信息里的请求号会出现在该请求的查询字符串中。

列表在浏览器侧超过 15 秒，或请求失败时，状态行保持原来的第一句，并在后面写上耗时、请求号和发起时间：

```text
列表查询超时，请重试。耗时 15.0 秒，请求号 20261017150405007ab12，时间 2026-10-17 15:04:05
列表查询失败，请重试。耗时 0.4 秒，请求号 20261017150405007ab12，时间 2026-10-17 15:04:05
列表查询失败（HTTP 502），请重试。耗时 0.8 秒，请求号 20261017150405007ab12，时间 2026-10-17 15:04:05
```

请求号由页面生成，放在查询参数 `rid`，同时放在请求头 `X-Request-Id`。`admin/ajax_order.php` 的 `orderList` / `orderSummary`，以及商户列表 `user/ajax2.php` 的 `orderList` / `orderSummary` / `recordList`，只在 `rid` 为 8–40 位字母数字时把它原样写回响应头 `X-Request-Id`。其它字符直接丢弃，避免响应头被换行注入。

默认访问日志里的 `$request` 已经包含查询字符串。请求停在边缘、没有到达 PHP 时，仍然可以用 URL 中的 `rid=` 对照。

本文只说明建议加在部署配置里的日志项。不要改仓库中的 `nginx.txt` 或 `config.php`；那两份文件不是线上站点配置。

## Nginx

在管理站点的 `http` 配置中增加一种日志格式，再在该站点的 `server` 中引用。日志路径按部署机器自己的目录填写。

```nginx
log_format manage_timed '$remote_addr - $remote_user [$time_local] '
                        '"$request" $status $body_bytes_sent '
                        'rt=$request_time urt=$upstream_response_time '
                        'rid=$sent_http_x_request_id';

access_log /var/log/nginx/manage.access.log manage_timed;
```

`$request_time` 是从收到请求到响应发送完成的秒数。`$upstream_response_time` 是上游 PHP 的处理秒数；请求没有进入源站时这一项为空。`$sent_http_x_request_id` 只有源站回写了响应头才有值，所以对照时优先看 `$request` 里的 `rid=`。

列表接口示例（路径随后台目录变化）：

```text
GET /admin/ajax_order.php?act=orderList&rid=20261017150405007ab12
```

## MariaDB 慢查询

对「页面显示约 15 秒，且访问日志里上游时间也接近这个数」的请求，用慢查询日志看当时的 SQL。阈值 1 秒。不要打开 `log_queries_not_using_indexes`，否则走了索引的短查询也会被记下来。

在数据库服务器上临时打开：

```sql
SET GLOBAL slow_query_log = ON;
SET GLOBAL long_query_time = 1;
SET GLOBAL log_queries_not_using_indexes = OFF;
```

需要重启后仍然生效时，写入数据库自己的配置文件：

```ini
[mysqld]
slow_query_log = 1
long_query_time = 1
log_queries_not_using_indexes = 0
```

## 订单列表使用的索引

后台订单列表在开始日期和结束日期都为空、且不是精确订单号时，默认加上最近 7 个自然日（含今天）的 `pre_order.addtime` 条件。健康检查安装器在索引缺失时创建：

```sql
ALTER TABLE pre_order ADD KEY `idx_health_addtime` (`addtime`, `channel`, `status`), ALGORITHM=INPLACE, LOCK=NONE
```

范围写在 `A.addtime` 上，可以使用该索引的第一列。安装脚本 `install/install.sql` 不包含这支索引；已运行过健康检查安装的库上应当已经存在，因此这里不再另加迁移。

精确查询系统订单号、商户订单号或接口订单号时，不套用这 7 天窗口。筛选里的「全部日期」会明确去掉 `addtime` 条件。商户订单列表在日期留空时仍表示不限时间。
