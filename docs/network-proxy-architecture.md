# 网络代理架构与开发约束

更新日期：2026-08-06
适用环境：生产服务器 `47.106.222.150`，PHP 8.4
文档目的：记录当前所有出站代理的职责、配置位置、代码入口和安全边界，防止后续开发把支付、Telegram、商户通知等流量混用。

## 1. 总体原则

当前系统不是“一台代理承载全部网络请求”，而是按业务用途拆分为三条专用代理链：

1. 商户异步通知代理：只访问商户提供的 `notify_url`。
2. Telegram 专用代理：只访问 Telegram Bot API。
3. 14 号支付通道上游代理：只访问 `pay.lishaopay.top` 的服务器端接口。

此外还保留一套旧的“中转代理”配置。它只影响调用通用 `curl_get()` 的代码，并不是真正覆盖全部出站请求。生产环境当前未启用这套全局配置。

支付宝、微信等支付机构接口、AI 健康分析接口和普通站点请求不得因为上述三条专用代理而改变网络路径。

## 2. 当前生产状态

下表状态于 2026-07-24 在生产服务器核验：

| 流量 | 当前状态 | 代理类型 | 配置来源 | TLS 校验 | 失败策略 |
| --- | --- | --- | --- | --- | --- |
| 商户异步通知 | 已启用；UID 1004 例外直连 | SOCKS5H | `/etc/epay/merchant-notify-proxy.ini` | 当前关闭，沿用旧行为 | 保持订单通知失败状态，由既有重试机制处理 |
| Telegram Bot API | 已启用 | SOCKS5H | 数据库 `pre_config` 的 `telegram_proxy_*` | 严格开启 | 进入 Telegram 队列重试，不影响支付 |
| 14 号通道上游 | 已启用 | SOCKS5H | `/etc/epay/upstream_proxy` | 严格开启 | mapi 网络或格式异常时回退浏览器 GET |
| 旧全局中转代理 | 未启用 | 可配置 | 数据库 `pre_config` 的 `proxy_*` | 通用 `curl_get()` 当前不校验证书 | 专用代理未启用时，部分旧代码可能使用它 |
| AI 健康分析 API | 强制直连 | 无 | `/etc/epay/ai-health.env` | 严格开启 | AI 任务失败，不影响订单与支付 |
| 其他支付插件 | 默认直连 | 无 | 各支付通道配置 | 由各插件自行决定 | 按插件原逻辑处理 |

代理服务器地址、账号、密码、Bot Token、支付密钥和 AI API Key 不得写入本文档、Git 仓库、Issue、日志或聊天记录。

## 3. 流量关系

```text
商户支付成功
  -> do_notify()
  -> merchant_notify_request()
  -> 商户通知专用 SOCKS5H
  -> 商户 notify_url

Telegram 通知队列/后台测试
  -> Telegram\BotAPI
  -> Telegram 专用 SOCKS5H
  -> https://api.telegram.org

14 号通道 appswitch=1
  -> epay_plugin::pay_mapi()
  -> EpayCore::getHttpResponse()
  -> 仅当目标主机为 pay.lishaopay.top 时使用上游 SOCKS5H
  -> 上游 mapi.php/api.php

AI 健康日报
  -> Health\AiClient
  -> 显式清空代理
  -> 配置的 HTTPS AI 端点
```

浏览器访问 `api.xuanfanpay.top`、`luckrun.xuanfanpay.top` 和 `manage.xuanfanpay.top` 属于入站流量，不经过上述 PHP 出站代理。Nginx 到 PHP-FPM 的 FastCGI 转发也不属于本文所说的网络代理。

## 4. 商户异步通知代理

### 4.1 职责

只代理平台向商户 `notify_url` 发出的通知，包括：

- 支付完成后的即时异步通知。
- `cron.php?do=notify` 定时补发。
- `notify2` 补偿任务。
- 管理后台手工重新通知。

这些入口最终都必须经过 `do_notify()`，才能共享同一代理和成功判断逻辑。

### 4.2 代码与配置

- 代码入口：`includes/functions.php`
- 主调用：`do_notify()`
- HTTP 实现：`merchant_notify_request()`
- 配置读取：`getMerchantNotifyProxyConfig()`
- 默认配置：`/etc/epay/merchant-notify-proxy.ini`
- 可选覆盖环境变量：`EPAY_MERCHANT_NOTIFY_PROXY_FILE`
- 生产权限：`root:www-data`，文件模式 `640`

配置文件只允许保存在 Web 根目录外。结构示例仅展示字段，不填写真实值：

