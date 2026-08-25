# 智能健康简报项目发布控制器

`deploy/ai-health-release-control.php` 是项目自己的发布控制器，负责把本次智能健康简报改造的应用目录、数据库附加表和 systemd 调度作为一个发布单元管理。

`deploy/ai-health-gate-control.py` 是正式发布门禁的哈希绑定适配器。它只接受 `deploy`、`migrate-additive`、`rollback`、`verify-artifact`、`health-candidate`、`rehearse-migration` 和 `health-restored-migration` 的精确参数形状。审批检查不允许调用前三个生产操作；只有最终验证器输出 `GO` 后，独立部署步骤才可使用已封印命令。

## 设计边界

- 仅接受绝对路径；候选目录、共享配置目标和 systemd 文件必须是普通文件或无软链接祖先的目录。
- `config.php` 必须在当前发布和候选发布中都保持软链接，目标摘要必须一致。
- 发布清单由 `deploy/ai-health-release-files.txt` 冻结，候选目录先通过 `scripts/verify-ai-health-release.php`。
- 数据库只执行 `health_install.php` 的附加表迁移。文件回滚不会删除健康表，也不会自动回滚订单、通道、商户或 Telegram 历史数据。
- 数据库备份必须在 `apply` 前由外部受控流程生成，权限不得允许组或其他用户读取；控制器只校验并记录其摘要，不把密码放入命令行。
- systemd 单元使用临时文件写入后原子替换；失败时恢复备份并执行 `daemon-reload`。
- `current` 使用临时软链接后原子替换；失败时恢复原发布目录。
- `plan` 永远只读并输出 `production_authorized=false`。`apply` 和 `rollback` 都需要精确确认串，避免误触。
- `apply` 强制要求切换后健康探针和回滚后恢复探针；探针以 JSON argv 数组传入，不经过 shell，探针失败会触发文件回滚。

## 只读预检

```bash
/usr/bin/php8.4 deploy/ai-health-release-control.php \
  --operation=plan \
  --release-root=/srv/epay/releases/<release-id> \
  --current-link=/srv/epay/current \
  --systemd-root=/etc/systemd/system \
  --backup-root=/srv/epay/backups/<release-id> \
  --health-check-json='["/usr/bin/curl","-fsS","https://pay.example/health"]' \
  --restored-health-check-json='["/usr/bin/curl","-fsS","https://pay.example/health"]'
```

预检会校验候选载荷、当前配置软链接、4 个 systemd 单元和切换目标，不写入生产状态。

## 发布和回滚

发布前应使用不含密码的 MySQL defaults 文件生成独立数据库备份，并由人工核对备份摘要。确认所有外部支付、回调和 Telegram worker 探针均已准备后，才可执行：

```bash
/usr/bin/php8.4 deploy/ai-health-release-control.php \
  --operation=apply \
  --release-root=/srv/epay/releases/<release-id> \
  --current-link=/srv/epay/current \
  --systemd-root=/etc/systemd/system \
  --backup-root=/srv/epay/backups/<release-id> \
  --db-backup=/srv/epay/backups/<release-id>.sql \
  --health-check-json='["/usr/bin/curl","-fsS","https://pay.example/health"]' \
  --restored-health-check-json='["/usr/bin/curl","-fsS","https://pay.example/health"]' \
  --confirm=EPAY-HEALTH-RELEASE-APPLY
```

发现候选代码、systemd 或健康探针异常时：

```bash
/usr/bin/php8.4 deploy/ai-health-release-control.php \
  --operation=rollback \
  --release-root=/srv/epay/releases/<release-id> \
  --current-link=/srv/epay/current \
  --systemd-root=/etc/systemd/system \
  --backup-root=/srv/epay/backups/<release-id> \
  --restored-health-check-json='["/usr/bin/curl","-fsS","https://pay.example/health"]' \
  --confirm=EPAY-HEALTH-RELEASE-ROLLBACK
```

控制器完成文件和调度回滚后，仍必须人工复测后台登录、商户登录、API 下单、支付回调、商户通知和旧 Telegram 场景。数据库备份仅用于人工审计或经单独批准的恢复，不由紧急文件回滚自动导入。

## 隔离演练

控制器已在 `/private/tmp/epay-ai-health-release-control-test-r48-20260720` 完成一次完整 `apply → rollback` 演练：候选目录成为 `current`，4 个新单元摘要匹配冻结清单；回滚后旧目录和备份前单元摘要均恢复。演练使用真实候选载荷和真实 `_test` 数据库迁移回归，但以 `--local-test-mode=1` 限制全部目标路径在隔离目录中，并用无副作用命令代替 `systemctl`。测试专用的迁移和 systemd 命令覆盖只允许该模式，生产调用无法传入。

正式 `apply` 尚未执行。r50 发布前只完成了备份与迁移前指纹采集；应用目录、systemd 和数据库均未切换。
