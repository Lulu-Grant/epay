# PHP 8.4 Upgrade Verification Report

Generated: 2026-06-22

Branch: `upgrade/php-84-compatible`

## Scope

This report records the implementation and verification status for the target-mode PHP 8.4 upgrade work. The project target remains PHP `>=7.4 <8.5`.

## Completed Stages

| Stage | Status | Evidence |
| --- | --- | --- |
| Stage 0: repository and baseline freeze | Complete | `docs/php84-baseline-report.md` records branch, remotes, PHP file counts, plugin count, Composer baseline, and known risks. |
| Stage 1: compatibility detection tooling | Complete | Added local scripts under `tools/php84/` and GitHub Actions matrix in `.github/workflows/php84-compat.yml`. |
| Stage 2: Composer and dependency governance | Complete for static/dependency checks | Added PHP version constraint, generated `includes/composer.lock`, upgraded locked dependencies, and verified install/platform checks on PHP 7.4. |
| Stage 3: explicit PHP 8.4 compatibility fixes | Complete for currently detected business deprecated patterns | Replaced business `strftime()` usages and verified blocked deprecated patterns no longer exist in non-vendor PHP files. |

## Composer Changes

`includes/composer.json` now declares:

- Package metadata for the project.
- PHP platform requirement: `>=7.4 <8.5`.
- Composer platform pin: `7.4.33`.
- `lpilp/guomi` upgraded to `^2.0`.

`includes/composer.lock` is now present and should be committed with the upgrade branch.

Locked direct dependencies:

| Package | Locked version | Notes |
| --- | --- | --- |
| `cccyun/alipay-sdk` | `1.10` | Upgraded from the previously installed vendor copy. |
| `cccyun/wechatpay-sdk` | `1.11` | Upgraded from the previously installed vendor copy. |
| `cccyun/qqpay-sdk` | `1.3` | Upgraded from the previously installed vendor copy. |
| `lpilp/guomi` | `2.0.0` | Replaces old dependency chain using `mdanter/ecc` and `fgrosse/phpasn1`. |

Locked transitive dependencies:

| Package | Locked version |
| --- | --- |
| `genkgo/php-asn1` | `2.5.0` |
| `paragonie/ecc` | `2.5.0` |
| `paragonie/random_compat` | `9.99.100` |
| `paragonie/sodium_compat` | `1.24.0` |

## Vendor Strategy

Current strategy: keep `includes/vendor` committed while also committing `includes/composer.lock`.

Reason:

- The upstream project already commits `includes/vendor`.
- The lock file makes dependency resolution reproducible.
- The committed vendor tree keeps existing deployment behavior stable for environments that do not run Composer during deploy.

Policy:

- Do not manually patch `includes/vendor` without a documented reason.
- Prefer upstream package upgrades first.
- If upstream packages still emit PHP 8.4 deprecated notices, record source and risk before considering fork or patch-package style maintenance.

## Verification Commands

### PHP 7.4 Local Checks

Runtime: `/opt/homebrew/opt/php@7.4/bin/php`, version `7.4.33`.

| Command | Result |
| --- | --- |
| `/opt/homebrew/opt/php@7.4/bin/php tools/php84/check-env.php` | Pass. Required and recommended extensions loaded. |
| `PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php sh tools/php84/lint-all.sh` | Pass. Checked 710 PHP files, no syntax errors. |
| `/opt/homebrew/opt/php@7.4/bin/php tools/php84/check-php74-floor.php` | Pass. Checked 371 PHP files for PHP 8+ syntax tokens and common PHP 8+ standard-library calls. |
| `/opt/homebrew/opt/php@7.4/bin/php tools/php84/check-plugin-metadata.php` | Pass. Checked 44 plugin metadata files with P0/P1/P2 classification. Reported 6 compatibility warnings for runtime follow-up. |
| `/opt/homebrew/opt/php@7.4/bin/php tools/php84/check-signatures.php` | Pass. MD5 signing, MD5 verification, RSA signing, RSA verification, wrong-key rejection, and PEM/base64 wrapper checks passed. |
| `/opt/homebrew/opt/php@7.4/bin/php tools/php84/check-rewrite-rules.php` | Pass. Apache, Nginx, and IIS friendly URL rewrite examples preserve `pay`, `api`, and document route rules plus Nginx protected-directory denies. |
| `PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php sh tools/php84/composer-check.sh` | Pass. `composer validate`, `install`, and `check-platform-reqs` all passed. |
| `/opt/homebrew/opt/php@7.4/bin/php -l tools/php84/db-fixture-check.php` | Pass. Database fixture script parses on PHP 7.4. |

PHP 7.4 HTTP smoke check used the built-in server:

```bash
PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8094 sh tools/php84/http-smoke.sh
```

| URL | Result |
| --- | --- |
| `http://127.0.0.1:8094/install/` | HTTP 200, installer environment page loads. |
| `http://127.0.0.1:8094/install/update.php` | HTTP 200, update page loads. |
| `http://127.0.0.1:8094/submit.php` | HTTP 200, returns expected missing merchant configuration message. |
| `http://127.0.0.1:8094/mapi.php` | HTTP 200, `application/json`, returns `{"code":-4, "msg":"未传入任何参数"}`. |

This smoke check does not replace database-backed runtime acceptance.

### Database Fixture Tooling

`tools/php84/db-fixture-check.php` now provides a repeatable database fixture check for PHP 7.4 and PHP 8.4 environments.

The script:

- Creates a temporary database named `epay_php84_*`.
- Imports `install/install.sql` using the configured table prefix.
- Verifies the core tables required by the acceptance document: `config`, `order`, `user`, `channel`, `settle`, `transfer`, `refundorder`, and `record`.
- Verifies the installed `version` value against `DB_VERSION`.
- Performs basic create, update, and read checks for user, channel, order, refund order, balance record, settle, and transfer records.
- Drops the temporary database unless `EPAY_DB_KEEP=1` is set.

Required environment variables:

```bash
EPAY_DB_HOST=127.0.0.1
EPAY_DB_PORT=3306
EPAY_DB_USER=root
EPAY_DB_PASSWORD=...
php tools/php84/db-fixture-check.php
```

Local result on this machine:

| Command | Result |
| --- | --- |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= /opt/homebrew/opt/php@7.4/bin/php tools/php84/db-fixture-check.php` | Pass. Created and dropped a temporary database, imported 123 install SQL statements, verified core tables and CRUD for user, channel, order, refund order, balance record, settle, and transfer. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= php tools/php84/db-fixture-check.php` | Pass as an additional PHP 8.5 smoke check. |

### Rollback Rehearsal Tooling

`tools/php84/rollback-rehearsal-check.php` provides a temporary database rollback rehearsal. It creates a fresh `epay_php84_rollback_*` database, imports `install/install.sql`, writes a rollback marker plus user/channel/order fixtures, creates a logical SQL backup, intentionally mutates the database into a broken post-upgrade state, restores the backup, and verifies the marker, user balance, order status, gateway trade number, channel row, and `DB_VERSION` are back at the backup point.

`tools/php84/apache-fpm-rollback-rehearsal.sh` provides a local web-runtime rollback rehearsal. It runs the Apache/PHP-FPM smoke on a disposable installed app through PHP 8.4, stops that temporary stack, then starts the same Apache port again through PHP 7.4 and reruns the core route checks. This proves the documented PHP-FPM runtime switch path can be exercised locally without touching the real project `config.php` or `install/install.lock`.

`tools/php84/nginx-fpm-rollback-rehearsal.sh` provides the same runtime switch rehearsal for Nginx/PHP-FPM. It runs the Nginx/PHP-FPM smoke through PHP 8.4, cleans up the temporary stack, then starts the same Nginx port again through PHP 7.4 and reruns the same route and protected-directory checks. The remote validation run used the already installed PHP 8.4 and PHP 7.4 package binaries on the Debian 12 validation host.

These tools prove executable database backup/restore, local Apache/PHP-FPM runtime switch rehearsal, and remote Nginx/PHP-FPM runtime switch rehearsal against disposable data. They do not replace production deployment-system rollback or restoration of a real production backup artifact.

Local and remote result:

| Command | Result |
| --- | --- |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= /opt/homebrew/opt/php@7.4/bin/php tools/php84/rollback-rehearsal-check.php` | Pass. Temporary database created, backup file generated, broken state simulated, backup restored, and fixture data verified. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= /opt/homebrew/opt/php@8.4/bin/php tools/php84/rollback-rehearsal-check.php` | Pass with the same coverage on PHP 8.4. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= EPAY_ROLLBACK_APACHE_PORT=8631 EPAY_ROLLBACK_FPM_PORT=9631 sh tools/php84/apache-fpm-rollback-rehearsal.sh` | Pass. Apache served the same external port first through PHP-FPM 8.4 and then, after cleanup and restart, through PHP-FPM 7.4; both phases passed `/`, `/api.php`, `/mapi.php`, `/submit.php`, `/cron.php?key=fixture-cron-key`, `/api/unknown`, and `/pay/submit/EPAY_DOES_NOT_EXIST/` checks. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=root EPAY_DB_PASSWORD= PHP84_BIN=/usr/bin/php8.4 PHP84_FPM_BIN=/usr/sbin/php-fpm8.4 PHP74_BIN=/usr/bin/php7.4 PHP74_FPM_BIN=/usr/sbin/php-fpm7.4 NGINX_BIN=/usr/sbin/nginx EPAY_ROLLBACK_NGINX_PORT=18240 EPAY_ROLLBACK_FPM_PORT=19240 EPAY_ROLLBACK_NGINX_REPEAT_COUNT=3 sh tools/php84/nginx-fpm-rollback-rehearsal.sh` | Pass on Debian 12 remote validation host. Versioned CLI/FPM paths were confirmed before the run. Nginx served the same external port first through PHP-FPM 8.4.22 and then, after cleanup and restart, through PHP-FPM 7.4.33. Both phases passed three repeated rounds of core route checks plus protected-directory denies, and no temporary database/user, Nginx/FPM process, or smoke port remained after cleanup. |

### Installed HTTP Smoke Checks

`tools/php84/installed-http-smoke.sh` creates a temporary installed copy of the application, creates a temporary database with `tools/php84/db-fixture-check.php`, creates a scoped temporary database user with a non-empty password, writes a temporary `config.php`, creates `install/install.lock`, starts the PHP built-in server with a temporary rewrite router, and verifies installed-state entry, authentication, friendly `/api/...` and `/pay/...` routes, and local `epay`/`epayn` payment behavior.

For automated admin login checks, the temporary copy normally removes `admin/code.php` so the existing application fallback disables image-code verification for the main credential, cookie, and protected-page checks. The script now keeps a private smoke backup of `admin/code.php`, briefly restores it before the normal login checks, verifies PNG captcha generation plus wrong-code rejection with correct admin credentials and no `admin_token` issue, then removes it again before continuing the broader authenticated workflow. This still does not replace a full browser-level manual captcha acceptance pass.