```ini
enabled=1
server=[secret]
port=[secret]
user=[secret]
password=[secret]
connect_timeout=5
timeout=10
tls_verify=0
direct_uids=1004
```

### 4.3 网络行为

- 使用 SOCKS5H，由代理端解析商户域名。
- 连接超时 5 秒，总超时 10 秒。
- 最多手工跟随 3 次重定向，后续跳转仍使用同一代理。
- 只接受 `http` 和 `https` 通知地址。
- 拒绝带 URL 用户凭据、CRLF 或无法解析的地址。
- 返回正文包含 `success`、`SUCCESS` 或 `Success` 时视为通知成功。
- `direct_uids` 中的商户显式设置空代理与 `NOPROXY=*`，不继承专用代理、旧全局代理或环境代理。

生产配置有效且启用时，请求失败不会在同一次尝试中改走直连，避免泄露源站 IP。订单通知状态和既有重试计划继续决定后续补发。

如果配置文件缺失、被关闭或解析无效，代码会退回旧的 `curl_get()` 路径。此时会根据旧全局代理开关决定走全局代理或直连。因此，生产环境必须监控配置文件可读性，不能把“文件丢失”视为安全的代理故障。

### 4.4 当前风险

- 生产配置 `tls_verify=0`，这是为保持原有商户兼容性而暂时保留的行为。HTTPS 内容仍加密，但无法可靠验证目标证书和域名，存在中间人风险。
- 仍允许商户使用明文 HTTP 回调地址。
- 当前没有商户回调目标端口白名单。
- 专用代理是单点依赖，需要独立监控连通性和容量。
- UID 1004 因商户回调服务仅允许生产服务器来源 IP，当前作为直连例外。该例外会向商户暴露生产服务器公网 IP，商户放行代理出口并完成验证后应撤销。

后续开启 TLS 校验必须先统计现有商户 HTTPS 证书质量并灰度测试，不能顺手修改。

## 5. Telegram 专用代理

### 5.1 职责

只代理 `includes/lib/Telegram/BotAPI.php` 发出的 Telegram Bot API 请求。支付下单、支付机构接口、商户异步通知和 AI 请求不使用这组配置。

### 5.2 配置优先级

Telegram 客户端按以下顺序选择网络路径：

1. `telegram_proxy=1` 时使用 `telegram_proxy_*` 专用配置。
2. 未开启专用代理但旧 `proxy=1` 时，兼容性回退到旧全局代理。
3. 两者都未启用时直连。

生产环境当前为：

- `telegram_proxy=1`
- `telegram_proxy_type=sock5h`
- 旧全局代理未启用

配置键：

```text
telegram_proxy
telegram_proxy_server
telegram_proxy_port
telegram_proxy_user
telegram_proxy_pwd
telegram_proxy_type
```

这些值由 `admin/telegram_set.php` 管理，校验逻辑位于：

- `admin/ajax.php`
- `includes/lib/Telegram/SettingsService.php`

### 5.3 安全与故障行为

- 每次请求先显式清空环境代理，再按上述优先级设置代理。
- SOCKS5H 由代理端解析 `api.telegram.org`。
- 强制 IPv4。
- 开启 `CURLOPT_SSL_VERIFYPEER=true`。
- 开启 `CURLOPT_SSL_VERIFYHOST=2`。
- 不跟随 HTTP 重定向。
- 专用代理启用但配置不完整时直接报错，不静默回退全局代理或直连。
- Telegram 失败由消息队列重试，不得阻塞订单入账、支付回调或商户通知。
- 错误信息必须脱敏 Bot Token。

## 6. 14 号通道上游代理

### 6.1 当前用途

14 号通道当前状态：

- 插件：`epay`
- 状态：启用
- `appswitch=1`
- 上游主机：`pay.lishaopay.top`

服务器端 mapi 下单、订单查询和退款等 `EpayCore` 请求，只有目标主机严格等于 `pay.lishaopay.top` 时才使用定向 SOCKS5H。其他 `epay` 上游和其他支付插件不应继承这条代理。

### 6.2 代码与配置

- 通道逻辑：`plugins/epay/epay_plugin.php`
- HTTP 客户端：`plugins/epay/inc/EpayCore.class.php`
- 目标主机白名单：`EpayCore::$proxy_hosts`
- 代理配置：`/etc/epay/upstream_proxy`
- 生产权限：`root:www-data`，文件模式 `640`

配置文件当前使用单行代理 URL。本文档不记录其真实内容：

