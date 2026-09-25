# P5 列表查询与短缓存实施报告

日期：2026-09-25。适用运行时：**PHP 8.4.x**。

开发依据：[优化开发文档](P5_LIST_QUERY_CACHE_OPTIMIZATION_PLAN.md)。基线：`upgrade/php-84-compatible` @ `4ea86432bf2ad698c2aacd5978d57d405b815c61`；开发分支：`feature/p5-list-query-cache`。

## 1. 交付状态

本地实现和隔离验收完成；配置示例的四类缓存开关均为 **false**。本轮没有推送 GitHub、连接或修改 P4/P5、部署、执行生产 DDL，也没有启用生产缓存。GitHub CI、生产灰度及 30 分钟观察须在后续发布中分别确认。

覆盖管理支付订单、商户支付订单、商城订单、后台商品和商户资金明细五类列表。当前页和详情仍直接查询数据库；只有总数、统计、展示字典及关联字符集元数据可短缓存。没有修改支付签名、路由权重、金额计算、退款、库存扣减或影子投影的业务规则。商城详情继续不显示“记录模式”。

## 2. 代码与行为

| 交付 | 实现位置 | 行为 |
| --- | --- | --- |
| 统一筛选 | `includes/lib/ListQueryFilter.php` | SQL 与键共用条件；绑定参数；保留类型/通道优先顺序、创建时间边界和原金额口径；商户 UID 来自认证结果；订单号保持字符串 |
| 查询编排 | `includes/lib/ListQueryReader.php` | 总数缓存、实时行查询；支付深页先取主键；明确返回失败；矛盾总数/空尾页最多一次只读一致性事务修复 |
| 文件缓存 | `includes/lib/ListReadCache.php` | JSON、绝对 TTL、认证作用域及数据库/版本/epoch 隔离、随机失效代号、50 ms 锁等待预算、容量准入及有界清理 |
| 失效入口 | `includes/lib/ListCacheInvalidator.php`、`includes/functions.php` | 明确业务钩子；事务内跳过，由事务所有者提交后失效；缓存异常不改变已提交付款结果 |
| 商城查询 | `Shop/OrderService.php`、`Shop/GoodsService.php`、`Shop/TradeJoin.php` | 精确字段搜索、UID 驱动关联、混合字符集保留被关联列索引、深页延迟关联、字典/元数据复用 |
| 页面适配 | `assets/js/list-read-ui.js` 与五个页面 | 总数时间、一次性 `fresh=1`、失败重试、取消旧请求并丢弃迟到响应；统计手动刷新；商城全局概况不随搜索重查 |
| 公共初始化 | `includes/common.php` | 安装探测由全表配置读取改为 `SELECT 1 ... LIMIT 1` |
| 运维与 CI | `scripts/list-read-cache-clean.php`、配置和 systemd 模板、PHP 8.4 workflow | 默认关闭；有界清理；既有补偿 unit 增加缓存目录可写范围；三种缓存状态验收 |

页面仍保留 `total/rows`、统计 `code/data`，新增 `meta` 记录统计时间、缓存状态、实时行标志和纠正后的分页位置。SQL 失败不会被转换成有效的空列表或 0 统计。管理利润与商户净入账统计使用不同命名空间。

商城新界面默认“支付单号（精确）”；老接口未传 `search_field` 时继续综合 OR 搜索。LP 和历史数字单号均按字符串查询。宽泛关键词/商品中间匹配仍可能扫描较多记录，不承诺精确搜索的性能。

浏览器验收发现 Bootstrap 后台导航在 768 px 会换行遮挡刷新按钮，已在 768–1199 px 使用折叠菜单。改动限定于后台顶层导航，管理和商户共享样式版本升为 `v=2`。

## 3. 写入失效覆盖

下表中的“支付”指 `payment.global` 加对应 `payment.uid.<uid>`；未知 UID 用 `payment.bulk`。商户计数不依赖其他商户的 UID 标记。磁盘文件名全部是摘要。