Set `EPAY_BROWSER_SMOKE=1` to add a temporary Playwright Chromium pass inside the installed smoke. The browser pass installs Playwright only in the disposable smoke work directory, uses a normal desktop Chrome user agent so the existing `txprotect` HeadlessChrome guard does not turn the smoke into a false 404, and verifies the home page, admin login page, admin login cookie/dashboard, user login page CSRF, user password login cookie/dashboard. This adds browser-cookie and DOM-level evidence, but it still does not replace a full manual browser acceptance pass for captcha, installer UX, and real deployment traffic.

Local result on this machine:

| Command | Result |
| --- | --- |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8097 sh tools/php84/installed-http-smoke.sh` | Pass. Installed endpoints, authentication, admin/user order-settle-transfer pages, old merchant API queries, signed refund query/duplicate refund API, signed transfer balance/query API, admin manual fill-order, local payment callbacks, P0 submit-runtime checks, and seven P1 submit-runtime checks passed. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=php EPAY_SMOKE_PORT=8098 sh tools/php84/installed-http-smoke.sh` | Pass as an additional PHP 8.5 smoke check. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8103 sh tools/php84/installed-http-smoke.sh` | Pass after adding admin/user logout checks. Installed endpoints, authentication, logout, protected-page re-intercept, admin/user order-settle-transfer pages, old merchant API queries, signed refund/transfer APIs, admin manual fill-order, local payment callbacks, P0 submit-runtime checks, seven P1 submit-runtime checks, and local performance samples passed as a script run. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8104 sh tools/php84/installed-http-smoke.sh` | Pass after adding admin/user logout checks on the PHP 7.4 compatibility floor. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8107 sh tools/php84/installed-http-smoke.sh` | Pass after adding friendly URL rewrite-router coverage. Installed endpoints, authentication/logout, friendly `/api/...` refund/transfer routes, friendly `/pay/notify/...`, `/pay/return/...`, and `/pay/submit/...` payment routes, P0/P1 submit-runtime checks, P0 bad-signature rejection checks, and local performance samples passed. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8108 sh tools/php84/installed-http-smoke.sh` | Pass with the same friendly URL rewrite-router coverage on the PHP 7.4 compatibility floor. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8109 sh tools/php84/installed-http-smoke.sh` | Pass after adding admin settlement action coverage. Single settlement completion, settlement batch creation, settlement batch completion, existing payment/API/plugin paths, and local performance samples passed. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8110 sh tools/php84/installed-http-smoke.sh` | Pass with the same admin settlement action coverage on the PHP 7.4 compatibility floor. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8111 sh tools/php84/installed-http-smoke.sh` | Pass after adding admin transfer action coverage. Transfer result read, manual transfer status update, duplicate transfer refund idempotency, existing payment/API/plugin paths, and local performance samples passed. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8112 sh tools/php84/installed-http-smoke.sh` | Pass with the same admin transfer action coverage on the PHP 7.4 compatibility floor. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8113 sh tools/php84/installed-http-smoke.sh` | Pass after adding unsupported-refund plugin coverage and null channel-config compatibility coverage. Unsupported API refund is rejected without changing order, refund, or merchant balance state; existing payment/API/plugin/admin paths and local performance samples passed. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8114 sh tools/php84/installed-http-smoke.sh` | Pass with the same unsupported-refund plugin and null channel-config coverage on the PHP 7.4 compatibility floor. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8115 sh tools/php84/installed-http-smoke.sh` | Pass after adding payment order creation negative-path coverage. Missing merchant, bad signature, invalid amount, duplicate same-parameter order reuse, and duplicate changed-parameter rejection passed with no unintended order creation. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8116 sh tools/php84/installed-http-smoke.sh` | Pass with the same payment order creation negative-path coverage on the PHP 7.4 compatibility floor. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8119 sh tools/php84/installed-http-smoke.sh` | Pass after adding unauthenticated admin-sensitive AJAX guard coverage. Manual fill-order, settlement batch creation, and transfer result AJAX endpoints redirect unauthenticated requests before business handling. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8120 sh tools/php84/installed-http-smoke.sh` | Pass with the same unauthenticated admin-sensitive AJAX guard coverage on the PHP 7.4 compatibility floor. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8121 sh tools/php84/installed-http-smoke.sh` | Pass after adding user-center ownership guard coverage. A second authenticated merchant cannot read or refresh merchant `1000` order, settlement, or transfer detail/list paths through `user/ajax2.php`. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8122 sh tools/php84/installed-http-smoke.sh` | Pass with the same user-center ownership guard coverage on the PHP 7.4 compatibility floor. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8123 sh tools/php84/installed-http-smoke.sh` | Pass after adding payment response-shape coverage. Temporary installed smoke plugin verifies `mapi.php` `html`, `qrcode`, and `urlscheme` payloads plus `/api/pay/create` JSON wrapper with `jsapi` payload. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8124 sh tools/php84/installed-http-smoke.sh` | Pass with the same payment response-shape coverage on the PHP 7.4 compatibility floor. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8125 sh tools/php84/installed-http-smoke.sh` | Pass after adding user-center refund query and submit coverage. Temporary smoke-only refundable plugin verifies `user/ajax2.php?act=refund_query`, wrong-password rejection, successful refund, order refund state, refund-order creation, and merchant balance deduction. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8126 sh tools/php84/installed-http-smoke.sh` | Pass with the same user-center refund coverage on the PHP 7.4 compatibility floor. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8127 sh tools/php84/installed-http-smoke.sh` | Pass after adding signed transfer submit and transfer proof coverage. Temporary smoke-only transfer plugin verifies `/api/transfer/submit` creates a successful transfer row, writes gateway order data, deducts merchant balance, and `/api/transfer/proof` returns the expected proof URL. |
| `EPAY_DB_USER=apple EPAY_DB_PASSWORD= EPAY_DB_SOCKET=/tmp/mysql.sock PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8129 sh tools/php84/installed-http-smoke.sh` | Pass with the same signed transfer submit/proof coverage on the PHP 7.4 compatibility floor. The socket setting is required on this workstation because the local MySQL `apple` account authenticates over unix socket rather than TCP. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8130 sh tools/php84/installed-http-smoke.sh` | Pass after adding early database-bootstrap failure handling to the installed smoke script. The success path still covers installed endpoints, signed transfer submit/proof, refund, settlement, payment callbacks, and P0/P1 submit-runtime checks. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8131 sh tools/php84/installed-http-smoke.sh` | Pass after adding `qqpay` synchronous-return unsupported-path coverage. `/pay/return/{trade_no}/` for a `qqpay` order renders the controlled `插件方法不存在:return` error page without PHP fatal output and leaves the order unpaid with no income record. |
| `EPAY_DB_USER=apple EPAY_DB_PASSWORD= EPAY_DB_SOCKET=/tmp/mysql.sock PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8132 sh tools/php84/installed-http-smoke.sh` | Pass with the same `qqpay` unsupported synchronous-return coverage on the PHP 7.4 compatibility floor. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8133 sh tools/php84/installed-http-smoke.sh` | Pass after adding P1 Swiftpass-family bad-signature notify coverage. `unionpay`, `swiftpass`, and `swiftpass2` reject invalid notify payloads without PHP error output, order status changes, or income records. |
| `EPAY_DB_USER=apple EPAY_DB_PASSWORD= EPAY_DB_SOCKET=/tmp/mysql.sock PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8134 sh tools/php84/installed-http-smoke.sh` | Pass with the same P1 Swiftpass-family bad-signature notify coverage on the PHP 7.4 compatibility floor. |
| `EPAY_DB_USER=apple EPAY_DB_PASSWORD= EPAY_DB_SOCKET=/tmp/mysql.sock PERF_SAMPLE_COUNT=2 EPAY_PERF_PORT_BASE=8260 sh tools/php84/performance-repeat-sample.sh` | Pass. Tool validation run collected two installed-smoke performance samples per runtime and produced PHP 7.4/PHP 8.4 median comparisons. |
| `EPAY_DB_USER=apple EPAY_DB_PASSWORD= EPAY_DB_SOCKET=/tmp/mysql.sock EPAY_PERF_PORT_BASE=8400 sh tools/php84/performance-repeat-sample.sh` | Pass. Repeated local performance sampling collected three complete installed-smoke samples per runtime and produced median comparisons for home, authenticated admin/user home, signed order creation, local payment notify, and cron. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8601 sh tools/php84/installed-http-smoke.sh` | Pass after adding P1 `kuaiqian` missing-signature notify coverage. A forged success callback without `signMsg` is rejected without PHP error output, order status changes, or income records. |
| `EPAY_DB_USER=apple EPAY_DB_PASSWORD= EPAY_DB_SOCKET=/tmp/mysql.sock PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8602 sh tools/php84/installed-http-smoke.sh` | Pass with the same P1 `kuaiqian` missing-signature notify coverage on the PHP 7.4 compatibility floor. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8603 sh tools/php84/installed-http-smoke.sh` | Pass after adding temporary smoke-only PFX certificate fixtures and P1 `ysepay`/`sandpay` bad-signature notify coverage. Both callbacks reject forged success payloads without PHP error output, order status changes, or income records. |
| `EPAY_DB_USER=apple EPAY_DB_PASSWORD= EPAY_DB_SOCKET=/tmp/mysql.sock PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8604 sh tools/php84/installed-http-smoke.sh` | Pass with the same P1 `ysepay`/`sandpay` bad-signature notify coverage and smoke-only PFX certificate fixtures on the PHP 7.4 compatibility floor. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8605 sh tools/php84/installed-http-smoke.sh` | Pass after adding smoke-signed P1 `ysepay`/`sandpay` success notify coverage. Both callbacks update unpaid orders, store gateway trade/buyer data, credit the merchant once, and reject duplicate income on replay. |
| `EPAY_DB_USER=apple EPAY_DB_PASSWORD= EPAY_DB_SOCKET=/tmp/mysql.sock PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8606 sh tools/php84/installed-http-smoke.sh` | Pass with the same P1 `ysepay`/`sandpay` success notify and duplicate notify idempotency coverage on the PHP 7.4 compatibility floor. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8607 sh tools/php84/installed-http-smoke.sh` | Pass after adding P1 `ysepay` smoke-signed synchronous return coverage. The return path updates an unpaid order and merchant balance without PHP error output. |
| `EPAY_DB_USER=apple EPAY_DB_PASSWORD= EPAY_DB_SOCKET=/tmp/mysql.sock PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8608 sh tools/php84/installed-http-smoke.sh` | Pass with the same P1 `ysepay` synchronous return coverage on the PHP 7.4 compatibility floor. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8648 sh tools/php84/installed-http-smoke.sh` | Pass after adding official `alipay` local success notify, duplicate notify idempotency, and synchronous return state-machine coverage. The smoke-only endpoints use the upstream Alipay SDK local RSA2 verification and then exercise the real `processNotify()`/`processReturn()` state updates without calling the live Alipay query API. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8649 sh tools/php84/installed-http-smoke.sh` | Pass with the same official `alipay` local success notify, duplicate notify idempotency, and synchronous return state-machine coverage on the PHP 7.4 compatibility floor. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8650 sh tools/php84/installed-http-smoke.sh` | Pass after adding admin captcha smoke coverage. The script restores `admin/code.php` only in the temporary copy, verifies PNG generation and session-cookie creation, confirms correct admin credentials with a wrong captcha are rejected before `admin_token` issuance, then removes `admin/code.php` before the existing no-captcha fallback login workflow. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8651 sh tools/php84/installed-http-smoke.sh` | Pass with the same admin captcha image and wrong-code rejection coverage on the PHP 7.4 compatibility floor. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8652 EPAY_BROWSER_SMOKE=1 sh tools/php84/installed-http-smoke.sh` | Pass after adding optional browser-level installed smoke coverage. Playwright Chromium verified the home page, admin login page, admin login cookie/dashboard, user login page CSRF, and user password login cookie/dashboard, then the full installed HTTP smoke suite passed on PHP 8.4. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8653 EPAY_BROWSER_SMOKE=1 sh tools/php84/installed-http-smoke.sh` | Pass with the same browser-level installed smoke coverage and full installed HTTP smoke suite on the PHP 7.4 compatibility floor. |

