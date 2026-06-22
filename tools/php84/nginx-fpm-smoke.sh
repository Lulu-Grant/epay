#!/usr/bin/env sh
set -u

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
PHP_FPM_BIN=${PHP_FPM_BIN:-/opt/homebrew/opt/php@8.4/sbin/php-fpm}
PHP_BIN=${PHP_BIN:-/opt/homebrew/opt/php@8.4/bin/php}
NGINX_BIN=${NGINX_BIN:-nginx}
CURL_BIN=${CURL_BIN:-curl}
NODE_BIN=${NODE_BIN:-node}
NPM_BIN=${NPM_BIN:-npm}
EPAY_NGINX_BROWSER_SMOKE=${EPAY_NGINX_BROWSER_SMOKE:-0}
EPAY_PLAYWRIGHT_VERSION=${EPAY_PLAYWRIGHT_VERSION:-1.61.0}
HOST=${EPAY_NGINX_SMOKE_HOST:-127.0.0.1}
HTTP_PORT=${EPAY_NGINX_SMOKE_PORT:-8630}
FPM_PORT=${EPAY_FPM_SMOKE_PORT:-9630}
FPM_USER=${EPAY_FPM_USER:-}
FPM_GROUP=${EPAY_FPM_GROUP:-}
REPEAT_COUNT=${EPAY_NGINX_REPEAT_COUNT:-1}
BASE_URL="http://$HOST:$HTTP_PORT"
DB_HOST=${EPAY_DB_HOST:-127.0.0.1}
DB_PORT=${EPAY_DB_PORT:-3306}
DB_SOCKET=${EPAY_DB_SOCKET:-}
DB_USER=${EPAY_DB_USER:-root}
DB_PASSWORD=${EPAY_DB_PASSWORD:-}
DB_PREFIX=${EPAY_DB_PREFIX:-pay}
DB_NAME=${EPAY_DB_NAME:-epay_php84_nginx_$(date +%Y%m%d_%H%M%S)_$$}
APP_DB_USER=${EPAY_APP_DB_USER:-epayphp84ngx_$$}
APP_DB_PASSWORD=${EPAY_APP_DB_PASSWORD:-epay_php84_nginx_pw_$$}
WORK_DIR=$(mktemp -d "${TMPDIR:-/tmp}/epay-nginx-fpm-smoke.XXXXXX") || exit 1
chmod 755 "$WORK_DIR"
APP_DIR="$WORK_DIR/app"
FPM_CONF="$WORK_DIR/php-fpm.conf"
FPM_LOG="$WORK_DIR/php-fpm.log"
FPM_PID_FILE="$WORK_DIR/php-fpm.pid"
PHP_ERROR_LOG="$WORK_DIR/php-errors.log"
NGINX_CONF="$WORK_DIR/nginx.conf"
NGINX_LOG="$WORK_DIR/nginx-error.log"
NGINX_PID_FILE="$WORK_DIR/nginx.pid"
FPM_PID=""
NGINX_PID=""
DB_CREATED=0
APP_DB_USER_CREATED=0
failures=0

