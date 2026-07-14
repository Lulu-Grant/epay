# Telegram 长驻交互机器人验收记录

生成时间：2026-07-07 22:28

## 范围

本记录对应以下两份文档的目标模式实现：

- `docs/telegram-long-running-bot-development-plan.md`
- `docs/telegram-long-running-bot-acceptance-criteria.md`

## 本地验证

- PHP 语法检查通过：
  - `includes/lib/Telegram/BotAPI.php`
  - `includes/lib/Telegram/BotService.php`
  - `includes/lib/Telegram/MessageHandler.php`
  - `includes/lib/Telegram/Installer.php`
  - `telegram_bot_worker.php`
  - `admin/ajax_telegram.php`
  - `admin/telegram_set.php`
  - `user/ajax_telegram.php`
  - `user/telegram.php`
- PHP 7.4 兼容性底线检查通过：
  - `tools/php84/check-php74-floor.php`
  - 检查 PHP 文件数量：428
- 命令处理器仿真通过：
  - `/start`
  - `/bind BADCODE`
  - `/order T1`
  - `/queue`
  - `/admin_today`
  - 通知开关 callback
  - 常用中文菜单入口

## 线上部署验证

线上目录：`/www/wwwroot/epay.tianlupay.com`

备份目录：`/root/epay-backups/telegram-bot-worker-20260707-221949`

已部署文件：

- `includes/lib/Telegram/BotAPI.php`
- `includes/lib/Telegram/BotService.php`
- `includes/lib/Telegram/MessageHandler.php`
- `includes/lib/Telegram/Installer.php`
- `admin/ajax_telegram.php`
- `admin/telegram_set.php`
- `user/ajax_telegram.php`
- `user/telegram.php`
- `user/head.php`
- `telegram_bot_worker.php`
- `install/addon_telegram.sql`

线上数据库安装验证：

- `pay_telegram_admin_settings`
- `pay_telegram_bind`
- `pay_telegram_bind_code`
- `pay_telegram_notify_queue`
- `pay_telegram_update`
- `addon_telegram = 1100`

线上服务验证：

- systemd 服务：`epay-telegram-bot.service`
- 启动方式：`/www/server/php/84/bin/php /www/wwwroot/epay.tianlupay.com/telegram_bot_worker.php`
- 服务状态：`active (running)`
- 长轮询状态缓存：
  - `state = running`
  - `last_error = null`
  - `error_count = 0`
  - `bot_username = TianlupayBot`

线上 Telegram API 验证：

- 默认命令设置成功。
- 生产环境处理器级 `/start` 测试发送成功。
- Telegram API 返回 `error = null`。

## 已修正问题

- `getUpdates` 长轮询的 curl 超时时间从固定 30 秒改为根据 Telegram timeout 自动增加余量，避免正常长轮询被误记为失败。
- worker 在空更新正常返回时会清除上一轮临时错误，后台健康状态不再残留过期错误。
- 后台 Telegram 配置页不再回显完整 Bot Token，空输入代表保留原 token。
- 首次启动 worker 默认跳过历史 update，避免上线后批量回复旧消息。

## 验收结论

当前长驻机器人开发、部署和核心验收通过。
建议人工补测一次真实 Telegram 入站链路：在 Telegram 中向机器人发送 `/start`、`/admin`、`/queue`，并在商户后台生成绑定码后测试 `/bind 绑定码`。

## 运维命令

```bash
systemctl status epay-telegram-bot.service
journalctl -u epay-telegram-bot.service -f
systemctl restart epay-telegram-bot.service
cd /www/wwwroot/epay.tianlupay.com && /www/server/php/84/bin/php telegram_bot_worker.php --once
```
