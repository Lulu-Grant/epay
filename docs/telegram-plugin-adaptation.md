# Telegram 机器人通知插件剥离与适配分析

来源压缩包：`/Volumes/数据/迁移自Mac/Users/apple/Downloads/1657614.zip`

剥离目录：`tools/telegram-plugin-extracted/`

## 剥离文件

- `admin/telegram_set.php`
- `admin/ajax_telegram.php`
- `user/telegram.php`
- `telegram_polling.php`
- `telegram_notify_cron.php`
- `includes/lib/Telegram/BotAPI.php`
- `includes/lib/Telegram/BotService.php`
- `includes/lib/Telegram/MessageHandler.php`
- `includes/lib/Telegram/NotifyHelper.php`
- `includes/lib/Telegram/QueueHelper.php`
- `install/addon_telegram.sql`

## 插件能力

- 管理员配置 Bot Token、管理员 Chat ID、机器人用户名。
- 商户通过 Telegram 机器人绑定商户号。
- 支持商户接收订单、结算、登录、投诉、余额不足等通知。
- 支持管理员通过机器人查询今日、昨日、订单、通道统计等信息。
- 支持通知队列表 `telegram_notify_queue`，避免支付流程直接阻塞在 Telegram API。

## 当前项目兼容性

后端类库基本可用。当前项目的自动加载器会把 `lib\Telegram\BotAPI` 映射到 `includes/lib/Telegram/BotAPI.php`，所以类库目录放入 `includes/lib/Telegram/` 后可以被加载。

数据库结构基本可用。`addon_telegram.sql` 使用 `pre_` 表前缀，部署时需要按当前 `config.php` 的 `dbqz` 转换为实际表前缀。

通知触发点可复用。当前项目已经统一通过 `\lib\MsgNotice::send($scene, $uid, $param)` 发送订单、结算、登录、投诉等通知，Telegram 适合挂在这个入口。

## 不能直接安装的原因

1. 当前项目没有 `addon_update()` 函数，而插件后台页 `admin/telegram_set.php` 直接调用它，原样放入会 fatal。
2. 插件页面来自另一套新版后台 UI，使用 `card card-flush`、`form-select`、`ki-duotone` 等样式，与当前 Bootstrap 3 后台不一致，需要重写页面。
3. `user/telegram.php` 存在明显逻辑错误：Bot Token 已配置时反而返回 403，条件应反过来。
4. 插件版 `includes/lib/MsgNotice.php` 是整文件替换版，包含语音、打印、短信、机器人 Webhook 等大量当前项目没有的依赖，不能直接覆盖当前 `MsgNotice.php`。
5. `telegram_polling.php` 是长驻轮询脚本，需要进程守护。仅靠普通 cron 不稳定，生产环境更适合 systemd/supervisor，或改为 Telegram webhook。
6. 插件使用“商户号 + 商户密钥”在 Telegram 内绑定，能用，但会让商户密钥出现在聊天记录中。更稳妥的方式是改成用户中心生成一次性绑定码。

## 推荐适配方案

第一阶段只接入通知能力，不接入复杂查询机器人。

- 新增 `includes/lib/Telegram/` 类库。
- 新增 `telegram_notify_cron.php`。
- 执行 `addon_telegram.sql` 对应的建表 SQL。
- 增加配置项：
  - `telegram_bot_token`
  - `telegram_admin_chat_id`
  - `telegram_bot_name`
  - `telegram_notice`
  - `telegram_proxy`
  - `telegram_proxy_server`
  - `telegram_proxy_port`
  - `telegram_proxy_user`
  - `telegram_proxy_pwd`
  - `telegram_proxy_type`
- 在 `MsgNotice::send()` 中最小化加入 Telegram 入队逻辑，不覆盖原有微信/邮件逻辑。
- 新增 Bootstrap 3 风格的后台配置页。
- 商户绑定先用后台手工绑定 Chat ID，或用户中心生成一次性绑定码。

## 本次已适配内容

- 已新增 `includes/lib/Telegram/BotAPI.php`。
- 已新增 `includes/lib/Telegram/Installer.php`。
- 已新增 `includes/lib/Telegram/NotifyHelper.php`。
- 已新增 `includes/lib/Telegram/QueueHelper.php`。
- 已新增 `install/addon_telegram.sql`。
- 已新增 `admin/telegram_set.php`，使用当前项目 Bootstrap 3 后台风格。
- 已新增 `admin/ajax_telegram.php`，用于测试消息、绑定商户、解绑、删除绑定、手动处理队列。
- 已新增 `telegram_notify_cron.php`，用于定时处理 Telegram 通知队列。
- 已修改 `includes/lib/MsgNotice.php`，在现有微信/邮件通知之外增加 Telegram 队列入队。
- 已修改 `admin/head.php`，在系统设置菜单加入 Telegram 通知设置入口。

本次适配没有接入完整 `telegram_polling.php` 长驻机器人，也没有复制原包的 `MessageHandler.php`。当前版本以“后台配置 + 手工绑定 Chat ID + 队列发送通知”为主，降低对支付主流程的影响。

## 启用步骤

1. 进入后台 `Telegram通知设置` 页面，页面会自动初始化所需数据表。
2. 填写 `Bot Token`、`管理员 Chat ID`、机器人用户名，并开启 Telegram 通知。
3. 服务器无法直连 Telegram 时，开启“Telegram专用代理”，填写代理地址、端口和认证信息，并优先选择 `SOCKS5H` 让代理端解析域名。该配置只作用于 Telegram Bot API，不影响支付通道和商户回调。
4. 点击“发送测试消息”，确认服务器可访问 Telegram API。
5. 在绑定区域填写商户号和 Telegram Chat ID。
6. 增加计划任务：

```bash
* * * * * cd /www/wwwroot/epay.tianlupay.com && php telegram_notify_cron.php >/dev/null 2>&1
```

第二阶段再接入完整机器人交互。

第二阶段开发与验收文档：

- [Telegram 长驻交互机器人开发文档](telegram-long-running-bot-development-plan.md)
- [Telegram 长驻交互机器人验收标准](telegram-long-running-bot-acceptance-criteria.md)

- 修复 `user/telegram.php` 的 403 判断。
- 适配 `user/ajax2.php?act=saveTelegramNotify`。
- 适配商户侧菜单。
- 守护 `telegram_polling.php`，或改成 webhook。
- 测试 `/start`、绑定、解绑、订单查询、通知设置。

## 验收标准

- 未配置 Bot Token 时，不影响现有订单、结算、登录、投诉通知。
- 配置 Bot Token 后，订单支付成功不会因 Telegram API 超时阻塞支付流程。
- 新订单、结算、登录、投诉至少四类通知可进入队列表。
- 队列脚本能成功发送消息，并记录失败次数与错误信息。
- 管理员后台可以保存 Bot Token、管理员 Chat ID，并能发送测试消息。
- 商户绑定后可以按通知类型开关 Telegram 通知。
- Telegram 专用代理启用后，Bot API 请求使用专用代理，支付通道与商户异步通知保持原网络路径。
- `SOCKS5H` 模式由代理服务器解析 `api.telegram.org`，避免本机 DNS 或网络策略导致连接超时。
- 原有微信模板消息和邮件通知行为不变。
- PHP 8.4 下 `php -l` 全部通过。
