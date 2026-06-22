#!/usr/bin/env sh
set -u

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
PHP_BIN=${PHP_BIN:-php}
PHP_FPM_BIN=${PHP_FPM_BIN:-php-fpm}
NGINX_BIN=${NGINX_BIN:-nginx}
CURL_BIN=${CURL_BIN:-curl}
SERVER_MODE=${EPAY_INSTALL_SERVER_MODE:-builtin}
HOST=${EPAY_INSTALL_SMOKE_HOST:-127.0.0.1}
PORT=${EPAY_INSTALL_SMOKE_PORT:-8099}
FPM_PORT=${EPAY_INSTALL_FPM_PORT:-9099}
FPM_USER=${EPAY_FPM_USER:-}
FPM_GROUP=${EPAY_FPM_GROUP:-}
BASE_URL="http://$HOST:$PORT"
DB_HOST=${EPAY_DB_HOST:-127.0.0.1}
DB_PORT=${EPAY_DB_PORT:-3306}
DB_SOCKET=${EPAY_DB_SOCKET:-}
DB_USER=${EPAY_DB_USER:-root}
DB_PASSWORD=${EPAY_DB_PASSWORD:-}
DB_PREFIX=${EPAY_DB_PREFIX:-pay}
INSTALL_DB_NAME=${EPAY_INSTALL_DB_NAME:-epay_php84_install_$(date +%Y%m%d_%H%M%S)_$$}
UPGRADE_DB_NAME=${EPAY_UPGRADE_DB_NAME:-epay_php84_upgrade_$(date +%Y%m%d_%H%M%S)_$$}
APP_DB_USER=${EPAY_APP_DB_USER:-epayphp84iu_$$}
APP_DB_PASSWORD=${EPAY_APP_DB_PASSWORD:-epay_php84_install_pw_$$}
WORK_DIR=$(mktemp -d "${TMPDIR:-/tmp}/epay-install-smoke.XXXXXX") || exit 1
APP_DIR="$WORK_DIR/app"
SERVER_LOG="$WORK_DIR/server.log"
PHP_ERROR_LOG="$WORK_DIR/php-errors.log"
FPM_CONF="$WORK_DIR/php-fpm.conf"
FPM_LOG="$WORK_DIR/php-fpm.log"
FPM_PID_FILE="$WORK_DIR/php-fpm.pid"
NGINX_CONF="$WORK_DIR/nginx.conf"
NGINX_LOG="$WORK_DIR/nginx-error.log"
NGINX_PID_FILE="$WORK_DIR/nginx.pid"
SERVER_PID=""
FPM_PID=""
NGINX_PID=""
INSTALL_DB_CREATED=0
UPGRADE_DB_CREATED=0
APP_DB_USER_CREATED=0
failures=0

