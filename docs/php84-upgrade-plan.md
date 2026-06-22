# PHP 8.4 升级计划

生成日期：2026-06-22

目标：将彩虹易支付升级为支持 PHP 8.4 的版本，同时保持 PHP 7.4 运行兼容。本文档只定义升级方案，不包含源码修改。

## 背景

当前项目是传统 PHP 单体支付系统，推荐运行环境为 PHP 7.4 + MySQL 5.7。项目入口分散，核心初始化位于 `includes/common.php`，支付插件通过 `includes/lib/Plugin.php` 动态加载，核心支付流程集中在 `includes/lib/Payment.php`。

代码规模初步统计：

- PHP 文件总量约 545 个。
- 非 vendor PHP 文件约 363 个。
- 支付插件主文件 44 个。
- Composer 根文件位于 `includes/composer.json`。
- `includes/vendor` 已提交到仓库，目前未发现根级 `composer.lock`。

初步扫描结论：

- 当前 PHP 8.5 CLI 全量语法检查未发现语法级硬错误。
- PHP 8.4/8.5 下 vendor 依赖会出现多处弃用警告，集中在 `lpilp/guomi` 和 `mdanter/ecc` 的隐式 nullable 参数。
- 业务代码中发现 `strftime()` 用法 3 处，需要迁移。
- 项目当前默认 `error_reporting(E_ERROR | E_PARSE | E_COMPILE_ERROR)`，会隐藏大量 PHP 8.4 兼容性问题。

## 升级原则

1. 保持 PHP 7.4 兼容。
   升级期间不得引入 PHP 8 专属语法，例如 union type、match、enum、readonly、构造器属性提升、属性类型强约束等。

2. 先建立可观测性，再改代码。
   在测试环境开启 `E_ALL`，记录 deprecated、warning、notice，确认问题清单后再分批修复。

3. 先处理硬兼容，再处理质量优化。
   第一阶段目标是 PHP 7.4 和 PHP 8.4 都能稳定运行；安全重构、SQL 参数化、架构整理另列后续阶段。

4. 支付链路优先。
   支付创建、跳转、异步通知、同步返回、退款、转账、结算的正确性优先于后台展示细节。

5. 插件分层治理。
   先保证所有插件可加载，再对高使用率插件做真实接口或沙箱调用验证。

## 目标运行环境

最低兼容环境：

- PHP 7.4
- MySQL 5.7
- Nginx 或 Apache，沿用现有伪静态规则
- 必需扩展：pdo_mysql、curl、openssl、json、mbstring、gd、fileinfo、session
- 建议扩展：gmp、bcmath、intl、zip、xml

目标升级环境：

- PHP 8.4
- MySQL 5.7 或 8.0
- Nginx 或 Apache，沿用现有伪静态规则
- 必需扩展与最低兼容环境一致

## 主要风险清单

### R1. 依赖不可重复安装

现状：`includes/composer.json` 只有 require，没有平台版本声明，也未发现根级 `composer.lock`。

风险：不同机器执行 composer install/update 时可能解析出不同版本，导致 PHP 7.4 或 PHP 8.4 行为不一致。

处理方案：

- 在 `includes/composer.json` 声明 PHP 版本范围。
- 生成并提交 `composer.lock`。
- 明确 `vendor` 管理策略：继续提交 vendor，或改为部署时安装。二者只能选一种。

建议版本范围：

```json
{
  "require": {
    "php": ">=7.4 <8.5"
  }
}
```

### R2. PHP 8.4 弃用警告被隐藏

现状：`includes/common.php` 只报告严重错误。

风险：测试环境无法发现 deprecated、warning、notice，线上切换 PHP 8.4 后可能出现日志爆量或隐藏行为变化。

处理方案：

- 增加测试环境错误级别开关。
- 测试环境使用 `E_ALL`。
- 生产环境保留安全输出策略，但错误写入日志。

### R3. vendor 依赖 PHP 8.4 弃用

现状：`lpilp/guomi` 和 `mdanter/ecc` 在 PHP 8.4/8.5 下触发隐式 nullable 参数弃用。

