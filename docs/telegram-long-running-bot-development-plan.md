# Telegram 长驻交互机器人开发文档

生成日期：2026-07-07

目标：在现有 Telegram 队列通知能力基础上，接入完整长驻交互机器人，使管理员和商户可以通过 Telegram 完成菜单交互、订单查询、统计查询、通知设置和商户绑定，同时确保支付主流程不受影响。

## 1. 当前基础

当前项目已经完成第一阶段 Telegram 通知适配：

- 已新增 `includes/lib/Telegram/BotAPI.php`。
- 已新增 `includes/lib/Telegram/Installer.php`。
- 已新增 `includes/lib/Telegram/NotifyHelper.php`。
- 已新增 `includes/lib/Telegram/QueueHelper.php`。
- 已新增 `admin/telegram_set.php` 和 `admin/ajax_telegram.php`。
- 已新增 `telegram_notify_cron.php`，用于处理主动通知队列。
- 已在 `includes/lib/MsgNotice.php` 挂接 Telegram 入队逻辑。
- 已初始化 Telegram 表结构：
  - `pre_telegram_bind`
  - `pre_telegram_update`
  - `pre_telegram_notify_queue`
  - `pre_telegram_admin_settings`

第一阶段只负责系统主动通知，不负责接收用户消息。

第二阶段要新增的是交互机器人能力。

## 2. 开发目标

### 2.1 管理员目标

管理员可以在 Telegram 中：

- 使用 `/start` 查看管理员菜单。
- 查看全站今日、昨日、近 7 日、本月订单统计。
- 查看支付方式和支付通道收入统计。
- 查询任意订单详情。
- 查看 Telegram 通知队列状态。
- 切换管理员通知设置。

### 2.2 商户目标

商户可以在 Telegram 中：

- 使用 `/start` 查看商户菜单。
- 绑定或解绑自己的商户账号。
- 查看商户资料和余额。
- 查看今日、昨日、近 7 日、本月订单统计。
- 查询自己的订单详情。
- 切换订单、结算、登录、投诉、余额提醒等通知开关。

### 2.3 运维目标

运维可以：

- 用 systemd 管理长驻机器人进程。
- 查看机器人运行状态、最近错误、最后处理的 update_id。
- 安全重启机器人，不重复处理历史消息。
- 在 Telegram API 不可用时，保证支付流程和通知队列不受影响。

## 3. 非目标

本阶段不做以下事项：

- 不改变支付创建、支付跳转、异步通知、结算等核心支付流程。
- 不把 Telegram API 调用放入支付同步链路。
- 不让 Telegram 机器人直接执行退款、人工改订单、结算打款等高风险操作。
- 不在 Telegram 聊天中要求商户发送长期 API 密钥。
- 不替代后台权限体系，Telegram 仅作为查询和通知入口。

## 4. 推荐架构

采用“双进程职责分离”：

```text
支付系统事件
  -> MsgNotice::send()
  -> Telegram QueueHelper 入队
  -> telegram_notify_cron.php 每分钟发送通知

Telegram 用户消息
  -> telegram_bot_worker.php 长轮询 getUpdates
  -> MessageHandler 解析命令/按钮
  -> BotService 查询业务数据
  -> BotAPI 回复 Telegram 消息
```

职责划分：

- `telegram_notify_cron.php`：主动通知队列，不接收消息。
- `telegram_bot_worker.php`：长驻交互机器人，只接收并响应 Telegram update。
- `BotAPI.php`：Telegram HTTP API 封装。
- `BotService.php`：业务数据读取、权限判断、绑定解绑、统计查询。
- `MessageHandler.php`：命令、菜单、按钮、会话状态处理。

## 5. 长驻方式选择

### 5.1 推荐：systemd + PHP CLI 长轮询

优点：

- 不需要新增公网 webhook 入口。
- 与当前服务器环境匹配。
- 出错后 systemd 可自动重启。
- 可通过日志排查运行状态。

缺点：

- 需要维护一个常驻进程。
- 必须防止多实例同时运行。

### 5.2 备选：Telegram Webhook

优点：

- 不需要长驻进程。
- 消息由 Telegram 主动推送。

缺点：

- 需要暴露公网入口。
- 要做 webhook secret 校验。
- 入口超时、Nginx/PHP-FPM 配置会影响交互稳定性。

本阶段采用长轮询，后续保留 webhook 切换空间。