### Apache/PHP-FPM Smoke Checks

`tools/php84/apache-fpm-smoke.sh` creates a temporary installed application copy, temporary database, scoped temporary database user with a non-empty password, and isolated Apache plus PHP-FPM configuration. It loads Apache `mod_rewrite`, `mod_proxy`, and `mod_proxy_fcgi`, forwards PHP requests to PHP-FPM, applies the project friendly route rules for `/api/...`, `/pay/...`, and document pages, and verifies the core installed HTTP entries without touching the real project `config.php` or real `install/install.lock`. Set `EPAY_APACHE_REPEAT_COUNT` to repeat the core route set under the same temporary Apache/PHP-FPM stack and scan PHP, Apache, and PHP-FPM logs for fatal/warning/deprecated output or worker failure signals.

This is a functional production-like runtime smoke for Apache/PHP-FPM plus a configurable local stability sample. It does not replace Nginx/IIS runtime checks, manual browser acceptance, long-running production worker monitoring, or production performance sampling.

Local result on this machine:

| Command | Result |
| --- | --- |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php PHP_FPM_BIN=/opt/homebrew/opt/php@8.4/sbin/php-fpm EPAY_APACHE_SMOKE_PORT=8621 EPAY_FPM_SMOKE_PORT=9621 sh tools/php84/apache-fpm-smoke.sh` | Pass. Apache 2.4 with PHP-FPM 8.4 served `/`, `/api.php`, `/mapi.php`, `/submit.php`, `/cron.php?key=fixture-cron-key`, `/api/unknown`, and `/pay/submit/EPAY_DOES_NOT_EXIST/` without PHP error output. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php PHP_FPM_BIN=/opt/homebrew/opt/php@7.4/sbin/php-fpm EPAY_APACHE_SMOKE_PORT=8622 EPAY_FPM_SMOKE_PORT=9622 sh tools/php84/apache-fpm-smoke.sh` | Pass with the same Apache/PHP-FPM route and entry coverage on the PHP 7.4 compatibility floor. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php PHP_FPM_BIN=/opt/homebrew/opt/php@8.4/sbin/php-fpm EPAY_APACHE_SMOKE_PORT=8623 EPAY_FPM_SMOKE_PORT=9623 EPAY_APACHE_REPEAT_COUNT=8 sh tools/php84/apache-fpm-smoke.sh` | Pass. Apache/PHP-FPM 8.4 served the core route set eight repeated times under one temporary stack, stayed alive, and PHP/Apache/PHP-FPM logs were clean. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php PHP_FPM_BIN=/opt/homebrew/opt/php@7.4/sbin/php-fpm EPAY_APACHE_SMOKE_PORT=8624 EPAY_FPM_SMOKE_PORT=9624 EPAY_APACHE_REPEAT_COUNT=8 sh tools/php84/apache-fpm-smoke.sh` | Pass with the same repeated Apache/PHP-FPM stability sample on the PHP 7.4 compatibility floor. |

### Nginx/PHP-FPM Smoke Checks

`tools/php84/nginx-fpm-smoke.sh` provides the same disposable installed-copy and PHP-FPM model for Nginx. It writes an isolated Nginx configuration that mirrors the project `nginx.txt` friendly route rules, forwards PHP requests to PHP-FPM with explicit FastCGI parameters, verifies the same core installed entries, emits `[PERF]` response-time lines for each checked route, and checks Nginx protected-directory denies for `/includes` and `/plugins`. Set `EPAY_NGINX_REPEAT_COUNT` to repeat the core route set under the same temporary Nginx/PHP-FPM stack and scan PHP, PHP-FPM, and Nginx logs for fatal/warning/deprecated output or worker failure signals. Set `EPAY_NGINX_BROWSER_SMOKE=1` to install Playwright in the disposable smoke directory and verify the real Nginx/PHP-FPM entry with Chromium: home page, admin login page, admin login cookie/dashboard, user login page CSRF, user password login cookie/dashboard. On Linux runners where the script starts PHP-FPM as root, it writes an explicit temporary pool `user`/`group` using `www-data` or `EPAY_FPM_USER`/`EPAY_FPM_GROUP`, and gives only the disposable smoke directory/log the access needed for the worker.

This workstation does not currently have an `nginx` binary, so local Nginx runtime evidence is skipped here. Runtime evidence was collected on the isolated Debian 12 validation host provided for this work after installing standard package-managed Nginx, MariaDB, PHP 8.4, and PHP 7.4 runtimes. The smoke uses temporary databases, temporary database users, temporary Nginx/PHP-FPM configs, and non-default local ports; no `epay_php84%` database/user or smoke Nginx/FPM process remained after the run.

Local and remote result:

| Command | Result |
| --- | --- |
| `sh -n tools/php84/nginx-fpm-smoke.sh` | Pass. Shell syntax is valid. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php PHP_FPM_BIN=/opt/homebrew/opt/php@8.4/sbin/php-fpm sh tools/php84/nginx-fpm-smoke.sh` | Not run: exited `77` with `nginx binary not found: nginx`. No temporary Nginx smoke database or database user remained. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=root EPAY_DB_PASSWORD= PHP_BIN=php8.4 PHP_FPM_BIN=/usr/sbin/php-fpm8.4 NGINX_BIN=/usr/sbin/nginx EPAY_NGINX_SMOKE_PORT=18084 EPAY_FPM_SMOKE_PORT=19084 sh tools/php84/nginx-fpm-smoke.sh` | Pass on Debian 12 remote validation host. Nginx 1.22.1 with PHP-FPM 8.4.22 served `/`, `/api.php`, `/mapi.php`, `/submit.php`, `/cron.php?key=fixture-cron-key`, `/api/unknown`, and `/pay/submit/EPAY_DOES_NOT_EXIST/`; `/includes` and `/plugins` returned 403. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=root EPAY_DB_PASSWORD= PHP_BIN=php7.4 PHP_FPM_BIN=/usr/sbin/php-fpm7.4 NGINX_BIN=/usr/sbin/nginx EPAY_NGINX_SMOKE_PORT=18074 EPAY_FPM_SMOKE_PORT=19074 sh tools/php84/nginx-fpm-smoke.sh` | Pass on the same Debian 12 remote validation host with PHP-FPM 7.4.33 and the same Nginx route/protected-directory coverage. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=root EPAY_DB_PASSWORD= PHP_BIN=php8.4 PHP_FPM_BIN=/usr/sbin/php-fpm8.4 NGINX_BIN=/usr/sbin/nginx EPAY_NGINX_SMOKE_PORT=18184 EPAY_FPM_SMOKE_PORT=19184 EPAY_NGINX_REPEAT_COUNT=8 sh tools/php84/nginx-fpm-smoke.sh` | Pass on Debian 12 remote validation host. The same Nginx/PHP-FPM 8.4 stack served eight repeated rounds of `/`, `/api.php`, `/mapi.php`, `/cron.php?key=fixture-cron-key`, and `/pay/submit/EPAY_DOES_NOT_EXIST/`; PHP, PHP-FPM, and Nginx runtime logs were clean. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=root EPAY_DB_PASSWORD= PHP_BIN=php7.4 PHP_FPM_BIN=/usr/sbin/php-fpm7.4 NGINX_BIN=/usr/sbin/nginx EPAY_NGINX_SMOKE_PORT=18174 EPAY_FPM_SMOKE_PORT=19174 EPAY_NGINX_REPEAT_COUNT=8 sh tools/php84/nginx-fpm-smoke.sh` | Pass on the same Debian 12 remote validation host with PHP-FPM 7.4.33. Eight repeated core-route rounds completed and PHP, PHP-FPM, and Nginx runtime logs were clean. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=root EPAY_DB_PASSWORD= PHP_BIN=/usr/bin/php8.4 PHP_FPM_BIN=/usr/sbin/php-fpm8.4 NGINX_BIN=/usr/sbin/nginx EPAY_NGINX_SMOKE_PORT=18384 EPAY_FPM_SMOKE_PORT=19384 EPAY_NGINX_BROWSER_SMOKE=1 EPAY_NGINX_REPEAT_COUNT=2 sh tools/php84/nginx-fpm-smoke.sh` | Pass on Debian 12 remote validation host after installing Node/npm and Playwright Chromium system dependencies for validation. Chromium verified the real Nginx/PHP-FPM 8.4 entry for home, admin login/dashboard cookie flow, and user password login/dashboard cookie flow; two repeated core-route rounds completed and runtime logs were clean. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=root EPAY_DB_PASSWORD= PHP_BIN=/usr/bin/php7.4 PHP_FPM_BIN=/usr/sbin/php-fpm7.4 NGINX_BIN=/usr/sbin/nginx EPAY_NGINX_SMOKE_PORT=18374 EPAY_FPM_SMOKE_PORT=19374 EPAY_NGINX_BROWSER_SMOKE=1 EPAY_NGINX_REPEAT_COUNT=2 sh tools/php84/nginx-fpm-smoke.sh` | Pass on the same Debian 12 remote validation host. Chromium verified the real Nginx/PHP-FPM 7.4 compatibility-floor entry for home, admin login/dashboard cookie flow, and user password login/dashboard cookie flow; two repeated core-route rounds completed and runtime logs were clean. No temporary database/user, Nginx/FPM process, or smoke port remained after the browser-smoke runs. |

Installed-state endpoints covered:

| URL | Result |
| --- | --- |
| `/` | HTTP 200, no PHP error output. |
| `/admin/login.php` | HTTP 200, admin login page loads. |
| `/admin/` | HTTP 200, protected page redirects unauthenticated users to login. |
| `/user/login.php` | HTTP 200, user login page loads. |
| `/user/` | HTTP 200, protected page redirects unauthenticated users to login. |
| `/api.php` | HTTP 200, JSON missing-action response. |
| `/submit.php` | HTTP 200, expected missing merchant configuration message. |
| `/mapi.php` | HTTP 200, JSON missing-params response. |
| `/cron.php` | HTTP 200, expected missing cron key response. |

Authentication paths covered:

| Flow | Result |
| --- | --- |
| Admin wrong password | JSON failure response. |
| Admin captcha image | Temporary-copy `admin/code.php` returns a valid PNG captcha and creates a session cookie. |
| Admin wrong captcha | Correct admin credentials with an incorrect captcha are rejected before `admin_token` is issued. |
| Admin correct password | JSON success response and `admin_token` cookie allows protected dashboard access. |
| Admin logout | `admin_token` is cleared and a subsequent protected admin page request redirects to login. |
| User wrong key | JSON failure response. |
| User correct key | JSON success response and `user_token` cookie allows protected user center access. |
| User logout | `user_token` is cleared and a subsequent protected user page request redirects to login. |

### Install and Upgrade Smoke Checks

`tools/php84/install-upgrade-smoke.sh` creates a temporary application copy, temporary databases, and a scoped temporary database user with a non-empty password. It verifies the web installer and updater without writing to the real project `config.php` or real `install/install.lock`. By default it uses PHP's built-in server. Set `EPAY_INSTALL_SERVER_MODE=nginx-fpm` with `PHP_FPM_BIN` and `NGINX_BIN` to run the same install and upgrade flow through isolated Nginx plus PHP-FPM configs.

The script covers:

- `/install/` environment page loading without PHP error output.
- Database connection failure messaging for invalid credentials.
- Successful installer database configuration save.
- `install.sql` table creation through `/install/?step=4`.
- `install/install.lock` creation after successful install.
- Runtime blocking when `install/install.lock` is missing after a configured install.
- Default administrator login after a fresh install, with `admin/code.php` removed only in the temporary copy to keep the check focused on credential and cookie behavior.
- Locked installer behavior after install completion.
- Low-version database upgrade through `/install/update.php`.
- Upgrade repeat guard after reaching the current version.
- Preservation of sample user, channel, and order data across the upgrade fixture.

Local result on this machine:

| Command | Result |
| --- | --- |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_INSTALL_SMOKE_PORT=8101 sh tools/php84/install-upgrade-smoke.sh` | Pass. Fresh install, bad database credentials, lock creation, default admin login, low-version upgrade, data preservation, and repeat-upgrade guard passed. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_INSTALL_SMOKE_PORT=8102 sh tools/php84/install-upgrade-smoke.sh` | Pass with the same coverage on the PHP 7.4 compatibility floor. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_INSTALL_SMOKE_PORT=8117 sh tools/php84/install-upgrade-smoke.sh` | Pass after adding missing `install.lock` runtime blocking coverage. Fresh install, bad database credentials, lock creation, runtime block when lock is removed, default admin login, low-version upgrade, data preservation, and repeat-upgrade guard passed. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_INSTALL_SMOKE_PORT=8118 sh tools/php84/install-upgrade-smoke.sh` | Pass with the same missing `install.lock` runtime blocking coverage on the PHP 7.4 compatibility floor. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=root EPAY_DB_PASSWORD= PHP_BIN=/usr/bin/php8.4 PHP_FPM_BIN=/usr/sbin/php-fpm8.4 NGINX_BIN=/usr/sbin/nginx EPAY_INSTALL_SERVER_MODE=nginx-fpm EPAY_INSTALL_SMOKE_PORT=18484 EPAY_INSTALL_FPM_PORT=19484 sh tools/php84/install-upgrade-smoke.sh` | Pass on Debian 12 remote validation host. Nginx/PHP-FPM 8.4 verified fresh installer page, invalid database credentials, writable config save through the web installer, table creation, `install.lock` creation, default admin login, missing-lock runtime blocking, low-version upgrade, data preservation, and repeat-upgrade guard. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=root EPAY_DB_PASSWORD= PHP_BIN=/usr/bin/php7.4 PHP_FPM_BIN=/usr/sbin/php-fpm7.4 NGINX_BIN=/usr/sbin/nginx EPAY_INSTALL_SERVER_MODE=nginx-fpm EPAY_INSTALL_SMOKE_PORT=18474 EPAY_INSTALL_FPM_PORT=19474 sh tools/php84/install-upgrade-smoke.sh` | Pass on the same Debian 12 remote validation host with PHP-FPM 7.4.33 and the same install/upgrade coverage. No temporary database/user, Nginx/FPM process, or smoke port remained after the install-upgrade runs. |

Authenticated management pages covered:

| Area | Result |
| --- | --- |
| Admin order list | Authenticated `/admin/order.php` loads without PHP error output. |
| Admin settlement batch page | Authenticated `/admin/settle.php` loads without PHP error output. |
| Admin transfer list | Authenticated `/admin/transfer.php` loads without PHP error output. |
| User order list | Authenticated `/user/order.php` loads without PHP error output. |
| User settlement list | Authenticated `/user/settle.php` loads without PHP error output. |
| User transfer list | Authenticated `/user/transfer.php` loads without PHP error output. |

Payment order creation paths covered:

| Flow | Result |
| --- | --- |
| Missing merchant | Signed `mapi.php` request for a nonexistent merchant returns JSON failure and does not proceed to order creation. |
| Bad merchant signature | `mapi.php` request signed with the wrong merchant key returns `code=-3`, reports signature verification failure, and creates no order row. |
| Invalid amount | Signed `mapi.php` request with non-numeric amount returns JSON failure and creates no order row. |
| Duplicate merchant order number, same parameters | Repeated signed `mapi.php` request with the same unpaid `out_trade_no` and identical parameters reuses the existing `trade_no` and keeps exactly one order row. |
| Duplicate merchant order number, changed parameters | Repeated signed `mapi.php` request with the same unpaid `out_trade_no` but changed amount returns the existing parameter-change rejection and creates no second order row. |

Merchant API paths covered:

| Flow | Result |
| --- | --- |
| Old `api.php?act=query` | Merchant account query returns `code=1`. |
| Old `api.php?act=settle` | Settlement list query returns `code=1` against the database fixture. |
| Old `api.php?act=order` | Refunded fixture order query returns `code=1` and `status=2`. |
| Old `api.php?act=orders` | Merchant order list query returns `code=1`. |
| New `/api/pay/refundquery` | Signed MD5 POST returns `code=0` and `status=1` for the existing refund fixture. |
| New `/api/pay/refund` duplicate path | Signed MD5 POST with an existing `out_refund_no` returns the existing successful refund order without calling an external gateway refund. |
| New `/api/pay/refund` unsupported plugin path | Signed MD5 POST against an order using a plugin without `refund()` returns `code=-1` with the unsupported API refund error, and leaves order status/refund amount, refund-order count, and merchant balance unchanged. |
| New `/api/transfer/balance` | Signed MD5 POST returns `code=0` for the enabled transfer fixture merchant. |
| New `/api/transfer/query` | Signed MD5 POST returns `code=0` and `status=1` for the existing successful transfer fixture. |

Security regression paths covered:

| Flow | Result |
| --- | --- |
| Cross-merchant order access | A second fixture merchant with a valid key cannot read merchant `1000`'s order through old `api.php?act=order`; the API returns failure instead of order data. |
| Cross-merchant refund access | The second fixture merchant cannot read merchant `1000`'s refund order through signed `/api/pay/refundquery`; the API returns failure instead of refund data. |
| Cross-merchant transfer access | The second fixture merchant cannot read merchant `1000`'s transfer order through signed `/api/transfer/query`; the API returns failure instead of transfer data. |
| User-center cross-merchant data access | A second authenticated user cannot read merchant `1000` order detail, settlement result, transfer result, transfer status refresh, transfer proof, or filtered order/settlement/transfer lists through `user/ajax2.php`. |
| Bad payment callback signature | Local `epay` and `epayn` callback tests confirm bad signatures do not update unpaid orders. Official P0 `alipay`, `wxpay`, and `qqpay` bad-signature notify tests also reject the callback without changing order state or creating income records. |
| Bad synchronous return signature | Local `epay` and official P0 `alipay` bad-signature return tests reject the return path without changing order state or creating income records. |
| Protected admin/user pages | Unauthenticated admin and user center access is redirected to login, while authenticated sessions can access protected pages. |
| Admin sensitive AJAX login guards | Unauthenticated `admin/ajax_order.php?act=fillorder`, `admin/ajax_settle.php?act=create_batch`, and `admin/ajax_transfer.php?act=transfer_result` requests are redirected to admin login before business handling. |
| Missing install lock | After a temporary configured install, removing `install/install.lock` makes the runtime homepage stop with the expected install-lock security warning before normal application handling. |
| Download endpoint login guards | Unauthenticated `admin/download.php` and `user/download.php` access is redirected to login. |
| Download endpoint invalid action | Authenticated invalid `act` values return `No Act` instead of downloading local files. |
| Download endpoint static safety | `tools/php84/check-download-safety.php` verifies download endpoints keep login guards and do not use blocked local file output primitives such as `readfile()`, `fopen()`, or `fpassthru()`. |

Admin order operation paths covered:

| Flow | Result |
| --- | --- |
| Manual fill-order setup | Signed `mapi.php` request creates an unpaid order through the deterministic local `epay` channel. |
| `admin/ajax_order.php?act=fillorder` | Authenticated admin POST with same-origin referer returns `code=0`. |
| Manual fill-order state | Order becomes paid, `endtime` and `date` are set, merchant balance increases by `getmoney`, and exactly one income record is created. |

Admin settlement operation paths covered:

| Flow | Result |
| --- | --- |
| Settlement status fixture | Temporary installed smoke creates two pending settlement records for merchant `1000`. |
| `admin/ajax_settle.php?act=setSettleStatus` | Authenticated admin request with same-origin referer returns `code=200`; the target settlement row becomes complete and `endtime` is set. |
| `admin/ajax_settle.php?act=create_batch` | Authenticated admin request moves the remaining pending settlement row into a generated batch, inserts the `batch` summary row, and sets settlement status to processing. |
| `admin/ajax_settle.php?act=complete_batch` | Authenticated admin POST marks the batch settlement row complete. |

Admin transfer operation paths covered:

| Flow | Result |
| --- | --- |
| Transfer action fixture | Temporary installed smoke creates pending transfer rows with deterministic cost money and stored result text for merchant `1000`. |
| `admin/ajax_transfer.php?act=transfer_result` | Authenticated admin request with same-origin referer returns the stored transfer result. |
| `admin/ajax_transfer.php?act=setTransferStatus` | Authenticated admin POST returns `code=0` and updates the transfer row status. |
| `admin/ajax_transfer.php?act=refundTransfer` | Authenticated admin POST marks the transfer as failed and returns `costmoney` to merchant balance exactly once. |
| Duplicate `refundTransfer` | A second authenticated refund request returns success but does not add merchant balance a second time. |

Local `epay` payment paths covered:

| Flow | Result |
| --- | --- |
| `mapi.php` merchant order creation | Signed MD5 request creates an unpaid order and returns the existing API contract: `code=1`, `trade_no`, and `payurl`. |
| Payment response-shape mappings | A temporary smoke-only plugin in the installed copy verifies `mapi.php` maps plugin `html`, `qrcode`, and `scheme` responses to `html`, `qrcode`, and `urlscheme` JSON fields, and `/api/pay/create` maps a `jsapi` response to a valid JSON `pay_info` wrapper. |
| User-center refund flow | A temporary smoke-only refundable plugin and paid order fixture verify user-center `refund_query`, wrong login-password rejection, successful `refund_submit`, order status/refund amount, refund-order creation, and merchant balance deduction. |
| Signed transfer submit/proof flow | A temporary smoke-only transfer plugin verifies `/api/transfer/submit` writes a successful transfer with expected `uid`, `type`, amount, cost, status, and gateway order number, deducts merchant balance exactly once, and `/api/transfer/proof` returns the expected proof URL. |
| Channel selection | Fixture order is persisted through a deterministic local `epay` channel. |
| Bad async callback signature | `/pay/notify/{trade_no}/` returns `fail`; order remains unpaid. |
| Valid async callback | `/pay/notify/{trade_no}/` returns `success`; order status becomes paid, gateway trade number is stored, and merchant balance increases once. |
| Duplicate valid callback | Returns `success` again without creating a second income record or adding money twice. |
| Bad synchronous return signature | `/pay/return/{trade_no}/` returns the expected validation failure page; order remains unpaid. |
| Valid synchronous return | `/pay/return/{trade_no}/` returns the success jump page; order status becomes paid, gateway trade number is stored, and merchant balance increases once. |

Local `epayn` payment paths covered:

| Flow | Result |
| --- | --- |
| Temporary RSA fixture keys | Generated at runtime by the smoke script and injected only into the temporary database channel config. No static test private key is committed. |
| `mapi.php` merchant order creation | Signed MD5 merchant request creates an unpaid order and returns the existing API contract: `code=1`, `trade_no`, and `payurl`. |
| Channel selection | Fixture order is persisted through a deterministic local `epayn` channel. |
| Bad RSA async callback signature | `/pay/notify/{trade_no}/` returns `fail`; order remains unpaid. |
| Valid RSA async callback | `/pay/notify/{trade_no}/` returns `success`; order status becomes paid, gateway trade number and buyer are stored, and merchant balance increases once. |
| Duplicate valid callback | Returns `success` again without creating a second income record or adding money twice. |

P0 official plugin submit-runtime paths covered:

| Plugin | Flow | Result |
| --- | --- | --- |
| `alipay` | Signed `mapi.php` order creation followed by `/pay/submit/{trade_no}/` | Order is persisted through an `alipay` channel and submit returns a controlled jump page without PHP error output. |
| `wxpay` | Signed `mapi.php` order creation followed by `/pay/submit/{trade_no}/` | Order is persisted through a `wxpay` channel and submit returns a controlled jump page without PHP error output. |
| `qqpay` | Signed `mapi.php` order creation followed by `/pay/submit/{trade_no}/` | Order is persisted through a `qqpay` channel and submit returns a controlled jump page without PHP error output. |

P0 official plugin bad-signature runtime paths covered:

| Plugin | Flow | Result |
| --- | --- | --- |
| `alipay` | Invalid RSA2 `/pay/notify/{trade_no}/` POST using a temporary RSA channel fixture | Callback returns `fail`; order remains unpaid and no income record is created. |
| `alipay` | Invalid RSA2 `/pay/return/{trade_no}/` GET using the same temporary RSA channel fixture | Return path shows the expected verification failure; order remains unpaid and no income record is created. |
| `wxpay` | Invalid APIv2 XML `/pay/notify/{trade_no}/` POST | Callback returns failure XML; order remains unpaid and no income record is created. |
| `qqpay` | Invalid APIv2 XML `/pay/notify/{trade_no}/` POST | Callback returns failure XML; order remains unpaid and no income record is created. |
| `qqpay` | `/pay/return/{trade_no}/` GET against an unpaid `qqpay` order | The plugin intentionally has no synchronous `return()` method; the route renders the controlled `插件方法不存在:return` page without PHP fatal output, and the order remains unpaid with no income record. |

P0 official plugin local success state-machine paths covered:

| Plugin | Flow | Result |
| --- | --- | --- |
| `alipay` | Smoke-signed RSA2 success notify through a smoke-only endpoint that uses upstream Alipay SDK local verification and then calls the real order notify state machine | Callback returns `success`; order becomes paid, gateway trade number and buyer are stored, merchant balance increases once, and one income record is created. |
| `alipay` | Duplicate smoke-signed success notify against the same order | Callback returns `success` again without creating a second income record or adding money twice. |
| `alipay` | Smoke-signed RSA2 synchronous return through a smoke-only endpoint that uses upstream Alipay SDK local verification and then calls the real order return state machine | Return page is accepted; order becomes paid and merchant balance increases once without PHP error output. |

P0 official success callback limitation: the normal `alipay` plugin `notify()` and `return()` methods call the upstream `AlipayTradeService::check()` path, which performs live gateway order query after local signature verification. The automated smoke therefore covers `alipay` local SDK verification plus the real project state-machine update through smoke-only endpoints, but full normal-endpoint `alipay` success acceptance still requires sandbox-backed or mock-gateway query acceptance. `wxpay` and `qqpay` success-notify paths also perform live gateway order verification after local signature verification and remain pending for sandbox-backed or mock-gateway success fixtures; `qqpay` synchronous return remains intentionally unsupported and is covered as a controlled unsupported path.

P1 plugin submit-runtime paths covered:

| Plugin | Flow | Result |
| --- | --- | --- |
| `unionpay` | Signed `mapi.php` order creation followed by `/pay/submit/{trade_no}/` using `alipay` type | Order is persisted through a `unionpay` channel and submit returns a controlled jump page without PHP error output. |
| `swiftpass` | Signed `mapi.php` order creation followed by `/pay/submit/{trade_no}/` using `alipay` type | Order is persisted through a `swiftpass` channel and submit returns a controlled jump page without PHP error output. |
| `swiftpass2` | Signed `mapi.php` order creation followed by `/pay/submit/{trade_no}/` using `alipay` type | Order is persisted through a `swiftpass2` channel and submit returns a controlled jump page without PHP error output. |
| `kuaiqian` | Signed `mapi.php` order creation followed by `/pay/submit/{trade_no}/` using `alipay` type | Order is persisted through a `kuaiqian` channel and submit returns a controlled jump page without PHP error output. |
| `ysepay` | Signed `mapi.php` order creation followed by `/pay/submit/{trade_no}/` using `alipay` type | Order is persisted through a `ysepay` channel and submit returns a controlled jump page without PHP error output. |
| `sandpay` | Signed `mapi.php` order creation followed by `/pay/submit/{trade_no}/` using `alipay` type | Order is persisted through a `sandpay` channel and submit returns a controlled jump page without PHP error output. |
| `stripe` | Signed `mapi.php` order creation followed by `/pay/submit/{trade_no}/` using `alipay` type and direct-pay switch | Order is persisted through a `stripe` channel and submit returns a controlled jump page without PHP error output. |

P1 plugin bad/missing-signature runtime paths covered:

| Plugin | Flow | Result |
| --- | --- | --- |
| `unionpay` | Invalid XML `/pay/notify/{trade_no}/` POST with matching order and invalid signature | Callback is rejected without PHP error output; order remains unpaid and no income record is created. |
| `swiftpass` | Invalid XML `/pay/notify/{trade_no}/` POST with matching order and invalid RSA signature | Callback is rejected without PHP error output; order remains unpaid and no income record is created. |
| `swiftpass2` | Invalid XML `/pay/notify/{trade_no}/` POST with matching order and invalid signature | Callback is rejected without PHP error output; order remains unpaid and no income record is created. |
| `kuaiqian` | Forged success `/pay/notify/{trade_no}/` GET with matching order and missing `signMsg` | Callback is rejected with `<result>0</result>` without PHP error output; order remains unpaid and no income record is created. |
| `ysepay` | Forged success `/pay/notify/{trade_no}/` POST with matching order and invalid `sign` | Callback is rejected with `fail` without PHP error output; order remains unpaid and no income record is created. |
| `sandpay` | Forged success `/pay/notify/{trade_no}/` POST with matching order and invalid `sign` plus smoke-only PFX fixture | Callback is rejected with `respCode=020002` without PHP error output; order remains unpaid and no income record is created. |

P1 plugin success notify runtime paths covered:

| Plugin | Flow | Result |
| --- | --- | --- |
| `ysepay` | Smoke-signed success `/pay/notify/{trade_no}/` POST with matching order and smoke-only certificate fixture | Callback is accepted with `success`; order is paid, gateway trade number and buyer are stored, merchant balance is credited once, and duplicate replay is idempotent. |
| `sandpay` | Smoke-signed success `/pay/notify/{trade_no}/` POST with matching order and smoke-only certificate fixture | Callback is accepted with `respCode=000000`; order is paid, gateway trade number and buyer are stored, merchant balance is credited once, and duplicate replay is idempotent. |

P1 plugin synchronous return runtime paths covered:

| Plugin | Flow | Result |
| --- | --- | --- |
| `ysepay` | Smoke-signed success `/pay/return/{trade_no}/` GET with matching order and smoke-only certificate fixture | Return callback is accepted without PHP error output; order is paid, gateway trade number is stored, merchant balance is credited once, and one income record is created. |

P1 runtime limitation: `paypal` is not included in this automated installed smoke because its `submit()` path creates an order through the external PayPal API. It still requires sandbox credentials and network-backed acceptance.

Local performance samples:

The installed smoke emits `[PERF]` lines for the performance and stability acceptance items. These are single local samples using PHP's built-in server and a temporary MariaDB-backed install, so they are useful as a repeatable regression signal but do not replace production PHP-FPM and web-server monitoring. The Apache/PHP-FPM smoke covers functional web-server execution, not sustained performance.

| Flow | PHP 7.4 ms | PHP 8.4 ms | PHP 8.4 delta |
| --- | ---: | ---: | ---: |
| Home page | 3.33 | 2.01 | -39.6% |
| Authenticated admin home | 25.67 | 1.86 | -92.8% |
| Authenticated user home | 2.93 | 2.16 | -26.3% |
| Signed order creation API | 29.06 | 4.20 | -85.5% |
| Local `epay` payment notify | 6.50 | 7.88 | +21.2% |
| `cron.php` with valid cron key | 1.80 | 1.54 | -14.4% |

Repeated local performance samples:

`tools/php84/performance-repeat-sample.sh` wraps the installed HTTP smoke and runs complete installed-state samples multiple times for PHP 7.4 and PHP 8.4. It parses `[PERF]` output and reports per-flow medians, reducing the risk of treating one noisy built-in-server request as a performance result.

Latest local repeated run:

| Flow | PHP 7.4 median ms | PHP 8.4 median ms | PHP 8.4 delta | Samples |
| --- | ---: | ---: | ---: | ---: |
| Home page | 2.09 | 2.07 | -1.0% | 3/3 |
| Authenticated admin home | 2.25 | 2.25 | +0.0% | 3/3 |
| Authenticated user home | 2.16 | 2.44 | +13.0% | 3/3 |
| Signed order creation API | 4.99 | 4.80 | -3.8% | 3/3 |
| Local `epay` payment notify | 5.63 | 6.73 | +19.5% | 3/3 |
| `cron.php` with valid cron key | 1.98 | 1.82 | -8.1% | 3/3 |

Local repeated-sample result: all six measured flows completed successfully on PHP 7.4 and PHP 8.4. The PHP 8.4 local payment notify median remains the closest watched path at +19.5% against PHP 7.4, below the 20% local alert line in this run but still close enough to carry forward as a production-like performance validation item. Final performance acceptance still requires sustained PHP-FPM/web-server sampling, worker stability checks, and log-volume monitoring.

Remote Nginx/PHP-FPM repeated performance samples:

`tools/php84/nginx-fpm-performance-sample.sh` wraps the Nginx/PHP-FPM smoke and runs repeated temporary Nginx/PHP-FPM stacks for PHP 7.4 and PHP 8.4. It parses `[PERF]` output from the Nginx smoke, filters protected-directory and stability-loop helper routes, and reports per-flow medians for the same core entry checks under a real Nginx/PHP-FPM runtime.

Latest remote repeated run:

| Flow | PHP 7.4 median ms | PHP 8.4 median ms | PHP 8.4 delta | Samples |
| --- | ---: | ---: | ---: | ---: |
| `/api.php` missing action | 3.15 | 2.93 | -7.0% | 3/3 |
| `cron.php` with valid cron key | 3.80 | 3.69 | -2.9% | 3/3 |
| Friendly `/api/unknown` route | 3.08 | 3.13 | +1.6% | 3/3 |
| Friendly missing payment route | 3.86 | 3.69 | -4.4% | 3/3 |
| Home page | 9.25 | 6.28 | -32.1% | 3/3 |
| `/mapi.php` missing params | 0.97 | 1.03 | +6.2% | 3/3 |
| `/submit.php` missing merchant | 0.90 | 0.95 | +5.6% | 3/3 |

Remote Nginx/PHP-FPM repeated-sample result: all seven measured core-entry flows completed successfully on PHP 7.4 and PHP 8.4 with three samples per runtime and two repeated route rounds per sample. No PHP 8.4 median exceeded the PHP 7.4 median by more than 20% in this run, and no temporary database/user, Nginx/FPM process, or smoke port remained after cleanup. Final performance acceptance still requires longer production-like sampling, real traffic mix, external gateway paths, and log-volume monitoring.

### Local Default PHP Checks

Runtime: `php`, version `8.5.4`.

This runtime is newer than the target range and is used only as an additional smoke check. Final PHP 8.4 acceptance must run in the GitHub Actions matrix or a PHP 8.4 environment.

| Command | Result |
| --- | --- |
| `php tools/php84/check-env.php` | Pass with warning: PHP 8.5.4 is newer than target. |
| `sh tools/php84/lint-all.sh` | Pass. Checked 704 PHP files at the time of that run, no syntax errors. Vendor deprecated notices recorded below. A later PHP 7.4 run after adding the DB fixture script checked 705 files. |
| `sh tools/php84/check-deprecated-patterns.sh` | Pass. No blocked deprecated PHP patterns found in non-vendor PHP files. |
| `php tools/php84/check-php74-floor.php` | Pass. PHP 7.4 compatibility floor scan passed under the local default PHP runtime. |
| `php tools/php84/check-plugin-metadata.php` | Pass. Checked 44 plugin metadata files with P0/P1/P2 classification. Reported 6 compatibility warnings for runtime follow-up. |
| `php tools/php84/check-signatures.php` | Pass. MD5/RSA signing and verification checks passed. |
| `php tools/php84/check-download-safety.php` | Pass. Download endpoint login guards and blocked local file output primitive checks passed. |
| `php tools/php84/check-rewrite-rules.php` | Pass. Apache, Nginx, and IIS friendly URL rewrite examples preserve expected public routes. |
| `php -r "require 'includes/vendor/autoload.php'; echo 'autoload ok'.PHP_EOL;"` | Pass. |
| `sh tools/php84/composer-check.sh` | Pass for validate/install. Platform check skipped because PHP 8.5 is outside target range. |
| `composer audit --working-dir=includes --no-dev` | Pass. No security vulnerability advisories found. |

### PHP 8.4 Local Checks

Runtime: `/opt/homebrew/opt/php@8.4/bin/php`, version `8.4.22`.

Environment:

| Check | Result |
| --- | --- |
| `/opt/homebrew/opt/php@8.4/bin/php -v` | Pass. `PHP 8.4.22 (cli)`. |
| `/opt/homebrew/opt/php@8.4/bin/php tools/php84/check-env.php` | Pass. Required extensions `pdo_mysql`, `curl`, `openssl`, `json`, `mbstring`, `gd`, `fileinfo`, and `session` loaded. Recommended extensions `gmp`, `bcmath`, `intl`, `zip`, and `xml` loaded. |

Static and dependency checks:

| Command | Result |
| --- | --- |
| `PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php sh tools/php84/lint-all.sh` | Pass. Checked 710 PHP files, no syntax errors. Vendor deprecated notices were emitted and remain tracked in the vendor risk register. |
| `sh tools/php84/check-deprecated-patterns.sh` | Pass. No blocked deprecated PHP patterns found in non-vendor PHP files. |
| `/opt/homebrew/opt/php@8.4/bin/php tools/php84/check-php74-floor.php` | Pass. Checked 371 PHP files for PHP 8+ syntax tokens and common PHP 8+ standard-library calls. |
| `/opt/homebrew/opt/php@8.4/bin/php tools/php84/check-plugin-metadata.php` | Pass. Checked 44 plugin metadata files. Same 6 runtime follow-up warnings remain. |
| `/opt/homebrew/opt/php@8.4/bin/php tools/php84/check-signatures.php` | Pass. MD5/RSA signing and verification checks passed on PHP 8.4. |
| `/opt/homebrew/opt/php@8.4/bin/php tools/php84/check-download-safety.php` | Pass. Download endpoint login guards and blocked local file output primitive checks passed on PHP 8.4. |
| `/opt/homebrew/opt/php@8.4/bin/php tools/php84/check-rewrite-rules.php` | Pass. Apache, Nginx, and IIS friendly URL rewrite examples preserve `pay`, `api`, and document route rules plus Nginx protected-directory denies. |
| `PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php sh tools/php84/composer-check.sh` | Pass. `composer validate`, `install`, and `check-platform-reqs` all passed on PHP 8.4. |
| `composer audit --working-dir=includes --no-dev` | Pass. No security vulnerability advisories found. |

Database and installed-state runtime checks:

| Command | Result |
| --- | --- |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= /opt/homebrew/opt/php@8.4/bin/php tools/php84/db-fixture-check.php` | Pass. Temporary database created and dropped; core tables, refund order, balance record, settle, and transfer fixture checks passed. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8133 sh tools/php84/installed-http-smoke.sh` | Pass. Installed-state endpoints, admin/user authentication/logout, admin/user order-settle-transfer pages, unauthenticated admin-sensitive AJAX guards, user-center cross-merchant ownership guards, payment order creation negative/duplicate paths, payment response-shape mappings, user-center refund query/submit flow, signed transfer submit/proof flow, old merchant API queries, friendly `/api/...` signed refund/transfer APIs, unsupported-refund plugin rejection without state changes, admin manual fill-order, admin settlement status/batch actions, admin transfer result/status/refund-idempotency actions, friendly `/pay/...` local `epay` notify/return/idempotency, local `epayn` RSA notify/idempotency, P0 `alipay`/`wxpay`/`qqpay` submit-runtime checks, P0 official bad-signature notify checks, P0 `alipay` bad-signature return check, P0 `qqpay` controlled unsupported-return check, seven P1 submit-runtime checks, P1 `unionpay`/`swiftpass`/`swiftpass2` bad-signature notify checks, null channel-config compatibility, early database-bootstrap failure handling, and local performance samples passed. |
| `EPAY_DB_USER=apple EPAY_DB_PASSWORD= EPAY_DB_SOCKET=/tmp/mysql.sock PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_SMOKE_PORT=8134 sh tools/php84/installed-http-smoke.sh` | Pass with the same installed-state coverage on the PHP 7.4 compatibility floor. |
| `PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_SMOKE_PORT=8130 sh tools/php84/installed-http-smoke.sh` with the workstation's default unavailable MySQL TCP root credentials | Expected fail. The script now exits immediately after database fixture bootstrap failure and does not continue into noisy HTTP checks. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@8.4/bin/php EPAY_INSTALL_SMOKE_PORT=8117 sh tools/php84/install-upgrade-smoke.sh` | Pass. Fresh install, database failure messaging, lock creation, missing-lock runtime blocking, default admin login, low-version upgrade, data preservation, and repeat-upgrade guard passed. |
| `EPAY_DB_HOST=localhost EPAY_DB_USER=apple EPAY_DB_PASSWORD= PHP_BIN=/opt/homebrew/opt/php@7.4/bin/php EPAY_INSTALL_SMOKE_PORT=8118 sh tools/php84/install-upgrade-smoke.sh` | Pass with the same install/upgrade coverage on the PHP 7.4 compatibility floor. |

