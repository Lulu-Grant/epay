# 智能健康简报运行手册

当前生产任务运行于 `47.106.222.150` 的 `/srv/epay/current`，使用 PHP 8.4。`epay-health-snapshot.timer`、`epay-health-report.timer` 与 `epay-health-report-retry.timer` 负责快照、首轮日报和续跑；域名与发布规则见[当前生产环境与入口](production-environment.md)。

1207起，日报默认支持 `compact-channel-v2`：本地对已结束自然日逐笔订单执行确定性聚合、脱敏、抽样和证据编号，再由AI完成健康判断；必要时AI可对最多3个通道请求受限逐笔取证。`raw-channel-v1` 继续作为回滚模式。小时快照继续服务历史指标，但不再作为AI日报分母。未完成任务每15分钟续跑，最多等待6小时，每日调用硬上限100次。

## 运行入口

```bash
# 发布前先在仓库验证源文件、目标映射、权限、大小和摘要
/usr/bin/php8.4 scripts/verify-ai-health-release.php

# 在受信源包中验证实际不可变候选目录；安装 systemd 文件后再增加 --verify-absolute
/usr/bin/php8.4 scripts/verify-ai-health-release.php --release-root=/srv/epay/releases/<release-id>
/usr/bin/php8.4 scripts/verify-ai-health-release.php --release-root=/srv/epay/releases/<release-id> --verify-absolute

# 显式安装附加表与队列幂等字段；必须从未切换的不可变候选目录执行
/usr/bin/php8.4 /srv/epay/releases/<release-id>/health_install.php

# 保存上一个完整小时的聚合快照
/usr/bin/php8.4 /srv/epay/current/health_snapshot.php

# 开关关闭时进行人工验证；最多回算最近 12 个完整小时
/usr/bin/php8.4 /srv/epay/current/health_snapshot.php --force --backfill=6

# 只固化昨天的原始数据统计，不调用 AI、不发送（诊断用）
/usr/bin/php8.4 /srv/epay/current/health_report.php --force

# 只读比较原始模式与紧凑模式负载，不调用AI、不写报告、不发送
/usr/bin/php8.4 /srv/epay/current/scripts/health-compact-payload-compare.php "$(date -d yesterday +%F)"

# 生成指定日期 AI 日报并在成功后进入 Telegram 队列；--send 隐含 --ai
/usr/bin/php8.4 /srv/epay/current/health_report.php 2026-07-18 --send

# 显式生成 AI 建议，不发送
/usr/bin/php8.4 /srv/epay/current/health_report.php 2026-07-18 --ai --force
```

三个业务入口只能在 CLI 运行，并使用 MySQL `GET_LOCK` 防止任务重叠。只有显式 `--ai` 或隐含 AI 的 `--send` 才允许调用外部模型；不带二者只保存原始口径统计，该报告不可进入 Telegram 队列。systemd 日报服务固定使用 `--send --ai`。原始日报单日支持上限为 10000 笔，超过时失败关闭，不截断数据生成部分简报。安装必须显式执行；后台 GET 和定时任务不会自动执行 DDL。默认关闭快照、日报和 AI，不会修改订单、通道或商户数据。清单中的 `{RELEASE_ROOT}` 只能解析到单一的新建不可变发布目录，不能解析为活动软链接 `/srv/epay/current`。

## AI 密钥

首次创建时将 `deploy/ai-health.env.example` 原子写入 `/etc/epay/ai-health.env`。以下命令在目标存在时立即失败，不会覆盖已有密钥；密钥轮换必须使用另行审批的原子替换流程：

```bash
/bin/bash -eu <<'EOF'
install -d -o root -g root -m 0750 /etc/epay
(
  set -o noclobber
  umask 0137
  cat deploy/ai-health.env.example > /etc/epay/ai-health.env
)
chown root:www-data /etc/epay/ai-health.env
chmod 0640 /etc/epay/ai-health.env
EOF
```

`HEALTH_AI_ALLOWED_HOSTS` 必须精确列出 API 基址主机，多个主机使用英文逗号分隔。客户端仅接受 HTTPS 443、校验证书和域名、禁止重定向，并限制响应体为 1 MiB。

`HEALTH_AI_ALLOWED_MODELS` 必须精确列出允许模型。只有本地代理返回 Fake-IP 时才配置 `HEALTH_AI_PINNED_IPV4=主机=公网IPv4`；该地址仍须通过全局可路由校验。

## Telegram 代理边界

Telegram 客户端继续使用站点现有的显式代理配置，并始终严格校验 Telegram TLS 证书和域名。带用户名/密码的 HTTP、SOCKS4、SOCKS5 或 SOCKS5H 代理认证在应用到代理这一跳本身不具备 TLS 保护，因此只能使用受控的专用代理：限制来源为支付服务器 IP、使用独立低权限凭据、定期轮换并禁止与其他业务共用。无法满足该边界时不得配置可复用代理密码，应改用网络层访问控制或受 TLS 保护的代理方案。

## systemd

