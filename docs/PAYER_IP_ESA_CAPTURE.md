# 网关收银页付款人 IP 采集

2026 年 10 月 21 日开发，10 月 22 日发布并启用。适用 PHP 8.4。分支 `feature/payer-ip-esa` 已推送，P5 运行版本为 `a2599d9`，已采用 `origin_auth` 模式开启采集，明确读取 `Ali-Real-Client-Ip`，无需维护回源网段列表。详见 [回源鉴权发布与启用记录](PAYER_IP_ORIGIN_AUTH_P5_RELEASE.md)；[首次默认关闭发布记录](PAYER_IP_ESA_P5_RELEASE.md) 保留历史状态。

商户服务器调用 MAPI 时提交的 `clientip` 继续保存在原 `order.ip`。付款人打开网关收银页时，独立采集经可信 ESA 回源传递的地址到 `payer_ip`，不要求商户改变接口，也不改订单原 IP、通道、金额、状态或回调。

## 采集范围与写入规则

| 入口 | 采集时机 |
| --- | --- |
| `/pay/submit/{trade_no}/` | 加载支付插件之前 |
| `/pay/qrcode/{trade_no}/` | 加载支付插件之前 |
| `/cashier.php` | 读取有效订单之后 |
| `/submit2.php` | 读取有效订单之后 |
| 网页 `/submit.php` | 插件网页下单入口，支持浏览器 GET 和表单 POST |
| `/mapi.php` | 不采集，即使服务器伪装浏览器请求头 |
| notify、return、查单、退款等 | 不采集 |

请求须接受 HTML、有浏览器 UA，并且不是已知工具或爬虫；存在 `Sec-Fetch-Dest` 时只接受文档和页面框架。这里只用于减少后台请求和预加载污染，不能证明访客是真人。

首次可信观察以原子条件更新写入，只处理数据库中 `status=0 AND payer_ip IS NULL` 且订单号、商户一致的订单。重复打开或并发访问不会覆盖第一次记录；不可信访问不占用首次记录。网页下单的内存订单可能缺少状态字段，最终由 SQL 条件判断数据库状态。数据库异常只写固定诊断 `[payer-ip] capture_storage_failed`，不输出请求头、异常详情或密钥，不抛出支付错误。默认关闭或配置不完整时，不执行采集 SQL。

一次首次观察增加一次按订单主键的条件 UPDATE，无额外查询和外部请求。刷新时已加载 `payer_ip` 的订单不再写库。数据库正常的行锁等待仍属于本次请求耗时，不承诺数据库故障期间零延迟。

## 信任边界

默认 `cidr` 模式使用 PHP `REMOTE_ADDR` 与已核实的本站 ESA 回源网段比对。新增的 `origin_auth` 模式可替代网段校验：源站 Nginx 校验 ESA 覆盖写入的共享鉴权头，并确认连接实际使用 TLS，再通过 FastCGI 参数 `EPAY_ESA_ORIGIN_AUTH=1`、`EPAY_ESA_ORIGIN_TLS=on` 向 PHP 传递证明。应用不读取共享密值，也不把 HTTP 请求头、X-Forwarded-Proto 或本机健康检查豁免当成证明。示例见 `tools/nginx/payer-ip-origin-auth-fastcgi.conf.example`；此模式的部署及链路验证须单独完成后才启用。

两种模式均只读配置选定的一个真实 IP 请求头，绝不自动回退到 XFF、X-Real-IP、CF 或商户传入值。头值必须是单个公网 IPv4 或 IPv6；列表、端口、空值、私网、回环、保留地址、异常空白一律跳过。IPv6 和 IPv4 映射地址先规范化。`origin_auth` 不需要配置 ESA 网段，但仍需验证真实 IP 头由 ESA 注入并覆盖访客传入值。

