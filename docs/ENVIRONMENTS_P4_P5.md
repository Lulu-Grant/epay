# 环境一览：P4 / P5（仅主机与域名，无密码）

> 本文只列 IP/域名/路径角色。**不含**任何账号、密码、Token、密钥。

## P4 — 玄帆（xuanfanpay）生产

| 项 | 值 |
|----|-----|
| 角色 | 玄帆生产；**代理默认只读**（无用户授权禁止部署/改库/改订单/改通道） |
| 主机 IP | `47.119.136.160` |
| 新商户 API | `https://api.xuanfanpay.top/` |
| 支付域（遗留） | `pay.xuanfanpay.top` |
| 历史 P3 | `47.106.222.150` — **默认禁止** |

## P5 — 乐跑 / helingpay（克隆）

| 项 | 值 |
|----|-----|
| 角色 | 乐跑业务克隆；**仅在用户授权后部署** |
| 主应用主机 | `47.119.144.92`（深圳） |
| HK SOCKS | `47.238.158.22` |
| 应用路径 | `/srv/epay/current` |
| 共享配置（路径索引） | `/srv/epay/shared/config.php`；`/etc/epay/*` |
| API / 支付 / 管理 | `api.helingpay.top` / `pay.helingpay.top` / `manage.helingpay.top` |
| 管理入口 | `/lpao`（**不是** `/admin`） |
| 商户前台 | `luckrun.helingpay.top` |
| 企业静态站 | `helingpay.top` @ `8.148.237.86`（广州） |
| 品牌 / 订单前缀 | 乐跑 / `LP` |
| UI overlay 仓库 | 私有 `Lulu-Grant/helingpay-epay`（lp-dark CSS，无密钥） |

## 密钥存放（索引，无值）

| 位置 | 说明 |
|------|------|
| 生产 `/srv/epay/shared/config.php` | 真实 DB/业务密钥 |
| 生产 `/etc/epay/*` | 运维侧注入配置 |
| 本地 `config.local.php` | **仅 localhost**；勿提交生产密钥 |
| Git / 文档 / 聊天 / Codex Prompt | **禁止**写入任何密钥正文 |

## 通道注意

- 历史约定：通道 **14** 排除，直至用户解除。
- Roll107（P5 gid=8 支付宝轮询评估）属运维流程，应用功能开发一般不涉及；细节在 ops 侧 `roll107_eval_spec`，勿臆造 SQL。
