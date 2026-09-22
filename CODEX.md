# Codex 入口（支付渠道运维 / 联合开发）

本仓库供 **Codex + Grokbot** 在 WIN-VPS 上联合开发使用。

## 先读

1. **主交接**：[`docs/CODEX_JOINT_DEV_HANDOFF.md`](docs/CODEX_JOINT_DEV_HANDOFF.md)（必读）
2. **一页起步**：[`docs/CODEX_README.md`](docs/CODEX_README.md)
3. **环境表**：[`docs/ENVIRONMENTS_P4_P5.md`](docs/ENVIRONMENTS_P4_P5.md)

## Git 基线

- 远端：`https://github.com/Lulu-Grant/epay.git`
- 分支：`upgrade/php-84-compatible`
- 同步时 HEAD（Mac/GitHub）：`ab806d5` — *请用 `git log -1` 复核*
- 上游：`https://github.com/lopinx/epay.git`

## 硬规则（摘要）

- **默认只读**：未获用户明确授权，禁止部署、改库、改通道、改订单、写生产配置。
- **禁止密钥入仓/入文档/入 Prompt**：密码、Token、私钥、`.env`、生产 `config.php` 内容一律不进 Git/文档/聊天。
- **P4（玄帆）默认只读**；**P5（乐跑）仅在授权后部署**；历史 P3 默认禁止。
- UI/CSS 改动走 token/共享样式，勿改支付/DB 逻辑；P5 UI 另见私有 overlay `Lulu-Grant/helingpay-epay`。

## 本地起步

```bash
git checkout upgrade/php-84-compatible
git status --short --branch
git rev-parse HEAD
# 打开 docs/CODEX_JOINT_DEV_HANDOFF.md
```

功能开发请从本分支拉 feature 分支；提交前确认无密钥文件。