风险：如果生产环境开启较高错误级别，支付签名、国密、证书相关场景可能产生大量日志。

处理方案：

- 优先查询上游是否有兼容版本。
- 如无兼容版本，建立内部补丁或替代库评估。
- 禁止直接无记录地修改 vendor，需要通过 composer patch、fork 包或文档化补丁管理。

### R4. 动态插件加载缺少接口约束

现状：插件通过 `include + class_exists + method_exists + 静态方法` 加载。

风险：语法检查通过不代表运行路径可用。插件中的常量、全局变量、证书路径、OpenSSL 行为可能在 PHP 8.4 下才暴露问题。

处理方案：

- 建立插件批量加载测试。
- 检查每个插件的 `info`、`submit`、`mapi`、`notify`、`return`、`refund` 方法是否符合预期。
- 对高优先级插件进行沙箱或模拟回调验证。

### R5. OpenSSL 行为差异

现状：多个插件和核心支付签名使用 OpenSSL，包含 `openssl_get_privatekey`、`openssl_pkey_get_private`、`openssl_sign`、`openssl_verify`、`openssl_public_encrypt` 等。

风险：密钥格式、资源对象变化、算法默认值、证书读取失败可能导致支付签名失败。

处理方案：

- 建立 RSA/MD5/证书签名样例测试。
- 覆盖支付宝、微信、易支付、Stripe、PayPal、银联、快钱等典型插件。
- 确认 PHP 7.4 和 8.4 生成签名一致，验签结果一致。

### R6. SQL 与数据库兼容风险

现状：数据库层基于 PDO，但大量业务 SQL 仍然字符串拼接，且 `PdoHelper` 使用 `ERRMODE_SILENT`。

风险：升级测试中 SQL 错误不明显，MySQL 8.0 与 MySQL 5.7 的 sql_mode 和保留字差异可能导致隐性问题。

处理方案：

- 升级阶段保持 MySQL 5.7 为基准。
- 增加 MySQL 8.0 兼容性验证作为扩展目标。
- 测试环境打开 SQL 错误日志。
- 后续独立安全专项处理 SQL 参数化。

## 分阶段计划

### 阶段 0：仓库与基线冻结

目标：建立可回退、可对比的升级基线。

任务：

- 确认 `origin` 指向自有仓库，`upstream` 指向原仓库。
- 创建升级分支，例如 `upgrade/php-84-compatible`.
- 记录当前版本号、数据库版本、PHP 7.4 环境配置。
- 备份一套可运行数据库样本，脱敏保留订单、用户、通道、配置数据。

产出：

- 升级分支。
- 基线环境说明。
- 数据库样本和回滚备份。

### 阶段 1：兼容性检测工具化

目标：先建立检测机制。

任务：

- 增加 PHP 7.4、8.4 双版本语法检查脚本。
- 增加插件批量加载脚本。
- 增加 Composer 校验流程。
- 增加运行时错误日志收集说明。
- 建立 CI 矩阵：PHP 7.4、8.1、8.2、8.3、8.4。

建议检查项：

```bash
find . -path './.git' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
composer validate --working-dir=includes --strict
composer install --working-dir=includes
```

产出：

- 本地检测脚本。
- CI 配置。
- 初始问题清单。

### 阶段 2：Composer 与依赖治理

目标：让依赖安装可重复，且明确支持 PHP 7.4 到 8.4。

任务：

- 为 `includes/composer.json` 增加 PHP 版本约束。
- 执行依赖解析并生成 `composer.lock`。
- 检查 `cccyun/alipay-sdk`、`cccyun/wechatpay-sdk`、`cccyun/qqpay-sdk`、`lpilp/guomi` 的最新兼容版本。
- 处理 `mdanter/ecc` 和 `lpilp/guomi` 的 PHP 8.4 deprecated。
- 明确是否继续提交 `includes/vendor`。

产出：

- 更新后的 Composer 依赖策略。
- 锁定的依赖版本。
- 依赖风险说明。

