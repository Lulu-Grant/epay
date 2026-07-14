# 无感商城影子订单模式开发文档

## 1. 文档信息

| 项目 | 内容 |
| --- | --- |
| 文档状态 | 待实施 |
| 目标模式 | `shadow` 无感影子订单 |
| 兼容范围 | PHP 7.4 至 PHP 8.4、MySQL 5.7+ |
| 原支付事实表 | `pre_order` |
| 商城附属表 | `pre_shop_orders` |
| 关联键 | `pre_order.trade_no = pre_shop_orders.pay_trade_no` |

本文档定义商城从“支付必经的购买确认页”改造为“不影响原支付链路的后台影子订单”的目标设计。

原有 `docs/shop-feature-development-guide.md` 作为商城包装层的历史基线保留，不在本阶段删除。

## 2. 改造背景

当前商城开启后，原支付订单会先进入商城确认页，用户点击支付方式后才执行通道分配。

现网四小时漏斗样本显示：

- 291 笔商城包装订单中，148 笔停留在 `channel=0`。
- 其中 131 笔成功打开确认页，但没有点击支付方式。
- 17 笔未打开确认页。
- 所有进入 `shopping.php?act=continue` 的订单都成功进入 `submit2.php` 并获得支付通道。

因此，主要问题不是通道分配失败，而是商城确认页增加了一个不必要的用户操作节点。

## 3. 目标行为

### 3.1 用户体验

- 用户不再经过 `shopping.php?act=checkout`。
- 跳转支付恢复商城接入前的原收银台或原支付插件流程。
- MAPI 恢复原本的二维码、H5、JSAPI、APP、URL Scheme 或 jump 响应。
- 用户无需填写商品、联系方式或购买备注。
- 商城记录的创建、支付同步和自动发货全部在后台完成。

### 3.2 系统行为

- 普通支付订单仍只创建一张 `pre_order`。
- 符合商城规则的订单在后台增加一张 `pre_shop_orders` 附属记录。
- 附属记录从已启用商品中随机选择商品，并固化名称和图片快照。
- 商城金额始终等于原订单金额，数量固定为 `1`。
- 支付成功后，商城订单幂等更新为“已支付、已发货”。
- 商城任何异常不得阻断原支付、入账和商户通知。

## 4. 非目标

本次改造明确不做：

- 由商城创建第二张支付订单。
- 改变原订单的收款商户、金额、商品名、回调和扩展参数。
- 将随机商品名写回 `pre_order.name`。
- 增加商城专用收款 UID 或商城专用支付通道。
- 用商城状态代替原商户的异步回调状态。
- 自动重新发起历史未支付订单。
- 在本期引入外部消息队列服务。

## 5. 核心不变量

1. `pre_order` 是唯一支付事实来源。
2. `pre_shop_orders` 只是商城展示、统计和履约状态的附属投影。
3. 一张 `pre_order` 最多对应一张 `pre_shop_orders`。
4. 必须保留 `pre_shop_orders.pay_trade_no` 唯一索引。
5. 不得改写原订单的 `uid`、`money`、`name`、`param`、`notify_url`、`return_url`。
6. 原商户的 `gid`、通道轮询、费率、直清模式和入账规则保持不变。
7. 商城写入失败时必须记录错误，但必须继续原支付流程。
8. 排除清单中的商户不创建新商城影子订单。
9. 商户重试相同订单时复用原商城记录，不重新随机商品。
10. 重复支付通知不得重复扣库存，也不得将已签收或已完成状态降级。

## 6. 目标架构

### 6.1 订单创建流程

```text
商户请求
   |
   v
原签名、商户、金额、域名和风控校验
   |
   v
创建或复用 pre_order
   |
   +---- 轻量尝试创建 pre_shop_orders
   |       失败：记录日志，不报错给用户
   |
   v
执行原收银台或原支付插件
   |
   v
分配原商户支付通道
   |
   v
返回二维码 / H5 / JSAPI / APP / Scheme / jump
```

