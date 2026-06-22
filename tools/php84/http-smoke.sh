#!/usr/bin/env sh
set -u

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
PHP_BIN=${PHP_BIN:-php}
CURL_BIN=${CURL_BIN:-curl}
HOST=${EPAY_SMOKE_HOST:-127.0.0.1}
PORT=${EPAY_SMOKE_PORT:-8094}
BASE_URL="http://$HOST:$PORT"
TMP_DIR=$(mktemp -d "${TMPDIR:-/tmp}/epay-http-smoke.XXXXXX") || exit 1
SERVER_LOG="$TMP_DIR/server.log"
SERVER_PID=""
failures=0

cleanup() {
  if [ -n "$SERVER_PID" ]; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
    wait "$SERVER_PID" >/dev/null 2>&1 || true
  fi
  rm -rf "$TMP_DIR"
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

"$PHP_BIN" -S "$HOST:$PORT" -t "$ROOT_DIR" >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!

ready=0
tries=0
while [ "$tries" -lt 30 ]; do
  if ! kill -0 "$SERVER_PID" >/dev/null 2>&1; then
    printf 'PHP built-in server exited early.\n' >&2
    cat "$SERVER_LOG" >&2
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
  printf 'PHP built-in server did not become ready at %s\n' "$BASE_URL" >&2
  cat "$SERVER_LOG" >&2
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
  expected_type=$4
  expected_body=$5
  expect_json=$6
  body="$TMP_DIR/$name.body"
  meta="$TMP_DIR/$name.meta"
  request_failed=0

  if ! "$CURL_BIN" -sS -L -o "$body" -w "%{http_code}\n%{content_type}\n" "$BASE_URL$path" >"$meta"; then
    printf '[FAIL] %s: curl failed\n' "$name"
    failures=$((failures + 1))
    return
  fi

  status=$(sed -n '1p' "$meta")
  content_type=$(sed -n '2p' "$meta")

  if [ "$status" != "$expected_status" ]; then
    printf '[FAIL] %s: expected HTTP %s, got %s\n' "$name" "$expected_status" "$status"
    failures=$((failures + 1))
    request_failed=1
  fi

  case "$content_type" in
    *"$expected_type"*) ;;
    *)
      printf '[FAIL] %s: expected Content-Type containing %s, got %s\n' "$name" "$expected_type" "$content_type"
      failures=$((failures + 1))
      request_failed=1
      ;;
  esac

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
    printf '[OK] %s: %s %s\n' "$name" "$status" "$content_type"
  else
    printf '[DONE] %s: %s %s\n' "$name" "$status" "$content_type"
  fi
}

request install "/install/" "200" "text/html" "安装环境检测|安装程序|install\\.lock" "no"
request update "/install/update.php" "200" "text/html" "成功执行SQL|升级|安装|返回首页" "no"
request submit_missing_merchant "/submit.php" "200" "text/html" "你还未配置支付接口商户" "no"
request mapi_missing_params "/mapi.php" "200" "application/json" "未传入任何参数" "json"

if [ "$failures" -ne 0 ]; then
  printf '\nServer log tail:\n' >&2
  tail -n 80 "$SERVER_LOG" >&2
  exit 1
fi

printf 'HTTP smoke checks passed at %s using %s\n' "$BASE_URL" "$PHP_BIN"