头名存在产品差异：阿里云 CDN 官方文档使用 `Ali-Cdn-Real-Ip`，ESA 托管转换默认使用 `Ali-Real-Client-Ip`。本功能按本次需求默认选前者；后者必须经运维核实后显式配置，不同时读取两个头。[CDN 请求头说明](https://help.aliyun.com/zh/cdn/user-guide/configure-custom-request-headers/)、[ESA 托管转换说明](https://help.aliyun.com/zh/edge-security-acceleration/esa/user-guide/managed-conversion/)。

上线前应完成这些核实：

1. 选择 `cidr` 时，从本站 ESA 控制台或只读 `GetOriginProtection` 获取实际 IPv4 和 IPv6 回源列表，核对当前回源模式与节点覆盖；不能从日志样本猜整个网段，不能信任所有阿里云地址。[ESA 回源保护说明](https://www.alibabacloud.com/help/en/edge-security-acceleration/esa/user-guide/origin-protection)、[GetOriginProtection API](https://www.alibabacloud.com/help/tc/edge-security-acceleration/esa/api-esa-2024-09-10-getoriginprotection)。选择 `origin_auth` 时，改为验证共享密值、HTTPS 回源和 Nginx 的 FastCGI 证明；ESA 支持覆盖式添加回源请求头和配置 HTTPS 回源。[请求头规则](https://help.aliyun.com/zh/edge-security-acceleration/esa/user-guide/modify-outgoing-request-header)、[回源协议](https://help.aliyun.com/zh/edge-security-acceleration/esa/user-guide/back-to-source-protocols-and-ports)。
2. 确认 ESA 删除或覆盖访客传入的同名真实 IP 头；实际测试伪造头不能改变到源站的观察地址。
3. 确认到 PHP 的 `REMOTE_ADDR` 仍是实际 ESA 连接对端。若 Nginx 已通过 `real_ip_header` 改写，或前面还有其他代理，本实现不能直接把改写后的地址当 ESA 对端；需先单独设计并核验保留原连接地址的链路。
4. 回源共享网段不是站点身份证明，仍需核实本站回源 Host 与既有源站访问边界。不要为了本功能自动开启 ESA 回源保护或修改防火墙，否则可能改变现有流量路径。
5. 收银页和携带订单参数的路径应绕过 CDN 缓存，确保付款人的页面请求实际到达源站；仅 PHP 的不缓存响应头不能替代边缘缓存规则。

当前生产选择 `origin_auth`，不导入或猜测回源 CIDR。单元测试中的公共 IP 只是合成输入；启用验收则由深圳和香港真实请求验证来源地址、鉴权及实际 TLS，记录中不公开共享密值。

## 表结构与配置

迁移文件：`install/migrations/20261021_order_payer_ip.sql`。`install/install.sql` 同步包含新装结构。

| 新字段 | 类型 | 含义 |
| --- | --- | --- |
| `payer_ip` | VARCHAR 45，ASCII | 首次符合采集条件的浏览器请求地址 |
| `payer_ip_at` | DATETIME | 源站观察时间，沿用应用数据库时区 |
| `payer_ip_peer` | VARCHAR 45，ASCII | 此次请求的 ESA 连接对端 |
| `payer_ip_source` | VARCHAR 32，ASCII | `esa:ali-cdn-real-ip` 或明确选择的另一头名 |

四列均可为空，无历史回填，无新增索引，原 `ip` 列保持不变。

| 配置项 | 初始值 | 启用要求 |
| --- | --- | --- |
| `payer_ip_version` | `1` | 迁移最后写入的结构标记 |
| `payer_ip_enabled` | `0` | 运维明确设为 `1` 才采集 |
| `payer_ip_esa_verified` | `0` | 完成上述链路核验后设为 `1` |
| `payer_ip_esa_cidrs` | 空 | 本站经核验的完整回源网段，逗号或空白分隔 |
| `payer_ip_trust_mode` | `cidr` | `cidr` 或 `origin_auth`，显式选定，未知值拒绝 |
| `payer_ip_header` | `Ali-Cdn-Real-Ip` | 只允许此值或 `Ali-Real-Client-Ip`，显式选一个 |

配置由既有配置存储加载，新增键不会替换生产 `config.php`，也不包含密钥。已有环境通过 `install/migrations/20261022_payer_ip_origin_auth.sql` 添加默认信任模式，该迁移不会开启采集或防御。

`origin_auth` 的共享密值只存放在 ESA 与服务器 root 私有 Nginx 配置，不进入应用配置表、PHP 请求头、URL、日志或响应。ESA 使用“添加”操作覆盖同名头，源站完整核验密值。先准备源站证书与 HTTPS 监听，再由 ESA 切换并开启源站证书校验；确认真实 IP 头与缓存旁路后，才开启公网拒绝规则和采集。保留限定的本机健康检查、计划任务及证书续期通路，逐个验收实际域名。缺少任一 FastCGI 证明时只跳过采集，不自动降级为 CIDR 或 XFF。

## 与防御模式的关系

采集可用于防御未开启的商户，但它不会自动开启商户防御，也不会自动把旧订单纳入每日限额或投诉封禁。持有支付链接的人可能是转发者、预览程序或自动化客户端；单次页面观察不是已验证付款人身份。商户直接展示上游二维码或 App SDK 支付、付款人没有访问上述网关页的订单，会保持 `payer_ip=NULL`，不能从旧 `ip` 补成可信记录。

防御模式增加显式 `merchant_defense_ip_mode=esa`，可复用同一个严格 ESA 解析器；仍要求 `merchant_defense_net_verified=1` 和全部采集配置通过核验。不可信对端或头缺失时防御验证拒绝，不自动降级到其他地址。该模式继续以浏览器挑战上下文中的 IP 进行配额和投诉处理，单独的 `payer_ip` 不替代验证上下文；通道查单关单、旧链接核对等其他启用条件仍然有效。原有 direct 和 trusted_proxy 模式兼容保留，不自动切换现有配置。

## 本地验证

使用 PHP 8.4、PDO MySQL 和专用回环 MariaDB 测试库。测试固定连接 `127.0.0.1:23306`，凭据仅为合成 fixture；CI 的 3306 仅在 `CI=true`、`GITHUB_ACTIONS=true` 时允许。测试不加载生产配置，每次创建随机命名的 fixture 库并在结束时删除，只使用假支付宝 SDK，不发起真实交易。

```powershell
php scripts/payer-ip-regression.php
node scripts/payer-ip-http-regression.cjs
node scripts/payer-ip-http-regression.cjs --origin-auth
php scripts/merchant-defense-regression.php
php scripts/merchant-defense-concurrency.php
node scripts/merchant-defense-browser.spec.cjs
```

Windows 如 PHP 默认扩展目录不匹配，可对 PHP 指定 `-d extension_dir=本机PHP的ext目录 -d extension=pdo_mysql`；Node 脚本接受 `EPAY_PHP_TEST_BIN` 和 `EPAY_PHP_TEST_EXT_DIR`。浏览器回归还需 Playwright 和 Chromium；可指定 `EPAY_PLAYWRIGHT_MODULE`、`EPAY_TEST_CHROME`。

新增用例覆盖新装与升级结构一致、旧 IP 不回填、关闭时零采集查询、源站伪造头、头名严格选择、IPv6、MAPI 不误记、真实 HTTP 页面入口、并发首次写入、状态竞态和存储异常。鉴权模式额外验证伪造 HTTP 证明、缺少实际 TLS、无效鉴权、未知模式和空网段。CI 已加入新增脚本。数据库回归 118 项、CIDR HTTP 回归 33 项、鉴权 HTTP 回归 37 项、原防御回归 88 项、浏览器回归 28 项及原 20 进程防御并发测试均需通过。

## 上线与回退顺序

先在生产只读核对实际前缀、四列是否存在、数据库版本与表大小，并按既有流程备份结构。迁移脚本只执行一次，不应在未知锁表行为下直接运行。P5 MariaDB 10.11 可在预演确认支持后显式要求 `ALGORITHM=INSTANT, LOCK=NONE`，不支持应停止评估，不能自动降级长时间阻塞 DDL。

先完成迁移、保持配置关闭，再按文件白名单部署采集器及入口钩子，保留线上 UI 覆盖层和其他差异。取得本站真实网段、头覆盖和缓存旁路验收结果后，单独授权启用采集，并刷新既有应用配置缓存；先观察覆盖率、空值原因、IP 分布和支付错误率，再评估防御模式启用条件。ESA 回源列表变化后需同步核验并维护白名单。不要宣称“所有订单已获得付款人真实 IP”。

回退优先将 `payer_ip_enabled` 设回 `0`，保留四列和既有证据；若防御已选择 ESA 模式，应先关闭对应商户防御，避免关闭解析器后挑战不可用，再按原部署流程回退代码。无需删列或改写历史订单。真实 IP 只供受控后台调查，不加入商户公共 API 响应或页面输出；保留期限与访问权限应沿用平台数据治理要求。