## PHP 8.4 CI Coverage

`.github/workflows/php84-compat.yml` now runs the static compatibility gate on:

- PHP 7.4
- PHP 8.1
- PHP 8.2
- PHP 8.3
- PHP 8.4

Each matrix entry runs:

- PHP environment check.
- Full PHP lint.
- Blocked deprecated pattern scan.
- PHP 7.4 compatibility floor scan for PHP 8+ syntax tokens and common PHP 8+ standard-library calls.
- Plugin metadata smoke check.
- Payment signature consistency check for MD5 and RSA.
- Download endpoint safety check.
- Rewrite rule safety check for bundled Apache, Nginx, and IIS friendly URL examples.
- HTTP smoke checks for install, update, submit missing merchant, and mapi missing params.
- Composer validate/install/platform checks.
- Composer security audit.

The workflow also defines a MariaDB-backed database fixture job on PHP 7.4 and PHP 8.4. It runs `php tools/php84/db-fixture-check.php` against a temporary MariaDB service.

The database-backed job runs `php tools/php84/rollback-rehearsal-check.php`, so PHP 7.4 and PHP 8.4 CI cover temporary database backup generation, broken-state simulation, backup restore, and restored-data verification against a real MariaDB service.

The same database-backed job also runs `sh tools/php84/installed-http-smoke.sh`, so PHP 7.4 and PHP 8.4 CI cover installed-state entry, authentication/logout, admin/user order-settle-transfer page loads, download endpoint login/invalid-action checks, unauthenticated admin-sensitive AJAX guards, user-center cross-merchant ownership guards, payment order creation negative/duplicate paths, payment response-shape mappings, user-center refund query/submit flow, merchant/refund/transfer API smoke checks through friendly `/api/...` routes, signed transfer submit/proof flow, unsupported-refund plugin rejection without state changes, admin manual fill-order, admin settlement status/batch actions, admin transfer result/status/refund-idempotency actions, local `epay`/`epayn` payment callback smoke checks through friendly `/pay/...` routes, P0/P1 submit-runtime checks through friendly `/pay/submit/...` routes, P0 official bad-signature notify/return rejection checks, P0 `qqpay` controlled unsupported-return check, P1 `unionpay`/`swiftpass`/`swiftpass2`/`ysepay`/`sandpay` bad-signature notify checks, P1 `ysepay`/`sandpay` success notify and duplicate notify checks with smoke-only certificate fixtures, P1 `ysepay` synchronous return success check, P1 `kuaiqian` missing-signature notify check, seven P1 submit-runtime checks, null channel-config compatibility, and local performance sample output against a real MariaDB service.