## 6. 新增或修改文件

### 6.1 新增文件

| 文件 | 作用 |
| --- | --- |
| `telegram_bot_worker.php` | 长驻 worker 入口，负责拉取 Telegram update |
| `includes/lib/Telegram/BotService.php` | Telegram 业务服务层 |
| `includes/lib/Telegram/MessageHandler.php` | Telegram 消息和按钮处理层 |
| `docs/telegram-long-running-bot-development-plan.md` | 本开发文档 |
| `docs/telegram-long-running-bot-acceptance-criteria.md` | 验收标准 |

### 6.2 修改文件

| 文件 | 修改内容 |
| --- | --- |
| `includes/lib/Telegram/BotAPI.php` | 补齐 `setMyCommands`、`deleteMyCommands`、`removeKeyboard`，确保 PHP 7.4/8.4 兼容 |
| `includes/lib/Telegram/Installer.php` | 如需新增绑定码表或状态字段，统一在安装器中兼容初始化 |
| `install/addon_telegram.sql` | 如需新增表结构，保留 `pre_` 前缀 |
| `admin/telegram_set.php` | 增加 worker 状态、最后 update_id、最近错误、设置命令按钮 |
| `admin/ajax_telegram.php` | 增加获取 worker 状态、设置 Bot 命令、清理错误等操作 |

## 7. 数据库设计

### 7.1 已有表继续使用

`pre_telegram_bind`

- 保存 Telegram chat_id 与商户 UID 绑定关系。
- 用于判断商户通知和查询权限。

`pre_telegram_update`

- 保存已处理 update。
- 用于断点续跑和避免重复处理。

`pre_telegram_notify_queue`

- 保存系统主动通知队列。
- 长驻交互机器人不直接写入支付通知队列，除非做管理提醒。

`pre_telegram_admin_settings`

- 保存管理员通知设置。

### 7.2 建议新增表

建议新增 `pre_telegram_bind_code`，用于商户安全自助绑定。

字段建议：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | int unsigned auto_increment | 主键 |
| `uid` | int unsigned | 商户 UID |
| `code` | varchar(32) | 一次性绑定码 |
| `chat_id` | varchar(64) nullable | 实际绑定的 Telegram Chat ID |
| `status` | tinyint | 0 未使用，1 已使用，2 已过期 |
| `addtime` | datetime | 创建时间 |
| `expiretime` | datetime | 过期时间 |
| `usetime` | datetime nullable | 使用时间 |

索引建议：

- `UNIQUE KEY code (code)`
- `KEY uid (uid)`
- `KEY status_expiretime (status, expiretime)`

## 8. 绑定流程设计

### 8.1 推荐绑定流程

1. 商户登录用户中心。
2. 在用户中心 Telegram 绑定页生成一次性绑定码。
3. 商户打开 Telegram Bot，输入 `/bind 绑定码`。
4. worker 校验绑定码：
   - 绑定码存在。
   - 未使用。
   - 未过期。
   - 对应商户正常。
5. 写入或更新 `pre_telegram_bind`。
6. 标记绑定码已使用。
7. 给商户发送绑定成功消息。

### 8.2 管理员手动绑定

保留现有后台能力：

```text
后台 -> 系统设置 -> Telegram通知设置 -> 绑定商户 Telegram Chat ID
```

用于客服协助或异常恢复。

### 8.3 不推荐流程

不推荐在 Telegram 中输入：

```text
/bind 商户号 商户密钥
```

原因：商户密钥会长期留存在 Telegram 聊天记录中，泄露后风险较高。

## 9. 权限模型

### 9.1 管理员权限

管理员身份只通过配置项判断：

- `telegram_admin_chat_id`

只有该 chat_id 可以访问：

- 全站统计。
- 任意订单查询。
- 通道统计。
- Telegram 队列状态。
- 管理员通知设置。

### 9.2 商户权限

商户身份通过 `pre_telegram_bind` 判断：

- `chat_id` 已绑定。
- `status=1`。
- 查询订单时必须强制带 `uid` 条件。

商户不得：

- 查询其他商户订单。
- 查询全站统计。
- 查询通道成本或平台利润。
- 执行退款、改状态、补发通知等高风险操作。

### 9.3 未绑定用户

未绑定用户只能访问：

- `/start`
- `/help`
- `/bind 绑定码`

其他命令统一提示先绑定。

## 10. 命令设计

### 10.1 通用命令

