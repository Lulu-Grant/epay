# 商城包装层目标模式开发提示词

> 历史基线：本文档对应旧 `checkout` 购买确认页开发。当前开发以 `docs/shop-shadow-order-development-guide.md` 和 `docs/shop-feature-acceptance-criteria.md` 为准。

## 主提示词

```text
你正在开发彩虹易支付的“商户订单商城包装层”。

目标：
商城开启后，除排除 UID 外的所有普通商户支付订单默认增加购买确认页和商城履约记录。商城不是平台统一收款系统。

不可破坏的不变量：
1. 原 pre_order.uid、money、name、param、notify_url、return_url 保持不变。
2. 不得创建第二张 pre_order。
3. pre_shop_orders.pay_trade_no 唯一关联原 pre_order.trade_no。
4. 购买页不得编辑金额或商户。
5. 支付使用原商户 gid、费率、通道和资金规则。
6. 支付成功必须更新商城状态，并继续原商户异步通知。
7. 商城同步失败不能阻断原商户通知。
8. 排除 UID 完全走原支付流程且不写商城表。
9. 新商城订单随机固化一个可用商品，重试和回调不得重新随机。
10. 支付成功后商城订单自动变为已发货，不依赖人工物流操作。

开发流程：
1. 阅读 docs/shop-feature-development-guide.md。
2. 阅读 docs/shop-feature-acceptance-criteria.md。
3. 检查工作区已有修改，不覆盖无关 Telegram 等模块。
4. 先实现配置排除模型。
5. 实现原支付订单的幂等附加。
6. 实现购买确认页。
7. 接入 submit.php 与 mapi.php。
8. 恢复原商户通道和回调语义。
9. 执行静态检查和完整隔离验收。
10. 未通过全部 P0/P1 前不得线上开启。

完成定义：
- 纳入商户返回购买确认页。
- 排除商户行为与改造前一致。
- 重复发起只产生 1 张支付订单和 1 张商城记录。
- 购买页伪造 money/uid/param 无效。
- 购买页不出现任何填写表单，只通过支付方式按钮继续原订单。
- 购买页显示随机商城商品，原 `pre_order.name` 保持不变。
- 模拟重复支付后原商户只入账一次。
- 商城已支付/已发货，原商户 notify 成功。
- Playwright 桌面和移动端无报错、无横向溢出。
```

## 代码审查提示词

```text
审查当前商城包装层实现。优先查找：
- 是否创建第二张支付订单；
- 是否改写原 uid、money、name、param 或回调地址；
- 是否绕过原商户用户组通道；
- 是否因商城同步异常阻断商户回调；
- 是否存在重复附加商城记录；
- 排除商户是否仍写商城表；
- 购买页是否暴露金额输入；
- MAPI 是否明确返回 jump，排除商户是否保持原返回类型。

按严重程度输出问题，并给出文件与行号。没有问题时说明剩余真实支付验收风险。
```

## 验收提示词

```text
执行 SHOP_TEST_REQUIRE_BROWSER=1 bash scripts/shop-feature-local-acceptance.sh。

必须核对数据库：
- 纳入订单 pre_order 数量=1；
- 对应 pre_shop_orders 数量=1；
- uid、money、param、notify_url 与商户请求一致；
- 排除 UID 的 shop 记录数量=0；
- 支付后原商户余额只增加一次；
- pre_order.notify=0 且 shop pay_status=1；
- shop order_status=2 且 status_times 同时存在 paid/shipped；
- 重复发起与重复支付均幂等。

任何 P0 失败时停止部署并报告。
```
