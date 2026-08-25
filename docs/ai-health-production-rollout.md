# 智能健康简报生产发布与回滚方案

## 发布边界

- 只部署 `deploy/ai-health-release-files.txt` 明确列出的智能健康快照、日报、后台查看页、Telegram `daily_health` 场景及配套调度文件。应用目标中的 `{RELEASE_ROOT}` 必须一次性解析为同一个新的不可变目录 `/srv/epay/releases/<release-id>`，禁止解析为 `/srv/epay/current`。
- 在受信源包执行 `/usr/bin/php8.4 scripts/verify-ai-health-release.php`，复制后从同一个源包增加 `--release-root=/srv/epay/releases/<release-id>`；安装 systemd 文件后增加 `--verify-absolute`。校验器按 `source\0destination\0mode\0size\0file_sha256\0` 的排序记录和固定上下文复算绑定摘要，并逐个复核实际目标文件、权限、大小、摘要及软链接祖先。校验脚本、映射和摘要属于发布证据，不进入 Web 运行目录。
- 不修改支付下单、通道选择、订单状态、异步回调、商户通知或用户组配置。
- 新认领、退避和送达不确定状态机只作用于 `daily_health`；数据库 worker 会话锁覆盖所有 Telegram 场景，避免定时任务与后台手工入口并发消费。关闭健康开关只阻止未来生成、AI 请求和新入队；worker 仍会安全处理已经入队的日报。既有订单、结算通知仍存在外部发送成功后、队列状态更新前进程崩溃导致的重复窗口，本次发布不声称旧场景 exactly-once。
- 首次发布保持 `health_snapshot_enabled=0`、`health_report_enabled=0`、`health_ai_enabled=0`，先验证规则版报告，再分别启用快照、日报和 AI。
- API 密钥只保存于 `/etc/epay/ai-health.env`，不得进入 Web 目录、数据库、Git、命令行参数或发布日志。
- `config.php` 不属于本次部署载荷；切换前后必须断言它仍是 `/srv/epay/shared/config.php` 的同一软链接，且目标摘要不变。
- AI 使用独立供应商项目和独立密钥，并在供应商侧设置日/月硬预算与告警；应用内调用次数限制只是第二道保护。
- 当前站点采用目录发布、`current` 软链接和附加数据表。本次部署必须经过支持该发布形态的正式发布门禁；仅有代码审查结论不能授权上线。

## 发布前检查

1. 先按 `docs/ai-health-release-rehearsal.md` 完成本地正向发布和三类故障回滚演练，封存 `evidence.json`；该证据不等同于生产授权。
2. 记录当前 `/srv/epay/current` 指向、Git 提交、PHP 8.4 版本和服务状态。
3. 备份数据库结构、`pre_config`、Telegram 配置以及完整 `pre_telegram_notify_queue` 表结构和数据；附加健康表不存在时只记录为空，不创建占位表。
4. 在独立候选目录运行 PHP 8.4 语法检查、健康简报回归、支付回调回归和凭据扫描；在名称以 `_local` 或 `_test` 结尾的隔离数据库执行 `scripts/health-report-db-regression.php`，并要求既有通知成功、关闭绑定、三次失败终止、锁竞争、健康通知共存及冻结接收方六项断言全部为真。
5. 核对 SQL 仅新增 `pre_health_snapshot`、`pre_health_report` 及关闭状态的配置键，不包含订单、通道、商户表的更新或删除。
6. 确认生产服务器磁盘、内存、负载、MariaDB、Nginx、PHP-FPM 和 Telegram 队列正常。
7. 使用轮换后的独立 AI 密钥；供应商基址和允许主机必须完全一致并使用 HTTPS 443。

## 灰度步骤

1. 从当前生产发布目录复制到新的不可变候选目录，不覆盖当前目录；保留生产 `config.php`，不得用仓库中的空数据库配置覆盖。
2. 将清单中的 `{RELEASE_ROOT}` 固定解析为新建的 `/srv/epay/releases/<release-id>`，只覆盖清单源文件到该不可变候选目录；systemd 文件仍使用清单中的绝对目标。禁止直接写入 `/srv/epay/current`。设置代码为只读，确认配置软链接、共享目录和上传目录权限未改变。
3. 复制后必须对实际候选目录执行 `/usr/bin/php8.4 scripts/verify-ai-health-release.php --release-root=/srv/epay/releases/<release-id>`；安装 systemd 文件后再以 `--verify-absolute` 复核绝对目标。任何目标漂移、权限差异或软链接祖先均立即停止。
4. 暂停 Telegram 定时 worker 和后台手工队列处理入口，确认没有运行中的消费者；预检队列无重复非空去重键并完成全表备份。
5. 从候选目录执行 `/usr/bin/php8.4 /srv/epay/releases/<release-id>/health_install.php`。迁移会将 metadata lock 与 InnoDB lock 等待限制为 5 秒；复核两个新表及 Telegram 队列的去重、认领、投递开始和退避字段，失败时不得切换代码。
6. 核对正式审查包已在隔离数据库执行共享 Telegram worker 回归并封存六项断言；生产库只检查迁移前后队列数量、重复去重键及遗留 `status=3`，不得为了验收向真实管理员或商户注入测试通知。确认异常计数为零后再恢复 Telegram worker。
7. 原子切换 `/srv/epay/current` 到候选目录并平滑重载 PHP 8.4 OPcache。
8. 保持三个功能开关关闭，验证后台、商户登录、API 根路径策略、下单页和现有支付回调。
9. 保持开关关闭，以 `--force --backfill=6` 手工运行小时快照；确认只读统计、锁和滚动回算正常。
10. 完成生产 `EXPLAIN` 和受控负载检查后才启用快照，观察至少两个完整小时，确认每小时重算最近窗口且任务不重叠。
11. 累积并验收 24 个连续小时后启用“每日简报”，先手工入队一条规则版报告，确认 Telegram 发送成功且不泄露订单明细或密钥。
12. 最后启用“AI 调查建议”，确认供应商硬预算生效后手工增强同一份未发送规则报告；AI 只能选择本地规则的优先复核项，事实、证据和排查动作均由本地代码定义。失败时必须保留规则版结果且不重复发送。
13. 所有验收通过后再启用日报 timer；生产 AI 调用先保持每日一次，不增加自动通道操作。