| 命令 | 说明 |
| --- | --- |
| `/start` | 显示菜单 |
| `/help` | 显示帮助 |
| `/bind code` | 绑定商户 |
| `/unbind` | 解绑商户 |

### 10.2 商户命令

| 命令 | 说明 |
| --- | --- |
| `/info` | 查看商户信息 |
| `/today` | 今日统计 |
| `/yesterday` | 昨日统计 |
| `/week` | 近 7 日统计 |
| `/month` | 本月统计 |
| `/order trade_no` | 查询订单 |
| `/settings` | 通知设置 |

### 10.3 管理员命令

| 命令 | 说明 |
| --- | --- |
| `/admin` | 管理员菜单 |
| `/admin_today` | 全站今日统计 |
| `/admin_yesterday` | 全站昨日统计 |
| `/admin_week` | 全站近 7 日统计 |
| `/admin_month` | 全站本月统计 |
| `/channel` | 通道统计 |
| `/admin_order trade_no` | 管理员订单查询 |
| `/queue` | Telegram 队列状态 |

## 11. 菜单设计

### 11.1 商户菜单

建议使用 Telegram reply keyboard：

```text
商户信息 | 今日流水
昨日流水 | 近周流水
近月流水 | 订单查询
通知设置 | 解绑商户
```

### 11.2 管理员菜单

管理员菜单：

```text
全站今日 | 全站昨日
全站近周 | 全站近月
通道统计 | 查询订单
队列状态 | 设置
返回主菜单
```

按钮回调尽量使用短 callback data，例如：

- `menu`
- `today`
- `order`
- `admin_today`
- `toggle_order`

## 12. Worker 设计

`telegram_bot_worker.php` 的核心逻辑：

1. 设置 `IN_CRONLITE` 或 `$nosession=true`。
2. 加载 `includes/common.php`。
3. 校验 `telegram_notice` 和 `telegram_bot_token`。
4. 使用 `flock` 获取锁，防止多实例。
5. 读取 `pre_telegram_update` 中最大的 `update_id`。
6. 如果首次启动且没有历史 `update_id`，默认使用 `getUpdates(-1, 1, 0)` 跳过历史消息，避免上线后回复旧消息。如需处理历史消息，可加 `--process-history`。
7. 调用 `BotAPI::getUpdates($lastUpdateId + 1, 100, 30)`。
8. 对每个 update：
   - 先写入 `pre_telegram_update`。
   - 调用 `MessageHandler::handleUpdate($update)`。
   - 单条失败只记录错误，不退出主循环。
9. 每轮 sleep 1 秒。
10. 连续错误超过阈值后退避 10 秒。

### 12.1 进程锁

建议锁文件：

```text
/tmp/epay_telegram_bot_worker.lock
```

锁策略：

- 获取不到锁则输出“已有 worker 运行”并退出。
- systemd 自动重启时不会产生多实例。

### 12.2 日志

worker 不应打印完整 Token。

日志至少包含：

- 启动时间。
- Bot username。
- last update_id。
- 每次错误摘要。
- 连续错误次数。

Token 只允许脱敏显示前 6 位和后 4 位。

## 13. systemd 配置

建议服务文件：

