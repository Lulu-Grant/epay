# 商户订单商城包装层开发文档

> 历史基线：本文档描述 `checkout` 购买确认页模式，现仅作为回滚参考。当前目标以 `docs/shop-shadow-order-development-guide.md` 为准。

## 1. 当前定义

商城不是平台自营收款系统，也不改变商户支付订单。

商城开启后，普通商户创建支付订单时默认增加一次购买确认行为，并在 `pre_shop_orders` 中保存支付同步和履约信息。只有配置在排除清单中的商户继续完全走原流程。

商城必须保持以下原订单字段不变：

- `pre_order.uid`：原商户 UID。
- `pre_order.money`：原订单金额。
- `pre_order.name`：原商品名称。
- `pre_order.param`：原商户扩展参数。
- `pre_order.notify_url`：原异步通知地址。
- `pre_order.return_url`：原同步返回地址。
- 原商户用户组、费率、通道和资金入账规则。

## 2. 核心不变量

1. 一张原支付订单最多对应一张商城订单。
2. 商城不能创建第二张 `pre_order`。
3. 商城购买页不能修改金额、商户或商户回调参数。
4. 支付成功后先同步商城状态，再继续原商户异步通知。
5. 商城状态同步失败不能阻断原商户通知。
6. 排除商户不能写入 `pre_shop_orders`。
7. 商户重复发起相同未支付订单时复用原商城购买记录。

## 3. 功能范围

### 3.1 包含

- 商城总开关。
- 排除商户 UID 清单。
- 原订单购买确认页。
- 无表单的支付方式确认页。
- 商城订单查询凭证。
- 支付状态同步。
- 后台商城订单与原商户 UID 查看。
- 物流和履约状态维护。
- 跳转支付接口与 MAPI 创建接口接入。

### 3.2 不包含

- 平台统一收款商户。
- 商城自行决定订单金额。
- 商城自行选择全局支付通道。
- 购物车、优惠券和多商户开店。
- 自动物流和退款售后闭环。

`pre_shop_goods` 与商品后台仅保留为商城展示内容，不得从展示商品独立创建支付订单。

## 4. 配置模型

配置表：`pre_shop_config`

| key | 默认值 | 说明 |
| --- | --- | --- |
| `shop_status` | `0` | `1` 开启商城包装层 |
| `shop_name` | `商城` | 购买确认页名称 |
| `shop_desc` | 空 | 商城展示说明 |
| `shop_excluded_uids` | 空 | 排除 UID，逗号分隔 |
| `shop_query_verify` | `1` | 查询必须校验 token 或联系方式后四位 |

排除规则：

- 空清单表示所有普通商户默认进入商城。
- 支持逗号、分号、空格和换行输入。
- 保存时转换为去重后的逗号格式。
- 只允许正整数 UID，最多 1000 个。
- 排除清单可以预先填写尚未创建的商户 UID。

旧配置 `shop_pay_uid`、`shop_min_amount`、`shop_max_amount` 仅用于历史回滚，新代码不得读取。

## 5. 数据关系

支付订单：`pre_order`

商城附加订单：`pre_shop_orders`

关系：

```text
pre_order.trade_no = pre_shop_orders.pay_trade_no
```

`pre_shop_orders.pay_trade_no` 必须保持唯一索引。

商城记录映射：

| 商城字段 | 来源 |
| --- | --- |
| `shop_trade_no` | 商城生成，`S` 加 21 位数字 |
| `pay_trade_no` | 原 `pre_order.trade_no` |
| `out_trade_no` | 原 `pre_order.out_trade_no` |
| `goods_id` | 首次附加时随机选中的启用商城商品；无可用商品时为 `0` |
| `goods_name` | 随机商城商品名称快照；无可用商品时回退原 `pre_order.name` |
| `goods_image` | 随机商城商品图片快照 |
| `goods_price` | 原 `pre_order.money` |
| `quantity` | 固定 `1` |
| `money` | 原 `pre_order.money` |
| `pay_type` | 原订单指定方式或购买页选择方式 |
| `query_token` | 32 位随机查询凭证 |

商户 UID 不在商城表重复存储，后台通过 `pay_trade_no` 关联 `pre_order.uid`，避免双份数据不一致。

## 6. 创建流程

### 6.1 跳转支付 `submit.php`

1. 完成原商户签名、状态、金额、域名和风控校验。
2. 按原逻辑创建或复用 `pre_order`。
3. 判断 `ConfigService::shouldWrapMerchant($pid)`。
4. 未开启或商户在排除清单中：继续原收银台/插件流程。
5. 需要包装：调用 `OrderService::attachPaymentOrder()`。
6. 从启用、未删除且有库存的商城商品中随机选择一个并固化快照。
7. 返回购买确认页 URL，不提前选择支付通道。

### 6.2 MAPI `mapi.php`

MAPI 仍先创建原支付订单。需要包装时统一返回 jump 类型，URL 指向购买确认页。

