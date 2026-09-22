# PHP 8.4 维护验收标准

本文件适用于当前维护分支的新变更和发布。唯一受支持的运行时为 PHP 8.4.x；详见[运行版本政策](php84-support-policy.md)。过去的升级对比结果保存在历史报告中，不再作为新变更的兼容性门槛。

## 阻断条件

以下任一项失败，不进入灰度或生产发布：

- PHP 8.4.x 的环境、语法、Composer 安装或平台要求检查失败。
- 核心入口、安装升级、后台或商户端出现 fatal error。
- 支付创建、签名验签、异步通知、重复回调、退款、结算或转账的相关回归失败。
- 商户数据隔离、管理员权限或敏感配置保护回退。
- P0 支付插件无法加载；本次涉及的插件模拟回归失败。
- 回滚到上一个已验证的 PHP 8.4 构建不可行。

## 环境与依赖

1. `php -v` 必须显示 8.4.x；`php tools/php84/check-env.php` 必须通过。
2. 必需扩展：`pdo_mysql`、`curl`、`openssl`、`json`、`mbstring`、`gd`、`fileinfo`、`session`。按实际插件配置检查 `gmp`、`bcmath`、`intl`、`zip`、`xml`。
3. `includes/composer.json` 与 `includes/composer.lock` 的平台均指向 PHP 8.4；在 8.4 环境执行 `composer validate --working-dir=includes --strict`、`composer install --working-dir=includes --no-dev` 和 `composer check-platform-reqs --working-dir=includes`。
4. 数据库使用受控测试库；版本至少为 MySQL 5.7 或经验证兼容的 MariaDB。验收不得连接生产账务库。

## 代码与功能检查

在 PHP 8.4.x 下运行：

```bash
php tools/php84/check-env.php
sh tools/php84/lint-all.sh
sh tools/php84/check-deprecated-patterns.sh
php tools/php84/check-plugin-metadata.php
php tools/php84/check-signatures.php
php tools/php84/check-download-safety.php
php tools/php84/check-rewrite-rules.php
sh tools/php84/http-smoke.sh
sh tools/php84/composer-check.sh
```

有测试数据库时继续运行 `tools/php84/db-fixture-check.php`、`tools/php84/rollback-rehearsal-check.php`、安装升级与已安装 HTTP 冒烟脚本。对本次改动涉及的支付插件、投诉适配器或商户页面，再执行相应隔离回归。测试替身必须阻止真实网关请求、退款、通知和计划任务副作用。

核心入口至少覆盖首页、支付提交、商户 API、后台与商户登录、友好路由、安装态限制和受保护目录。支付回归至少覆盖错误签名拒绝、金额与订单号不匹配拒绝、成功回调及重复回调幂等。跨商户查询和写入必须拒绝。

## 发布与性能

以同为 PHP 8.4 的当前稳定构建为对照，记录关键接口成功率、错误率、P95 延迟和 PHP/FPM 日志。观察自然流量时不得以少量成功订单推断通道权重。任何与代码版本相关的回退均指向上一个已验证的 PHP 8.4 构建，按[回滚手册](php84-rollback-runbook.md)执行。

验收记录需写明提交、PHP 8.4 补丁版本、依赖锁版本、测试命令与结果、未执行项、灰度观察及回退点。未执行的线上支付或真实设备测试不能标为通过。