| 写入口 | 提交边界与失效标签 | 验证 |
| --- | --- | --- |
| `lib/api/Pay.php` 网页/MAPI 建单与路由；`submit2.php`；`paypage/ajax.php` | 原自动提交写入后 → 支付 | 网页/MAPI/收银台既有安装 HTTP 测试；商城三状态流程；订单号回归 |
| `user/ajax.php` 测试支付、付费注册；`user/ajax2.php` 充值、购买组；`admin/ajax_pay.php` 测试订单 | 成功建单后 → 支付 | 安装 HTTP、注册完成、代码写点核对 |
| `Payment::processOrder()`、`updateOrder()`、`checkBlockUser()` | 成功状态 CAS、交易号/买家等自动提交阶段分别失效 → 支付 | 真数据库付款及重复回调、插件回调、100 次/状态性能样本 |
| `processOrder()` 利润和通知；`scheduleMerchantNotifyRetry()`；`cron.php` 两种通知任务 | 各写入阶段 → 支付 | 支付通知回归、真实本机通知、重试队列、直接 SQL 核对 |
| `Order::refund/freeze/unfreeze` | 状态写入后 → 支付；资金变化由流水钩子处理 | 真 SQL 退款/冻结/解冻后重新读取商户计数 |
| `admin/ajax_order.php` 单删、改状态、批量、补单、预授权解冻、重置通知；`user/ajax2.php` 重置通知 | 自动提交阶段；批量逐个已提交操作 → 支付/批量 | 跨 HTTP 进程改状态失效、原支付回归、代码核对 |
| `admin/clean.php`、`cron.php` 订单清理 | 清理语句后 → 支付批量；流水清理 → `ledger.bulk` | 批量代号机制、代码写点核对 |
| `changeUserMoney()`、`completePaidRegistration()` | 实际事务提交后 → `ledger.uid.<uid>` | 真 SQL 余额/流水测试、300 次付款只入账一次、付费注册回归 |
| `admin/ajax_user.php:delRecord` | 删除后 → `ledger.bulk` | 批量代号机制、代码写点核对 |
| `Shop/OrderService` 记录、同步路由、购买确认、付款投影、物流、软删除、补偿 | 自动提交写入后或事务提交后 → `shop.orders`；付款/补偿同时 → `shop.goods` | 真 SQL 软删除、事务回滚、三状态 Web/MAPI/CLI 补偿/发货及库存回归 |
| `Shop/GoodsService` 创建、编辑、启停、软删除 | 写入后 → `shop.goods` | 真 SQL 商品新增/删除；既有商城浏览器操作 |
| `admin/ajax_pay.php` 类型新增、编辑、删除、启停 | 写入后 → `display.types` | 管理端改名称后商户端跨请求读取新名称 |

扫描包含业务 PHP 的直接 INSERT/UPDATE/DELETE、DB helper 和插件调用。`settle/ext/combine/profits/profits2` 等只影响实时行或支付内部决策、不影响本轮筛选/聚合的更新不写失效文件。插件通过 Payment helper 更新时由上述钩子覆盖。安装/升级 SQL、DBA 直接修改和后续新增写入口不能自动识别：执行迁移前关闭相应缓存，变更 schema 后换 epoch；新增业务写入口须补钩子。

本轮没有把“扫描清单”写成“每个 HTTP 管理动作都做过端到端测试”。表中明确区分真实 SQL/HTTP 验证与代码核对。现有管理鉴权仍只有完整权限范围；以后引入管理员角色或行权限时，要先更新作用域版本和筛选，再启用该范围缓存。

## 4. 验收环境和性能

