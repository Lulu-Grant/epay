# 当前生产环境与入口

更新日期：2026-08-06。本文档是当前生产环境、域名职责和发布方式的唯一操作依据。

## 域名职责

| 用途 | 地址 | 说明 |
| --- | --- | --- |
| 展示页与商城 | `https://luckrun.xuanfanpay.top/` | 普通 Web 页面与商城入口。 |
| 管理后台 | `https://manage.xuanfanpay.top/admin/` | 仅平台管理员使用。未登录返回 `401` 属于正常保护行为。 |
| 商户中心与开发文档 | `https://luckrun.xuanfanpay.top/user/login.php` | 商户密码或密钥登录；`doc.html`、`doc_old.html` 与测试页也使用此域名。 |
| 商户 API、收银台与回调 | `https://api.xuanfanpay.top/` | 商户下单结果、支付入口、异步通知与同步返回均以此域名为准。 |
| 兼容入口 | `https://pay.xuanfanpay.top/` | 兼容历史访问，不应用于新商户 API 配置。 |

新商户不得再配置 `tianlupay.com` 域名。商户 `notify_url` 是商户自有服务器地址；平台不保证接受 HTTP、重定向或私网回调地址。

## 运行环境

- 主机：`47.106.222.150`
- SSH 别名：`xuanfanpay-production`；身份密钥仅保存在本机 `~/.ssh/`，密码仅保存在 macOS 钥匙串。
- 旧生产主机已退役，不得用于部署、查单、补发、数据库操作或健康检查。
- Web 根目录：`/srv/epay/current`，为活动发布的软链接。
- 不可变发布目录：`/srv/epay/releases/<release-id>`。
- 共享配置：`/srv/epay/shared/config.php`，通过 `config.php` 软链接提供。
- PHP：8.4；服务：`php8.4-fpm`。
- 数据库：MariaDB，表前缀以共享 `config.php` 的 `dbqz` 为准。

不要在发布目录中直接修改 `config.php`、支付证书、商户密钥或数据库备份。

出站代理的用途隔离、配置位置、TLS 策略和回滚要求统一参见 [网络代理架构与开发约束](network-proxy-architecture.md)。新增外部 HTTP 调用前必须先按该文档确定直连或专用代理边界。

## 发布与回滚

发布必须创建新的发布目录并以原子方式更新 `/srv/epay/current`。加法数据库迁移可保留在代码回滚后，不执行破坏性 DDL 或业务数据回滚。

上线后最低验证项：

1. `systemctl is-active php8.4-fpm` 为 `active`。
2. `readlink -f /srv/epay/current` 指向预期发布目录。
3. `luckrun` 可访问；`manage` 未登录返回受保护响应；`api` 根路径仍不暴露普通页面。
4. 商户受控订单能创建、支付、回调与通知。

## 健康简报任务

- `epay-health-snapshot.timer`：每小时快照。
- `epay-health-report.timer`：每日 09:05 生成日报并进入 Telegram 队列。
- `epay-health-report-retry.timer`：每15分钟续跑未完成的AI原始数据日报，完成后进入 Telegram 队列。

检查命令：

```bash
systemctl list-timers 'epay-health-*'
systemctl is-active epay-health-snapshot.timer epay-health-report.timer epay-health-report-retry.timer
```

AI 与 Telegram 故障不得影响订单、支付通道、结算或既有通知任务。
