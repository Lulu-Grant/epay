# P5 管理员代登录来源校验修复

日期：2026-09-29（UTC+8）。运行目标：PHP 8.4。仅涉及 P5。

## 原因

启用代登录后，依次暴露两个独立问题：

1. ESA 使用 HTTP 回源，未传递原始 HTTPS 协议头，导致固定 HTTPS 域名校验失败。已在两个固定 SSO location 设置 `$fcgi_https on`；公共 HTTP 入口仍由 ESA 跳转 HTTPS。
2. 两个 PHP 交接页使用 `Referrer-Policy: no-referrer`，Nginx 又追加 `same-origin`。浏览器跨域表单 POST 因此发送 `Origin: null`，无法通过严格来源校验。只改 PHP 不足以修复，Nginx 最后追加的策略仍会覆盖它。

浏览器行为参考：[MDN Referrer-Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Referrer-Policy)。

## 实现

`admin/sso.php` 与 `user/sso.php` 使用 `Referrer-Policy: strict-origin`，保留表单 POST 所需来源；Referer 仅包含协议和域名，不含页面路径或查询参数。HTTPS 降级请求不发送来源。

P5 `/etc/nginx/sites-enabled/helingpay.conf` 的 `/lpao/sso.php` 和 `/user/sso.php` 两个精确 location 配置如下片段，置于已有 FastCGI include 前。必须保留管理入口原有 Basic Auth、限流与路径检查。

```nginx
set $fcgi_https on;
# location 自定义 add_header 后，需重新包含公共安全响应头。
include snippets/epay-headers.conf;
fastcgi_hide_header Referrer-Policy;
add_header Referrer-Policy strict-origin always;
include snippets/epay-php.conf;
```

线上每个交接入口只输出一个 `Referrer-Policy: strict-origin`。其他页面仍保留 `same-origin`。不放宽来源允许列表，不接受空/null/外域来源，不修改票据、浏览器绑定、会话、商户密钥或支付订单逻辑。

## 验证

- PHP 8.4：两个入口语法检查通过。
- `node tools/browser/admin-sso-origin-check.cjs`：使用 Playwright 与本机 Edge 的隔离浏览器；所有 HTTPS 请求均拦截为测试页面，不访问生产或使用真实账户。
- 复现旧 `no-referrer` 与 Nginx 追加 `same-origin` 两种失败；验证同域发起、管理→商户 bootstrap、商户→管理 confirm、管理→商户 consume 的真实浏览器表单来源正确，Referer 不泄露路径/参数。
- 直接执行实际 PHP 来源校验函数，验证缺失/null/外域来源拒绝。
- 公网入口：正常管理域来源 + 故意无效票据，抵达票据格式校验；缺失/null/外域来源仍在来源校验处拒绝。没有创建或消费真实生产票据。
- 公网两个 SSO 入口均仅返回 `strict-origin`；管理入口未认证仍为 401；商户普通登录 200，策略保持 `same-origin`。
- Nginx 配置测试、reload 及 PHP 8.4 FPM graceful reload 通过。
- 尚未完成真实管理员会话下的生产完整登录验收；需从商户列表重新发起，旧交接页不可刷新或重放。

## 部署与回滚

当前运行目录：`/srv/epay/releases/20260925-p5-list-cache-6ad175d`。本次为受限热修复：只更新两个 SSO PHP 文件与 Nginx 两个精确 location；原 release 标识保持不变，不能仅凭 release 标识判断这两个文件的状态。下一次完整发布必须携带本修复代码和入口配置。

代码备份：`/srv/epay/backups/admin-sso-referrer-kdtyz516`（含原始 `admin/sso.php`、`user/sso.php` 与结果清单）。配置备份：`/srv/epay/backups/admin-sso-referrer-nginx-m77_up3o`（含 `nginx-site.before`、`nginx-site.after`）。

若需回滚，先核对当前文件未发生后续更新，再恢复以上备份；PHP 语法检查、`nginx -t` 通过后分别 graceful reload `php8.4-fpm` 与 Nginx。回滚会重新出现跨域来源校验失败，不能作为保持代登录可用的方案。

修复后 PHP 文件 SHA-256：

| 文件 | SHA-256 |
|---|---|
| admin/sso.php | 129f050a24e166c0f3d01968cfb39218faf07bf3bf873312ab6fa15b307d0871 |
| user/sso.php | 3ff4446a395d4d8cd8462dc984f23d89c3b8c9a6fb756a1628b8b06a6c7a67d6 |