```text
socks5h://[user]:[password]@[server]:[port]
```

### 6.3 网络与降级

- 连接超时 5 秒。
- 总超时 10 秒。
- 开启 TLS 证书校验和域名校验。
- mapi 网络连接失败或返回格式错误时，支付宝支付回退到浏览器 GET 跳转。
- 上游返回明确业务错误时不回退，避免同一订单重复建单。
- 浏览器回退是用户设备直接访问上游，不经过服务器端 SOCKS5H。

### 6.4 生产与本地源码漂移

截至 2026-07-24，生产机以下两个文件包含 14 号通道的定向代理和 mapi 降级补丁，但当前本地工作树同名文件内容不同：

- `plugins/epay/inc/EpayCore.class.php`
- `plugins/epay/epay_plugin.php`

当前本地和生产一致的代理相关文件为：

- `includes/functions.php`
- `includes/lib/Telegram/BotAPI.php`

这是发布阻断项。下一次发布前必须先把生产版 14 号补丁同步回仓库、审查差异并执行回归测试。禁止直接用当前本地 `plugins/epay/` 覆盖生产目录。

## 7. 旧全局中转代理

后台入口为 `admin/set.php?mod=proxy`，配置键为：

```text
proxy
proxy_server
proxy_port
proxy_user
proxy_pwd
proxy_type
```

它的实际边界是 `includes/functions.php::curl_get()`，不是整个 PHP 进程，也不会自动覆盖所有插件：

- 调用 `curl_get()` 的旧代码会使用它。
- `get_curl()` 没有读取这套代理配置。
- 自己创建 cURL 句柄的支付插件通常不会使用它。
- Telegram 在专用代理关闭时可以兼容性回退到它。
- 商户通知专用配置无效或关闭时会退回 `curl_get()`，因而可能使用它。
- AI 客户端显式禁用代理，不使用它。

生产环境当前未发现 `proxy` 配置，即按关闭处理。后续不得为了修复单一业务连通性而开启全局代理，应为该业务增加目标受限的专用客户端。

通用 `curl_get()` 当前关闭 TLS 证书和域名校验。这是遗留技术债，不能把它用于新的高敏感接口。

## 8. 明确保持直连的流量

下列流量不应因商户、Telegram 或 14 号代理改造而改变：

- 支付宝、微信及其他支付插件访问各自官方接口。
- 支付机构向本站发起的异步回调，这是入站请求。
- 商户浏览器打开收银台和支付页面。
- Nginx 与 PHP-FPM、MariaDB 的本机通信。
- AI 健康分析客户端。`includes/lib/Health/AiClient.php` 显式设置空代理和 `NOPROXY=*`。
- 未在专用代理白名单中的 `epay` 上游。

新增支付插件时必须检查插件是否调用 `curl_get()`。如果调用，它可能意外继承旧全局代理。

## 9. 新开发的网络访问规则

任何新增外部 HTTP 客户端必须在代码评审中回答以下问题：

1. 访问的固定主机或允许主机集合是什么？
2. 应当直连、走哪条现有专用代理，还是建立新的专用代理？
3. DNS 在本机还是代理端解析？
4. 是否严格校验 TLS 证书和域名？
5. 连接超时和总超时是多少？
6. 是否允许重定向，重定向后是否重新校验主机、协议和端口？
7. 请求失败能否安全重试，是否存在重复下单、重复退款或重复通知风险？
8. 日志是否会泄露 Token、签名、商户密钥、代理凭据或完整查询参数？
9. 代理故障时应失败关闭、回退直连，还是转入队列？
10. 如何单独关闭和回滚，且不影响其他业务？

开发约束：

- 禁止把代理账号密码硬编码到 PHP、Nginx、systemd unit 或仓库文档。
- 禁止把支付接口临时接入旧全局代理。
- 禁止一个专用代理配置同时控制 Telegram、商户通知和支付上游。
- 禁止用关闭 TLS 校验解决证书或网络问题。
- 禁止在有副作用的 POST 请求上进行无条件自动重试。
- 必须设置连接超时和总超时。
- 必须限制目标协议、主机和异常重定向。
- 必须让非核心通知进入队列或补偿任务，不能拖慢支付主链路。
- 必须记录代理启用状态，但日志中只记录目标主机、HTTP 状态、cURL 错误码和脱敏原因。

## 10. 配置与权限