cleanup() {
  trap - EXIT INT TERM
  "$NGINX_BIN" -p "$WORK_DIR" -c "$NGINX_CONF" -s stop >/dev/null 2>&1 || true
  ps -ef | awk -v conf="$NGINX_CONF" 'index($0, conf) && index($0, "nginx") && !index($0, "awk") {print $2}' |
    while IFS= read -r pid; do
      [ -n "$pid" ] && kill "$pid" >/dev/null 2>&1 || true
    done
  if [ -n "$FPM_PID" ]; then
    kill -QUIT "$FPM_PID" >/dev/null 2>&1 || kill "$FPM_PID" >/dev/null 2>&1 || true
    wait "$FPM_PID" >/dev/null 2>&1 || true
  fi
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

if ! command -v "$NGINX_BIN" >/dev/null 2>&1; then
  printf 'nginx binary not found: %s\n' "$NGINX_BIN" >&2
  printf 'Set NGINX_BIN=/path/to/nginx to run Nginx/PHP-FPM smoke checks.\n' >&2
  exit 77
fi

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
  printf 'PHP binary not found: %s\n' "$PHP_BIN" >&2
  exit 1
fi

if ! command -v "$PHP_FPM_BIN" >/dev/null 2>&1; then
  printf 'php-fpm binary not found: %s\n' "$PHP_FPM_BIN" >&2
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

if ! [ "$REPEAT_COUNT" -ge 1 ] 2>/dev/null; then
  printf 'EPAY_NGINX_REPEAT_COUNT must be a positive integer, got: %s\n' "$REPEAT_COUNT" >&2
  exit 1
fi

DB_CREATED=1
if ! EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
  EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
  EPAY_DB_PREFIX="$DB_PREFIX" EPAY_DB_KEEP=1 \
  "$PHP_BIN" "$ROOT_DIR/tools/php84/db-fixture-check.php"; then
  printf 'Database fixture bootstrap failed; aborting Nginx/PHP-FPM smoke.\n' >&2
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
  printf 'Temporary application database user creation failed; aborting Nginx/PHP-FPM smoke.\n' >&2
  exit 1
fi

mkdir -p "$APP_DIR"
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
    $content = "<?php\n/* Generated by tools/php84/nginx-fpm-smoke.sh */\n\$dbconfig=" . var_export($dbconfig, true) . ";\n";
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
        "captcha_open_login" => "0",
        "close_keylogin" => "0",
        "cronkey" => "fixture-cron-key",
        "localurl" => rtrim(getenv("EPAY_BASE_URL"), "/")."/",
        "pay_maxmoney" => "0",
        "pay_minmoney" => "0"
    );
    $stmt = $pdo->prepare("REPLACE INTO `".$prefix."_config` (`k`, `v`) VALUES (?, ?)");
    foreach ($config as $key => $value) {
        $stmt->execute(array($key, $value));
    }
    $merchantKey = md5("php84-fixture");
    $uidStmt = $pdo->prepare("SELECT `uid` FROM `".$prefix."_user` WHERE `key`=? ORDER BY `uid` ASC LIMIT 1");
    $uidStmt->execute(array($merchantKey));
    $uid = $uidStmt->fetchColumn();
    if ($uid === false) {
        throw new RuntimeException("Fixture merchant not found for Nginx browser smoke");
    }
    $fixturePwd = md5(md5("php84-password") . md5("1277180438".$uid));
    $pdo->prepare("UPDATE `".$prefix."_user` SET `account`=?, `username`=?, `email`=?, `phone`=?, `pwd`=?, `status`=1, `pay`=1, `settle`=1, `refund`=1, `transfer`=1, `keylogin`=1 WHERE `uid`=?")
        ->execute(array("fixture@example.com", "fixture-user", "fixture@example.com", "13800138000", $fixturePwd, $uid));
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

cat > "$NGINX_CONF" <<EOF
pid $NGINX_PID_FILE;
error_log $NGINX_LOG warn;
events {
  worker_connections 32;
}
http {
  access_log off;
  types {
    text/html html htm;
    text/css css;
    application/javascript js;
    image/png png;
    image/jpeg jpg jpeg;
    image/gif gif;
  }
  default_type application/octet-stream;
  server {
    listen $HOST:$HTTP_PORT;
    server_name localhost;
    root $APP_DIR;
    index index.php index.html;

    location / {
      if (!-e \$request_filename) {
        rewrite ^/(.[a-zA-Z0-9\\-_]+).html$ /index.php?mod=\$1 last;
      }
      rewrite ^/pay/(.*)$ /pay.php?s=\$1 last;
      rewrite ^/api/(.*)$ /api.php?s=\$1 last;
      rewrite ^/doc/(.[a-zA-Z0-9\\-_]+).html$ /index.php?doc=\$1 last;
    }

    location ^~ /plugins {
      deny all;
    }

    location ^~ /includes {
      deny all;
    }

    location ~ \\.php$ {
      fastcgi_pass $HOST:$FPM_PORT;
      fastcgi_index index.php;
      fastcgi_param QUERY_STRING \$query_string;
      fastcgi_param REQUEST_METHOD \$request_method;
      fastcgi_param CONTENT_TYPE \$content_type;
      fastcgi_param CONTENT_LENGTH \$content_length;
      fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
      fastcgi_param SCRIPT_NAME \$fastcgi_script_name;
      fastcgi_param REQUEST_URI \$request_uri;
      fastcgi_param DOCUMENT_URI \$document_uri;
      fastcgi_param DOCUMENT_ROOT \$document_root;
      fastcgi_param SERVER_PROTOCOL \$server_protocol;
      fastcgi_param REQUEST_SCHEME \$scheme;
      fastcgi_param HTTPS \$https if_not_empty;
      fastcgi_param GATEWAY_INTERFACE CGI/1.1;
      fastcgi_param SERVER_SOFTWARE nginx;
      fastcgi_param REMOTE_ADDR \$remote_addr;
      fastcgi_param REMOTE_PORT \$remote_port;
      fastcgi_param SERVER_ADDR \$server_addr;
      fastcgi_param SERVER_PORT \$server_port;
      fastcgi_param SERVER_NAME \$server_name;
      fastcgi_param REDIRECT_STATUS 200;
    }
  }
}
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

