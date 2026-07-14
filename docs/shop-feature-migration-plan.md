# 商城功能迁移方案

> 历史基线：本文档记录商城从独立收款迁移到购买确认包装层的过程。当前无感影子订单目标以 `docs/shop-shadow-order-development-guide.md` 为准。

## 1. 迁移目标

将原“平台统一收款商城”迁移为“原商户订单商城包装层”。

目标行为：

- 商城开启后普通商户默认进入购买确认页。
- 原支付订单是唯一支付事实来源。
- 金额、商户、商品名、参数和回调保持原值。
- 商城表保存查询凭证、支付同步和履约信息；新订单不再采集购买人资料。
- 后台配置排除商户 UID，不再配置商城收款 UID。

## 2. 可保留部分

- `pre_shop_config`、`pre_shop_goods`、`pre_shop_orders` 三张表。
- `pre_shop_orders.pay_trade_no` 唯一索引。
- 商城订单号、查询 token、隐私脱敏。
- 后台商城订单、统计、物流和软删除。
- 充值类商品作为静态商城目录和订单随机商品来源。
- 支付成功幂等回写框架。

## 3. 必须重写部分

| 旧实现 | 新实现 |
| --- | --- |
| `shop_pay_uid` 统一收款 | 原 `pre_order.uid` 收款 |
| 商城决定金额 | 原 `pre_order.money` 决定金额 |
| 商城创建第二张支付订单 | 原支付订单附加一张商城记录 |
| `param=shop:*` 标记 | 通过唯一 `pay_trade_no` 关联 |
| 商城专用全局通道路由 | 原商户 gid/通道/费率 |
| 商城自通知替代商户通知 | 商城同步后继续原商户通知 |
| 价格输入页 | 原金额只读购买确认页 |
| 配置收款 UID | 配置排除 UID |

## 4. 不能导入或继续使用

- 旧 `shop_pay_uid` 运行逻辑。
- 旧 `shop_min_amount`、`shop_max_amount` 金额逻辑。
- 独立商城下单创建 `pre_order` 的代码。
- 覆盖商户 `param`、`notify_url`、`return_url` 的代码。
- 支付成功后直接将 `notify=0` 而不通知商户的代码。
- 商城全局通道随机选择代码。

旧配置键可暂留数据库用于代码回滚，但新代码不得读取。

## 5. 迁移步骤

1. 完成新代码与隔离验收。
2. 备份线上运行文件和商城三表。
3. 记录原 `shop_status`。
4. 临时关闭商城，避免部署中间态接入订单。
5. 同步 ConfigService、OrderService、Pay、shopping、functions、admin 等全部文件。
6. 执行 `addon_shop.sql`，写入 `shop_excluded_uids`。
7. 核对排除 UID；默认空表示全部商户进入商城。
8. 执行 PHP 语法和 HTTP 健康检查。
9. 恢复原商城开关。
10. 使用测试商户创建订单，确认原 UID、金额、param、通知地址不变。

## 6. 数据迁移

不修改 `pre_order` 结构，不批量补历史订单。

现有未支付商城订单：

- 若为旧平台自营模型且线上无记录，不需要迁移。
- 若存在记录，部署前单独审计，不自动改写 `pay_trade_no` 或商户归属。

新订单从上线时刻开始随机固化商城商品。历史未支付订单不重新随机；历史已支付且仍为待发货的订单可在备份后一次性迁移为已发货。

## 7. 回滚

最快回滚：设置 `shop_status=0`。所有新商户订单立即恢复原流程。

代码回滚：恢复部署前代码包和商城三表备份。不得回滚或删除部署后已产生的普通支付订单。

## 8. 验收入口

详细标准见：

- `docs/shop-feature-development-guide.md`
- `docs/shop-feature-acceptance-criteria.md`
- `scripts/shop-feature-local-acceptance.sh`