### 阶段 3：PHP 8.4 显性兼容修复

目标：修复明确的 PHP 8.4 兼容问题。

任务：

- 替换 `strftime()`。
- 检查动态属性写入，对必要类补充属性声明或兼容处理。
- 检查 `count()`、`implode()`、`array_key_exists()` 等函数传参类型。
- 检查字符串偏移、数组偏移、未定义变量、未定义数组键。
- 检查 OpenSSL key/resource/object 行为。
- 保持代码语法仍可在 PHP 7.4 解析。

产出：

- PHP 7.4 和 PHP 8.4 均无 fatal error。
- PHP 8.4 测试环境 deprecated/warning 明显下降。

### 阶段 4：核心链路回归

目标：验证支付平台核心业务正确。

必须覆盖：

- 安装和升级页面。
- 后台登录。
- 用户登录、注册、找回密码。
- 支付通道管理。
- 商户接口密钥生成。
- 创建订单。
- 页面支付跳转。
- API 支付创建。
- 同步返回。
- 异步通知。
- 手动补单。
- 退款查询和退款提交。
- 结算列表和结算状态变更。
- 转账列表和转账状态查询。
- 定时任务 `cron.php`。

产出：

- 核心链路测试报告。
- PHP 7.4/8.4 对比结果。

### 阶段 5：支付插件分级验证

目标：按风险和使用价值验证插件。

插件分级建议：

- P0：必须验证。`alipay`、`wxpay`、`qqpay`、`epay`、`epayn`。
- P1：重点验证。`stripe`、`paypal`、`unionpay`、`swiftpass`、`swiftpass2`、`kuaiqian`、`ysepay`、`sandpay`。
- P2：批量加载验证。其他插件至少保证 include 和配置读取无错误。

每个 P0/P1 插件至少验证：

- 插件配置可读取。
- 支付创建返回结构正确。
- 签名生成正确。
- 回调验签正确。
- 同步返回处理正确。
- 异步通知处理正确。
- 支持退款的插件验证退款接口。

产出：

- 插件兼容矩阵。
- 插件问题清单。
- 暂不支持或待修复插件列表。

### 阶段 6：灰度与发布

目标：安全切换到 PHP 8.4。

建议发布策略：

- 保留 PHP 7.4 生产环境作为回滚目标。
- 先在镜像环境完成完整回归。
- 灰度少量真实流量，优先覆盖低风险商户。
- 监控支付成功率、异步通知成功率、错误日志、慢查询、退款失败率。
- 灰度稳定后扩大流量。

回滚条件：

- 支付成功率明显低于 PHP 7.4 基线。
- 异步通知失败率明显上升。
- 出现签名、验签、证书读取类批量失败。
- 出现订单状态不一致。
- PHP fatal error 或 uncaught exception 持续出现。

## 建议时间安排

| 阶段 | 建议周期 | 说明 |
| --- | --- | --- |
| 阶段 0 | 0.5 天 | 分支、备份、基线确认 |
| 阶段 1 | 1 天 | 检测脚本和 CI |
| 阶段 2 | 1-2 天 | Composer 与依赖治理 |
| 阶段 3 | 2-4 天 | PHP 8.4 显性兼容修复 |
| 阶段 4 | 2-3 天 | 核心链路回归 |
| 阶段 5 | 3-7 天 | 插件分级验证 |
| 阶段 6 | 1-3 天 | 灰度和发布 |

总周期建议：10 到 20 个工作日，取决于插件真实接口验证数量。

## 不在本次升级范围

- 大规模框架重构。
- 全量 SQL 参数化改造。
- 前端 UI 重构。
- 支付插件接口重新设计。
- 从 MySQL 5.7 强制升级到 MySQL 8.0。
- 移除全部 vendor 入库策略，除非单独确认。

## 最终交付物

- PHP 8.4 兼容代码分支。
- PHP 7.4 回归通过证明。
- Composer 锁定文件和依赖说明。
- 插件兼容矩阵。
- 核心链路测试报告。
- 灰度发布与回滚说明。