## 验收标准

- Nginx、PHP-FPM、MariaDB、Telegram 服务均为 active，生产域名无新增 5xx。
- 支付、回调和商户通知回归结果与发布前一致，订单成功率无异常下降。
- 小时快照任务在 60 秒内完成，不锁订单表，不重复写入同一统计窗口。
- 规则版日报无需外网即可生成；数据不足时明确标记 `data_complete=false`，且不得掩盖最终通知失败、冻结等可直接确认的硬故障。
- AI 请求只包含脱敏后的本地规则命中项，不包含订单、商户或完整指标，并严格校验 TLS、域名、响应大小和 JSON 结构。
- AI 超时、HTTP 错误、非法 JSON 或未知字段时，报告降级成功且支付业务不受影响。
- Telegram 只发送管理员简报，HTML 和纯文本均正确，队列具备幂等保护。
- 既有 Telegram worker 必须健康，`daily_health` 入队后 10 分钟内应进入已发送或明确失败状态；systemd 日报任务成功只证明生成与入队。
- 后台设置默认关闭，未登录访问被拒绝，手工生成与发送操作具备 CSRF 和权限校验。
- 连续观察至少 24 小时后，应有 24 个完整小时的全站快照；缺失快照必须可见且不可由 AI 掩盖。

## 自动停止与回滚条件

出现以下任一情况立即停止 timer、关闭两个功能开关并回滚代码：

- 新增支付链路 5xx、回调延迟或订单成功率明显下降。
- 快照查询造成数据库负载、锁等待或慢查询持续升高。
- 报告向错误 Chat ID 发送、出现原始订单明细、密钥或个人数据。
- AI 客户端绕过主机允许列表、TLS 校验失败仍继续、响应未校验即入库。
- 定时任务重叠、报告重复发送或一分钟内持续重试。

回滚顺序：

1. `systemctl disable --now epay-health-snapshot.timer epay-health-report.timer`，并暂停 Telegram 定时 worker、后台手工队列处理和其他消费者。
2. 等待在途 Telegram 请求超过其 30 秒超时后，将所有未完成的 `daily_health` 队列行标记为终止失败并进入人工复核。不得把 `status=0` 或 `status=3` 恢复给旧 worker，绝不自动重发。

```sql
START TRANSACTION;

UPDATE {表前缀}_telegram_notify_queue
SET status=2, claimtime=NULL, next_attempt=NULL,
    error_msg='Daily health delivery cancelled during rollback'
WHERE scene='daily_health' AND status IN (0,3);
SET @cancelled_health_rows = ROW_COUNT();

UPDATE {表前缀}_health_report r
INNER JOIN {表前缀}_telegram_notify_queue q ON q.id=r.telegram_queue_id
SET r.telegram_status=3
WHERE q.scene='daily_health' AND q.status=2 AND r.telegram_status<>2;
SET @projected_health_rows = ROW_COUNT();

SELECT @cancelled_health_rows cancelled_health_rows,
       @projected_health_rows projected_health_rows,
       (SELECT COUNT(*) FROM {表前缀}_telegram_notify_queue WHERE scene='daily_health' AND status IN (0,3)) remaining_queue_rows,
       (SELECT COUNT(*) FROM {表前缀}_health_report r LEFT JOIN {表前缀}_telegram_notify_queue q ON q.id=r.telegram_queue_id
        WHERE r.telegram_status=1 AND (q.id IS NULL OR q.status NOT IN (0,3))) divergent_report_rows;
```

执行前必须将 `{表前缀}` 替换为生产 `dbqz`；必须保存事务前后的行数证据。上述批次故意不包含 `COMMIT`。只有人工确认 `remaining_queue_rows=0` 且 `divergent_report_rows=0` 后，才在同一数据库会话中单独执行 `COMMIT;`；任一断言非零时执行 `ROLLBACK;` 并调查。断开会话前必须明确提交或回滚。

3. 在数据库中将 `health_snapshot_enabled`、`health_report_enabled` 和 `health_ai_enabled` 全部设为 `0` 并清理配置缓存，确认没有遗留认领行后再切回旧代码。
4. 原子恢复 `/srv/epay/current` 到发布前目录，断言 `config.php` 软链接及目标摘要不变，平滑重载 PHP 8.4。
5. 复测后台登录、商户登录、API 下单、支付回调和 Telegram 原有通知，再恢复 Telegram worker。
6. 保留附加健康表和新增队列列用于审计，不在紧急回滚中删除数据表；确认稳定后再另行审批清理。
7. 封存候选清单、数据库备份、检查输出、时间线和失败原因。

## 观察指标

- PHP-FPM 5xx、慢请求、worker 饱和度和内存。
- MariaDB 慢查询、锁等待、连接数和 CPU。
- 每小时快照数量、耗时、数据完整性和重复键冲突。
- 日报生成耗时、AI 成功/降级状态、Telegram 入队和发送状态。
- 全站及主要通道订单量、曾支付成功率、退款/冻结数、最近成功时间和商户通知失败率。
