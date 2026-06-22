# PHP 8.4 Upgrade Baseline Report

Generated: 2026-06-22

Branch: `upgrade/php-84-compatible`

## Repository

- `origin`: `https://github.com/Lulu-Grant/epay.git`
- `upstream`: `https://github.com/lopinx/epay.git`
- Base branch before upgrade work: `main`

## Current Worktree Notes

- The `docs/` directory contains planning, acceptance, and target-mode prompt documents created before implementation work.
- Business PHP code has not been modified at this baseline point.

## Code Size

- Total PHP files: 545
- Non-vendor PHP files: 363
- Payment plugin main files: 44

## Key Runtime Files

- Global bootstrap: `includes/common.php`
- Autoloader: `includes/autoloader.php`
- Database helper: `includes/lib/PdoHelper.php`
- Plugin loader: `includes/lib/Plugin.php`
- Payment core: `includes/lib/Payment.php`
- Composer manifest: `includes/composer.json`

## Composer Baseline

- Composer manifest exists at `includes/composer.json`.
- No `includes/composer.lock` is present at baseline.
- `includes/vendor` is committed in the repository.
- Current root requirements:
  - `cccyun/alipay-sdk`: `^1.1`
  - `cccyun/wechatpay-sdk`: `^1.0`
  - `cccyun/qqpay-sdk`: `^1.0`
  - `lpilp/guomi`: `^1.0`

## Known Compatibility Risks

- `includes/common.php` currently uses `error_reporting(E_ERROR | E_PARSE | E_COMPILE_ERROR)`, which hides warnings and deprecations during normal runtime.
- `strftime()` exists in non-vendor PHP files and must be removed for PHP 8.4 compatibility acceptance.
- Existing vendor code emits PHP 8.4/8.5 deprecations around implicitly nullable parameters in `lpilp/guomi` and `mdanter/ecc`.
- Payment plugins are dynamically loaded and do not implement a shared interface, so plugin compatibility requires metadata smoke tests and staged runtime tests.
- Many business SQL statements are string-built even though the database helper uses PDO; SQL safety refactoring is outside the first compatibility pass but remains a risk.

## Baseline Acceptance Status

- Stage 0 repository and baseline inventory: complete.
- Stage 1 detection tooling: in progress.
- Composer lock and PHP platform constraint: not complete.
- PHP 8.4 explicit compatibility fixes: not complete.
- Core payment flow regression: not complete.
- P0 plugin runtime validation: not complete.

