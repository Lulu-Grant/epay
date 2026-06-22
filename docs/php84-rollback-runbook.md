# PHP 8.4 回滚操作手册

生成日期：2026-06-22

适用范围：彩虹易支付从 PHP 7.4 升级到 PHP 8.4 后的生产或灰度回滚。目标是在 30 分钟内恢复 PHP 7.4 运行环境，并确认后台、用户中心、支付创建和支付回调可用。

## 回滚触发条件

满足任一条件应立即进入回滚评估：

- PHP fatal error 持续出现，且 10 分钟内无法定位并热修。
- 支付成功率明显低于 PHP 7.4 基线。
- 异步通知失败率明显上升。
- 出现批量签名或验签失败。
- 出现订单重复入账、漏入账或状态错乱。
- 后台或用户中心无法登录。
- 退款、结算、转账出现资金状态异常。
- PHP warning/deprecated 日志在核心链路中高频出现并影响可观测性。

## 回滚前确认

回滚负责人在执行前确认：

- 当前发布版本号、Git commit、部署包路径已记录。
- PHP 7.4 运行环境可用，并保留原扩展集合。
- 最近一次数据库备份可用。
- 当前数据库已做回滚前备份。
- 已暂停新的 PHP 8.4 部署任务。
- 已通知支付、客服、运维相关负责人。

## 数据库保护

回滚前先做一次即时备份。以下命令中的库名、账号、备份目录按实际生产环境替换。

```bash
backup_dir=/data/backups/epay
db_name=epay
db_user=epay
timestamp=$(date +%Y%m%d_%H%M%S)
mkdir -p "$backup_dir"
mysqldump --single-transaction --routines --triggers \
  -u"$db_user" -p "$db_name" > "$backup_dir/${db_name}_before_php84_rollback_${timestamp}.sql"
```

校验备份文件：

```bash
test -s "$backup_dir/${db_name}_before_php84_rollback_${timestamp}.sql"
gzip -c "$backup_dir/${db_name}_before_php84_rollback_${timestamp}.sql" \
  > "$backup_dir/${db_name}_before_php84_rollback_${timestamp}.sql.gz"
```

默认回滚只切换运行时和代码，不回滚数据库。只有确认数据库迁移导致不可恢复错误时，才执行数据库恢复。

数据库恢复命令模板：

```bash
mysql -u"$db_user" -p "$db_name" < /data/backups/epay/KNOWN_GOOD_BACKUP.sql
```

## 代码回滚

推荐使用部署系统回滚到 PHP 8.4 发布前最后一个 PHP 7.4 稳定构建。

如果使用 Git 工作树部署，命令模板如下：

```bash
cd /path/to/epay
git fetch --all --prune
git checkout <last-known-good-php74-commit>
composer install --working-dir=includes --no-dev --prefer-dist --no-interaction
```

如果生产仍采用提交后的 `includes/vendor`，可以跳过 Composer install，但必须确认 `includes/vendor/autoload.php` 存在。

```bash
test -f includes/vendor/autoload.php
```

## PHP 运行时切回 7.4

Nginx + PHP-FPM 示例：

```bash
php74_fpm_sock=/run/php/php7.4-fpm.sock
nginx_site=/etc/nginx/sites-enabled/epay.conf

grep -q "$php74_fpm_sock" "$nginx_site"
nginx -t
systemctl restart php7.4-fpm
systemctl reload nginx
```

如果使用容器部署，回滚到 PHP 7.4 镜像标签：

```bash
docker compose pull app
docker compose up -d app
docker compose ps
```

容器镜像标签必须由部署系统指定为最后一个 PHP 7.4 稳定版本。

## 缓存和进程清理

```bash
systemctl reload php7.4-fpm || true
systemctl reload nginx || true
```

如果部署环境有 OPcache 管理接口，应执行 OPcache reset。没有管理接口时，通过重启 PHP-FPM 完成清理。

## 回滚后本地验收

在生产同等配置或回滚验证环境执行：

```bash
php -v
php tools/php84/check-env.php
sh tools/php84/lint-all.sh
sh tools/php84/check-deprecated-patterns.sh
php tools/php84/check-plugin-metadata.php
sh tools/php84/composer-check.sh
```

有数据库验证权限时执行：

```bash
EPAY_DB_HOST=127.0.0.1 \
EPAY_DB_PORT=3306 \
EPAY_DB_USER=root \
EPAY_DB_PASSWORD=... \
php tools/php84/db-fixture-check.php
```

