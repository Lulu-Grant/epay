#!/usr/bin/env sh
set -u

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
SMOKE_SCRIPT="$ROOT_DIR/tools/php84/apache-fpm-smoke.sh"

PHP84_BIN=${PHP84_BIN:-/opt/homebrew/opt/php@8.4/bin/php}
PHP84_FPM_BIN=${PHP84_FPM_BIN:-/opt/homebrew/opt/php@8.4/sbin/php-fpm}
PHP74_BIN=${PHP74_BIN:-/opt/homebrew/opt/php@7.4/bin/php}
PHP74_FPM_BIN=${PHP74_FPM_BIN:-/opt/homebrew/opt/php@7.4/sbin/php-fpm}
HTTPD_BIN=${HTTPD_BIN:-/usr/sbin/httpd}
CURL_BIN=${CURL_BIN:-curl}
HOST=${EPAY_ROLLBACK_APACHE_HOST:-127.0.0.1}
HTTP_PORT=${EPAY_ROLLBACK_APACHE_PORT:-8630}
FPM_PORT=${EPAY_ROLLBACK_FPM_PORT:-9630}
DB_HOST=${EPAY_DB_HOST:-127.0.0.1}
DB_PORT=${EPAY_DB_PORT:-3306}
DB_SOCKET=${EPAY_DB_SOCKET:-}
DB_USER=${EPAY_DB_USER:-root}
DB_PASSWORD=${EPAY_DB_PASSWORD:-}
DB_PREFIX=${EPAY_DB_PREFIX:-pay}

check_bin() {
  label=$1
  bin=$2
  if ! command -v "$bin" >/dev/null 2>&1; then
    printf '%s binary not found: %s\n' "$label" "$bin" >&2
    exit 1
  fi
}

run_runtime_smoke() {
  label=$1
  php_bin=$2
  fpm_bin=$3

  printf '[INFO] apache-fpm rollback rehearsal: starting %s on http://%s:%s\n' "$label" "$HOST" "$HTTP_PORT"
  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_PREFIX="$DB_PREFIX" \
    PHP_BIN="$php_bin" PHP_FPM_BIN="$fpm_bin" HTTPD_BIN="$HTTPD_BIN" CURL_BIN="$CURL_BIN" \
    EPAY_APACHE_SMOKE_HOST="$HOST" EPAY_APACHE_SMOKE_PORT="$HTTP_PORT" EPAY_FPM_SMOKE_PORT="$FPM_PORT" \
    sh "$SMOKE_SCRIPT"; then
    printf '[OK] apache-fpm rollback rehearsal: %s runtime passed\n' "$label"
  else
    printf '[FAIL] apache-fpm rollback rehearsal: %s runtime failed\n' "$label" >&2
    exit 1
  fi
}

check_bin "PHP 8.4" "$PHP84_BIN"
check_bin "PHP 8.4 FPM" "$PHP84_FPM_BIN"
check_bin "PHP 7.4" "$PHP74_BIN"
check_bin "PHP 7.4 FPM" "$PHP74_FPM_BIN"
check_bin "httpd" "$HTTPD_BIN"
check_bin "curl" "$CURL_BIN"

run_runtime_smoke "pre-rollback PHP 8.4" "$PHP84_BIN" "$PHP84_FPM_BIN"
run_runtime_smoke "post-rollback PHP 7.4" "$PHP74_BIN" "$PHP74_FPM_BIN"

printf 'Apache/PHP-FPM rollback rehearsal passed: PHP 8.4 and PHP 7.4 both served the same Apache port %s sequentially.\n' "$HTTP_PORT"
