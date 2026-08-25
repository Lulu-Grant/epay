# 智能健康简报本地发布演练

## 用途与边界

`scripts/ai-health-release-rehearsal.php` 用于验证智能健康简报的目录发布和回滚状态机。它只接受 `/private/tmp/epay-ai-health-release-rehearsal-*` 形式的全新隔离目录，不含 SSH、生产主机、生产数据库或 systemd 操作能力，不能作为上线授权或生产部署命令。

演练覆盖：

- 冻结发布清单和绑定摘要校验。
- 旧发布基线先复制到新的候选目录，共享 `config.php` 软链接保持不变，再覆盖 27 个应用文件并逐个执行 PHP 语法检查。
- 4 个 systemd 单元的关键安全配置检查、原子安装和摘要核验。
- 隔离测试库中的 10 类中断迁移、重复安装和遗留队列数据保留回归。
- `current` 软链接原子切换、共享 `config.php` 目标和摘要不变检查。
- 正常发布后恢复，以及 systemd 安装后、迁移后、切换后三类故障注入。
- 每个场景恢复旧目录、旧单元文件和原测试库夹具后的探针。

数据库迁移回归沿用 `scripts/health-schema-migration-regression.php`。该脚本只接受名称以 `_local` 或 `_test` 结尾的显式测试数据库，并在结束前恢复原表及健康配置。

## 执行

```bash
set -a
. /Users/apple/.config/epay/health-test-db.env
set +a

/opt/homebrew/Cellar/php@8.4/8.4.22/bin/php \
  scripts/ai-health-release-rehearsal.php \
  --work-root=/private/tmp/epay-ai-health-release-rehearsal-r46
```

成功输出包含 `production_authorized=false`、4 个场景和证据文件路径。完整证据保存在演练目录的 `evidence.json`，权限为 `0600`，不记录数据库凭据。

再次执行必须使用新的空目录。工具故意不自动删除上一轮目录，便于审查候选文件、备份和证据。

## 通过标准

- 冻结载荷绑定与 `deploy/ai-health-release-sha256.txt` 一致。
- 候选目录保留旧发布基线文件及共享 `config.php` 软链接，只覆盖冻结清单中的应用文件。
- 正常场景完成候选验证、迁移回归、原子切换和切换后探针。
- 三个故障场景均在指定阶段中断，没有继续执行后续发布步骤。
- 四个场景最终均恢复旧 `current`、共享配置摘要和原 systemd 文件摘要。
- 执行前后测试数据库原表、遗留队列行和健康配置保持一致。

## 与生产门禁的关系

该演练补足目录切换、systemd 文件和附加迁移的本地可重复证据，但当前正式发布门禁仍不支持这种复合发布形态。生产上线前仍需：

1. 冻结精确候选包、目标主机前态、数据库备份和生产探针。
2. 由支持目录发布、systemd、数据库迁移和自动回滚的门禁审核精确执行计划。
3. 获得明确 `GO` 后才允许连接生产服务器。

任何本地演练成功都不能替代上述授权。
