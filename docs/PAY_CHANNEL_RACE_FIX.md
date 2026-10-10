# 下单通道竞争与支付宝回调恢复

日期：2026-10-10。基线 `upgrade/php-84-compatible` @ `8cafd0a`，修复分支 `fix/pay-channel-race`。仅开发、隔离测试和本地提交；未部署、未连接生产数据库、未读取或修改任何密钥或配置文件。

## 问题与实现

同一商户订单被并发提交时，请求可能同时看到 `channel=0`，各自选取不同支付宝通道并生成链接。原来的无条件更新会覆盖先前通道，回调再按最后写入的应用查询交易，出现掉单。

| 文件 | 改动及理由 |
| --- | --- |
| `includes/lib/PaymentRoute.php` | 共用通道绑定：带参数 `UPDATE ... WHERE trade_no=? AND channel=0 AND status=0`，用 `query()->rowCount()` 判断胜负。失败者读取已绑定通道、子通道、实收/结算金额，不再次轮询。数据库写入失败、订单已支付、信息不完整或支付方式冲突时不生成链接。 |
| `includes/lib/api/Pay.php` | `submit` 和 `mapi/create` 共用订单创建及唯一键冲突回读逻辑，继续验证金额、名称、通知地址、返回地址、扩展参数和状态。两条路径均使用通道条件更新；随机金额也沿用胜出请求的数据。订单号仍调用原 `generate_trade_no()`，LP 生成函数没有修改。 |
| `submit2.php` | 收银台已有订单也会选取和写入通道；改为复用已绑定通道，首次写入使用相同竞争保护。避免收银台重发覆盖 API 订单通道。 |
| `paypage/ajax.php` | 直接收款入口的首次通道写入也采用共用保护，竞争失败时使用库中通道。此入口每次创建独立订单，未改变其订单去重语义。 |
| `plugins/alipay/alipay_plugin.php` | 异步通知分别执行纯验签和 `alipay.trade.query`。当前通道验签失败或 `app_id` 不符时，寻找启用、同支付类型、`plugin=alipay`、应用 ID 精确一致的通道，以该应用重验及查询。只有签名、通知/查询的订单号、交易号、金额及成功状态全部通过，才修正收款通道并处理订单。 |
| `includes/functions.php` | 回调日志增加明确字段白名单，过滤控制字符及限制长度；保留失败阶段和收款通道修正前后 ID。 |
| `install/migrations/20261010_order_uid_outtradeno_unique.sql` | 只读重复订单检查、展开明细及索引检查；最后提供人工执行的唯一索引迁移。 |
| `scripts/payment-channel-race-regression.php` | 通过实际 Pay 入口和内存替身制造交错提交、唯一键冲突及失败路径；去掉初始化加载后运行实际收银台和直接收款入口；可选 SQLite 内存数据库检验实际 PDO 影响行数。 |
| `scripts/payment-callback-regression.php` | 用服务替身测试回调正常处理、兜底、查询失败、金额/订单不符、歧义应用、并发修正及日志脱敏。覆盖配置加载工厂，测试不执行配置文件。 |
| `scripts/order-number-regression.php` | 两个 API 入口共用创建方法后，生成函数调用点由两处变为一处；LP 格式、其他入口与路由验证仍检查。 |
| `.github/workflows/php84-compat.yml` | 将新的通道竞争回归接入现有 PHP 8.4 检查。 |

`PdoHelper::exec()` 在预处理路径返回布尔值，不能用于判断更新是否命中。因此本修复使用已有 `query()` 返回的 PDOStatement，不改变数据库封装的公共行为。已绑通道不能重新切换支付方式；确需另一支付方式时必须使用新商户订单号。

## 回调与日志边界

- 支付成功状态接受 `TRADE_SUCCESS` 和 `TRADE_FINISHED`。兜底路径不接受关闭或待付款状态；原通道签名及查询均确认 `TRADE_CLOSED` 时只应答，不处理订单。
- 回调和查询返回的 `out_trade_no` 必须等于本站 `trade_no`，支付宝 `trade_no` 必须与查询结果一致，两个金额均须以分为单位等于订单 `realmoney`。
- 同一应用匹配多个启用通道时拒绝猜测，需要人工核对。兜底查询只匹配主通道的实际 `appid`，不推断商户变量占位或任意子通道配置。
- 修正使用原通道、子通道、金额及未支付状态的条件更新，清除旧子通道；另一个请求已修正到相同通道时允许继续原有幂等支付处理。已支付的其他通道、不同金额、不同支付宝交易号或其他并发变化均拒绝。
- 修正日志事件为 `alipay_notify_channel_repaired`，字段包含 `previous_channel` 和 `actual_channel`。原实收金额及结算金额不在兜底中重算，历史手续费差异需要人工核对。
- 拒绝事件为 `alipay_notify_rejected`，附带 `app_id`、与原订单通道应用 ID 是否相符、`sign_type`、`charset`、`notify_type`、`trade_status`、`total_amount`、`seller_id`、`stage`。验签失败为 `verify`，交易查询失败为 `query`，后续业务处理异常为 `process`。
- 不记录 `sign`、密钥、私钥、公钥、整个 POST、通道配置或异常消息全文；异常只记录阶段与异常类型。

## 本地自动测试

在本修复工作树运行（PHP 8.4.x）：

```powershell
php scripts/payment-channel-race-regression.php
php scripts/payment-callback-regression.php
php scripts/order-number-regression.php
```

Windows PHP 如未启用 SQLite 扩展，可额外启用本机自带扩展后复跑第一项，以检查真实 PDO 的 1/0 影响行数：

