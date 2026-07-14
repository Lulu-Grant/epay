# Telegram 长驻交互机器人验收标准

生成日期：2026-07-07

目标：定义 Telegram 长驻交互机器人从开发完成到生产灰度的验收标准。只有阻断项全部通过，才允许上线或扩大商户使用范围。

## 1. 验收结论分级

验收结果分为三类：

- 通过：所有阻断项通过，非阻断问题有记录和处理计划。
- 有条件通过：阻断项通过，但存在低风险问题，需要灰度期间重点监控。
- 不通过：任一阻断项失败，禁止上线或继续放量。

## 2. 阻断项总览

以下任一项失败即视为不通过：

- 新增 PHP 文件语法检查失败。
- PHP 7.4 不兼容。
- PHP 8.4 不兼容。
- 机器人 worker 无法启动。
- worker 重启后重复处理历史消息。
- 多实例运行导致同一消息被处理两次。
- `/start` 无响应。
- 管理员 chat_id 无法识别管理员权限。
- 普通商户可访问管理员功能。
- 商户可查询其他商户订单。
- Telegram API 失败导致支付创建、支付回调或订单状态更新异常。
- 商户绑定流程泄露长期 API 密钥。
- 机器人日志输出完整 Bot Token。
- systemd 无法自动重启异常退出的 worker。
- 回滚后支付系统或第一阶段 Telegram 通知不可用。

## 3. 环境验收

### 3.1 PHP 环境

必须满足：

- 生产 PHP CLI 可执行。
- PHP 版本为 8.4.x。
- 保持 PHP 7.4 兼容。
- 扩展 `curl`、`openssl`、`json`、`pdo_mysql`、`mbstring` 已启用。

验收命令：

```bash
/www/server/php/84/bin/php -v
/www/server/php/84/bin/php -m | grep -E 'curl|openssl|json|pdo_mysql|mbstring'
```

通过标准：

- PHP 8.4 CLI 正常。
- 必需扩展均存在。

### 3.2 网络环境

必须满足：

- 服务器可访问 `https://api.telegram.org`。
- `getMe` 成功返回机器人信息。
- `sendMessage` 可向管理员 chat_id 发送消息。

通过标准：

- Bot username 与后台配置一致。
- 管理员收到测试消息。

## 4. 代码静态验收

新增或修改文件必须通过语法检查：

```bash
php -l telegram_bot_worker.php
php -l telegram_notify_cron.php
php -l admin/telegram_set.php
php -l admin/ajax_telegram.php
php -l includes/lib/Telegram/BotAPI.php
php -l includes/lib/Telegram/BotService.php
php -l includes/lib/Telegram/MessageHandler.php
php -l includes/lib/Telegram/Installer.php
php -l includes/lib/Telegram/NotifyHelper.php
php -l includes/lib/Telegram/QueueHelper.php
php -l includes/lib/MsgNotice.php
```

通过标准：

- 全部返回 `No syntax errors detected`。
- 不使用 PHP 8 专属语法。

## 5. 数据库验收

必须确认表存在：

- `pre_telegram_bind`
- `pre_telegram_update`
- `pre_telegram_notify_queue`
- `pre_telegram_admin_settings`
- 如启用绑定码：`pre_telegram_bind_code`

通过标准：

- 安装器可重复执行。
- 已存在表不会被破坏。
- 表前缀按当前 `config.php` 的 `dbqz` 正确替换。
- `pre_telegram_update.update_id` 可保存 Telegram bigint update_id。
- `pre_telegram_bind.chat_id` 支持私聊和群组 chat_id。

## 6. Worker 运行验收

### 6.1 启动验收

必须满足：

- systemd 服务可以启动。
- worker 日志显示启动成功。
- worker 能读取 Bot Token、管理员 chat_id。
- worker 不输出完整 Token。

验收命令：

```bash
systemctl status epay-telegram-bot.service
journalctl -u epay-telegram-bot.service -n 100
```

通过标准：

- 服务状态为 `active (running)`。
- 日志无 fatal error。
- Token 已脱敏。

### 6.2 自动恢复验收

测试步骤：

1. 手动 kill worker 进程。
2. 等待 10 秒。
3. 查看 systemd 状态。

通过标准：

- systemd 自动拉起新进程。
- 日志记录重启。
- 未产生多个 worker 实例。

### 6.3 单实例验收

测试步骤：

1. 已有 systemd worker 运行。
2. 手动执行一次 `php telegram_bot_worker.php`。

通过标准：

- 手动进程获取不到锁并退出。
- 不会并发处理 Telegram update。

## 7. Update 处理验收

