# 彩虹易支付 PHP 8.4 兼容维护版

本仓库是基于彩虹易支付 `Version 3075` 的维护分支，目标是在保留 PHP 7.4 兼容下限的同时，推进 PHP 8.4 环境可运行、可部署、可验收。

当前分支：

```text
upgrade/php-84-compatible
```

## 项目定位

彩虹易支付是一个 PHP 在线支付平台，提供商户 API、支付收银台、管理后台、商户后台和插件化支付通道能力。本分支主要面向实际部署维护，重点不是重写系统，而是在现有架构上补齐兼容性、运维文档、投诉处理、统计展示和本地化资源等能力。

本分支已经包含：

- PHP 8.4 兼容性修正，并保持 PHP 7.4 语法下限。
- 管理后台和商户后台的基础可用性修复。
- 支付统计、历史收入统计、昨日订单数等后台展示增强。
- 支付宝 H5/二维码中转行为的配置化处理。
- 交易投诉模块适配，包括后台、商户端和投诉 API 基础能力。
- Telegram 通知插件的剥离与适配文件。
- 外部 CDN 静态资源本地化到 `assets/cdn/`。
- 独立开发文档入口 `doc.php`，避免首页路由改造影响 API 文档。
- 用户、商户、管理员和故障排查文档。

## 目录结构

```text
.
├── admin/                  # 管理后台
├── user/                   # 商户后台
├── includes/               # 核心库、业务函数、通用组件
├── includes/lib/Complain/  # 交易投诉适配
├── includes/lib/Telegram/  # Telegram 通知适配
├── plugins/                # 支付插件
├── template/               # 前端和文档模板
├── assets/cdn/             # 本地化第三方前端资源
├── docs/                   # 升级、验收、运维和用户文档
├── tools/php84/            # PHP 8.4 升级验证脚本
├── api.php                 # 商户 API 入口
├── pay.php                 # 支付页面入口
├── submit.php              # 支付提交入口
├── cron.php                # 计划任务入口
├── doc.php                 # 开发文档入口
└── nginx.txt               # Nginx 伪静态参考配置
```

## 运行环境

推荐环境：

- Linux + Nginx 或 Apache
- PHP 8.2 / 8.3 / 8.4
- MySQL 5.7 或 MariaDB 10.x
- 宝塔面板可用，但需要正确配置运行目录、伪静态和计划任务

兼容下限：

- PHP 7.4
- MySQL 5.7

必需 PHP 扩展：

```text
pdo_mysql curl openssl json mbstring gd fileinfo session
```

建议 PHP 扩展：

```text
gmp bcmath intl zip xml
```

宝塔部署时需要注意：

- 关闭站点防跨站限制，或确保 `open_basedir` 不阻断本项目读写缓存、插件、上传和日志路径。
- 支付回调、异步通知和计划任务必须使用 HTTPS 可访问域名。
- Nginx 伪静态参考 `nginx.txt`，Apache 参考 `.htaccess`。

## 安装部署

1. 拉取代码：

```bash
git clone https://github.com/Lulu-Grant/epay.git
cd epay
git checkout upgrade/php-84-compatible
```

2. 配置站点根目录到项目根目录。

3. 创建 MySQL 数据库，并确保 PHP 进程可连接。

4. 访问安装入口：

```text
https://你的域名/install/
```

5. 按安装向导写入数据库配置。

6. 安装完成后确认存在安装锁文件：

```text
install/install.lock
```

7. 配置伪静态：

```text
nginx.txt
```

8. 配置计划任务。后台支付配置页会显示当前站点的计划任务 URL，一般至少需要：

```cron
* * * * * curl -fsS --max-time 20 "https://你的域名/cron.php?key=你的计划任务密钥&do=notify" >/dev/null 2>&1
*/5 * * * * curl -fsS --max-time 30 "https://你的域名/cron.php?key=你的计划任务密钥" >/dev/null 2>&1
```

默认后台入口：

```text
https://你的域名/admin/
```

首次安装默认账号密码：

```text
admin / 123456
```

上线后必须立即修改默认密码。

## 常用入口