if ! "$NGINX_BIN" -p "$WORK_DIR" -c "$NGINX_CONF" >/dev/null 2>&1; then
  printf 'nginx failed to start.\n' >&2
  [ -f "$NGINX_LOG" ] && cat "$NGINX_LOG" >&2
  exit 1
fi

tries=0
while [ "$tries" -lt 30 ]; do
  if [ -f "$NGINX_PID_FILE" ]; then
    NGINX_PID=$(sed -n '1p' "$NGINX_PID_FILE")
    break
  fi
  tries=$((tries + 1))
  sleep 1
done

if [ -z "$NGINX_PID" ]; then
  printf 'nginx started but did not write pid file: %s\n' "$NGINX_PID_FILE" >&2
  [ -f "$NGINX_LOG" ] && cat "$NGINX_LOG" >&2
  exit 1
fi

ready=0
tries=0
while [ "$tries" -lt 30 ]; do
  if ! kill -0 "$NGINX_PID" >/dev/null 2>&1; then
    printf 'nginx exited early.\n' >&2
    [ -f "$NGINX_LOG" ] && cat "$NGINX_LOG" >&2
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
  printf 'Nginx did not become ready at %s\n' "$BASE_URL" >&2
  [ -f "$NGINX_LOG" ] && cat "$NGINX_LOG" >&2
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

  if ! "$CURL_BIN" -sS -L -o "$body" -w "%{http_code}\n%{time_total}\n" "$BASE_URL$path" >"$meta"; then
    printf '[FAIL] %s: curl failed\n' "$name"
    failures=$((failures + 1))
    return
  fi

  status=$(sed -n '1p' "$meta")
  elapsed=$(sed -n '2p' "$meta")
  elapsed_ms=$("$PHP_BIN" -r 'printf("%.2f", ((float)$argv[1]) * 1000);' "$elapsed")
  printf '[PERF] %s http=%s ms=%s\n' "$name" "$status" "$elapsed_ms"

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
  if ! ps -ef | awk -v conf="$NGINX_CONF" 'index($0, conf) && index($0, "nginx") && !index($0, "awk") {found=1} END {exit(found ? 0 : 1)}'; then
    printf '[FAIL] %s: nginx process exited\n' "$label"
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
  if [ -s "$NGINX_LOG" ] && grep -Eiq 'segmentation fault|worker process .* exited|upstream prematurely closed|recv\\(\\) failed|connect\\(\\) failed|connect\\(\\) to .* failed|\\[error\\]|\\[crit\\]|\\[alert\\]|\\[emerg\\]' "$NGINX_LOG"; then
    printf '[FAIL] %s: Nginx log contains severe runtime output\n' "$label"
    log_failed=1
  fi

  if [ "$log_failed" -eq 0 ]; then
    printf '[OK] %s: runtime logs clean\n' "$label"
  else
    failures=$((failures + 1))
  fi
}