### 7.1 不重复处理

测试步骤：

1. 向 Bot 发送 `/start`。
2. 确认收到回复。
3. 重启 worker。
4. 观察是否重复回复旧 `/start`。

通过标准：

- 重启后不重复回复旧消息。
- `pre_telegram_update` 中有对应 update_id。
- 首次上线时，如果历史 `update_id` 为空，worker 默认跳过 Telegram 历史积压消息，不向旧消息批量回复。

### 7.2 单条失败不阻塞

测试步骤：

1. 构造或等待一条无法识别的消息。
2. 再发送 `/help`。

通过标准：

- 无法识别消息不会导致 worker 退出。
- 后续 `/help` 正常回复。

## 8. 通用命令验收

### 8.1 `/start`

未绑定用户：

- 返回欢迎信息。
- 提示绑定方式。
- 不显示商户敏感信息。
- 不显示管理员菜单。

已绑定商户：

- 返回商户菜单。
- 显示当前绑定商户 UID 或名称。
- 显示统计、订单查询、通知设置等入口。

管理员：

- 返回管理员可进入的菜单入口。
- 管理员同时绑定商户时，仍可访问管理员菜单。

### 8.2 `/help`

通过标准：

- 返回支持命令列表。
- 未绑定用户只展示可用命令。
- 管理员展示管理员命令。

## 9. 商户功能验收

### 9.1 商户绑定

绑定码方式必须满足：

- 用户中心可生成绑定码。
- 绑定码有过期时间，建议 5 分钟。
- `/bind code` 成功后写入 `pre_telegram_bind`。
- 同一商户只能有一个有效绑定，或后台明确允许多绑定。
- 绑定码使用后失效。
- 过期绑定码不可使用。

禁止项：

- 不要求商户在 Telegram 发送长期 API 密钥。
- 不在回复中显示商户密钥。

### 9.2 商户解绑

测试步骤：

1. 已绑定商户发送 `/unbind`。
2. 确认解绑。
3. 再发送 `/today`。

通过标准：

- 解绑后 `pre_telegram_bind.status=0`。
- 解绑后不能查看商户统计。
- 解绑后不再收到商户订单通知。

### 9.3 商户统计

命令：

- `/today`
- `/yesterday`
- `/week`
- `/month`

通过标准：

- 只统计当前绑定商户的订单。
- 订单数、成功订单数、订单金额、成功金额与后台按商户筛选结果一致。
- 无订单时返回 0，不报错。

### 9.4 商户订单查询

命令：

```text
/order 系统订单号
/order 商户订单号
```

通过标准：

- 可查询自己的订单。
- 不可查询其他商户订单。
- 不存在或无权限时统一提示“未找到相关订单或无权查看”。
- 订单详情不展示插件密钥、上游密钥、证书等敏感配置。

### 9.5 商户通知设置

通过标准：

- 可以查看订单、结算、登录、投诉、余额提醒开关。
- 切换后写入 `pre_telegram_bind` 对应字段。
- 关闭订单通知后，订单支付成功不再向该商户 Telegram 发送订单通知。
- 设置切换不会影响其他商户。

## 10. 管理员功能验收

### 10.1 管理员权限识别

通过标准：

- 只有 `telegram_admin_chat_id` 对应 chat_id 能访问管理员功能。
- 普通商户发送管理员命令返回无权限。
- 未绑定用户发送管理员命令返回无权限。

### 10.2 全站统计

命令：

- `/admin_today`
- `/admin_yesterday`
- `/admin_week`
- `/admin_month`

通过标准：

- 订单总数、成功订单数、订单金额、成功金额和后台一致。
- 平台利润只对管理员展示。
- 无订单时返回 0。

### 10.3 通道统计

命令：

```text
/channel
```

通过标准：

- 展示启用通道的收入、订单量、成功率。
- 数据和后台统计口径一致。
- 普通商户不可访问。

### 10.4 管理员订单查询

命令：

```text
/admin_order 系统订单号
/admin_order 商户订单号
```

通过标准：

- 管理员可查询任意订单。
- 返回订单状态、商户 ID、支付方式、通道、金额、创建时间、完成时间、通知状态。
- 不展示通道密钥、证书、私钥。

### 10.5 队列状态

命令：

```text
/queue
```

通过标准：

- 展示待发送、已发送、失败数量。
- 展示最近失败错误摘要。
- 普通商户不可访问。

## 11. Telegram 菜单和按钮验收

必须满足：

- Reply keyboard 菜单点击后能触发对应功能。
- Inline keyboard callback 能正常响应。
- callback 后 Telegram 不出现长时间 loading。
- 返回主菜单按钮可用。
- 设置开关按钮点击后状态刷新。

