# 付款人 IP 采集 P5 发布记录

2026 年 10 月 22 日，Asia/Singapore。经用户“推送 部署”授权，运行代码 `e7c3a71938762da999f3cd154ab38b7870eb1990` 已从 `feature/payer-ip-esa` 推送并部署至 P5。采集保持关闭，ESA 网段与真实 IP 头尚待核验，不代表已完成付款人 IP 链路验收。

这是首次发布时的历史记录。后续版本 `a2599d9` 已改用回源鉴权并完成启用，当前状态见 [回源鉴权发布与启用记录](PAYER_IP_ORIGIN_AUTH_P5_RELEASE.md)。

[GitHub 提交](https://github.com/Lulu-Grant/epay/commit/e7c3a71938762da999f3cd154ab38b7870eb1990) 与 [完整 CI](https://github.com/Lulu-Grant/epay/actions/runs/39651355150)。CI 的 PHP 8.4 静态检查、数据库回归、商城缓存关闭／开启／故障三组验收全部通过，含十万订单列表与浏览器检查。

## 发布范围

| 项目 | 结果 |
| --- | --- |
| 上一版本 | `/srv/epay/releases/20261021-merchant-defense-dc67558`，完整保留 |
| 新版本 | `/srv/epay/releases/20261022-payer-ip-e7c3a71` |
| 当前入口 | `/srv/epay/current` 已原子切换到新版本 |
| 发布备份 | `/srv/epay/backups/20261022-payer-ip-e7c3a71`，仅服务器受控访问 |
| PHP / 数据库 | PHP 8.4.25 / MariaDB 10.11.18 |
| 运行文件 | 6 个；保留其余 1535 个普通文件的 inode、大小及修改时间 |

六个运行文件为 `includes/lib/PayerIp.php`、`includes/lib/Plugin.php`、`includes/lib/Defense/PolicyService.php`、`includes/lib/Defense/Runtime.php`、`cashier.php`、`submit2.php`。其中两个页面只合入采集钩子，不用仓库版本覆盖线上页面；另三个已有核心文件先核对上一代码基线。发布前后分别检查文件哈希和 PHP 语法。

线上收银页样式、用户导航、管理 UI、SSO、列表查询与缓存、通道轮询、支付宝回调、weilaile 插件和订单号逻辑均保留。`config.php` 与管理路径软链接保持原目标；未修改 Nginx、CDN、密钥、通道或商户开关。PHP FPM 采用平滑 reload。

## 数据库迁移

迁移来源为 `install/migrations/20261021_order_payer_ip.sql`。预检确认订单表 InnoDB，四个新字段尚不存在，新增配置键未占用；结构备份位于发布备份目录的 `schema-before.json`。`TABLE_ROWS` 约 28690 是数据库估算值，不是精确订单数。

执行时强制 `ALGORITHM=INSTANT, LOCK=NONE`，元数据锁与行锁等待上限 2 秒、语句上限 5 秒；未自动降级为复制表。三条语句完成：新增四个可空字段、插入默认配置、最后插入版本标记。没有 UPDATE 或 DELETE 旧订单，没有历史 IP 回填；原 `ip` 列定义与所有索引定义复核一致。

| 配置 | 发布后状态 |
| --- | --- |
| `payer_ip_version` | `1` |
| `payer_ip_enabled` | `0` |
| `payer_ip_esa_verified` | `0` |
| `payer_ip_esa_cidrs` | 空 |
| `payer_ip_header` | `Ali-Cdn-Real-Ip`，待核实实际头名 |
| 开启防御的商户 | 0 |

旧配置缓存即使还没有新增键，也会按采集关闭处理。未来启用时需按既有方式刷新配置缓存。

## 发布验收

切换后新版本六个运行文件哈希匹配，保留文件哈希匹配，上一版本未被改写。公开入口的响应状态与发布前一致：

| 入口 | HTTP 状态 |
| --- | --- |
| 管理后台匿名访问 | 401，原认证保护生效 |
| 商户登录 | 200 |
| SSO 空参数 | 400 |
| 投诉列表匿名访问 | 401 |
| 支付提交空参数 | 200，原错误提示页 |
| 防御入口空参数 | 400 |

最终复核时间为 02:22:29 +08:00。Nginx、PHP FPM、MariaDB、通知、商城影子单、列表缓存清理和防御定时器均为 active；防御定时任务在切换后的 02:22:20 成功运行，退出码 0。部署后检查窗口没有新增 PHP Fatal、Parse、Uncaught 或采集存储错误。未发起真实交易或重放生产支付回调；目前验收的是代码发布、关闭状态和现有入口兼容性。

## 启用前的工作

按 [开发与接入文档](PAYER_IP_ESA_CAPTURE.md) 获取并核验本站 ESA 回源网段、实际真实 IP 头、头覆盖行为和源站连接对端，再确认收银页缓存旁路。此后才能明确启用采集；不从日志样本推导网段，不信任任意 XFF，也不自动开启商户防御。

商户直接展示上游二维码、未打开网关页面的订单不在本轮采集覆盖范围内。防御模式的通道查关单能力与旧链接核对条件仍然保留。

## 回退

本次保持关闭，因此回退代码无需删列或调整商户开关。服务器备份目录中的受控 `release-control.py rollback` 可将 current 原子切回上一版本并平滑 reload PHP FPM；四个新列和默认配置保留，避免破坏已有证据。发布状态、迁移进度与验收结果分别保存在同目录的 `manifest.json`、`migration-progress.json`、`verification.json`。
