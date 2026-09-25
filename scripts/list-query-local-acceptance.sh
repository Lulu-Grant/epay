#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP_ROOT="$(mktemp -d /tmp/epay-list-acceptance.XXXXXX)"
DB_PORT="${LIST_TEST_DB_PORT:-33081}"
SITE_DIR="$TMP_ROOT/site"
DB_PID=""
PHP_PID=""
WEB_PORT="${LIST_TEST_WEB_PORT:-18083}"
MYSQL=(mysql --no-defaults --protocol=SOCKET --socket="$TMP_ROOT/mysql.sock" -uroot)
cleanup(){
  if [[ -n "$PHP_PID" ]]; then kill -- "-$PHP_PID" 2>/dev/null || true; wait "$PHP_PID" 2>/dev/null || true; fi
  mysqladmin --no-defaults --protocol=SOCKET --socket="$TMP_ROOT/mysql.sock" -uroot shutdown >/dev/null 2>&1 || true
  if [[ -n "$DB_PID" ]]; then wait "$DB_PID" 2>/dev/null || true; fi
  if [[ -n "${LIST_TEST_ARTIFACT_DIR:-}" ]]; then
    mkdir -p "$LIST_TEST_ARTIFACT_DIR"
    cp "$TMP_ROOT/report.json" "$TMP_ROOT/php.log" "$TMP_ROOT/mysql.err" "$LIST_TEST_ARTIFACT_DIR/" 2>/dev/null || true
    cp -a "$TMP_ROOT/browser" "$LIST_TEST_ARTIFACT_DIR/" 2>/dev/null || true
  fi
  case "$TMP_ROOT" in /tmp/epay-list-acceptance.*) rm -rf -- "$TMP_ROOT";; esac
}
trap cleanup EXIT
for cmd in php mysql mysqladmin mariadb-install-db mariadbd rsync curl setsid; do command -v "$cmd" >/dev/null; done
[[ "$DB_PORT" =~ ^[0-9]+$ && "$WEB_PORT" =~ ^[0-9]+$ && "$DB_PORT" -ge 33080 && "$DB_PORT" -le 65535 && "$WEB_PORT" -ge 1024 && "$WEB_PORT" -le 65535 ]] || exit 1
mkdir -m 700 "$SITE_DIR" "$TMP_ROOT/data" "$TMP_ROOT/cache"
rsync -a --exclude=.git "$ROOT_DIR/" "$SITE_DIR/"
mariadb-install-db --no-defaults --auth-root-authentication-method=normal --skip-test-db --datadir="$TMP_ROOT/data" >/dev/null
mariadbd --no-defaults --default-time-zone=+08:00 --datadir="$TMP_ROOT/data" --socket="$TMP_ROOT/mysql.sock" --pid-file="$TMP_ROOT/mysql.pid" --bind-address=127.0.0.1 --port="$DB_PORT" --log-error="$TMP_ROOT/mysql.err" &
DB_PID=$!
for _ in $(seq 1 60); do "${MYSQL[@]}" -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 0.25; done
tcp_data=$(mysql --no-defaults --protocol=TCP -h127.0.0.1 -P"$DB_PORT" -uroot -N -B -e 'SELECT @@datadir')
[[ "$tcp_data" == "$TMP_ROOT/data/" ]] || exit 1
"${MYSQL[@]}" -e 'CREATE DATABASE epay_list_acceptance DEFAULT CHARACTER SET utf8mb4;'
"${MYSQL[@]}" -e "CREATE USER 'listtest'@'127.0.0.1' IDENTIFIED BY 'fixture-only'; GRANT ALL ON epay_list_acceptance.* TO 'listtest'@'127.0.0.1';"
sed 's/pre_/pay_/g' "$SITE_DIR/install/install.sql" | "${MYSQL[@]}" epay_list_acceptance
sed 's/pre_/pay_/g' "$SITE_DIR/install/addon_shop.sql" | "${MYSQL[@]}" epay_list_acceptance
cat > "$SITE_DIR/config.php" <<PHP
<?php
\$dbconfig = ['host'=>'127.0.0.1','port'=>$DB_PORT,'user'=>'listtest','pwd'=>'fixture-only','dbname'=>'epay_list_acceptance','dbqz'=>'pay'];
PHP
touch "$SITE_DIR/install/install.lock"
mv "$SITE_DIR/admin/code.php" "$SITE_DIR/admin/code.php.disabled"
export EPAY_LIST_TEST_ISOLATED=1 EPAY_LIST_TEST_CACHE="$TMP_ROOT/cache" EPAY_LIST_CACHE_CONFIG="$TMP_ROOT/control.json" EPAY_LIST_TEST_REPORT="$TMP_ROOT/report.json"
php "$SITE_DIR/scripts/list-query-db-regression.php"
if [[ "${LIST_TEST_EXISTING_DB_CHECKS:-0}" == 1 ]]; then
  export EPAY_DB_HOST=127.0.0.1 EPAY_DB_PORT="$DB_PORT" EPAY_DB_USER=root EPAY_DB_PASSWORD=''
  EPAY_LIST_CACHE_CONFIG="$TMP_ROOT/disabled-control.json" php "$SITE_DIR/tools/php84/shop-repair-check.php"
  php "$SITE_DIR/tools/php84/db-fixture-check.php"
  php "$SITE_DIR/tools/php84/rollback-rehearsal-check.php"
  bash "$SITE_DIR/tools/php84/installed-http-smoke.sh"
  bash "$SITE_DIR/tools/php84/install-upgrade-smoke.sh"
fi
setsid env PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$WEB_PORT" -t "$SITE_DIR" > "$TMP_ROOT/php.log" 2>&1 &
PHP_PID=$!
for _ in $(seq 1 60); do curl -fsS "http://127.0.0.1:$WEB_PORT/user/login.php" >/dev/null && break; sleep 0.25; done
if [[ "${LIST_TEST_REQUIRE_BROWSER:-0}" == 1 ]]; then
  LIST_TEST_BASE_URL="http://127.0.0.1:$WEB_PORT" LIST_TEST_REPORT_DIR="$TMP_ROOT/browser" \
    NODE_PATH="${LIST_TEST_NODE_PATH:-}" "${LIST_TEST_NODE_BIN:-node}" "$SITE_DIR/scripts/list-query-browser-acceptance.cjs"
fi
if grep -E 'Fatal error|Uncaught|Parse error' "$TMP_ROOT/php.log"; then exit 1; fi
printf 'LIST_QUERY_ACCEPTANCE_OK\n'
