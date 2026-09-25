# Codex 起步（1 页）

面向：在本机 `C:\Users\winadmin\Documents\epay`（或任意检出）与 Grokbot **联合开发** 的 Codex。

## 立刻打开

→ **[`CODEX_JOINT_DEV_HANDOFF.md`](CODEX_JOINT_DEV_HANDOFF.md)**（主交接，中文）

## 这是什么

彩虹易支付 **3075** 维护分支：PHP 商户 API + 收银台 + 管理后台 + 商户后台 + 支付插件。生产目标 **PHP 8.4 + MariaDB/MySQL**。

## 仓库布局（速查）

| 路径 | 用途 |
|------|------|
| `admin/` | 管理后台 |
| `user/` | 商户后台 |
| `includes/` | 核心库 |
| `plugins/` | 支付通道插件 |
| `api.php` / `pay.php` / `submit.php` / `cron.php` | 入口 |
| `docs/` | 运维与开发文档 |
| `CODEX.md` | 根入口指针 |

## 环境（无密码）

| 代号 | 用途 | 备注 |
|------|------|------|
| P4 | 玄帆生产 `47.119.136.160` | 默认只读 |
| P5 | 乐跑克隆 `47.119.144.92` | 授权后才可部署 |
| UI overlay | `Lulu-Grant/helingpay-epay` | 仅 lp-dark CSS |

详见 [`ENVIRONMENTS_P4_P5.md`](ENVIRONMENTS_P4_P5.md)。

## 密钥策略

密钥只在服务器：`/srv/epay/shared/config.php`、`/etc/epay/*`。本地 `config.local.php` 仅 localhost。**永远不要**把密钥写进文档、Git、聊天或 Codex Prompt。

## 建议工作流

1. 从 `upgrade/php-84-compatible` 拉 feature 分支
2. 改功能/UI；CSS 复用共享 token，勿为单页堆 CSS；勿顺手改支付/DB
3. `git status` 确认无 `.env` / 密钥文件
4. 需要上 P4/P5 时等用户授权，由运维侧执行（Codex 默认不部署）

## 文档索引

- [列表查询与短缓存实施报告](P5_LIST_QUERY_CACHE_OPTIMIZATION_IMPLEMENTATION_REPORT.md)（PHP 8.4，GitHub CI 与 P5 分阶段发布记录）
- [列表查询与短缓存开发文档](P5_LIST_QUERY_CACHE_OPTIMIZATION_PLAN.md)
- `../README.md`、`../AGENTS.md`
- `grokbot-handover.md`（2026-08-31，偏 P3→P4；P5 以本交接与 ENVIRONMENTS 为准）
- `production-environment.md`、`network-proxy-architecture.md`
- `admin-operations-runbook.md`、`troubleshooting-guide.md`