“无感”指用户不再看到商城页面或多一次点击。首版不采用纯异步队列，而是采用“前台轻量旁路写入 + 后台异步补偿”，避免支付成功时商城记录尚未创建的竞态。

### 6.2 支付成功流程

```text
支付通道通知
   |
   v
幂等更新 pre_order 为已支付
   |
   v
执行原资金入账逻辑
   |
   +---- 按 pay_trade_no 同步商城订单
   |       已支付 + 已发货
   |       异常只记录日志
   |
   v
执行原商户异步通知
```

商城状态同步保持在原商户通知之前，但同步失败不得中止 `do_notify()`。

### 6.3 异步补偿流程

新增 CLI 补偿任务，每分钟执行一次：

1. 获取进程锁，防止任务重叠。
2. 从上次上线时间起扫描 `tid=0` 的支付订单。
3. 使用 `LEFT JOIN pre_shop_orders` 筛选缺失商城记录的订单。
4. 忽略当前排除商户。
5. 每批最多处理 200 笔，按 `pre_order.addtime` 升序执行。
6. 幂等创建商城影子订单。
7. 如原订单已支付，立即将商城订单更新为已支付、已发货。
8. 单笔错误不终止整批任务，输出结构化日志。

补偿任务必须为 CLI 脚本，不对公网提供无鉴权 URL。

## 7. 配置设计

在 `pre_shop_config` 增加：

| key | 可选值 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `shop_flow_mode` | `checkout` / `shadow` | `checkout` | 先以旧模式作为部署默认，验收后切换为 `shadow` |
| `shop_shadow_started_at` | `YYYY-MM-DD HH:MM:SS` | 空 | 补偿任务的新订单扫描起点 |

保留并继续使用：

- `shop_status`：商城总开关。
- `shop_excluded_uids`：不创建商城附属记录的商户 UID。
- `shop_name`、`shop_desc`：商城展示页文案。
- `shop_query_verify`：商城订单公共查询保护。

配置判定：

```text
shop_status != 1                         -> 不创建商城记录
uid 存在于 shop_excluded_uids             -> 不创建商城记录
shop_status = 1 且 shop_flow_mode=shadow -> 创建影子订单，但不跳转商城页
shop_status = 1 且 shop_flow_mode=checkout -> 保留旧购买确认流程，仅用于回滚
```

## 8. 数据设计

### 8.1 现有表结构

首版不新增业务表，继续使用 `pre_shop_orders`。

| 商城字段 | 数据来源 |
| --- | --- |
| `shop_trade_no` | 商城生成，`S` 加 21 位数字 |
| `pay_trade_no` | `pre_order.trade_no` |
| `out_trade_no` | `pre_order.out_trade_no` |
| `goods_id` | 首次记录时随机选择的启用商品 |
| `goods_name` | 随机商品名称快照 |
| `goods_image` | 随机商品图片快照 |
| `goods_price` | `pre_order.money` |
| `money` | `pre_order.money` |
| `quantity` | 固定 `1` |
| `pay_type` | 原订单实际 `type` |
| `pay_status` | 由原订单支付结果同步 |
| `order_status` | 待支付或已发货 |

### 8.2 `status_times` 约定

新建影子订单：

```json
{
  "created": "2026-07-10 23:00:00",
  "source": "merchant_order_shadow",
  "catalog_goods_id": 3
}
```

通道分配后可增加：

```json
{
  "payment_started": "2026-07-10 23:00:01"
}
```

支付成功后增加：

```json
{
  "paid": "2026-07-10 23:00:32",
  "shipped": "2026-07-10 23:00:32"
}
```

`shadow` 模式不产生 `confirmed` 时间。后台不得将缺少 `confirmed` 判定为异常。

### 8.3 随机商品规则