这会把原先请求二维码、JSAPI 或 APP 数据的商户改为跳转购买流程。必须直返原支付数据的商户应加入排除清单。

### 6.3 幂等

`attachPaymentOrder()` 先按 `pay_trade_no` 查询商城记录；不存在才随机商品并插入。唯一索引处理并发重试，插入冲突后再次查询已有记录并返回同一确认页。页面刷新、商户重试和支付回调不得重新随机商品。商城没有可用商品时回退原商品快照，不能阻断支付。

## 7. 购买确认页

URL：

```text
/shopping.php?act=checkout&trade_no={shop_trade_no}&token={query_token}
```

页面只展示：

- 36×36 的随机商城商品小图标，不展示商品名称。
- 原商户订单号。
- 原订单金额，只读。
- 原商户可用支付方式按钮。

页面不得包含以下可编辑字段：

- 购买人、联系方式和购买备注。
- 金额。
- 商户 UID。
- 原商户订单号。
- 原 `param`、通知地址和返回地址。

购买页不渲染 `form`、`input`、`select` 或 `textarea`。如果原订单指定了支付方式，页面只显示该方式的继续支付按钮；否则显示原商户当前可用方式的直接支付按钮。

商品名称只保存在商城订单快照、后台和订单查询中；购买确认页不得输出商品名称，包括图片 `alt` 文本。

确认后跳转：

```text
/submit2.php?typeid={type_id}&trade_no={original_trade_no}
```

仍然使用原 `trade_no`，不得生成新支付订单。

## 8. 支付通道与资金

商城订单使用原商户的：

- `uid` 和 `gid`。
- 用户组支付方式。
- 通道、轮询组或子通道。
- 商户费率。
- 直清/平台代收模式。
- 余额和服务费规则。

`cashier.php` 与 `submit2.php` 不增加商城专用全局通道路由。

## 9. 支付回写与商户通知

`processOrder()` 的普通订单分支按以下顺序处理：

1. 按原逻辑给 `pre_order.uid` 入账。
2. 通过 `pay_trade_no` 判断是否存在商城附加记录。
3. 幂等更新商城 `pay_status=1`、`order_status=2`、`paytime`，同时记录支付和自动发货时间。
4. 无论商城同步是否成功，都继续执行 `creat_callback()` 和 `do_notify()`。

商城自动发货只修改 `pre_shop_orders`，不得改写原支付订单商品名或商户回调参数。重复回调不得把已签收、已完成或已取消状态降级。
5. `notify` 字段只代表原商户通知结果。

商城不得把原订单 `notify` 直接设置为成功，也不得把原商户通知替换为商城自通知。

## 10. 排除商户行为

排除商户必须满足：

- `submit.php` 返回原收银台或插件页面。
- `mapi.php` 返回原二维码、跳转、JSAPI 或 APP 数据。
- 不写入 `pre_shop_orders`。
- 支付、入账、回调行为与商城代码接入前一致。

## 11. 安全要求

- 购买确认必须校验 `shop_trade_no + query_token`。
- token 使用 `random_bytes()` 生成，失败时才使用兼容回退。
- 商户签名验证仍在创建原订单前完成。
- 买家提交的 `money`、`uid`、`param` 等字段全部忽略。
- 输出商品名等订单信息时统一 HTML 转义。
- 公共查询不返回 token；历史订单存在联系方式时仍只返回脱敏值。
- 后台写操作继续校验登录、Referer 和 CSRF token。

## 12. 关键文件

| 文件 | 职责 |
| --- | --- |
| `includes/lib/Shop/ConfigService.php` | 开关与排除 UID |
| `includes/lib/Shop/OrderService.php` | 附加记录、确认、查询和回写 |
| `includes/lib/api/Pay.php` | submit/MAPI 接入点 |
| `shopping.php` | 购买确认和公共查询 |
| `includes/functions.php` | 支付成功后同步商城并继续商户通知 |
| `admin/shop_config.php` | 排除清单管理 |
| `admin/shop_orders.php` | 商城订单和原商户 UID 查看 |
| `install/addon_shop.sql` | 配置和商城表 |

## 13. 发布顺序

1. 备份代码和商城三表。
2. 暂时将 `shop_status` 设为 `0`。
3. 同步全部运行文件，不能只同步一半。
4. 执行更新后的 `addon_shop.sql`，创建 `shop_excluded_uids`。
5. 运行 PHP 语法和本地验收。
6. 检查排除 UID。
7. 恢复 `shop_status=1`。
8. 发起一笔测试商户订单，确认出现购买页。
9. 检查原订单 UID、金额、param 和回调地址未变。
10. 观察订单创建与通知日志。

## 14. 回滚

1. 立即设置 `shop_status=0`，新订单自动恢复原流程。
2. 现有已附加订单仍可使用原 `trade_no` 进入收银台。
3. 恢复代码备份。
4. 恢复前核对商城表，不删除历史商城记录。