```text
/admin/              管理后台
/user/               商户后台
/api.php             商户 API
/pay.php             支付页面入口
/submit.php          支付提交入口
/cron.php            计划任务入口
/doc.php             开发文档入口
/doc.html            开发文档伪静态入口
/doc_old.html        旧版开发文档入口
```

## 验证命令

环境检查：

```bash
php tools/php84/check-env.php
```

PHP 语法检查：

```bash
bash tools/php84/lint-all.sh
```

PHP 7.4 下限静态检查：

```bash
php tools/php84/check-php74-floor.php
```

弃用模式扫描：

```bash
bash tools/php84/check-deprecated-patterns.sh
```

本地临时安装 HTTP 冒烟测试：

```bash
EPAY_DB_USER="root" EPAY_DB_PASSWORD="你的本地数据库密码" bash tools/php84/installed-http-smoke.sh
```

该脚本会创建临时数据库、启动本地 PHP Server，并在结束后清理测试数据库。不要把它直接指向生产数据库账号。

更多验收项见：

- [PHP 8.4 升级计划](docs/php84-upgrade-plan.md)
- [PHP 8.4 升级验收标准](docs/php84-acceptance-criteria.md)
- [PHP 8.4 Upgrade Verification Report](docs/php84-verification-report.md)
- [PHP 8.4 回滚操作手册](docs/php84-rollback-runbook.md)

## 文档

面向使用和运维：

- [彩虹易支付用户使用文档](docs/user-manual.md)
- [商户快速上手指南](docs/merchant-quickstart.md)
- [管理员日常运维手册](docs/admin-operations-runbook.md)
- [支付系统故障排查手册](docs/troubleshooting-guide.md)

面向升级开发：

- [PHP 8.4 升级计划](docs/php84-upgrade-plan.md)
- [PHP 8.4 升级验收标准](docs/php84-acceptance-criteria.md)
- [PHP 8.4 Upgrade Baseline Report](docs/php84-baseline-report.md)
- [PHP 8.4 Upgrade Verification Report](docs/php84-verification-report.md)
- [PHP 8.4 目标模式开发提示词](docs/target-mode-php84-development-prompts.md)

扩展模块：

- [Telegram 机器人通知插件剥离与适配分析](docs/telegram-plugin-adaptation.md)

## 回调与通知规则

支付成功后，系统会向订单里的 `notify_url` 发起异步通知。商户端必须在处理成功后输出：

```text
success
```

只返回 HTTP 200 不代表成功；如果响应内容不包含 `success`，系统会继续重试，并最终标记为通知失败。

排查回调问题时优先检查：

- 商户回调地址是否能从支付服务器访问。
- 商户接口是否发生 `301/302` 跳转。
- CDN/WAF/Nginx 是否拦截服务器请求。
- 商户验签密钥是否和平台商户密钥一致。
- 商户成功处理后是否输出纯文本 `success`。

## 安全建议

- 不要在仓库中提交生产 `config.php`、数据库备份、支付证书、商户私钥或服务器密码。
- 管理员后台必须修改默认密码。
- 生产环境建议限制 `/install/` 访问，安装完成后保留 `install/install.lock`。
- 补发失败通知前，需要确认商户端具备幂等处理能力，避免重复入账。
- 支付通道配置、证书和密钥应只在生产服务器安全保存。

## 维护说明

这个分支偏向生产维护版。新增功能应遵循以下原则：

- 保持 PHP 7.4 语法下限。
- 优先修复实际部署问题，不做大规模架构重写。
- 支付、回调、结算、余额变更等资金相关逻辑必须先分析、再小步改动、再验证。
- 涉及订单通知补发、余额修正和通道切换时，必须先备份数据库并确认回滚路径。

## 上游与相关项目

- 原始上游：[lopinx/epay](https://github.com/lopinx/epay)
- 当前维护仓库：[Lulu-Grant/epay](https://github.com/Lulu-Grant/epay)
- 参考项目：[maajiko/Epay](https://github.com/maajiko/Epay)
- Docker 参考：[monlor/dockerfiles/epay](https://github.com/monlor/dockerfiles/tree/main/epay)