| 配置 | 存储位置 | 生产权限/保护 | 是否进入仓库 |
| --- | --- | --- | --- |
| 商户通知代理 | `/etc/epay/merchant-notify-proxy.ini` | `root:www-data`，`640` | 否 |
| 14 号上游代理 | `/etc/epay/upstream_proxy` | `root:www-data`，`640` | 否 |
| Telegram 代理 | 数据库 `pre_config` | 后台管理员权限 | 否 |
| AI API 密钥 | `/etc/epay/ai-health.env` | 受限系统文件 | 否 |
| 数据库配置 | `/srv/epay/shared/config.php` | 共享受限文件 | 否 |

凭据轮换时先建立第二个可用出口并完成探测，再原子替换配置。轮换后检查 PHP-FPM 运行用户可读、其他用户不可读，并清理终端历史、临时文件和备份中的明文凭据。

## 11. 验证清单

### 11.1 静态检查

```bash
php8.4 -l includes/functions.php
php8.4 -l includes/lib/Telegram/BotAPI.php
php8.4 -l plugins/epay/inc/EpayCore.class.php
php8.4 -l plugins/epay/epay_plugin.php

stat -c '%n %U:%G %a' \
  /etc/epay/merchant-notify-proxy.ini \
  /etc/epay/upstream_proxy
```

预期两个代理文件均为 `root:www-data 640`。检查时禁止直接 `cat` 文件。

### 11.2 商户通知

- 选择测试商户或已确认可幂等处理的订单。
- 手工通知一次，确认商户返回 `success`。
- 确认订单 `notify` 状态按既有规则更新。
- 检查 PHP-FPM 日志中无新增 `[merchant-notify-http]` 错误。
- 使用网络观测确认请求从代理出口发出，而不是生产服务器公网 IP。
- 不得对未知商户批量重发历史订单。

### 11.3 Telegram

- 在后台发送测试消息。
- 确认 Bot API TLS 校验开启。
- 确认失败消息进入队列重试。
- 确认支付回调耗时不受 Telegram 超时影响。

### 11.4 14 号通道

- 使用受控未支付订单验证 mapi 请求。
- 确认上游收到来自指定代理出口的请求。
- 确认 mapi 返回有效支付地址，且没有重复创建上游订单。
- 模拟网络错误时只允许支付宝分支进入浏览器 GET 降级。
- 模拟业务错误时必须直接报错，不能再次建单。
- 完成一笔小额真实支付，验证上游状态、本站状态和商户通知。

### 11.5 隔离性

- Telegram 代理故障不影响商户通知。
- 商户通知代理故障不影响支付机构接口和订单入账。
- 14 号代理故障不影响其他支付通道。
- AI API 故障不影响订单、支付、结算和 Telegram 队列。

## 12. 故障处理与回滚

### 商户通知代理

1. 先确认配置文件存在、可读且权限正确。
2. 检查代理连通性和 `[merchant-notify-http]` 日志。
3. 保留失败订单的通知状态，等待重试，不批量改成成功。
4. 只有在明确接受源站 IP 暴露后，才能临时关闭专用代理。
5. 回滚代码备份：`/srv/epay/backups/merchant-notify-proxy/functions.php.before-20260724-010442`。

### Telegram 代理

1. 先用后台测试消息确认错误类别。
2. 检查代理配置完整性、SOCKS5H 支持和 Telegram TLS 错误。
3. 保留队列，不删除未发送消息。
4. 关闭专用代理前确认服务器是否具备 Telegram 直连能力。

### 14 号上游代理

1. 检查 `/etc/epay/upstream_proxy` 权限和上游 TLS 错误。
2. 检查 `epay upstream request failed` 日志。
3. 代理故障时可将 14 号通道 `appswitch` 恢复为 `0`，使用已审计的浏览器 GET 路径。
4. 不回滚已经创建的订单，不重复发起同一上游订单。
5. 已有数据库回滚副本：`/root/pay_channel_14_before_mapi_20260717-0412.sql`。

## 13. 变更记录

| 日期 | 变更 |
| --- | --- |
| 2026-07-17 | 14 号通道切换为 mapi 服务器端下单，并为 `pay.lishaopay.top` 增加定向 SOCKS5H。 |
| 2026-07-18 | Telegram Bot API 恢复严格 TLS 证书与域名校验。 |
| 2026-07-24 | 新增商户异步通知专用 SOCKS5H，不改变 Telegram、支付机构和其他插件网络路径。 |
| 2026-07-24 | UID 1004 的商户回调明确返回 `ip not allowed`，临时加入 `direct_uids`，其余商户继续使用专用代理。 |
| 2026-07-24 | 核验生产代理状态，并记录 14 号插件生产/本地源码漂移。 |