cleanup() {
  trap - EXIT INT TERM
  if [ -n "$SERVER_PID" ]; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
    wait "$SERVER_PID" >/dev/null 2>&1 || true
  fi
  if [ -n "$NGINX_PID" ]; then
    "$NGINX_BIN" -p "$WORK_DIR" -c "$NGINX_CONF" -s stop >/dev/null 2>&1 || true
    ps -ef | awk -v conf="$NGINX_CONF" 'index($0, conf) && index($0, "nginx") && !index($0, "awk") {print $2}' |
      while IFS= read -r pid; do
        [ -n "$pid" ] && kill "$pid" >/dev/null 2>&1 || true
      done
  fi
  if [ -n "$FPM_PID" ]; then
    kill -QUIT "$FPM_PID" >/dev/null 2>&1 || kill "$FPM_PID" >/dev/null 2>&1 || true
    wait "$FPM_PID" >/dev/null 2>&1 || true
  fi
  for db_name in "$INSTALL_DB_NAME" "$UPGRADE_DB_NAME"; do
    EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
      EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$db_name" \
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
        $pdo->exec("DROP DATABASE IF EXISTS `".$name."`");
      ' >/dev/null 2>&1 || true
  done
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

if ! command -v "$CURL_BIN" >/dev/null 2>&1; then
  printf 'curl binary not found: %s\n' "$CURL_BIN" >&2
  exit 1
fi

if [ "$SERVER_MODE" = "nginx-fpm" ]; then
  if ! command -v "$PHP_FPM_BIN" >/dev/null 2>&1; then
    printf 'php-fpm binary not found: %s\n' "$PHP_FPM_BIN" >&2
    exit 1
  fi
  if ! command -v "$NGINX_BIN" >/dev/null 2>&1; then
    printf 'nginx binary not found: %s\n' "$NGINX_BIN" >&2
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
elif [ "$SERVER_MODE" != "builtin" ]; then
  printf 'Unsupported EPAY_INSTALL_SERVER_MODE: %s\n' "$SERVER_MODE" >&2
  exit 1
fi

stop_server() {
  if [ -n "$SERVER_PID" ]; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
    wait "$SERVER_PID" >/dev/null 2>&1 || true
    SERVER_PID=""
  fi
  if [ -n "$NGINX_PID" ]; then
    "$NGINX_BIN" -p "$WORK_DIR" -c "$NGINX_CONF" -s stop >/dev/null 2>&1 || true
    ps -ef | awk -v conf="$NGINX_CONF" 'index($0, conf) && index($0, "nginx") && !index($0, "awk") {print $2}' |
      while IFS= read -r pid; do
        [ -n "$pid" ] && kill "$pid" >/dev/null 2>&1 || true
      done
    NGINX_PID=""
  fi
  if [ -n "$FPM_PID" ]; then
    kill -QUIT "$FPM_PID" >/dev/null 2>&1 || kill "$FPM_PID" >/dev/null 2>&1 || true
    wait "$FPM_PID" >/dev/null 2>&1 || true
    FPM_PID=""
  fi
}

start_builtin_server() {
  "$PHP_BIN" -d opcache.enable_cli=0 -S "$HOST:$PORT" -t "$APP_DIR" >>"$SERVER_LOG" 2>&1 &
  SERVER_PID=$!
}

start_nginx_fpm_server() {
  : > "$PHP_ERROR_LOG"
  chown "$FPM_USER:$FPM_GROUP" "$PHP_ERROR_LOG" >/dev/null 2>&1 || true
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
  cat > "$NGINX_CONF" <<EOF
pid $NGINX_PID_FILE;
error_log $NGINX_LOG warn;
events {
  worker_connections 32;
}
http {
  access_log off;
  include /etc/nginx/mime.types;
  default_type application/octet-stream;
  server {
    listen $HOST:$PORT;
    server_name localhost;
    root $APP_DIR;
    index index.php index.html;

    location / {
      try_files \$uri \$uri/ /index.php?\$query_string;
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
}

start_server() {
  if [ "$SERVER_MODE" = "nginx-fpm" ]; then
    start_nginx_fpm_server
  else
    start_builtin_server
  fi
}

wait_for_install_server() {
  context=$1
  ready=0
  tries=0
  while [ "$tries" -lt 30 ]; do
    if [ -n "$SERVER_PID" ] && ! kill -0 "$SERVER_PID" >/dev/null 2>&1; then
      printf 'PHP built-in server exited early%s.\n' "$context" >&2
      cat "$SERVER_LOG" >&2
      exit 1
    fi
    if [ -n "$NGINX_PID" ] && ! kill -0 "$NGINX_PID" >/dev/null 2>&1; then
      printf 'nginx exited early%s.\n' "$context" >&2
      [ -f "$NGINX_LOG" ] && cat "$NGINX_LOG" >&2
      exit 1
    fi
    if "$CURL_BIN" -fsS "$BASE_URL/install/" >/dev/null 2>&1; then
      ready=1
      break
    fi
    tries=$((tries + 1))
    sleep 1
  done
  if [ "$ready" -ne 1 ]; then
    printf 'Install server did not become ready%s at %s\n' "$context" "$BASE_URL" >&2
    [ -f "$SERVER_LOG" ] && cat "$SERVER_LOG" >&2
    [ -f "$NGINX_LOG" ] && cat "$NGINX_LOG" >&2
    [ -f "$FPM_LOG" ] && cat "$FPM_LOG" >&2
    exit 1
  fi
}

scan_runtime_logs() {
  if [ "$SERVER_MODE" != "nginx-fpm" ]; then
    return
  fi
  if [ -s "$PHP_ERROR_LOG" ] && grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:|Uncaught' "$PHP_ERROR_LOG"; then
    printf '[FAIL] install_nginx_fpm_logs: PHP error log contains error output\n'
    failures=$((failures + 1))
  fi
  if [ -s "$FPM_LOG" ] && grep -Eiq 'segmentation fault|child .* exited on signal|exited on signal|unable to fork|panic' "$FPM_LOG"; then
    printf '[FAIL] install_nginx_fpm_logs: PHP-FPM log contains worker failure output\n'
    failures=$((failures + 1))
  fi
  if [ -s "$NGINX_LOG" ] && grep -Eiq 'segmentation fault|worker process .* exited|upstream prematurely closed|recv\\(\\) failed|connect\\(\\) failed|connect\\(\\) to .* failed|\\[crit\\]|\\[alert\\]|\\[emerg\\]' "$NGINX_LOG"; then
    printf '[FAIL] install_nginx_fpm_logs: Nginx log contains severe runtime output\n'
    failures=$((failures + 1))
  fi
}

EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
  EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" \
  EPAY_INSTALL_DB_NAME="$INSTALL_DB_NAME" EPAY_UPGRADE_DB_NAME="$UPGRADE_DB_NAME" \
  EPAY_APP_DB_USER="$APP_DB_USER" EPAY_APP_DB_PASSWORD="$APP_DB_PASSWORD" \
  "$PHP_BIN" -r '
    $dbs = array(getenv("EPAY_INSTALL_DB_NAME"), getenv("EPAY_UPGRADE_DB_NAME"));
    $user = getenv("EPAY_APP_DB_USER");
    foreach ($dbs as $db) {
        if (!preg_match("/^[A-Za-z0-9_]+$/", $db)) {
            fwrite(STDERR, "Unsafe database name\n");
            exit(1);
        }
    }
    if (!preg_match("/^[A-Za-z0-9_]+$/", $user)) {
        fwrite(STDERR, "Unsafe database user\n");
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
    $appPass = getenv("EPAY_APP_DB_PASSWORD");
    $pdo = new PDO($dsn, getenv("EPAY_DB_USER"), $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    foreach ($dbs as $db) {
        $pdo->exec("CREATE DATABASE `".$db."` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    }
    foreach (array("localhost", "%") as $host) {
        $pdo->exec("CREATE USER IF NOT EXISTS ".$pdo->quote($user)."@".$pdo->quote($host)." IDENTIFIED BY ".$pdo->quote($appPass));
        foreach ($dbs as $db) {
            $pdo->exec("GRANT ALL PRIVILEGES ON `".$db."`.* TO ".$pdo->quote($user)."@".$pdo->quote($host));
        }
    }
  '
INSTALL_DB_CREATED=1
UPGRADE_DB_CREATED=1
APP_DB_USER_CREATED=1

mkdir -p "$APP_DIR"
(cd "$ROOT_DIR" && tar --exclude './.git' -cf - .) | (cd "$APP_DIR" && tar -xf -)
rm -f "$APP_DIR/install/install.lock"
rm -f "$APP_DIR/admin/code.php"
chmod 755 "$WORK_DIR"
if [ "$SERVER_MODE" = "nginx-fpm" ]; then
  chown -R "$FPM_USER:$FPM_GROUP" "$APP_DIR" >/dev/null 2>&1 || true
fi

start_server
wait_for_install_server ""

body_has_no_php_errors() {
  ! grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$1"
}

expect_body() {
  name=$1
  body=$2
  pattern=$3
  if body_has_no_php_errors "$body" && grep -Eq "$pattern" "$body"; then
    printf '[OK] %s\n' "$name"
  else
    printf '[FAIL] %s: expected body pattern %s\n' "$name" "$pattern"
    printf '[BODY] %s: ' "$name"
    "$PHP_BIN" -r '
      $body = file_get_contents($argv[1]);
      $body = preg_replace("/\s+/u", " ", strip_tags($body));
      echo mb_substr($body, 0, 300), PHP_EOL;
    ' "$body"
    failures=$((failures + 1))
  fi
}

install_home_body="$WORK_DIR/install_home.body"
"$CURL_BIN" -sS -o "$install_home_body" "$BASE_URL/install/"
expect_body install_home_loads "$install_home_body" "安装环境检测|PHP版本>=7.4"

install_bad_db_body="$WORK_DIR/install_bad_db.body"
"$CURL_BIN" -sS -X POST \
  --data-urlencode "host=$DB_HOST" \
  --data-urlencode "port=$DB_PORT" \
  --data-urlencode "user=$APP_DB_USER" \
  --data-urlencode "pwd=definitely-wrong-password" \
  --data-urlencode "database=$INSTALL_DB_NAME" \
  --data-urlencode "dbqz=$DB_PREFIX" \
  -o "$install_bad_db_body" "$BASE_URL/install/?step=3"
expect_body install_bad_database_password "$install_bad_db_body" "连接数据库失败|数据库用户名或密码填写错误"

install_config_body="$WORK_DIR/install_config.body"
"$CURL_BIN" -sS -X POST \
  --data-urlencode "host=$DB_HOST" \
  --data-urlencode "port=$DB_PORT" \
  --data-urlencode "user=$APP_DB_USER" \
  --data-urlencode "pwd=$APP_DB_PASSWORD" \
  --data-urlencode "database=$INSTALL_DB_NAME" \
  --data-urlencode "dbqz=$DB_PREFIX" \
  -o "$install_config_body" "$BASE_URL/install/?step=3"
expect_body install_config_saved "$install_config_body" "数据库配置文件保存成功|立即安装数据表"

install_done_body="$WORK_DIR/install_done.body"
"$CURL_BIN" -sS -o "$install_done_body" "$BASE_URL/install/?step=4"
expect_body install_tables_created "$install_done_body" "安装完成|系统已成功安装完毕"

if [ -f "$APP_DIR/install/install.lock" ]; then
  printf '[OK] install_lock_created\n'
else
  printf '[FAIL] install_lock_created: install.lock missing\n'
  failures=$((failures + 1))
fi

EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
  EPAY_DB_USER="$APP_DB_USER" EPAY_DB_PASSWORD="$APP_DB_PASSWORD" EPAY_DB_NAME="$INSTALL_DB_NAME" \
  EPAY_DB_PREFIX="$DB_PREFIX" "$PHP_BIN" -r '
    $prefix = getenv("EPAY_DB_PREFIX");
    $socket = getenv("EPAY_DB_SOCKET");
    if ($socket !== false && $socket !== "") {
        $dsn = "mysql:unix_socket=".$socket.";dbname=".getenv("EPAY_DB_NAME").";charset=utf8mb4";
    } else {
        $dsn = "mysql:host=".getenv("EPAY_DB_HOST").";port=".getenv("EPAY_DB_PORT").";dbname=".getenv("EPAY_DB_NAME").";charset=utf8mb4";
    }
    $pdo = new PDO($dsn, getenv("EPAY_DB_USER"), getenv("EPAY_DB_PASSWORD"), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $version = $pdo->query("SELECT `v` FROM `".$prefix."_config` WHERE `k`=\"version\"")->fetchColumn();
    $admin = $pdo->query("SELECT `v` FROM `".$prefix."_config` WHERE `k`=\"admin_user\"")->fetchColumn();
    if ((string)$version !== "2038" || $admin !== "admin") {
        exit(1);
    }
  '
if [ "$?" -eq 0 ]; then
  printf '[OK] install_database_version_and_admin\n'
else
  printf '[FAIL] install_database_version_and_admin\n'
  failures=$((failures + 1))
fi

admin_cookie="$WORK_DIR/install_admin.cookie"
admin_login_body="$WORK_DIR/install_admin_login.body"
if "$CURL_BIN" -sS -c "$admin_cookie" -b "$admin_cookie" -e "$BASE_URL/admin/login.php" \
  -X POST -d "username=admin&password=123456&code=" \
  -o "$admin_login_body" "$BASE_URL/admin/login.php?act=login" &&
  "$PHP_BIN" -r '
    $data = json_decode(file_get_contents($argv[1]), true);
    exit(is_array($data) && isset($data["code"]) && (string)$data["code"] === "0" ? 0 : 1);
  ' "$admin_login_body"; then
  printf '[OK] install_default_admin_login\n'
else
  printf '[FAIL] install_default_admin_login\n'
  failures=$((failures + 1))
fi

install_locked_body="$WORK_DIR/install_locked.body"
"$CURL_BIN" -sS -o "$install_locked_body" "$BASE_URL/install/"
expect_body install_locked_after_success "$install_locked_body" "已经成功安装|install.lock"

rm -f "$APP_DIR/install/install.lock"
missing_lock_body="$WORK_DIR/install_missing_lock_runtime.body"
"$CURL_BIN" -sS -o "$missing_lock_body" "$BASE_URL/"
expect_body install_missing_lock_blocks_runtime "$missing_lock_body" "检测到无 install.lock|为了您站点安全"
: > "$APP_DIR/install/install.lock"

EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
  EPAY_DB_USER="$APP_DB_USER" EPAY_DB_PASSWORD="$APP_DB_PASSWORD" EPAY_DB_NAME="$UPGRADE_DB_NAME" \
  EPAY_DB_PREFIX="$DB_PREFIX" EPAY_INSTALL_SQL="$APP_DIR/install/install.sql" "$PHP_BIN" -r '
    $prefix = getenv("EPAY_DB_PREFIX");
    if (!preg_match("/^[A-Za-z0-9_]+$/", $prefix)) {
        fwrite(STDERR, "Unsafe prefix\n");
        exit(1);
    }
    $socket = getenv("EPAY_DB_SOCKET");
    if ($socket !== false && $socket !== "") {
        $dsn = "mysql:unix_socket=".$socket.";dbname=".getenv("EPAY_DB_NAME").";charset=utf8mb4";
    } else {
        $dsn = "mysql:host=".getenv("EPAY_DB_HOST").";port=".getenv("EPAY_DB_PORT").";dbname=".getenv("EPAY_DB_NAME").";charset=utf8mb4";
    }
    $pdo = new PDO($dsn, getenv("EPAY_DB_USER"), getenv("EPAY_DB_PASSWORD"), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $pdo->exec("set sql_mode = \"\"");
    $pdo->exec("set names utf8");
    $sqls = explode(";", file_get_contents(getenv("EPAY_INSTALL_SQL")));
    foreach ($sqls as $sql) {
        $sql = trim(str_replace("pre_", $prefix."_", $sql));
        if ($sql !== "") {
            $pdo->exec($sql);
        }
    }
    $pdo->prepare("UPDATE `".$prefix."_config` SET `v`=? WHERE `k`=\"version\"")->execute(array("2037"));
    $pdo->prepare("INSERT INTO `".$prefix."_user` (`uid`, `gid`, `key`, `money`, `account`, `username`, `email`, `phone`, `addtime`, `pay`, `settle`, `refund`, `transfer`, `keylogin`, `status`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?)")
        ->execute(array(1000, 0, md5("php84-upgrade-fixture"), "88.66", "upgrade-fixture@example.com", "upgrade-user", "upgrade-fixture@example.com", "13800138088", 1, 1, 1, 1, 1, 1));
    $pdo->prepare("INSERT INTO `".$prefix."_channel` (`type`, `plugin`, `name`, `rate`, `status`) VALUES (?, ?, ?, ?, ?)")
        ->execute(array(1, "epay", "PHP84 upgrade channel", "100.00", 1));
    $channelId = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `".$prefix."_order` (`trade_no`, `out_trade_no`, `uid`, `type`, `channel`, `name`, `money`, `realmoney`, `getmoney`, `notify_url`, `return_url`, `addtime`, `date`, `status`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), CURDATE(), ?)")
        ->execute(array("2026062200000000001", "upgrade-preserve-order", 1000, 1, $channelId, "Upgrade preserved order", "12.34", "12.34", "12.34", "http://127.0.0.1/notify", "http://127.0.0.1/return", 0));
  '
if [ "$?" -eq 0 ]; then
  printf '[OK] upgrade_fixture_prepared\n'
else
  printf '[FAIL] upgrade_fixture_prepared\n'
  failures=$((failures + 1))
fi

EPAY_CONFIG_FILE="$APP_DIR/config.php" EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" \
  EPAY_DB_USER="$APP_DB_USER" EPAY_DB_PASSWORD="$APP_DB_PASSWORD" EPAY_DB_NAME="$UPGRADE_DB_NAME" \
  EPAY_DB_PREFIX="$DB_PREFIX" "$PHP_BIN" -r '
    $dbconfig = array(
        "host" => getenv("EPAY_DB_HOST"),
        "port" => (int)getenv("EPAY_DB_PORT"),
        "user" => getenv("EPAY_DB_USER"),
        "pwd" => getenv("EPAY_DB_PASSWORD"),
        "dbname" => getenv("EPAY_DB_NAME"),
        "dbqz" => getenv("EPAY_DB_PREFIX"),
    );
    file_put_contents(getenv("EPAY_CONFIG_FILE"), "<?php\n\$dbconfig=".var_export($dbconfig, true).";\n");
  '

stop_server
start_server
wait_for_install_server " after upgrade config switch"

upgrade_body="$WORK_DIR/upgrade.body"
"$CURL_BIN" -sS -o "$upgrade_body" "$BASE_URL/install/update.php"
expect_body upgrade_runs_from_low_version "$upgrade_body" "成功执行SQL语句|点此返回首页"

EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
  EPAY_DB_USER="$APP_DB_USER" EPAY_DB_PASSWORD="$APP_DB_PASSWORD" EPAY_DB_NAME="$UPGRADE_DB_NAME" \
  EPAY_DB_PREFIX="$DB_PREFIX" "$PHP_BIN" -r '
    $prefix = getenv("EPAY_DB_PREFIX");
    $socket = getenv("EPAY_DB_SOCKET");
    if ($socket !== false && $socket !== "") {
        $dsn = "mysql:unix_socket=".$socket.";dbname=".getenv("EPAY_DB_NAME").";charset=utf8mb4";
    } else {
        $dsn = "mysql:host=".getenv("EPAY_DB_HOST").";port=".getenv("EPAY_DB_PORT").";dbname=".getenv("EPAY_DB_NAME").";charset=utf8mb4";
    }
    $pdo = new PDO($dsn, getenv("EPAY_DB_USER"), getenv("EPAY_DB_PASSWORD"), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $version = $pdo->query("SELECT `v` FROM `".$prefix."_config` WHERE `k`=\"version\"")->fetchColumn();
    $user = $pdo->query("SELECT `username` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
    $order = $pdo->query("SELECT `name` FROM `".$prefix."_order` WHERE `out_trade_no`=\"upgrade-preserve-order\"")->fetchColumn();
    $channel = $pdo->query("SELECT `name` FROM `".$prefix."_channel` WHERE `name`=\"PHP84 upgrade channel\"")->fetchColumn();
    if ((string)$version !== "2038" || $user !== "upgrade-user" || $order !== "Upgrade preserved order" || $channel !== "PHP84 upgrade channel") {
        fwrite(STDERR, "version=".$version." user=".$user." order=".$order." channel=".$channel."\n");
        exit(1);
    }
  '
if [ "$?" -eq 0 ]; then
  printf '[OK] upgrade_version_and_data_preserved\n'
else
  printf '[FAIL] upgrade_version_and_data_preserved\n'
  failures=$((failures + 1))
fi

upgrade_repeat_body="$WORK_DIR/upgrade_repeat.body"
"$CURL_BIN" -sS -o "$upgrade_repeat_body" "$BASE_URL/install/update.php"
expect_body upgrade_repeat_guard "$upgrade_repeat_body" "已经升级到最新版本"
scan_runtime_logs

if [ "$failures" -ne 0 ]; then
  printf 'Install/upgrade smoke finished with %s failure(s).\n' "$failures" >&2
  cat "$SERVER_LOG" >&2
  [ -f "$NGINX_LOG" ] && cat "$NGINX_LOG" >&2
  [ -f "$FPM_LOG" ] && cat "$FPM_LOG" >&2
  exit 1
fi

printf 'Install/upgrade smoke passed using server mode: %s.\n' "$SERVER_MODE"