- 仅从 `status=1 AND deleted=0 AND (stock=-1 OR stock>0)` 的商品中选择。
- 随机选择只在首次创建附属记录时执行。
- 重试、刷新、异步回调和补偿任务必须复用已有商品快照。
- 无可用商品时，使用原订单商品名称作为降级快照，不得阻断支付。
- 商品后续改名或下架不回写历史商城订单。

## 9. 服务接口设计

### 9.1 `ConfigService`

新增或调整：

```php
ConfigService::getFlowMode();
ConfigService::shouldRecordMerchant($uid);
ConfigService::shouldUseCheckout($uid);
```

- `shouldRecordMerchant()` 只决定是否创建商城记录。
- `shouldUseCheckout()` 只在历史 `checkout` 模式返回 `true`。
- 不再用 `shouldWrapMerchant()` 同时表示“写记录”和“强制跳转”两种含义。

### 9.2 `OrderService`

目标接口：

```php
OrderService::recordPaymentOrder($payTradeNo, $requestedType = '');
OrderService::syncPaymentRoute($payTradeNo);
OrderService::markPaidFromPaymentOrder($paymentOrder);
OrderService::reconcilePaymentOrder($payTradeNo);
```

`recordPaymentOrder()`：

- 按 `pay_trade_no` 幂等查找或创建商城记录。
- 返回数据行或结果状态，不负责输出页面和终止请求。
- 保留 `attachPaymentOrder()` 作为历史包装模式兼容入口，内部可复用新接口。

`syncPaymentRoute()`：

- 在原订单写入 `type/channel/subchannel` 后执行。
- 将 `pre_order.type` 同步到 `pre_shop_orders.pay_type`。
- 商城记录不存在时直接返回，不报错。

`reconcilePaymentOrder()`：

- 可安全重复调用。
- 缺失商城记录时创建。
- 商城记录存在时只补齐支付方式和状态。
- 原订单已支付时调用幂等的支付成功同步。

## 10. 代码改造点

| 文件 | 目标改造 |
| --- | --- |
| `includes/lib/Shop/ConfigService.php` | 增加流程模式与记录/跳转判定 |
| `includes/lib/Shop/OrderService.php` | 拆分“创建记录”与“返回购买页”，增加路由同步和补偿接口 |
| `includes/lib/api/Pay.php` | submit/MAPI 创建商城记录后继续原支付流程，不再输出商城 jump |
| `submit2.php` | 原订单获得实际支付方式后同步 `pay_type` |
| `includes/functions.php` | 保留支付成功商城回写，异常不阻断商户通知 |
| `admin/shop_config.php` | 显示无感模式，保留排除 UID 管理 |
| `admin/shop_orders.php` | 识别 `merchant_order_shadow` 来源，不把缺少 `confirmed` 标记为异常 |
| `install/addon_shop.sql` | 写入 `shop_flow_mode` 和 `shop_shadow_started_at` 默认配置 |
| `scripts/shop-shadow-reconcile.php` | CLI 异步补偿任务 |

### 10.1 `Pay::submit()` 伪代码

```php
// 原逻辑：创建或复用 pre_order

if (ConfigService::shouldRecordMerchant($pid)) {
    try {
        OrderService::recordPaymentOrder($tradeNo, $type);
    } catch (Exception $e) {
        error_log(/* 结构化错误 */);
    }
}

if (ConfigService::shouldUseCheckout($pid)) {
    // 仅历史回滚模式返回商城确认页
}

// shadow 模式不 exit，继续原 cashier / Channel::submit / Plugin 逻辑
```

### 10.2 `Pay::create()` 要求

- 不再因商城开启而统一返回 `jump`。
- 保留原 API 的 `pay_type` 和 `pay_info`。
- 原请求是二维码时仍返回二维码。
- 原请求是 H5 时仍返回 H5 跳转。
- 商城写入异常不得改变 API 响应结构。

## 11. 失败与并发处理

### 11.1 重复请求

- 先按 `pay_trade_no` 查询。
- 不存在时才创建。
- 并发插入冲突时，依靠 `uk_pay_trade_no` 唯一索引拦截。
- 插入冲突后重新查询已有记录，不向用户显示错误。