```powershell
$phpExe = (Get-Command php).Source
$phpExtension = Join-Path (Split-Path $phpExe) 'ext'
& $phpExe -d "extension_dir=$phpExtension" -d extension=pdo_sqlite scripts/payment-channel-race-regression.php
```

本机 PHP 8.4.25 的上述三项测试通过，SQLite 实际 PDO 的条件更新检查通过；相关 PHP 文件语法检查通过。自动测试使用内存数据库或内存替身，不访问线上、不执行配置文件或真实支付接口。唯一键冲突模拟的是 MySQL 1062 返回/异常；尚未在真实 MySQL 的并发隔离环境、支付宝沙箱或完整 HTTP 环境验收，也未运行 GitHub CI。

通道测试覆盖 `submit`、`mapi`、收银台三种入口的九种组合，以及直接收款与收银台的确定性交错：两个请求先看到未绑通道，后发请求先绑定通道，先发请求条件更新影响 0 行；确认两次链接的应用一致、金额一致、未重复创建订单，第三次提交不再选取通道。

## 隔离 HTTP 并发复现

此项留给已有本地或测试环境，不能对生产执行。测试环境应有 PHP 8.4、多进程 Web 服务及独立 MySQL；单进程 PHP 内置服务器不能复现真正并发。预先创建两个虚构支付宝通道，选择加权随机或顺序轮询，并使用返回带应用 ID 链接的本地插件替身，避免调用真实支付宝。业务数据与签名仅使用测试夹具。

准备两份完全相同、已按测试商户签名的表单文件 `request-a.txt` / `request-b.txt`，例如 `pid=...&type=alipay&out_trade_no=RACE-...&...&sign=...`。文件只能包含虚构测试数据；不要放入仓库。对同一 `out_trade_no` 并发提交：

```powershell
$epayTestUrl = 'http://127.0.0.1:8080/submit.php'
$jobs = @(
    Start-Job -ArgumentList $epayTestUrl, (Join-Path $PWD 'request-a.txt'), (Join-Path $PWD 'response-a.txt') -ScriptBlock {
        param($url, $requestFile, $responseFile)
        curl.exe -sS -i --data-binary "@$requestFile" $url --output $responseFile
    }
    Start-Job -ArgumentList $epayTestUrl, (Join-Path $PWD 'request-b.txt'), (Join-Path $PWD 'response-b.txt') -ScriptBlock {
        param($url, $requestFile, $responseFile)
        curl.exe -sS -i --data-binary "@$requestFile" $url --output $responseFile
    }
)
$jobs | Wait-Job | Receive-Job
$jobs | Remove-Job
```

同时覆盖 `/mapi.php`（表单包含 `clientip`、`method=web`、`device=pc` 等测试参数），以及 `submit` 与 `mapi` 各一次的混合组合。至少重复 100 轮，每轮使用新商户订单号。若需稳定复现，在本地测试替身的首次条件更新前设置屏障，让两个请求均完成读取；不要将调试屏障提交或部署。

在测试数据库检查：

```sql
SELECT trade_no, uid, out_trade_no, channel, subchannel, realmoney, getmoney
FROM pay_order WHERE uid = <测试商户ID> AND out_trade_no = '<本轮测试订单号>';
```

验收要求：唯一索引迁移后只有一行，两次响应都对应同一系统订单号与数据库所存通道的应用 ID、金额。真实支付 URL 的签名、时间戳可以不同，不要求 URL 字节完全相同。回调需分别测试原通道成功、实际收款通道恢复、伪造签名、金额不符、查无交易、待付款状态与重复通知；支付入账和商户通知仍由既有幂等流程负责。

## 上线前人工确认

1. 核对实际表前缀、MySQL 版本、索引与字符集，确认 JSON 函数可用、唯一索引长度适合当前列定义，执行只读重复检查。迁移使用 `pay_order`，前缀不同需人工替换。
2. 逐条核对重复行是否有付款、退款、结算、商城影子订单等关联，人工决定保留/归档方式；不得自动按最早/最晚订单或未支付状态删除。维护窗口暂停所有下单写入，清理后再单独执行 ALTER，并验证唯一索引存在。
3. **代码条件更新只能保护同一系统订单行。未加唯一索引时，两个并发 INSERT 仍可能创建不同系统订单，因此不能宣称去重完成。**迁移与代码都完成后再做并发 HTTP 验收。
4. 同一 `(uid,out_trade_no)` 今后不再允许十天后复用。确认商户遵守订单号唯一性，并接受已绑订单不能切换支付方式；需换方式或重用旧号的商户应生成新号。
5. 确认每个用于兜底的应用只对应一个启用主通道，确认其收款归属、查询权限和主/子通道使用方式；存在动态应用变量或多通道同应用时须先人工整理，代码会拒绝歧义匹配。
6. 在隔离 MySQL 和支付宝沙箱验证证书/普通公钥两种模式，以及原通道、恢复通道、重复回调的入账和通知行为。配置加载器仍由运行环境提供，本次没有读取或修改其内容。
7. 核对历史收款通道修正后的手续费、分账及商城路由展示。此次保留已绑定金额，不自动补发历史回调、不自动退款，也不批量修正历史订单。

迁移脚本未执行；上线和任何生产验证需要另行明确授权。

2026-10-10 后续准备：已完成不触发业务操作的 P5 服务/目录/白名单代码哈希检查，并补充两个独立进程的 25 轮 SQLite 并发回归；未连接生产数据库、读取配置或切换版本。准备包、尚未完成的发布门槛及回滚顺序见 [上线准备记录](PAY_CHANNEL_RACE_RELEASE_PREP.md)。