The database-backed job also runs `sh tools/php84/install-upgrade-smoke.sh`, so PHP 7.4 and PHP 8.4 CI cover fresh installer behavior, database failure messaging, lock creation, missing-lock runtime blocking, default admin login after install, low-version upgrade, data preservation, and repeat-upgrade guard against a real MariaDB service.

## Business Compatibility Fixes

Replaced `strftime()` in business code with PHP 7.4-compatible `date()` calls:

| File | Change |
| --- | --- |
| `plugins/kayixin/inc/alipay_core.function.php` | `strftime("%Y%m%d%H%M%S", time())` to `date("YmdHis")`. |
| `includes/360safe/360webscan.php` | `strftime("%Y-%m-%d %H:%M:%S")` to `date("Y-m-d H:i:s")`. |
| `admin/ajax_order.php` | Manual fill-order now writes `endtime` and `date` with `NOW()` instead of an undefined `$date` variable. |
| `admin/ajax_settle.php` | Settlement status completion now writes `endtime` with `NOW()` instead of an undefined `$date` variable. |
| `admin/ajax_transfer.php` | Transfer refund action now treats an already failed/refunded transfer as idempotent and does not add merchant balance again. |
| `includes/lib/Channel.php` | Empty or invalid channel `config` JSON is normalized to an empty array before `array_merge()`, preventing PHP 8.4 `TypeError` while preserving PHP 7.4 behavior. |
| `user/ajax2.php` | User-center settlement and transfer detail/status/proof endpoints now require the current merchant `uid`, preventing cross-merchant reads and external status/proof calls from another authenticated user. |