测试在 Win 主机的独立 WSL `VCS-QA` 中执行，Ubuntu 24.04、PHP **8.4.26**、MariaDB **10.11.14**、Node 18.19.1、Playwright 1.51.1 Chromium。宿主机暴露 4 个逻辑处理器，CPU 型号 AMD Ryzen 9 9950X，内存约 24 GiB。数据库使用 `/tmp` 新建数据目录，测试以非 root 用户运行；所有应用/通知 URL 为本机回环地址。未使用生产业务数据。

HTTP 使用 4 worker PHP 内建服务，不是生产 Nginx/FPM；数字不能当成 P5 SLA。每个常规用例预热后采样 100 次，失败会中止验收。20 路并发单列。原始日志、JSON 和页面截图保存在本地被 Git 忽略的 `docs/evidence/list-query-cache-20260925/`；CI 会生成相应 artifact。

### 4.1 十万支付单 + 十万商城单

| 用例 | P50 | P95 | 说明 |
| --- | ---: | ---: | --- |
| 支付列表服务调用，缓存关闭 | 11.444 ms | 22.648 ms | 含字典、COUNT、实时行 |
| 支付列表服务调用，缓存开启 | 1.379 ms | 2.111 ms | 预热及 100 次共 101 次调用仅 1 个 COUNT、1 个展示字典查询；101 次均查询订单行 |
| 支付列表 HTTP | 16.068 ms | 24.433 ms | 包含认证和公共初始化 |
| 商城列表 HTTP | 15.687 ms | 33.073 ms | 无筛选首屏 |
| 商城精确单号 HTTP | 14.892 ms | 23.639 ms | 支付单号精确匹配 |

100 次相同筛选 COUNT 回源减少超过 99%，超过文档 90% 门槛；这不代表整个 HTTP 请求只访问一次数据库。三类 HTTP 均低于 300 ms 门槛，20 路并发全部成功，整批约 224 ms（不是单请求 P95）。统计 SUM 也单独验证 100 次复用同一聚合结果，命中服务调用 P95 为 0.404 ms。

深页结果与原查询逐行一致。以下“原”仅计实时行 SQL，“新”包含缓存查询编排开销，均为 100 次样本的 P95；不把它们等同于完整 HTTP 对比：

| 列表 | OFFSET 0 原/新 | OFFSET 5,000 原/新 | OFFSET 50,000 原/新 |
| --- | --- | --- | --- |
| 支付 | 1.850 / 2.453 ms | 6.068 / 3.448 ms | 28.600 / 12.463 ms |
| 商城 | 1.915 / 2.070 ms | 10.118 / 2.476 ms | 114.618 / 11.152 ms |

三组同/混合字符集测试均保持支付 `PRIMARY` 查找，精确商城单号走 `uk_pay_trade_no`、`const`、估计 1 行。元数据跨 DB 对象复用无额外探测。既有 2 万单三组测试 P95 为 6.32 / 4.50 / 8.00 ms，继续低于 200 ms。

**本轮无 DDL。** 当前结果支持先启用查询优化与短缓存，未新增重复索引。OFFSET 仍随深度增长；100 万单、可选游标分页及新增复合索引留在第二阶段，须另测读取收益、索引大小、写入成本和锁影响。

### 4.2 付款与混合读写

三种状态交替执行，各 100 次真实 `Payment::processOrder()`；包含本机商户通知、余额流水、影子创建/付款/发货和额外重复回调。每次模拟新请求重置缓存实例，故障状态每次都重新尝试初始化，避免仅测试第一次故障。300 次入账合计准确，无重复入账。

| 缓存状态 | 回调 P50 | 回调 P95 | 相对关闭的 P95 增量 |
| --- | ---: | ---: | ---: |
| 关闭 | 99.177 ms | 121.200 ms | 基线 |
| 开启 | 103.862 ms | 122.774 ms | +1.574 ms |
| 失效目录不可写 | 98.709 ms | 112.255 ms | 未增加；差异包含宿主机噪声 |

