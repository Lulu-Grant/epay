#!/usr/bin/env sh
set -u

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
PHP_FPM_BIN=${PHP_FPM_BIN:-/opt/homebrew/opt/php@8.4/sbin/php-fpm}
PHP_BIN=${PHP_BIN:-/opt/homebrew/opt/php@8.4/bin/php}
HTTPD_BIN=${HTTPD_BIN:-/usr/sbin/httpd}
CURL_BIN=${CURL_BIN:-curl}
HOST=${EPAY_APACHE_SMOKE_HOST:-127.0.0.1}
HTTP_PORT=${EPAY_APACHE_SMOKE_PORT:-8620}
FPM_PORT=${EPAY_FPM_SMOKE_PORT:-9620}
FPM_USER=${EPAY_FPM_USER:-}
FPM_GROUP=${EPAY_FPM_GROUP:-}
REPEAT_COUNT=${EPAY_APACHE_REPEAT_COUNT:-1}
BASE_URL="http://$HOST:$HTTP_PORT"
DB_HOST=${EPAY_DB_HOST:-127.0.0.1}
DB_PORT=${EPAY_DB_PORT:-3306}
DB_SOCKET=${EPAY_DB_SOCKET:-}
DB_USER=${EPAY_DB_USER:-root}
DB_PASSWORD=${EPAY_DB_PASSWORD:-}
DB_PREFIX=${EPAY_DB_PREFIX:-pay}
DB_NAME=${EPAY_DB_NAME:-epay_php84_apache_$(date +%Y%m%d_%H%M%S)_$$}
APP_DB_USER=${EPAY_APP_DB_USER:-epayphp84fpm_$$}
APP_DB_PASSWORD=${EPAY_APP_DB_PASSWORD:-epay_php84_fpm_pw_$$}
WORK_DIR=$(mktemp -d "${TMPDIR:-/tmp}/epay-apache-fpm-smoke.XXXXXX") || exit 1
chmod 755 "$WORK_DIR"
APP_DIR="$WORK_DIR/app"
FPM_CONF="$WORK_DIR/php-fpm.conf"
FPM_LOG="$WORK_DIR/php-fpm.log"
FPM_PID_FILE="$WORK_DIR/php-fpm.pid"
PHP_ERROR_LOG="$WORK_DIR/php-errors.log"
HTTPD_CONF="$WORK_DIR/httpd.conf"
HTTPD_LOG="$WORK_DIR/httpd-error.log"
HTTPD_PID_FILE="$WORK_DIR/httpd.pid"
HTTPD_MUTEX_DIR="$WORK_DIR/httpd-mutex"
FPM_PID=""
HTTPD_PID=""
DB_CREATED=0
APP_DB_USER_CREATED=0
failures=0