### 11.2 商城写入失败

- 记录 `trade_no`、`uid`、错误类型和错误摘要。
- 不记录商户密钥、签名、完整回调 URL 参数或其他敏感数据。
- 继续执行原通道分配。
- 由 CLI 任务在一分钟内补偿。

### 11.3 支付回调早于补偿

- 常规情况下，轻量旁路写入会在调用支付插件前完成。
- 如写入失败，原支付和商户通知仍正常完成。
- 补偿任务创建商城记录时必须检查 `pre_order.status`；如已支付，直接同步为已支付、已发货。

## 12. 日志与可观测性

统一日志事件：

| 事件 | 说明 |
| --- | --- |
| `shop_shadow_created` | 商城影子订单创建成功 |
| `shop_shadow_reused` | 重复请求复用原记录 |
| `shop_shadow_route_synced` | 实际支付方式已同步 |
| `shop_shadow_paid` | 商城订单已支付并自动发货 |
| `shop_shadow_create_failed` | 旁路写入失败 |
| `shop_shadow_reconciled` | 补偿任务成功补建 |
| `shop_shadow_reconcile_failed` | 补偿失败 |

建议后台健康指标：

- 当日符合规则的原支付订单数。
- 当日商城影子订单数。
- 影子订单缺失数。
- 原订单已支付、商城仍待支付的数量。
- 商城记录创建失败和补偿失败数。
- `channel=0` 订单中的商城影子订单数和订单年龄。

## 13. 性能要求

- 旁路写入不调用外部 HTTP 服务。
- 随机商品只查询必要字段，不读取大文本内容。
- 不在原支付请求中执行批量补偿。
- 单笔订单新增的本地数据库操作 P95 目标不超过 30ms。
- 整体支付创建请求 P95 不得比改造前增加 50ms 以上。
- CLI 补偿任务默认单批最多 200 笔，单次运行不超过 50 秒。

## 14. 兼容性要求

- 代码不使用 PHP 8 专有语法。
- 在 PHP 7.4、8.2 和 8.4 下通过语法检查和核心流程测试。
- 保持 MySQL 5.7 兼容，不使用 MySQL 8 专有 SQL。
- 原 `submit.php`、`mapi.php`、`cashier.php`、`submit2.php` 的公共参数不变。
- 商户签名算法和回调签名内容不变。

## 15. 测试设计

### 15.1 单元与服务层测试

1. 首次记录生成一张商城订单。
2. 重复记录返回同一张商城订单。
3. 并发两次记录仍只有一张数据。
4. 随机商品符合上架、未删除和库存规则。
5. 无可用商品时正常降级，原支付不中断。
6. 排除商户不创建商城记录。
7. 支付成功后商城状态为已支付、已发货。
8. 重复回调不重复扣库存。
9. 商城写入强制失败时，原支付仍能获得通道。

### 15.2 支付链路测试

覆盖：

- 跳转支付，指定支付方式。
- 跳转支付，未指定支付方式，进入原收银台。
- MAPI 二维码。
- MAPI H5 jump。
- MAPI JSAPI。
- 相同未支付订单重试。
- 已支付订单重试。
- 通道无可用节点时的原降级收银台。
- 商户通知成功、失败和重试。

### 15.3 数据一致性测试

逐笔核对：

```text
pre_order.trade_no       = pre_shop_orders.pay_trade_no
pre_order.out_trade_no   = pre_shop_orders.out_trade_no
pre_order.money          = pre_shop_orders.money
pre_order.money          = pre_shop_orders.goods_price
pre_order.type           = pre_shop_orders.pay_type（通道分配后）
```

必须确认 `pre_order.uid/name/param/notify_url/return_url` 在商城记录前后没有变化。

## 16. 验收标准

### 16.1 必须全部通过

