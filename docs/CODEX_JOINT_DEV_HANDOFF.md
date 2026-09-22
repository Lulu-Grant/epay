# Codex + Grokbot 联合开发交接（主文档）

生成：2026-09-22（Asia/Singapore）  
用途：给 **Codex** 与 **Grokbot** 在同一代码树上联合开发时使用的权威交接说明（中文）。  
安全：本文**不含**任何密码、Token、私钥或生产密钥正文。

---

## 1. 目的

把彩虹易支付维护仓库放到 WIN-VPS 工作区，使 Codex 能：

1. 读懂目录与环境边界（P4 / P5）；
2. 在默认**只读**权限下做研究与功能开发；
3. 与 Grokbot 共用同一套文档与 Git 基线，避免密钥与生产误操作。

**先读本文件**，再改代码。

---

## 2. 仓库 / 分支 / 基线

| 项 | 值 |
|----|-----|
| origin | `https://github.com/Lulu-Grant/epay.git` |
| upstream | `https://github.com/lopinx/epay.git` |
| 分支 | `upgrade/php-84-compatible` |
| 同步基线 HEAD | `ab806d5`（`docs: add Grokbot project handover guide`） |
| 说明 | Mac 侧曾以此为准；**请用 `git log -1` / `git rev-parse HEAD` 复核** |

```bash
git remote -v
git checkout upgrade/php-84-compatible
git status --short --branch
git rev-parse HEAD
git log -5 --oneline
```

功能开发：**从 `upgrade/php-84-compatible` 拉 feature 分支**，不要直接在生产机改。

---

## 3. 系统是什么

**彩虹易支付 Version 3075** — PHP 在线支付平台：

- 商户 API（`api.php` 等）
- 支付收银台 / 提交（`pay.php`、`submit.php`、`cashier.php`）
- 管理后台（`admin/`）
- 商户后台（`user/`）
- 插件化支付通道（`plugins/`）
- 计划任务（`cron.php`）

本分支重点：PHP 8.4 可运行、可部署、可验收的维护，而非整站重写。

---

## 4. 目录地图

```text
.
├── admin/           # 管理后台
├── user/            # 商户后台
├── includes/        # 核心库、业务函数、投诉/Telegram 等适配
├── plugins/         # 支付插件
├── template/        # 前端与文档模板
├── assets/          # 静态资源（含本地化 CDN）
├── paypage/         # 支付页相关
├── docs/            # 运维与开发文档（含本交接）
├── tools/           # 工具与 PHP8.4 脚本
├── api.php          # 商户 API 入口
├── pay.php          # 支付页面入口
├── submit.php       # 支付提交
├── cron.php         # 计划任务
├── doc.php          # 开发文档入口
├── CODEX.md         # Codex 根指针
├── README.md
└── AGENTS.md
```

更多说明见根目录 `README.md`、`AGENTS.md`。

---

## 5. 环境

### 5.1 P4 — 玄帆（xuanfanpay）生产

- 主机：`47.119.136.160`
- **代理默认 READ-ONLY**：无用户授权，禁止部署、DB 写入、改订单、改通道。
- 新商户 API：`https://api.xuanfanpay.top/`
- 遗留支付域：`pay.xuanfanpay.top`
- 历史 P3：`47.106.222.150` — **默认禁止**

### 5.2 P5 — 乐跑 / helingpay（克隆）

- 主应用：`47.119.144.92`（深圳）+ HK SOCKS `47.238.158.22`
- 应用路径：`/srv/epay/current`
- 域名：`api` / `pay` / `manage.helingpay.top`；商户 `luckrun.helingpay.top`；企业静态 `helingpay.top` @ `8.148.237.86`
- 管理入口：`/lpao`（**不是** `/admin`）
- 品牌 / 订单前缀：乐跑 / `LP`
- **仅在用户授权后部署**

### 5.3 UI overlay（可选）

- 私有仓库：`Lulu-Grant/helingpay-epay` — 仅 **lp-dark** UI/CSS，无密钥。
- 本地曾有参考树：`/workspace/epay-lp-dark/`（Grokbot box，非本仓必含）。

完整表：[`ENVIRONMENTS_P4_P5.md`](ENVIRONMENTS_P4_P5.md)。

---

## 6. 密钥策略（HARD）

**永远不要**把密钥、密码、Token、私钥写入：

- 聊天 / Codex Prompt
- Git 提交
- 本仓库文档
- 日志截图与粘贴内容

生产配置位置（**仅路径索引**）：

- `/srv/epay/shared/config.php`
- `/etc/epay/*`

仓库内 `config.php` 为空模板；本地 `config.local.php` **仅 localhost**。  
若发现 `*.env`、`secrets/`、含真密钥的 `config.local.php`、SSH pem — **不要复制进树、不要提交**。

---

## 7. 默认权限

- 默认：**只读研究**（读代码、写文档草稿、本地改 feature）。
- 写入生产 / 改 DB / 改通道 / 下单 / 部署：必须用户明确授权。
- 通道 **14** 历史上排除，直至用户解除。

---

## 8. Codex 联合开发约定

1. **分支**：从 `upgrade/php-84-compatible` 开 `feature/...` 或 `fix/...`。
2. **提交**：无密钥、无 `.env`、无生产 `config` 真值；消息说明「为什么」。
3. **UI/CSS**：复用 tokens / bootstrap3 / plugins / admin / merchant 共享样式；**不要**为单页堆独立 CSS；P5 缓存版本习惯如 `lp21`。
4. **边界**：做 CSS/文案时**不要**改支付核心与 DB 逻辑。
5. **联调**：需要 P4/P5 实测时，先问用户；由运维侧在授权范围内执行。

---

## 9. 关键文档索引

| 文档 | 说明 |
|------|------|
| `../README.md` | 项目总览 |
| `../AGENTS.md` | 目录与约定速查 |
| `grokbot-handover.md` | 2026-08-31 Grokbot 交接（偏 P3→P4）；**P5 以本文 + ENVIRONMENTS 补充** |
| `production-environment.md` | 生产环境 |
| `network-proxy-architecture.md` | 网络 / 代理架构 |
| `admin-operations-runbook.md` | 管理运维手册 |
| `troubleshooting-guide.md` | 排障 |
| `ENVIRONMENTS_P4_P5.md` | P4/P5 主机域名表 |
| LP UI 文档 | 若存在于 overlay 或 `DARK_UI_*` / `LP_*` 参考树 |

---

## 10. Roll107 说明（高层，非应用功能必读）

P5 上 gid=8 支付宝 `pay_roll` id=107 有约每 3 小时的轮询评估：硬故障替换 vs 抬升门槛（Wilson +3pp 与金额标准化成功率 +5pp 等）。  
**不要臆造 SQL。** 细节在 ops 侧 `roll107_eval_spec`（helingpay 运维代理）；**普通 Codex 应用/UI 开发不需要实现或修改该流程**。

---

## 11. 如何开始

```bash
# 若目录已是本仓库检出：
cd C:\Users\winadmin\Documents\epay   # 或你的检出路径
git fetch origin
git checkout upgrade/php-84-compatible
git pull --ff-only origin upgrade/php-84-compatible
git rev-parse HEAD   # 对照 ab806d5 或更新的 HEAD

# 本地建议：PHP 8.4 + MariaDB/MySQL，参考 README 扩展列表
# 然后打开：
#   docs/CODEX_JOINT_DEV_HANDOFF.md
#   docs/CODEX_README.md
#   CODEX.md
```

有疑问先只读调研，再向用户确认写入或部署范围。