cleanup() {
  trap - EXIT INT TERM
  if [ -n "$FPM_PID" ]; then
    kill -QUIT "$FPM_PID" >/dev/null 2>&1 || kill "$FPM_PID" >/dev/null 2>&1 || true
    wait "$FPM_PID" >/dev/null 2>&1 || true
  fi
  "$HTTPD_BIN" -f "$HTTPD_CONF" -k stop >/dev/null 2>&1 || true
  ps -ef | awk -v conf="$HTTPD_CONF" 'index($0, conf) && index($0, "httpd") && !index($0, "awk") {print $2}' |
    while IFS= read -r pid; do
      [ -n "$pid" ] && kill "$pid" >/dev/null 2>&1 || true
    done
  if [ "$DB_CREATED" -eq 1 ]; then
    EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
      EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
      "$PHP_BIN" -r '
        $name = getenv("EPAY_DB_NAME");
        if (!preg_match("/^[A-Za-z0-9_]+$/", $name)) {
            exit(1);
        }
        $socket = getenv("EPAY_DB_SOCKET");
        if ($socket !== false && $socket !== "") {
            $dsn = "mysql:unix_socket=".$socket.";charset=utf8mb4";
        } else {
            $dsn = "mysql:host=".getenv("EPAY_DB_HOST").";port=".getenv("EPAY_DB_PORT").";charset=utf8mb4";
        }
        $pass = getenv("EPAY_DB_PASSWORD");
        if ($pass === false) {
            $pass = "";
        }
        $pdo = new PDO($dsn, getenv("EPAY_DB_USER"), $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $pdo->exec("DROP DATABASE `".$name."`");
	  ' >/dev/null 2>&1 || true
  fi
  if [ "$APP_DB_USER_CREATED" -eq 1 ]; then
    EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
      EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_APP_DB_USER="$APP_DB_USER" \
      "$PHP_BIN" -r '
        $user = getenv("EPAY_APP_DB_USER");
        if (!preg_match("/^[A-Za-z0-9_]+$/", $user)) {
            exit(1);
        }
        $socket = getenv("EPAY_DB_SOCKET");
        if ($socket !== false && $socket !== "") {
            $dsn = "mysql:unix_socket=".$socket.";charset=utf8mb4";
        } else {
            $dsn = "mysql:host=".getenv("EPAY_DB_HOST").";port=".getenv("EPAY_DB_PORT").";charset=utf8mb4";
        }
        $pass = getenv("EPAY_DB_PASSWORD");
        if ($pass === false) {
            $pass = "";
        }
        $pdo = new PDO($dsn, getenv("EPAY_DB_USER"), $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $pdo->exec("DROP USER IF EXISTS ".$pdo->quote($user)."@".$pdo->quote("localhost"));
        $pdo->exec("DROP USER IF EXISTS ".$pdo->quote($user)."@".$pdo->quote("%"));
      ' >/dev/null 2>&1 || true
  fi
  rm -rf "$WORK_DIR"
}

trap cleanup EXIT INT TERM

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
  printf 'PHP binary not found: %s\n' "$PHP_BIN" >&2
  exit 1
fi

if ! command -v "$PHP_FPM_BIN" >/dev/null 2>&1; then
  printf 'php-fpm binary not found: %s\n' "$PHP_FPM_BIN" >&2
  exit 1
fi

if ! command -v "$HTTPD_BIN" >/dev/null 2>&1; then
  printf 'httpd binary not found: %s\n' "$HTTPD_BIN" >&2
  exit 1
fi

if ! command -v "$CURL_BIN" >/dev/null 2>&1; then
  printf 'curl binary not found: %s\n' "$CURL_BIN" >&2
  exit 1
fi

if [ -z "$FPM_USER" ]; then
  if [ "$(id -u)" -eq 0 ]; then
    if id www-data >/dev/null 2>&1; then
      FPM_USER=www-data
    elif id nobody >/dev/null 2>&1; then
      FPM_USER=nobody
    else
      printf 'Unable to determine non-root php-fpm pool user; set EPAY_FPM_USER.\n' >&2
      exit 1
    fi
  else
    FPM_USER=$(id -un)
  fi
fi

if [ -z "$FPM_GROUP" ]; then
  FPM_GROUP=$(id -gn "$FPM_USER" 2>/dev/null || printf '%s' "$FPM_USER")
fi

DB_CREATED=1
if ! EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
  EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
  EPAY_DB_PREFIX="$DB_PREFIX" EPAY_DB_KEEP=1 \
  "$PHP_BIN" "$ROOT_DIR/tools/php84/db-fixture-check.php"; then
  printf 'Database fixture bootstrap failed; aborting Apache/PHP-FPM smoke.\n' >&2
  exit 1
fi

APP_DB_USER_CREATED=1
if ! EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
  EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
  EPAY_APP_DB_USER="$APP_DB_USER" EPAY_APP_DB_PASSWORD="$APP_DB_PASSWORD" \
  "$PHP_BIN" -r '
    $db = getenv("EPAY_DB_NAME");
    $user = getenv("EPAY_APP_DB_USER");
    if (!preg_match("/^[A-Za-z0-9_]+$/", $db) || !preg_match("/^[A-Za-z0-9_]+$/", $user)) {
        fwrite(STDERR, "Unsafe database or user name\n");
        exit(1);
    }
    $socket = getenv("EPAY_DB_SOCKET");
    if ($socket !== false && $socket !== "") {
        $dsn = "mysql:unix_socket=".$socket.";charset=utf8mb4";
    } else {
        $dsn = "mysql:host=".getenv("EPAY_DB_HOST").";port=".getenv("EPAY_DB_PORT").";charset=utf8mb4";
    }
    $adminPass = getenv("EPAY_DB_PASSWORD");
    if ($adminPass === false) {
        $adminPass = "";
    }
    $appPass = getenv("EPAY_APP_DB_PASSWORD");
    $pdo = new PDO($dsn, getenv("EPAY_DB_USER"), $adminPass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    foreach (array("localhost", "%") as $host) {
        $pdo->exec("CREATE USER IF NOT EXISTS ".$pdo->quote($user)."@".$pdo->quote($host)." IDENTIFIED BY ".$pdo->quote($appPass));
        $pdo->exec("GRANT ALL PRIVILEGES ON `".$db."`.* TO ".$pdo->quote($user)."@".$pdo->quote($host));
    }
  '; then
  printf 'Temporary application database user creation failed; aborting Apache/PHP-FPM smoke.\n' >&2
  exit 1
fi

mkdir -p "$APP_DIR" "$HTTPD_MUTEX_DIR"
(cd "$ROOT_DIR" && tar --exclude './.git' -cf - .) | (cd "$APP_DIR" && tar -xf -)
mkdir -p "$APP_DIR/install"
: > "$APP_DIR/install/install.lock"
rm -f "$APP_DIR/admin/code.php"

EPAY_CONFIG_FILE="$APP_DIR/config.php" EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" \
  EPAY_DB_USER="$APP_DB_USER" EPAY_DB_PASSWORD="$APP_DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
  EPAY_DB_PREFIX="$DB_PREFIX" "$PHP_BIN" -r '
    $pass = getenv("EPAY_DB_PASSWORD");
    if ($pass === false) {
        $pass = "";
    }
    $dbconfig = array(
        "host" => getenv("EPAY_DB_HOST"),
        "port" => (int)getenv("EPAY_DB_PORT"),
        "user" => getenv("EPAY_DB_USER"),
        "pwd" => $pass,
        "dbname" => getenv("EPAY_DB_NAME"),
        "dbqz" => getenv("EPAY_DB_PREFIX"),
    );
    $content = "<?php\n/* Generated by tools/php84/apache-fpm-smoke.sh */\n\$dbconfig=" . var_export($dbconfig, true) . ";\n";
    file_put_contents(getenv("EPAY_CONFIG_FILE"), $content);
  '

EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
  EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
  EPAY_DB_PREFIX="$DB_PREFIX" EPAY_BASE_URL="$BASE_URL" "$PHP_BIN" -r '
    $prefix = getenv("EPAY_DB_PREFIX");
    $socket = getenv("EPAY_DB_SOCKET");
    if ($socket !== false && $socket !== "") {
        $dsn = "mysql:unix_socket=".$socket.";dbname=".getenv("EPAY_DB_NAME").";charset=utf8mb4";
    } else {
        $dsn = "mysql:host=".getenv("EPAY_DB_HOST").";port=".getenv("EPAY_DB_PORT").";dbname=".getenv("EPAY_DB_NAME").";charset=utf8mb4";
    }
    $pass = getenv("EPAY_DB_PASSWORD");
    if ($pass === false) {
        $pass = "";
    }
    $pdo = new PDO($dsn, getenv("EPAY_DB_USER"), $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $config = array(
        "cronkey" => "fixture-cron-key",
        "localurl" => rtrim(getenv("EPAY_BASE_URL"), "/")."/",
        "pay_maxmoney" => "0",
        "pay_minmoney" => "0"
    );
    $stmt = $pdo->prepare("REPLACE INTO `".$prefix."_config` (`k`, `v`) VALUES (?, ?)");
    foreach ($config as $key => $value) {
        $stmt->execute(array($key, $value));
    }
    $pdo->prepare("UPDATE `".$prefix."_cache` SET `v`=\"\" WHERE `k`=\"config\"")->execute();
  '

cat > "$FPM_CONF" <<EOF
[global]
pid = $FPM_PID_FILE
error_log = $FPM_LOG
daemonize = no

[www]
user = $FPM_USER
group = $FPM_GROUP
listen = $HOST:$FPM_PORT
pm = static
pm.max_children = 2
clear_env = no
catch_workers_output = yes
chdir = $APP_DIR
php_admin_flag[log_errors] = on
php_admin_value[error_log] = $PHP_ERROR_LOG
php_admin_value[display_errors] = 0
EOF

: > "$PHP_ERROR_LOG"
chown "$FPM_USER:$FPM_GROUP" "$PHP_ERROR_LOG" >/dev/null 2>&1 || true

cat > "$HTTPD_CONF" <<EOF
ServerRoot "$WORK_DIR"
PidFile "$HTTPD_PID_FILE"
ServerName localhost
Listen $HOST:$HTTP_PORT
Mutex file:$HTTPD_MUTEX_DIR default
LoadModule mpm_prefork_module /usr/libexec/apache2/mod_mpm_prefork.so
LoadModule unixd_module /usr/libexec/apache2/mod_unixd.so
LoadModule authz_core_module /usr/libexec/apache2/mod_authz_core.so
LoadModule authz_host_module /usr/libexec/apache2/mod_authz_host.so
LoadModule log_config_module /usr/libexec/apache2/mod_log_config.so
LoadModule mime_module /usr/libexec/apache2/mod_mime.so
LoadModule dir_module /usr/libexec/apache2/mod_dir.so
LoadModule alias_module /usr/libexec/apache2/mod_alias.so
LoadModule proxy_module /usr/libexec/apache2/mod_proxy.so
LoadModule proxy_fcgi_module /usr/libexec/apache2/mod_proxy_fcgi.so
LoadModule rewrite_module /usr/libexec/apache2/mod_rewrite.so
TypesConfig /private/etc/apache2/mime.types
ErrorLog "$HTTPD_LOG"
LogLevel warn
DocumentRoot "$APP_DIR"
DirectoryIndex index.php index.html

<Directory "$APP_DIR">
  Options FollowSymLinks
  AllowOverride None
  Require all granted
  RewriteEngine On
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteRule ^(.[a-zA-Z0-9\\-_]+).html$ index.php?mod=\$1 [QSA,PT,L]
  RewriteRule ^pay/(.*)$ pay.php?s=\$1 [QSA,PT,L]
  RewriteRule ^api/(.*)$ api.php?s=\$1 [QSA,PT,L]
  RewriteRule ^doc/(.[a-zA-Z0-9\\-_]+).html$ index.php?doc=\$1 [QSA,PT,L]
</Directory>

<FilesMatch \\.php$>
  SetHandler "proxy:fcgi://$HOST:$FPM_PORT"
</FilesMatch>
EOF

"$PHP_FPM_BIN" -y "$FPM_CONF" -F >/dev/null 2>&1 &
FPM_PID=$!

tries=0
while [ "$tries" -lt 30 ]; do
  if ! kill -0 "$FPM_PID" >/dev/null 2>&1; then
    printf 'php-fpm exited early.\n' >&2
    cat "$FPM_LOG" >&2
    exit 1
  fi
  if "$PHP_BIN" -r '$fp = @fsockopen($argv[1], (int)$argv[2], $errno, $errstr, 0.2); if ($fp) { fclose($fp); exit(0); } exit(1);' "$HOST" "$FPM_PORT"; then
    break
  fi
  tries=$((tries + 1))
  sleep 1
done

if [ "$tries" -ge 30 ]; then
  printf 'php-fpm did not become ready at %s:%s\n' "$HOST" "$FPM_PORT" >&2
  cat "$FPM_LOG" >&2
  exit 1
fi

if ! "$HTTPD_BIN" -f "$HTTPD_CONF" -k start >/dev/null 2>&1; then
  printf 'httpd failed to start.\n' >&2
  cat "$HTTPD_LOG" >&2
  exit 1
fi

tries=0
while [ "$tries" -lt 30 ]; do
  HTTPD_PID=$(ps -ef | awk -v conf="$HTTPD_CONF" 'index($0, conf) && index($0, "httpd") && !index($0, "awk") {print $2; exit}')
  if [ -n "$HTTPD_PID" ]; then
    break
  fi
  tries=$((tries + 1))
  sleep 1
done

if [ -z "$HTTPD_PID" ]; then
  printf 'httpd started but no process was found for config: %s\n' "$HTTPD_CONF" >&2
  cat "$HTTPD_LOG" >&2
  exit 1
fi

ready=0
tries=0
while [ "$tries" -lt 30 ]; do
  if ! kill -0 "$HTTPD_PID" >/dev/null 2>&1; then
    printf 'httpd exited early.\n' >&2
    cat "$HTTPD_LOG" >&2
    exit 1
  fi
  if "$CURL_BIN" -fsS "$BASE_URL/" >/dev/null 2>&1; then
    ready=1
    break
  fi
  tries=$((tries + 1))
  sleep 1
done

if [ "$ready" -ne 1 ]; then
  printf 'Apache did not become ready at %s\n' "$BASE_URL" >&2
  cat "$HTTPD_LOG" >&2
  exit 1
fi

check_json() {
  "$PHP_BIN" -r '
    $body = file_get_contents($argv[1]);
    json_decode($body);
    exit(json_last_error() === JSON_ERROR_NONE ? 0 : 1);
  ' "$1"
}

request() {
  name=$1
  path=$2
  expected_status=$3
  expected_body=$4
  expect_json=$5
  body="$WORK_DIR/$name.body"
  meta="$WORK_DIR/$name.meta"
  request_failed=0

  if ! "$CURL_BIN" -sS -L -o "$body" -w "%{http_code}\n" "$BASE_URL$path" >"$meta"; then
    printf '[FAIL] %s: curl failed\n' "$name"
    failures=$((failures + 1))
    return
  fi

  status=$(sed -n '1p' "$meta")
  if [ "$status" != "$expected_status" ]; then
    printf '[FAIL] %s: expected HTTP %s, got %s\n' "$name" "$expected_status" "$status"
    failures=$((failures + 1))
    request_failed=1
  fi

  if grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$body"; then
    printf '[FAIL] %s: response contains PHP error output\n' "$name"
    failures=$((failures + 1))
    request_failed=1
  fi

  if [ -n "$expected_body" ] && ! grep -Eq "$expected_body" "$body"; then
    printf '[FAIL] %s: expected body pattern not found: %s\n' "$name" "$expected_body"
    failures=$((failures + 1))
    request_failed=1
  fi

  if [ "$expect_json" = "json" ] && ! check_json "$body"; then
    printf '[FAIL] %s: response is not valid JSON\n' "$name"
    failures=$((failures + 1))
    request_failed=1
  fi

  if [ "$request_failed" -eq 0 ]; then
    printf '[OK] %s: %s\n' "$name" "$status"
  else
    printf '[DONE] %s: %s\n' "$name" "$status"
  fi
}

assert_runtime_alive() {
  label=$1
  if ! kill -0 "$FPM_PID" >/dev/null 2>&1; then
    printf '[FAIL] %s: php-fpm master exited\n' "$label"
    failures=$((failures + 1))
  fi
  if ! ps -ef | awk -v conf="$HTTPD_CONF" 'index($0, conf) && index($0, "httpd") && !index($0, "awk") {found=1} END {exit(found ? 0 : 1)}'; then
    printf '[FAIL] %s: httpd process exited\n' "$label"
    failures=$((failures + 1))
  fi
}

scan_runtime_logs() {
  label=$1
  log_failed=0

  if [ -s "$PHP_ERROR_LOG" ] && grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:|Uncaught' "$PHP_ERROR_LOG"; then
    printf '[FAIL] %s: PHP error log contains error output\n' "$label"
    log_failed=1
  fi
  if [ -s "$FPM_LOG" ] && grep -Eiq 'segmentation fault|child .* exited on signal|exited on signal|unable to fork|panic' "$FPM_LOG"; then
    printf '[FAIL] %s: PHP-FPM log contains worker failure output\n' "$label"
    log_failed=1
  fi
  if [ -s "$HTTPD_LOG" ] && grep -Eiq 'segmentation fault|child .* exited|proxy_fcgi:error|AH[0-9]+:.*(error|failed)' "$HTTPD_LOG"; then
    printf '[FAIL] %s: Apache log contains severe runtime output\n' "$label"
    log_failed=1
  fi

  if [ "$log_failed" -eq 0 ]; then
    printf '[OK] %s: runtime logs clean\n' "$label"
  else
    failures=$((failures + 1))
  fi
}

request home "/" "200" "彩虹易支付|易支付|支付" "no"
request api_no_act "/api.php" "200" "No Act|code" "json"
request mapi_missing_params "/mapi.php" "200" "未传入任何参数" "json"
request submit_missing_merchant "/submit.php" "200" "你还未配置支付接口商户|商户" "no"
request cron_valid "/cron.php?key=fixture-cron-key" "200" "" "no"
request friendly_api_unknown "/api/unknown" "200" "No Act|接口|不存在|code" "no"
request friendly_pay_missing "/pay/submit/EPAY_DOES_NOT_EXIST/" "200" "URL参数不符合规范|订单不存在|No such|不存在" "no"
assert_runtime_alive "apache_fpm_functional"

if [ "$REPEAT_COUNT" -gt 1 ]; then
  i=1
  while [ "$i" -le "$REPEAT_COUNT" ]; do
    request "stability_home_$i" "/" "200" "彩虹易支付|易支付|支付" "no"
    request "stability_api_$i" "/api.php" "200" "No Act|code" "json"
    request "stability_mapi_$i" "/mapi.php" "200" "未传入任何参数" "json"
    request "stability_cron_$i" "/cron.php?key=fixture-cron-key" "200" "" "no"
    request "stability_pay_$i" "/pay/submit/EPAY_DOES_NOT_EXIST/" "200" "URL参数不符合规范|订单不存在|No such|不存在" "no"
    assert_runtime_alive "apache_fpm_stability_$i"
    i=$((i + 1))
  done
  scan_runtime_logs "apache_fpm_stability"
  printf '[OK] apache_fpm_stability: repeated core requests completed count=%s\n' "$REPEAT_COUNT"
else
  scan_runtime_logs "apache_fpm_functional"
fi

if [ "$failures" -ne 0 ]; then
  printf '\nApache log tail:\n' >&2
  tail -n 80 "$HTTPD_LOG" >&2
  printf '\nPHP-FPM log tail:\n' >&2
  tail -n 80 "$FPM_LOG" >&2
  if [ -s "$PHP_ERROR_LOG" ]; then
    printf '\nPHP error log tail:\n' >&2
    tail -n 80 "$PHP_ERROR_LOG" >&2
  fi
  exit 1
fi

printf 'Apache/PHP-FPM smoke checks passed at %s using %s and %s repeat=%s\n' "$BASE_URL" "$HTTPD_BIN" "$PHP_FPM_BIN" "$REPEAT_COUNT"