通过标准：

- 菜单无死链。
- callback data 不包含敏感信息。
- 按钮重复点击不会导致异常。

## 12. 与通知队列兼容验收

必须确认：

- `telegram_notify_cron.php` 继续正常处理主动通知。
- `telegram_bot_worker.php` 不处理主动通知队列。
- 两个脚本可以同时运行。
- Telegram API 故障时，主动通知最多进入失败队列，不影响支付订单状态。

测试步骤：

1. 发送一条队列测试通知。
2. 执行 `telegram_notify_cron.php`。
3. 同时向 Bot 发送 `/start`。

通过标准：

- 队列通知发送成功。
- `/start` 正常响应。
- 两者日志互不干扰。

## 13. 支付系统健康验收

机器人上线后必须回归：

- 首页或伪装首页可访问。
- 管理员后台可登录。
- 用户中心可登录。
- 商户 API 拉单成功。
- 支付完成后订单变为已支付。
- 商户异步通知按原机制推进。
- 已有 crontab 不被破坏。

通过标准：

- 支付主链路无新增 fatal。
- 订单状态更新正常。
- `notify` 状态推进正常。
- 没有因 Telegram 失败导致的支付失败。

## 14. 安全验收

必须满足：

- Bot Token 不在页面、日志、Telegram 消息中完整出现。
- 绑定流程不要求商户发送长期密钥。
- 普通商户无法访问管理员命令。
- 商户无法越权查询订单。
- 订单详情不泄露密钥、证书、私钥、通道配置。
- worker 不接受外部 HTTP 参数执行敏感操作。
- webhook 如未来启用，必须校验 Telegram secret token。

通过标准：

- 使用普通商户账号完成越权测试，全部被拒绝。
- 日志抽查无完整 Token。

## 15. 性能和稳定性验收

必须满足：

- worker 空闲时 CPU 占用低。
- Telegram 长轮询 timeout 后能继续下一轮。
- 连续 API 错误时进入退避，不刷爆日志。
- 100 条 update 连续处理不崩溃。
- 单条命令响应建议低于 5 秒。

通过标准：

- 运行 24 小时无异常退出。
- systemd 重启次数不异常。
- 日志无高频重复错误。

## 16. 后台配置页验收

路径：

```text
后台 -> 系统设置 -> Telegram通知设置
```

必须满足：

- 未登录访问跳转后台登录。
- 可保存 Bot Token、管理员 Chat ID、机器人用户名、开关。
- 可发送管理员测试消息。
- 可查看队列统计。
- 可查看绑定商户列表。
- 可手动绑定、解绑、删除绑定。
- 可查看 worker 最近状态。

通过标准：

- 页面无 PHP fatal。
- AJAX 返回合法 JSON。
- 保存后缓存刷新。

## 17. Bot 命令设置验收

后台或 CLI 设置 Telegram 命令列表后，Bot 菜单中应包含：

- `start`
- `help`
- `bind`
- `unbind`
- `info`
- `today`
- `yesterday`
- `week`
- `month`
- `order`
- `settings`

管理员命令可根据需要不暴露在公开命令列表中，但必须可用。

通过标准：

- `setMyCommands` 调用成功。
- Telegram 客户端能看到基础命令。

## 18. 灰度验收

灰度步骤：

1. 仅启用管理员 chat_id。
2. 运行 worker 1 小时。
3. 绑定 1 个测试商户。
4. 完成 1 笔测试订单。
5. 验证订单通知和 `/order` 查询。
6. 观察 24 小时日志。
7. 再开放更多商户。

通过标准：

- 管理员功能正常。
- 测试商户功能正常。
- 支付系统无新增异常。
- worker 24 小时稳定运行。

## 19. 回滚验收

回滚步骤：

```bash
systemctl stop epay-telegram-bot.service
systemctl disable epay-telegram-bot.service
```

必要时后台关闭：

```text
telegram_notice=0
```

通过标准：

- 停止 worker 后支付系统正常。
- 第一阶段通知队列可选择继续保留。
- 后台可正常访问。
- 商户 API 拉单和回调不受影响。
- 不删除 Telegram 表也不会影响系统运行。

## 20. 最终上线条件

满足以下条件才允许正式上线：

- 阻断项全部通过。
- 商户和管理员核心命令全部验收通过。
- 权限越权测试全部被拒绝。
- 机器人 24 小时灰度稳定。
- 支付健康检查通过。
- 回滚演练通过。
- 运维已掌握 systemd 启停和日志查看。