browser_nginx_smoke() {
  if [ "$EPAY_NGINX_BROWSER_SMOKE" != "1" ]; then
    return
  fi
  if ! command -v "$NODE_BIN" >/dev/null 2>&1; then
    printf '[FAIL] nginx_browser_smoke: node binary not found: %s\n' "$NODE_BIN"
    failures=$((failures + 1))
    return
  fi
  if ! command -v "$NPM_BIN" >/dev/null 2>&1; then
    printf '[FAIL] nginx_browser_smoke: npm binary not found: %s\n' "$NPM_BIN"
    failures=$((failures + 1))
    return
  fi

  browser_dir="$WORK_DIR/browser-smoke"
  mkdir -p "$browser_dir"
  cat > "$browser_dir/package.json" <<EOF
{"private":true,"type":"commonjs","dependencies":{"playwright":"$EPAY_PLAYWRIGHT_VERSION"}}
EOF
  if ! "$NPM_BIN" --prefix "$browser_dir" install --silent --no-audit --no-fund; then
    printf '[FAIL] nginx_browser_smoke: unable to install playwright %s in temporary directory\n' "$EPAY_PLAYWRIGHT_VERSION"
    failures=$((failures + 1))
    return
  fi
  if ! "$NPM_BIN" --prefix "$browser_dir" exec -- playwright install chromium >/dev/null; then
    printf '[FAIL] nginx_browser_smoke: unable to install Playwright Chromium browser\n'
    failures=$((failures + 1))
    return
  fi

  cat > "$browser_dir/nginx-browser-smoke.cjs" <<'NODE'
const { chromium } = require('playwright');

const baseUrl = process.env.EPAY_BROWSER_BASE_URL;
const desktopChromeUserAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

async function expectText(page, pattern, label) {
  const body = await page.locator('body').innerText({ timeout: 10000 });
  if (!pattern.test(body)) {
    throw new Error(`${label}: expected body to match ${pattern}, got: ${body.slice(0, 500)}`);
  }
}

async function postForm(page, url, data) {
  return page.evaluate(async ({ url, data }) => {
    const body = new URLSearchParams(data);
    const response = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body,
      credentials: 'same-origin',
    });
    const text = await response.text();
    return { status: response.status, text };
  }, { url, data });
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const adminContext = await browser.newContext({ userAgent: desktopChromeUserAgent });
  const page = await adminContext.newPage();

  await page.goto(`${baseUrl}/`, { waitUntil: 'domcontentloaded' });
  await expectText(page, /支付|易支付|聚合/i, 'home page');

  await page.goto(`${baseUrl}/admin/login.php`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('input[name="user"]', { timeout: 10000 });
  await page.waitForSelector('input[name="pass"]', { timeout: 10000 });
  await expectText(page, /管理员登录/, 'admin login page');

  const adminLogin = await postForm(page, `${baseUrl}/admin/login.php?act=login`, {
    username: 'admin',
    password: '123456',
    code: '',
  });
  const adminData = JSON.parse(adminLogin.text);
  if (adminLogin.status !== 200 || String(adminData.code) !== '0') {
    throw new Error(`admin login failed: status=${adminLogin.status} body=${adminLogin.text}`);
  }
  const adminCookies = await adminContext.cookies(`${baseUrl}/admin/`);
  if (!adminCookies.some((cookie) => cookie.name === 'admin_token' && cookie.value)) {
    throw new Error('admin login did not issue admin_token cookie');
  }
  await page.goto(`${baseUrl}/admin/`, { waitUntil: 'domcontentloaded' });
  await expectText(page, /后台管理首页|管理员信息/, 'admin dashboard');
  await adminContext.close();

  const userContext = await browser.newContext({ userAgent: desktopChromeUserAgent });
  const userPage = await userContext.newPage();
  await userPage.goto(`${baseUrl}/`, { waitUntil: 'domcontentloaded' });
  const userLoginPage = await userPage.evaluate(async () => {
    const response = await fetch('/user/login.php', {
      credentials: 'same-origin',
    });
    const text = await response.text();
    return { status: response.status, text };
  });
  if (userLoginPage.status !== 200 || !/商户信息|密码登录|账号/.test(userLoginPage.text)) {
    throw new Error(`user login page failed: status=${userLoginPage.status} body=${userLoginPage.text.slice(0, 500)}`);
  }
  const csrfMatch = userLoginPage.text.match(/name=["']csrf_token["'][^>]*value=["']([^"']+)["']/);
  if (!csrfMatch) {
    throw new Error(`user login csrf token not found in fetched page: ${userLoginPage.text.slice(0, 500)}`);
  }
  const userLogin = await postForm(userPage, `${baseUrl}/user/ajax.php?act=login`, {
    type: '1',
    user: 'fixture@example.com',
    pass: 'php84-password',
    csrf_token: csrfMatch[1],
  });
  const userData = JSON.parse(userLogin.text);
  if (userLogin.status !== 200 || String(userData.code) !== '0') {
    throw new Error(`user login failed: status=${userLogin.status} body=${userLogin.text}`);
  }
  const userCookies = await userContext.cookies(`${baseUrl}/user/`);
  if (!userCookies.some((cookie) => cookie.name === 'user_token' && cookie.value)) {
    throw new Error('user login did not issue user_token cookie');
  }
  await userPage.goto(`${baseUrl}/user/`, { waitUntil: 'domcontentloaded' });
  await expectText(userPage, /用户中心|商户信息|欢迎回来/, 'user dashboard');

  await userContext.close();
  await browser.close();
  console.log('[OK] nginx_browser_smoke: home, admin login/dashboard, and user password login/dashboard passed');
})().catch((error) => {
  console.error(error && error.stack ? error.stack : error);
  process.exit(1);
});
NODE

  if EPAY_BROWSER_BASE_URL="$BASE_URL" "$NODE_BIN" "$browser_dir/nginx-browser-smoke.cjs"; then
    :
  else
    printf '[FAIL] nginx_browser_smoke: Playwright checks failed\n'
    failures=$((failures + 1))
  fi
}

request home "/" "200" "聚合支付|易支付|支付" "no"
request api_no_act "/api.php" "200" "No Act|code" "json"
request mapi_missing_params "/mapi.php" "200" "未传入任何参数" "json"
request submit_missing_merchant "/submit.php" "200" "你还未配置支付接口商户|商户" "no"
request cron_valid "/cron.php?key=fixture-cron-key" "200" "" "no"
request friendly_api_unknown "/api/unknown" "200" "No Act|URL Error|接口|不存在|code" "no"
request friendly_pay_missing "/pay/submit/EPAY_DOES_NOT_EXIST/" "200" "URL参数不符合规范|订单不存在|No such|不存在" "no"
request protected_includes "/includes/common.php" "403" "" "no"
request protected_plugins "/plugins/" "403" "" "no"
assert_runtime_alive "nginx_fpm_functional"
browser_nginx_smoke

if [ "$REPEAT_COUNT" -gt 1 ]; then
  i=1
  while [ "$i" -le "$REPEAT_COUNT" ]; do
    request "stability_home_$i" "/" "200" "聚合支付|易支付|支付" "no"
    request "stability_api_$i" "/api.php" "200" "No Act|code" "json"
    request "stability_mapi_$i" "/mapi.php" "200" "未传入任何参数" "json"
    request "stability_cron_$i" "/cron.php?key=fixture-cron-key" "200" "" "no"
    request "stability_pay_$i" "/pay/submit/EPAY_DOES_NOT_EXIST/" "200" "URL参数不符合规范|订单不存在|No such|不存在" "no"
    assert_runtime_alive "nginx_fpm_stability_$i"
    i=$((i + 1))
  done
  scan_runtime_logs "nginx_fpm_stability"
  printf '[OK] nginx_fpm_stability: repeated core requests completed count=%s\n' "$REPEAT_COUNT"
fi

if [ "$failures" -ne 0 ]; then
  printf '\nNginx log tail:\n' >&2
  [ -f "$NGINX_LOG" ] && tail -n 80 "$NGINX_LOG" >&2
  printf '\nPHP-FPM log tail:\n' >&2
  tail -n 80 "$FPM_LOG" >&2
  exit 1
fi

printf 'Nginx/PHP-FPM smoke checks passed at %s using %s and %s repeat=%s\n' "$BASE_URL" "$NGINX_BIN" "$PHP_FPM_BIN" "$REPEAT_COUNT"