Verification:

- `sh tools/php84/check-deprecated-patterns.sh` passes.
- `php tools/php84/check-php74-floor.php` passes on PHP 7.4 and PHP 8.4.
- No `strftime()` remains in non-vendor PHP code outside documentation.

## Vendor Deprecated Risk Register

The default PHP 8.5 lint exposes vendor deprecated notices for implicit nullable parameters. These were not changed directly because the current policy is to avoid undocumented vendor patching.

| Source | Impact | Current decision |
| --- | --- | --- |
| `includes/vendor/lpilp/guomi/src/ecc/RtEccFactory.php` | Deprecated notice on implicit nullable parameter in lint/runtime when loaded by newer PHP. | Risk accepted for current static stage; monitor upstream or fork only if PHP 8.4 runtime logs are noisy in payment paths. |
| `includes/vendor/lpilp/guomi/src/ecc/Sm2Curve.php` | Same as above. | Risk accepted for current static stage. |
| `includes/vendor/cccyun/alipay-sdk/src/*` | Deprecated notices in Alipay SDK methods with nullable default parameters. | Risk accepted for current static stage; payment runtime regression must confirm no fatal behavior. |
| `includes/vendor/cccyun/wechatpay-sdk/src/*` | Deprecated notices in WeChat Pay SDK V2/V3 methods with nullable default parameters. | Risk accepted for current static stage; payment runtime regression must confirm no fatal behavior. |
| `includes/vendor/paragonie/sodium_compat/src/Core32/Curve25519/*` | Deprecated notices in sodium compatibility classes. | Risk accepted for current static stage; evaluate upstream release before patching vendor. |

These notices are non-blocking only if PHP 8.4 runtime testing confirms there are no fatal errors and the notices are logged, not displayed to users. They must remain visible in the release risk log.

## Plugin Static Compatibility Matrix

`tools/php84/check-plugin-metadata.php` now classifies plugins by acceptance priority and checks static method availability before runtime testing.

Static gate result:

| Tier | Scope | Result |
| --- | --- | --- |
| P0 | `alipay`, `wxpay`, `qqpay`, `epay`, `epayn` | Pass for metadata and core methods. `qqpay` intentionally has no synchronous `return()` method; the installed smoke now verifies this as a controlled unsupported path with no order state change. |
| P1 | `stripe`, `paypal`, `unionpay`, `swiftpass`, `swiftpass2`, `kuaiqian`, `ysepay`, `sandpay` | Pass for metadata and required `submit()` method. Runtime follow-up warnings exist for optional or unsupported methods. |
| P2 | All remaining plugin metadata files | Pass for metadata loading. |

Runtime follow-up warnings:

| Plugin | Tier | Warning |
| --- | --- | --- |
| `qqpay` | P0 | Synchronous `return()` method is missing by plugin design; installed smoke verifies `/pay/return/{trade_no}/` renders a controlled unsupported-method page and leaves order state unchanged. |
| `kuaiqian` | P1 | `mapi()` is missing or unsupported. |
| `paypal` | P1 | `mapi()` and `notify()` are missing or unsupported. |
| `stripe` | P1 | `mapi()` and `notify()` are missing or unsupported. |

These are not treated as static blockers yet because the plugin acceptance document requires runtime path validation, including unsupported-method behavior and gateway-specific callback handling.

P1 runtime progress:

| Plugin | Runtime status |
| --- | --- |
| `unionpay` | Submit-runtime path and bad-signature notify rejection covered on PHP 7.4 and PHP 8.4. |
| `swiftpass` | Submit-runtime path and bad-signature notify rejection covered on PHP 7.4 and PHP 8.4. |
| `swiftpass2` | Submit-runtime path and bad-signature notify rejection covered on PHP 7.4 and PHP 8.4. |
| `kuaiqian` | Submit-runtime path and missing-signature notify rejection covered on PHP 7.4 and PHP 8.4; `mapi()` remains unsupported/missing by metadata check. |
| `ysepay` | Submit-runtime path, bad-signature notify rejection, success notify state update, duplicate notify idempotency, and synchronous return success covered on PHP 7.4 and PHP 8.4 with a smoke-only certificate fixture. |
| `sandpay` | Submit-runtime path, bad-signature notify rejection, success notify state update, and duplicate notify idempotency covered on PHP 7.4 and PHP 8.4 with a smoke-only certificate fixture. |
| `stripe` | Submit-runtime path covered on PHP 7.4 and PHP 8.4 using direct-pay switch; `mapi()` and `notify()` remain unsupported/missing by metadata check. |
| `paypal` | Metadata loads, but automated submit-runtime is not covered because it requires an external PayPal API order creation. Sandbox-backed acceptance remains pending. |

## Remaining Blocking Acceptance Work

The following acceptance items are not complete yet and must not be marked as passed:

| Area | Status | Reason |
| --- | --- | --- |
| PHP 8.4 runtime acceptance | Partially covered | Local PHP 8.4.22 with required and recommended extensions passes env, lint, deprecated-pattern, plugin metadata, signature, Composer, database fixture, installed HTTP smoke checks, optional browser-level installed smoke checks, Apache/PHP-FPM functional smoke checks, and a repeated Apache/PHP-FPM local stability sample. Remote Debian 12 validation now proves Nginx/PHP-FPM runtime execution, repeated core-route stability samples, and browser-level home/admin/user login-dashboard checks on PHP 8.4.22 and PHP 7.4.33 with the project friendly route rules and protected-directory denies. Broader manual acceptance, longer production-like PHP-FPM monitoring, and IIS runtime evidence are still pending. |
| Core HTTP entry checks | Partially covered | Stateless smoke checks and installed-state HTTP smoke checks pass locally on PHP 7.4 and PHP 8.4. Optional Playwright installed smoke covers browser-level home/admin/user login/dashboard paths. Bundled Apache, Nginx, and IIS rewrite examples are statically checked; installed smoke covers friendly `/api/...` and `/pay/...` runtime routes through a temporary built-in-server router; Apache/PHP-FPM runtime smoke now covers core entries and friendly Apache rewrite execution; remote Nginx/PHP-FPM smoke covers core entries, friendly `/api/...` and `/pay/...` route execution, protected `/includes`/`/plugins` denies, and Chromium browser-level home/admin/user login-dashboard paths on PHP 7.4 and PHP 8.4. Real IIS runtime and full manual browser acceptance are still pending. |
| Install and upgrade flow | Partially covered | Stateless install/update page loads, database fixture checks, and `tools/php84/install-upgrade-smoke.sh` now cover fresh install, invalid DB credential messaging, lock creation, runtime blocking when `install.lock` is missing, default admin login, low-version update, data preservation, and repeat-upgrade guard on PHP 7.4 and PHP 8.4. The same install/upgrade flow now also passes through isolated remote Nginx/PHP-FPM runtimes on PHP 8.4.22 and PHP 7.4.33. Historical production database upgrade rehearsal and manual browser installer validation are still pending. |
| Admin login and user login | Partially covered | Seeded temporary credentials pass success/failure, protected-page cookie checks, logout, and post-logout protected-page re-intercept on PHP 7.4 and PHP 8.4. Authenticated admin/user order, settlement, and transfer pages load without PHP error output. Optional Playwright smoke verifies browser-level admin login cookie/dashboard and user password login cookie/dashboard on PHP 7.4 and PHP 8.4 through both the local installed smoke and the remote Nginx/PHP-FPM smoke. Admin captcha PNG generation, session-cookie creation, and wrong-code rejection before `admin_token` issuance are now covered by automated smoke on PHP 7.4 and PHP 8.4. Full manual browser-level captcha acceptance remains pending. |
| Payment creation, redirect, return, notify | Partially covered | Local `epay` signed `mapi.php` order creation, missing-merchant rejection, bad-signature rejection without order creation, invalid-amount rejection without order creation, duplicate unpaid order reuse, duplicate changed-parameter rejection, payment response-shape mappings for `html`, `qrcode`, `urlscheme`, and `/api/pay/create` `jsapi` JSON payloads, user-center refund query/submit flow, admin manual fill-order, async notify replay, and synchronous return replay pass on PHP 7.4 and PHP 8.4. Local `epayn` signed order creation and async notify replay also pass. Official P0 plugins `alipay`, `wxpay`, and `qqpay` cover signed order creation, submit-runtime entry, and bad-signature notify rejection without state changes on PHP 7.4 and PHP 8.4. Official `alipay` also covers bad-signature return rejection plus smoke-only local SDK verification for success notify, duplicate notify idempotency, and synchronous return state-machine updates; official `qqpay` covers its intentionally unsupported synchronous return path without state changes. P1 `ysepay` covers smoke-signed success notify, duplicate notify idempotency, and synchronous return success; P1 `sandpay` covers smoke-signed success notify and duplicate notify idempotency. Gateway-backed success notify/return fixtures for official `alipay` normal endpoints, and success notify fixtures for official `wxpay`/`qqpay`, remain pending because their SDK checks require real gateway query acceptance. |
| Duplicate callback idempotency | Partially covered | Local `epay` and `epayn` duplicate async notify replay is covered and confirms no duplicate income record or balance increment. Official `alipay` duplicate success notify replay is covered through smoke-only local SDK verification and confirms no duplicate income record or balance increment. P1 `ysepay` and `sandpay` duplicate success notify replay is covered with smoke-only certificate fixtures. Other P0/P1 gateways still require runtime callback fixtures where success callbacks are feasible. |
| OpenSSL and signatures | Partially covered | `tools/php84/check-signatures.php` verifies MD5 signing, MD5 verification, RSA signing, RSA verification, wrong-key rejection, and PEM/base64 wrapping on PHP 7.4 and PHP 8.4. Full payment-path OpenSSL coverage remains tied to plugin runtime tests. |
| Refund, settlement, and transfer | Partially covered | Database fixture covers refund order, refund balance record, settle status change, and transfer status change on PHP 7.4 and PHP 8.4. Installed smoke now covers old settlement query API, signed refund query API, signed duplicate-refund API, unsupported-refund plugin rejection without state changes, user-center refund query and submit with password guard, signed transfer balance/query APIs, signed transfer submit/proof flow, authenticated admin/user settlement-transfer list pages, admin single settlement completion, admin settlement batch creation, admin settlement batch completion, admin transfer result read, admin transfer status update, and idempotent admin transfer refund on PHP 7.4 and PHP 8.4. Actual external gateway refund execution and gateway-backed settlement/transfer operations still require broader acceptance. |
| Security regression | Partially covered | Installed smoke now covers protected-page redirects, browser-cookie issuance for admin/user login, unauthenticated admin-sensitive AJAX guards, admin captcha wrong-code rejection before token issuance, user-center cross-merchant ownership guards, callback bad-signature rejection for local and official P0 paths, P1 `unionpay`/`swiftpass`/`swiftpass2`/`ysepay`/`sandpay` bad-signature notify rejection, P1 `kuaiqian` missing-signature notify rejection, cross-merchant denial for order/refund/transfer API reads, download endpoint login/invalid-action behavior, and missing `install.lock` runtime blocking on PHP 7.4 and PHP 8.4. Static download safety checks confirm download endpoints retain login guards and avoid blocked local file output primitives. Broader admin sensitive actions and manual browser-level permission checks remain pending. |
| P0 plugin runtime validation | Partially covered | Metadata smoke test passes. Runtime coverage now exists for local `epay` and `epayn` create/notify/idempotency paths, plus `alipay`, `wxpay`, and `qqpay` signed order creation, submit-runtime entry, and bad-signature notify rejection. `alipay` also covers bad-signature return rejection, smoke-only local SDK verification for success notify, duplicate notify idempotency, and synchronous return state-machine updates; `qqpay` covers its controlled unsupported synchronous-return path. Gateway-backed success notify/return tests for official `alipay` normal endpoints and success notify tests for `wxpay`/`qqpay` are still required where feasible. |
| P1 plugin runtime validation | Partially covered | Metadata smoke test passes. Submit-runtime coverage now exists for `unionpay`, `swiftpass`, `swiftpass2`, `kuaiqian`, `ysepay`, `sandpay`, and `stripe` on PHP 7.4 and PHP 8.4. Bad-signature notify rejection is covered for `unionpay`, `swiftpass`, `swiftpass2`, `ysepay`, and `sandpay`; missing-signature notify rejection is covered for `kuaiqian`; success notify and duplicate notify idempotency are covered for `ysepay` and `sandpay` with smoke-only certificate fixtures; synchronous return success is covered for `ysepay`. PayPal submit-runtime still requires sandbox credentials and external API acceptance. Other P1 gateway success return/refund paths remain pending where supported. |
| Performance and stability | Partially covered | Installed smoke records local PHP 7.4/PHP 8.4 samples for home, admin home, user home, order creation, payment notify, and cron. `tools/php84/performance-repeat-sample.sh` now runs complete installed-smoke samples repeatedly for both runtimes and reports per-flow medians; the latest three-sample local run passed with PHP 8.4 payment notify at +19.5% versus PHP 7.4. Apache/PHP-FPM smoke now supports repeated core-route requests under one temporary stack and confirms the PHP-FPM/httpd processes stay alive with clean PHP/Apache/FPM logs on PHP 7.4 and PHP 8.4. Remote Nginx/PHP-FPM smoke now supports repeated core-route requests and confirms PHP-FPM/Nginx processes stay alive with clean PHP/FPM/Nginx logs on PHP 7.4 and PHP 8.4. `tools/php84/nginx-fpm-performance-sample.sh` now records three remote Nginx/PHP-FPM samples per runtime for seven core-entry flows; all PHP 8.4 medians stayed within the 20% local alert line versus PHP 7.4 in the latest run. Longer production-like PHP-FPM/web-server performance runs, real traffic mix, external gateway paths, and external log-volume monitoring remain pending before performance acceptance can pass. |
| Rollback rehearsal | Partially covered | `docs/php84-rollback-runbook.md` defines trigger conditions, database backup/restore commands, PHP 7.4 runtime switch steps, and post-rollback checks. `tools/php84/rollback-rehearsal-check.php` proves temporary database backup/restore on PHP 7.4 and PHP 8.4, including broken-state simulation and restored fixture verification. `tools/php84/apache-fpm-rollback-rehearsal.sh` proves a local Apache/PHP-FPM runtime switch from PHP 8.4 back to PHP 7.4 on the same Apache port. `tools/php84/nginx-fpm-rollback-rehearsal.sh` now proves the same PHP 8.4 to PHP 7.4 runtime switch on the remote Debian 12 Nginx/PHP-FPM validation host with package-installed PHP 8.4.22 and PHP 7.4.33. A real deployment-system rollback rehearsal with a production-like backup artifact is still pending. |

## Current Gate Result

Static/dependency gate: conditionally pass.

Production or gray release gate: not passed.

Reason: PHP 7.4 and PHP 8.4 static checks, explicit PHP 7.4 compatibility floor scans, Composer governance, download endpoint safety checks, rewrite rule checks, database-backed fixture checks, temporary database rollback rehearsal checks, local Apache/PHP-FPM rollback runtime-switch rehearsal, remote Nginx/PHP-FPM rollback runtime-switch rehearsal, installed authentication/logout checks, browser-level home/admin/user login-dashboard smoke checks, remote Nginx/PHP-FPM browser-level home/admin/user login-dashboard smoke checks, admin captcha image and wrong-code rejection checks, authenticated admin/user order-settle-transfer page checks, install/upgrade smoke checks including missing `install.lock` runtime blocking, remote Nginx/PHP-FPM install-upgrade smoke checks, Apache/PHP-FPM functional and repeated local stability smoke checks, remote Nginx/PHP-FPM PHP 7.4 and PHP 8.4 functional and repeated stability smoke checks, remote Nginx/PHP-FPM repeated performance sampling, download endpoint login/invalid-action checks, unauthenticated admin-sensitive AJAX guard checks, user-center cross-merchant ownership guard checks, payment order creation negative/duplicate path checks, payment response-shape mapping checks, user-center refund query/submit checks, old merchant API queries, friendly `/api/...` signed refund query/duplicate refund/unsupported-plugin rejection checks, friendly `/api/...` signed transfer balance/query and submit/proof checks, cross-merchant API denial checks, admin manual fill-order, admin settlement status/batch action checks, admin transfer result/status/refund-idempotency checks, null channel-config compatibility, friendly `/pay/...` local `epay`/`epayn` payment callback checks, friendly `/pay/submit/...` P0/P1 submit-runtime checks, P0 official bad-signature notify/return rejection checks, P0 official `alipay` local SDK success notify, duplicate notify, and synchronous return state-machine checks, P0 `qqpay` controlled unsupported-return check, P1 `unionpay`/`swiftpass`/`swiftpass2`/`ysepay`/`sandpay` bad-signature notify checks, P1 `ysepay`/`sandpay` success notify and duplicate notify checks, P1 `ysepay` synchronous return success check, P1 `kuaiqian` missing-signature notify check, seven P1 submit-runtime checks, and repeated local installed-smoke performance sampling are passing locally or on the isolated validation host. Production or gray release remains blocked by incomplete full manual browser acceptance, official gateway success callback fixtures for normal/live-query P0 endpoints, PayPal sandbox runtime acceptance, remaining P1 gateway success return/refund coverage, external refund/transfer execution coverage, gateway-backed settlement/transfer operation coverage, broader security/manual permission checks, longer production-like performance and stability verification, real IIS runtime rewrite verification, and real deployment-system rollback rehearsal with a production-like backup artifact.
