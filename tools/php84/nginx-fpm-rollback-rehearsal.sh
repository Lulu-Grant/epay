#!/usr/bin/env sh
set -u

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
SMOKE_SCRIPT="$ROOT_DIR/tools/php84/nginx-fpm-smoke.sh"

PHP84_BIN=${PHP84_BIN:-/opt/homebrew/opt/php@8.4/bin/php}
PHP84_FPM_BIN=${PHP84_FPM_BIN:-/opt/homebrew/opt/php@8.4/sbin/php-fpm}
PHP74_BIN=${PHP74_BIN:-/opt/homebrew/opt/php@7.4/bin/php}
PHP74_FPM_BIN=${PHP74_FPM_BIN:-/opt/homebrew/opt/php@7.4/sbin/php-fpm}
NGINX_BIN=${NGINX_BIN:-nginx}
CURL_BIN=${CURL_BIN:-curl}
HOST=${EPAY_ROLLBACK_NGINX_HOST:-127.0.0.1}
HTTP_PORT=${EPAY_ROLLBACK_NGINX_PORT:-8640}
FPM_PORT=${EPAY_ROLLBACK_FPM_PORT:-9640}
REPEAT_COUNT=${EPAY_ROLLBACK_NGINX_REPEAT_COUNT:-1}
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

if ! [ "$REPEAT_COUNT" -ge 1 ] 2>/dev/null; then
  printf 'EPAY_ROLLBACK_NGINX_REPEAT_COUNT must be a positive integer, got: %s\n' "$REPEAT_COUNT" >&2
  exit 1
fi

run_runtime_smoke() {
  label=$1
  php_bin=$2
  fpm_bin=$3

  printf '[INFO] nginx-fpm rollback rehearsal: starting %s on http://%s:%s\n' "$label" "$HOST" "$HTTP_PORT"
  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_PREFIX="$DB_PREFIX" \
    PHP_BIN="$php_bin" PHP_FPM_BIN="$fpm_bin" NGINX_BIN="$NGINX_BIN" CURL_BIN="$CURL_BIN" \
    EPAY_NGINX_SMOKE_HOST="$HOST" EPAY_NGINX_SMOKE_PORT="$HTTP_PORT" EPAY_FPM_SMOKE_PORT="$FPM_PORT" \
    EPAY_NGINX_REPEAT_COUNT="$REPEAT_COUNT" \
    sh "$SMOKE_SCRIPT"; then
    printf '[OK] nginx-fpm rollback rehearsal: %s runtime passed\n' "$label"
  else
    printf '[FAIL] nginx-fpm rollback rehearsal: %s runtime failed\n' "$label" >&2
    exit 1
  fi
}

check_bin "PHP 8.4" "$PHP84_BIN"
check_bin "PHP 8.4 FPM" "$PHP84_FPM_BIN"
check_bin "PHP 7.4" "$PHP74_BIN"
check_bin "PHP 7.4 FPM" "$PHP74_FPM_BIN"
check_bin "nginx" "$NGINX_BIN"
check_bin "curl" "$CURL_BIN"

run_runtime_smoke "pre-rollback PHP 8.4" "$PHP84_BIN" "$PHP84_FPM_BIN"
run_runtime_smoke "post-rollback PHP 7.4" "$PHP74_BIN" "$PHP74_FPM_BIN"

printf 'Nginx/PHP-FPM rollback rehearsal passed: PHP 8.4 and PHP 7.4 both served the same Nginx port %s sequentially repeat=%s.\n' "$HTTP_PORT" "$REPEAT_COUNT"