```bash
install -m 0644 deploy/systemd/epay-health-* /etc/systemd/system/
systemctl daemon-reload
systemctl enable epay-health-snapshot.timer epay-health-report.timer epay-health-report-retry.timer
systemctl start epay-health-snapshot.timer
# 确认 AI 数据出站边界、调用额度和 Telegram 接收方后再启动日报 timer
systemctl start epay-health-report.timer
systemctl start epay-health-report-retry.timer
systemctl list-timers 'epay-health-*'
```

服务器时区必须为 `Asia/Shanghai`，两个 timer 的 `OnCalendar` 也显式携带 `Asia/Shanghai`，不能依赖主机默认时区。快照每小时第 10 分钟运行并滚动回算最近 6 小时；回算窗口必须严格大于 3 小时结算成熟窗口。日报每天 09:05 生成昨天的完整数据。Telegram worker 使用数据库会话锁避免定时任务与后台手工触发并发发送；日报另有报告级去重键，并区分“已认领未投递”和“已开始投递”。只有前者可在 worker 中断后自动恢复，投递结果不确定时进入人工复核。健康简报在入队事务中解析并冻结有效 Chat ID，worker 不得使用发送时的可变管理员配置替换目的地。健康队列读取或维护失败会被隔离，不阻断既有订单、结算等通知；每批最多处理 10 条日报，并为既有通知保留处理容量。

Web 兼容入口 `telegram_notify_cron.php` 优先接受 `Authorization: Bearer <cronkey>`，并以 `hash_equals` 严格比较；查询参数 `?key=` 只为兼容旧调度保留，会把密钥暴露给访问日志和中间层，生产应改用 CLI/systemd 或 Bearer 头。既有订单、结算等旧通知仍采用“发送后再更新队列”的历史流程：数据库会话锁只能防止并发 worker，无法消除外部发送成功后、状态更新前进程崩溃造成的重复通知窗口。该残余风险不得描述为 exactly-once；上线期间需监控同一队列 ID 的重复消息，状态机重构应另立项目并单独验收。

## 降级和停用

- 关闭后台“AI 订单健康分析”后，自动每日简报不能开启；人工可仅生成本地客观口径统计，但不会生成或发送规则版降级简报。
- AI 出站内容包含当日逐笔订单、关联投诉、通道元数据、客观聚合指标和七日对照。密码、token、API key、Authorization、Cookie 和私钥形式的字段在本地脱敏，URL 查询参数和片段全部移除；订单号、商户号、IP、买家字段、回调基础地址和投诉内容按已确认的业务需求发送到配置的外部模型。启用前必须确认该数据边界符合组织隐私和合规要求。
- 关闭后台“每日简报”后，仍可保存快照和手工生成历史报告；系统停止未来自动生成和新入队，但已排队、等待重试或正在发送的 `daily_health` 任务仍按原去重键继续处理。该开关不是取消指令。
- 停用调度：`systemctl disable --now epay-health-snapshot.timer epay-health-report.timer epay-health-report-retry.timer`。
- `ai_status=4` 表示等待重试，`ai_status=5` 表示6小时窗口或100次预算耗尽；二者都不能进入正常Telegram日报队列。
- `ai_status=5` 不会自动重开。故障修复或新自然日预算可用后，管理员可在未入队前点击“生成 AI 健康简报”受控重开，或使用 `health_report.php <日期> --ai --force`。已入队/已送达报告仍不可改写。
- AI任务表仅保留来源行数、载荷哈希、请求/响应哈希和AI结构化结果，不保存逐笔订单请求正文。
- AI 或 Telegram 故障不会改变订单状态、通道配置或路由。
- Telegram 已成功记录按配置天数清理，所有终态失败记录统一保留 30 天后清理，避免长期保存订单与登录通知参数。
- 订单、投诉或对照查询失败、单日订单超过 10000 笔或 AI 返回无法通过证据校验时，报告保持待重试/已终止，不生成部分或本地降级简报。

## 验收查询

```sql
SELECT snapshot_time,period_start,period_end,scope_type,COUNT(*)
FROM {表前缀}_health_snapshot GROUP BY snapshot_time,period_start,period_end,scope_type
ORDER BY snapshot_time DESC LIMIT 20;

SELECT report_date,health_level,ai_status,telegram_status,ai_error
FROM {表前缀}_health_report ORDER BY report_date DESC LIMIT 10;
```

将 `{表前缀}` 替换为生产 `config.php` 中的 `dbqz`。小时快照完整性用于历史趋势与独立快照验收，不决定日报是否调用AI。紧凑模式需核对 `ai_source_rows`、`ai_sample_rows`、`ai_drilldown_rows`、`ai_input_tokens`、`ai_calls`、`ai_pipeline_version=compact-channel-v2`、`ai_status` 和 `ai_next_retry_at`。异常时将 `health_ai_payload_mode` 切回 `raw`，不删除既有任务或报告。

`epay-health-report.service` 成功仅代表日报已生成并可靠入队，不代表 Telegram 已送达。必须同时监控既有 Telegram worker、`daily_health` 队列中超过 10 分钟的待发送/认领记录及报告投递状态；worker 不健康时不得启用日报 timer。

送达失败或不确定的日报不会自动重发。运维人员必须先在 Telegram 与队列日志中确认是否已送达，再通过单独审批的 attempt 流程处理；当前后台不提供无确认的一键补发。