符合 `max(基线 P95 × 10%, 5 ms)` 的增量门槛。数据是回调服务调用加本机通知耗时，不是外部支付网络耗时。

另以独立 CLI 写进程与列表读进程并行，目标 0/1/10 次付款每秒，各 100 次读请求；10 次/秒使用两个写进程。实际写入 0/10/100 次、窗口约 9.9–10.02 秒，三种缓存状态均无付款结果错误。

| 正常开启时的写频率 | COUNT 命中/回源 | 列表服务 P95 |
| --- | ---: | ---: |
| 0 次/秒 | 99 / 1 | 2.952 ms |
| 1 次/秒 | 81 / 19 | 6.033 ms |
| 10 次/秒 | 1 / 99 | 10.568 ms |

高写入会持续失效，缓存收益明显下降，不能承诺所有负载均减少 90% 查询。故障写进程停止本次请求内的缓存重试，其他读进程已有的新鲜统计可能保留至剩余 TTL；行数据继续实时，`fresh=1` 可立即回源，不提供过期兜底。

## 5. 正确性与故障验证

- 缓存：跨实例命中、两个站点/商户/发布/epoch 隔离、绝对 TTL、慢查询不延长 TTL、0 值、SQL 失败、过大条目、损坏 JSON/代号、非法配置、额度满、锁竞争、写目录不可写、在途查询失效。
- Linux 8 个进程同时冷读，10 ms loader 合并为一次；锁等待超预算时允许查库回退。Windows 的磁盘/锁性能不能替代此 Linux 验收。
- 有界清理：`limit=1` 持续轮转、不饿死后面的条目；收回遗留临时文件；不跟随异常分片链接删除外部文件；保留标记及锁文件。
- 数据库：退款、冻结/解冻、流水提交失效，事务回滚不发布失效；尾页删除只做一次短只读事务纠正，结束后不残留事务。
- HTTP：未登录、退出后、篡改 UID、两个商户隔离；无效筛选明确报错；跨请求改状态和支付类型显示失效；强制刷新不返回旧统计。
- 浏览器：五页 × 1366/768/390 px，表格内部可横向滚动而页面不溢出；刷新、统计弹窗、搜索不重取全局概况、延迟旧响应不覆盖新条件、失败与重试可见，无 JavaScript 异常。
- 既有回归：投诉同步/只读、轮询权重、邮箱完整性、注册完成、管理 SSO、支付回调、订单号、UI 契约；全库 PHP 8.4 语法、废弃模式、签名、插件元数据、下载/重写检查及 HTTP smoke。
- 真数据库既有验收：安装库 fixture、备份恢复演练、安装/升级、安装后 HTTP/插件支付，以及影子补偿状态保护、事务和库存测试。
- 商城完整 Web/MAPI/CLI/浏览器流程分别在缓存关闭、开启、目录不可用下通过。GitHub workflow 已纳入上述新增单元和三状态验收，本轮未触发远程 CI；未变更 Composer 依赖，未重新安装或审计依赖。

## 6. 实现细节与运维界限

容量采用 256 个固定分片，各自短锁和小账本，默认每片最多 39 条，合计 **9,984 条**，数据字节合计上限 64 MiB；比计划的 10,000 条稍保守。某分片先满时停止该片新增写入，不为了命中率扫描或淘汰其他分片。账本先预留再发布数据，进程崩溃可能暂时多算，不会少算并放大写入。

原子写在持有目标锁时复用单一 `.tmp`，避免崩溃产生无上限随机临时文件；清理轮转检查最多 1,000 个账本条目，不删除稳定锁和活跃失效代号。账本损坏时该片停止写入并回源、清理返回失败；不要在线删标记“修复”。先关闭缓存、保存证据，在停止缓存写入后清理受损数据分片，再开启。账本、锁、代号、文件系统分配和瞬时临时副本不计入 64 MiB 数据预算，需额外磁盘/inode 余量与监控。