1. `shadow` 模式下的支付响应和访问日志不再出现 `shopping.php?act=checkout`。
2. 指定支付方式的订单直接进入原支付插件或原 H5 流程。
3. 未指定支付方式的订单进入原收银台。
4. 符合规则的 100 笔新订单最终存在 100 张且仅 100 张商城附属记录。
5. 排除商户的新订单不存在商城附属记录。
6. 所有商城订单的商户、金额和支付方式均可追溯至原订单。
7. 支付成功后商城订单在正常情况下立即显示已支付、已发货。
8. 补偿场景下，商城记录和支付状态在 2 分钟内补齐。
9. 人为制造商城写入异常后，原支付通道分配、支付入账和商户通知仍正常。
10. 重复回调 3 次后，商城订单仍只有一张，库存只扣减一次。
11. PHP 7.4、8.2、8.4 语法检查全部通过。
12. 原商户 API 签名、查单、异步回调和同步返回的协议字段不变。

### 16.2 发布后指标

切换后前 4 小时与切换前同时段对比：

- 因“未点击商城确认页”造成的 `channel=0` 必须为 `0`。
- 含商城记录的订单中，影子订单缺失率必须低于 0.1%。
- 原支付创建请求 P95 延迟增量不得超过 50ms。
- 原商户回调成功率不得低于切换前基线。
- 商城已支付状态与原订单已支付状态的不一致数必须为 `0`，或在 2 分钟补偿窗口内归零。

## 17. 发布步骤

1. 备份线上代码和商城三张表。
2. 记录切换时间，写入 `shop_shadow_started_at`。
3. 以 `shop_flow_mode=checkout` 部署新代码，确保行为暂时不变。
4. 执行配置 SQL，部署 CLI 补偿任务。
5. 执行 PHP 语法、数据库索引、HTTP 和回调健康检查。
6. 使用专用测试商户完成跳转支付和 MAPI 回归。
7. 将 `shop_flow_mode` 切换为 `shadow`。
8. 立即检查前 10 笔订单的支付 URL、通道、商城记录和商户回调。
9. 连续监控 30 分钟、2 小时和 4 小时指标。
10. 验收后将 `shadow` 作为新安装的默认模式。

线上 PHP 8.4 计划任务示例：

```cron
* * * * * /www/server/php/84/bin/php /www/wwwroot/epay.tianlupay.com/scripts/shop-shadow-reconcile.php --limit=200 >> /www/wwwlogs/epay-shop-shadow-reconcile.log 2>&1
```

脚本内部已按站点路径使用进程锁，不需要对公网暴露调度 URL。

## 18. 回滚方案

### 18.1 仅回滚用户流程

将：

```text
shop_flow_mode=shadow
```

改为：

```text
shop_flow_mode=checkout
```

新订单恢复原商城确认页。已经创建的商城影子订单保留，不删除。

### 18.2 完全关闭商城附属记录

设置：

```text
shop_status=0
```

新订单不再创建商城记录，原支付仍正常运行。停止 CLI 补偿任务，但不回滚或删除已产生的 `pre_order`。

## 19. 历史订单处理

- 切换前已支付且商城状态正常的订单不处理。
- 切换前 `channel=0` 的待支付订单不自动分配通道，不自动重新发起支付。
- 用户重新提交同一笔未支付订单时，复用原 `pre_order` 和原商城快照，但按 `shadow` 模式直接进入原支付流程。
- 补偿任务默认不扫描 `shop_shadow_started_at` 之前的订单。

## 20. 实施完成定义

只有在以下条件全部满足后，才可标记本改造完成：

- 开发、SQL、CLI 补偿、管理配置和文档已同步完成。
- 本地真实数据副本回归通过。
- PHP 7.4 与 PHP 8.4 验收通过。
- 跳转支付和 MAPI 均不再强制进入商城页。
- 商城异常注入测试证明原支付与商户通知不受影响。
- 线上灰度和四小时监控指标达标。
- 回滚开关已验证可用。