```ini
[Unit]
Description=Epay Telegram Bot Worker
After=network-online.target mysql.service
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=/www/wwwroot/epay.tianlupay.com
ExecStart=/www/server/php/84/bin/php /www/wwwroot/epay.tianlupay.com/telegram_bot_worker.php
Restart=always
RestartSec=5
User=www
Group=www
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

如果线上 PHP CLI 只能由 root 正常读取项目文件，可先用 root 运行，但最终建议调整目录权限后切到 `www`。

常用命令：

```bash
systemctl daemon-reload
systemctl enable --now epay-telegram-bot.service
systemctl status epay-telegram-bot.service
journalctl -u epay-telegram-bot.service -f
systemctl restart epay-telegram-bot.service
```

## 14. 后台管理页增强

`admin/telegram_set.php` 建议新增：

- Bot 当前状态：
  - Token 是否配置。
  - `getMe` 是否成功。
  - Bot username。
  - 管理员 chat_id 是否配置。
- Worker 状态：
  - systemd 是否运行。
  - 最后处理 update_id。
  - 最近处理时间。
  - 最近错误。
- 操作按钮：
  - 设置 Bot 命令。
  - 发送测试消息。
  - 立即处理通知队列。
  - 清理 Telegram 错误缓存。

注意：PHP 页面不直接执行 `systemctl restart`，避免 Web 用户权限扩大。重启操作交给 SSH 运维。

## 15. 错误处理

### 15.1 Telegram API 错误

处理要求：

- `BotAPI` 保存 `lastError`。
- worker 捕获异常并写入缓存或日志。
- 单条消息处理失败不影响后续 update。
- 连续失败时退避，避免刷屏。

### 15.2 数据库错误

处理要求：

- 建表失败必须记录错误。
- 业务查询失败返回友好提示。
- 不向 Telegram 用户暴露 SQL 细节。

### 15.3 权限错误

处理要求：

- 未绑定用户提示绑定。
- 非管理员访问管理员命令提示无权限。
- 商户订单查询无结果时统一返回“未找到相关订单或无权查看”。

## 16. 安全要求

- 不在日志、页面、Telegram 消息中输出完整 Bot Token。
- 不在 Telegram 中要求商户发送长期 API 密钥。
- 订单查询必须区分管理员和商户权限。
- 订单详情中避免显示敏感上游账号、密钥、证书字段。
- callback data 不放敏感信息。
- 绑定码必须一次性、短有效期、使用后失效。
- 机器人只读查询为主，不做资金状态修改。

## 17. 兼容性要求

代码必须兼容：

- PHP 7.4
- PHP 8.4

禁止使用：

- union type
- match
- enum
- readonly
- attribute
- 构造器属性提升

所有新增 PHP 文件必须通过：

```bash
php -l telegram_bot_worker.php
php -l includes/lib/Telegram/BotService.php
php -l includes/lib/Telegram/MessageHandler.php
```

## 18. 分阶段开发计划

### 阶段 1：基础 worker 和只读命令

目标：让机器人可稳定接收并响应基础命令。

任务：

- 新增 `telegram_bot_worker.php`。
- 接入 `BotService.php`。
- 接入 `MessageHandler.php`。
- 实现 `/start`、`/help`。
- 实现管理员 `/admin_today`。
- 实现商户 `/today`。
- 实现 `/order trade_no`。
- 加进程锁和错误日志。

验收重点：

- worker 长驻稳定。
- 不重复处理旧 update。
- 管理员和商户权限隔离。

### 阶段 2：商户绑定与设置

目标：支持商户安全自助绑定。

任务：

- 新增绑定码表。
- 用户中心新增生成绑定码入口。
- 实现 `/bind code`。
- 实现 `/unbind`。
- 实现 `/settings` 通知开关。

验收重点：

- 绑定码一次性。
- 过期码不可使用。
- 解绑后不再收到商户通知。

### 阶段 3：管理员完整面板

目标：管理员可通过 Telegram 做日常查询。

任务：

- 实现 `/admin` 菜单。
- 实现全站统计。
- 实现通道统计。
- 实现队列状态。
- 增加后台 worker 状态展示。
- 设置 Bot 命令列表。

验收重点：

- 统计数据和后台一致。
- 管理员权限严格。
- 不暴露敏感配置。

### 阶段 4：线上灰度

目标：低风险上线。

任务：

- 先只给管理员 chat_id 开启。
- 绑定 1 个测试商户。
- 观察 24 小时日志。
- 再逐步开放商户自助绑定。

验收重点：

- 支付系统无新增 fatal。
- Telegram 故障不影响订单。
- worker 异常自动恢复。

## 19. 回滚方案

如果长驻机器人出现异常：

1. 停止 worker：

```bash
systemctl stop epay-telegram-bot.service
systemctl disable epay-telegram-bot.service
```

2. 保留 `telegram_notify_cron.php`，继续使用第一阶段主动通知能力。
3. 如交互代码导致后台异常，回滚新增文件和 `BotAPI.php` 增量修改。
4. 不删除 Telegram 数据表，避免丢失绑定关系。
5. 如必须完全关闭 Telegram，后台设置 `telegram_notice=0`。

## 20. 开发完成定义

满足以下条件才视为开发完成：

- 文档中阶段 1 至阶段 3 功能全部实现。
- 所有新增 PHP 文件语法检查通过。
- 本地或测试环境完成真实 Telegram Bot 交互测试。
- 生产 systemd 服务可启动、重启、查看日志。
- 支付核心链路无变化或已完成回归。
- 验收标准文档中的阻断项全部通过。
