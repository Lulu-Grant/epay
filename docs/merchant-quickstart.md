# 商户快速上手指南

## 域名与入口

| 用途 | 地址 |
|------|------|
| 商户后台 | https://luckrun.helingpay.top/user/ |
| API 接口 Base URL | https://api.helingpay.top/ |
| 支付网关 | https://api.helingpay.top/ 或 https://pay.helingpay.top/ |
| 开发文档 | https://luckrun.helingpay.top/doc.html |

官网 www.helingpay.top 为企业介绍站点，不提供支付对接文档。

## 对接前准备

1. 登录商户后台，完善资料并确认支付权限已开启。
2. 在「个人资料 → API 信息」查看商户 ID（pid）、商户密钥（V1 MD5）或生成 RSA 密钥对（V2）。
3. 阅读开发文档：V2（推荐，RSA）或 V1（MD5，见文档内旧版链接）。

## 平台订单号 trade_no

- 新订单格式：`LP` + 14 位时间（YmdHis）+ 5 位随机数，例如 `LP2026091505523330818`。
- 历史订单可能为纯数字。
- 对接时请一律按**字符串**处理，不要按纯数字解析。

## 建议验收步骤

1. 使用测试金额发起一笔下单（submit / mapi 或 V2 `api/pay/create`）。
2. 完成支付后确认异步通知（notify_url）收到且验签通过，并返回 `success`。
3. 在商户后台订单列表核对平台订单号与商户订单号。
4. 如需退款/代付，先在后台开启对应 API 开关再调用接口。

## 注意

- 请勿把密钥、私钥提交到公开仓库或发给无关第三方。
- API 请求请指向 `https://api.helingpay.top/`，不要使用商户后台域名作为接口地址。