执行临时数据库回滚演练，验证逻辑备份、破坏状态、恢复备份和核心数据校验：

```bash
EPAY_DB_HOST=127.0.0.1 \
EPAY_DB_PORT=3306 \
EPAY_DB_USER=root \
EPAY_DB_PASSWORD=... \
php tools/php84/rollback-rehearsal-check.php
```

该演练只操作 `epay_php84_rollback_*` 临时数据库，验证备份文件非空、配置/用户/通道/订单样本可恢复到备份点；它不能替代生产部署系统的真实代码回滚演练。

执行 Apache + PHP-FPM 运行时切回演练，验证同一个 Apache 入口可先由 PHP 8.4 服务，再切回 PHP 7.4 并继续通过核心入口和友好路由检查：

```bash
EPAY_DB_HOST=127.0.0.1 \
EPAY_DB_PORT=3306 \
EPAY_DB_USER=root \
EPAY_DB_PASSWORD=... \
PHP84_BIN=/path/to/php8.4 \
PHP84_FPM_BIN=/path/to/php8.4-fpm \
PHP74_BIN=/path/to/php7.4 \
PHP74_FPM_BIN=/path/to/php7.4-fpm \
EPAY_ROLLBACK_APACHE_PORT=8631 \
EPAY_ROLLBACK_FPM_PORT=9631 \
sh tools/php84/apache-fpm-rollback-rehearsal.sh
```

该演练复用 `tools/php84/apache-fpm-smoke.sh`，只创建临时应用副本、临时数据库和临时 Apache/PHP-FPM 配置；它不能替代生产部署系统中的真实版本切换和真实备份 artifact 验收。

执行 Nginx + PHP-FPM 运行时切回演练，验证同一个 Nginx 入口可先由 PHP 8.4 服务，再切回 PHP 7.4 并继续通过核心入口、友好路由和受保护目录检查：

```bash
EPAY_DB_HOST=127.0.0.1 \
EPAY_DB_PORT=3306 \
EPAY_DB_USER=root \
EPAY_DB_PASSWORD=... \
PHP84_BIN=/usr/bin/php8.4 \
PHP84_FPM_BIN=/usr/sbin/php-fpm8.4 \
PHP74_BIN=/usr/bin/php7.4 \
PHP74_FPM_BIN=/usr/sbin/php-fpm7.4 \
NGINX_BIN=/usr/sbin/nginx \
EPAY_ROLLBACK_NGINX_PORT=18240 \
EPAY_ROLLBACK_FPM_PORT=19240 \
EPAY_ROLLBACK_NGINX_REPEAT_COUNT=3 \
sh tools/php84/nginx-fpm-rollback-rehearsal.sh
```

该演练复用 `tools/php84/nginx-fpm-smoke.sh`，只创建临时应用副本、临时数据库和临时 Nginx/PHP-FPM 配置，并在同一外部端口上顺序验证 PHP 8.4 与 PHP 7.4。它可作为 Nginx 运行时切换证据，但仍不能替代生产部署系统中的真实版本切换和真实备份 artifact 验收。

在回滚验证库执行安装态 smoke：

```bash
EPAY_DB_HOST=127.0.0.1 \
EPAY_DB_PORT=3306 \
EPAY_DB_USER=root \
EPAY_DB_PASSWORD=... \
PHP_BIN=php \
EPAY_SMOKE_PORT=8097 \
sh tools/php84/installed-http-smoke.sh
```

## 回滚后线上验收

必须人工或自动确认：

- `/` 首页正常。
- `/admin/` 未登录拦截正常，管理员可登录。
- `/user/` 未登录拦截正常，商户可登录。
- `/mapi.php` 缺少参数返回合法 JSON。
- 测试商户可创建订单。
- 测试订单可完成支付回调。
- 重复回调不会重复入账。
- `cron.php` 可执行且无 fatal error。
- PHP error log 无新增持续 fatal error。

## 观察窗口

回滚后至少观察 30 分钟：

- 支付成功率恢复到 PHP 7.4 基线。
- 异步通知成功率恢复到 PHP 7.4 基线。
- 商户通知失败率无异常上升。
- 后台和用户中心登录成功率正常。
- 无新增订单资金状态异常。

## 回滚记录

每次回滚必须记录：

- 回滚开始和结束时间。
- 触发原因。
- 回滚前 PHP 8.4 commit。
- 回滚后 PHP 7.4 commit 或部署包版本。
- 数据库备份文件路径。
- 是否执行数据库恢复。
- 回滚后验收命令和结果。
- 后续修复负责人和跟进事项。