默认 `metrics_sample_permille=10`，对 1% 请求/CLI 批次输出 `list_read_cache_metrics` 聚合：各类别 hit/load、累计查询微秒、锁回退、容量拒绝、失效成功和故障原因。故障原因日志按本请求去重，不记录搜索值、SQL、UID、数据库身份、订单内容或缓存键。抽样指标不能冒充完整流量计数。

## 7. 后续发布和回滚材料

1. 本地提交经审查后推送并确认 GitHub CI。部署仍按 P5 发布授权执行。无需生产索引迁移。
2. 使用 [默认关闭配置](../tools/config/list-read-cache.example.json)。配置位于 `/etc/epay/list-read-cache.json`，root 管理、应用只读；缓存目录 `/srv/epay/shared/cache/list-read` 位于 Web 根之外、0700、属主 `www-data`，文件 0600。`EPAY_LIST_CACHE_CONFIG` 仅供受控进程环境覆盖，HTTP 参数不能改路径。
3. 先关闭四类缓存发布查询代码。然后字典/元数据 → 管理计数/统计 → 商户白名单/全体分阶段启用。`admin_enabled`、`merchants_enabled` 和 `merchant_allowlist` 分别控制读范围。
4. 更新 [补偿 unit](../tools/systemd/epay-shop-shadow.service) 的精确可写目录；检查 FPM、cron、补偿 CLI 的身份/配置一致，再启用 [清理 service](../tools/systemd/epay-list-read-cache-clean.service) 和 [timer](../tools/systemd/epay-list-read-cache-clean.timer)。清理器可手工运行 `php8.4 scripts/list-read-cache-clean.php --limit=1000`，返回安全 JSON 计数。
5. 变更列字符集、排序规则、表前缀/数据库或统计规则时关闭缓存并更新 `schema_epoch`/命名空间。版本发布目录独立隔离数据，稳定标记继续共享；多应用节点不能直接沿用本机缓存。
6. 至少观察 30 分钟：列表/回调错误和 P95、hit/load、失效失败、容量拒绝、`du` 的实际磁盘量、inode、tags 增长及补偿日志。这里的回调性能必须重新在生产自然流量观察；本地结果不替代它。
7. 缓存异常先把相应开关置 false，下一请求/CLI 批次回源。若要回退旧代码，先关闭缓存再切换版本。回滚不恢复业务数据库、不撤销真实付款；保留日志和数据分片证据。发布及观察的实际状态补记在本报告，不能仅凭代码存在宣称上线。

## 8. 复现入口

Linux 上需要 PHP 8.4、MariaDB 工具、curl、rsync、Node 和 Playwright Chromium，数据库应使用可启动隔离实例的非 root 测试用户：

```bash
php8.4 scripts/list-read-cache-regression.php
LIST_TEST_REQUIRE_BROWSER=1 LIST_TEST_NODE_BIN=/usr/bin/node \
  LIST_TEST_NODE_PATH=/path/to/node_modules \
  LIST_TEST_ARTIFACT_DIR=/path/to/list-artifacts \
  bash scripts/list-query-local-acceptance.sh
SHOP_TEST_LIST_CACHE_MODE=on SHOP_TEST_LIST_BENCHMARK=1 \
  SHOP_TEST_REQUIRE_BROWSER=1 SHOP_TEST_NODE_BIN=/usr/bin/node \
  SHOP_TEST_NODE_PATH=/path/to/node_modules \
  SHOP_TEST_ARTIFACT_DIR=/path/to/shop-artifacts \
  bash scripts/shop-feature-local-acceptance.sh
```

将最后一条的模式换成 `off`、`fault` 并去掉 benchmark 即可复现其余流程矩阵。`LIST_TEST_EXISTING_DB_CHECKS=1` 可额外执行既有数据库、恢复、安装/升级与 HTTP 检查。测试脚本要求独立库名、回环地址和测试环境标记；不会读取生产密钥。
