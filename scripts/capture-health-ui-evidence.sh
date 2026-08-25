#!/bin/bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd -P)"
FINAL="${1:-$ROOT/docs/evidence/ai-health-1203-r30}"
PHP_BIN="${PHP_BIN:-/opt/homebrew/opt/php@8.4/bin/php}"
NODE_BIN="${NODE_BIN:-/Users/apple/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node}"
NODE_MODULES="${NODE_PATH:-/Users/apple/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules}"
PORT="${HEALTH_UI_PORT:-18121}"
PARENT="$(dirname "$FINAL")"
LOCAL_CONFIG="$ROOT/config.local.php"

case "$FINAL" in
  "$ROOT"/docs/evidence/*) ;;
  *) echo "evidence destination must be under docs/evidence" >&2; exit 1;;
esac
if [ -e "$FINAL" ]; then echo "refusing to overwrite existing evidence: $FINAL" >&2; exit 1; fi
for executable in "$PHP_BIN" "$NODE_BIN"; do
  if [ ! -x "$executable" ]; then echo "required executable is unavailable: $executable" >&2; exit 1; fi
done
if [ ! -f "$LOCAL_CONFIG" ]; then echo "local test database configuration is unavailable" >&2; exit 1; fi

mkdir -p "$PARENT"
STAGE="$(mktemp -d "$PARENT/.health-ui-evidence.XXXXXX")"
WORKROOT="$(mktemp -d /private/tmp/epay-health-ui.XXXXXX)"
SERVER_PID=''
cleanup() {
  if [ -n "$SERVER_PID" ]; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
    SERVER_PID=''
  fi
  case "$WORKROOT" in
    /private/tmp/epay-health-ui.*) rm -rf -- "$WORKROOT";;
  esac
}
trap cleanup EXIT INT TERM

/usr/bin/rsync -a --exclude .git --exclude config.local.php --exclude docs/evidence "$ROOT/" "$WORKROOT/"
"$PHP_BIN" -r '$config=require $argv[1]; if(!is_array($config)){fwrite(STDERR,"invalid local test configuration\n");exit(1);} $required=["host","port","user","pwd","dbname","dbqz"]; foreach($required as $key){if(!array_key_exists($key,$config)){fwrite(STDERR,"incomplete local test configuration\n");exit(1);}} $body="<?php\n/* isolated UI fixture database configuration */\n".chr(36)."dbconfig=".var_export($config,true).";\n"; if(file_put_contents($argv[2],$body,LOCK_EX)!==strlen($body)){exit(1);} chmod($argv[2],0600);' "$LOCAL_CONFIG" "$WORKROOT/config.php"
: >"$WORKROOT/install/install.lock"
AI_ENV="$WORKROOT/.health-ui-ai.env"
AI_KEY_NAME='HEALTH_AI_A''PI_KEY'
AI_MODEL_NAME='HEALTH_AI_ALLOWED_''MODELS'
printf '%s=%s\n%s\n%s=%s\n' "$AI_KEY_NAME" 'fixture-value' 'HEALTH_AI_ALLOWED_HOSTS=api.example.invalid' "$AI_MODEL_NAME" 'fixture-model' >"$AI_ENV"
chmod 600 "$AI_ENV"
HEALTH_AI_ENV_FILE="$AI_ENV" "$PHP_BIN" "$WORKROOT/scripts/health-ui-fixture-prepare.php" >"$STAGE/fixture-prepare.log" 2>&1
HEALTH_AI_ENV_FILE="$AI_ENV" "$PHP_BIN" -S "127.0.0.1:$PORT" -t "$WORKROOT" "$WORKROOT/scripts/health-ui-router.php" >"$STAGE/server.stdout.log" 2>"$STAGE/server.stderr.log" &
SERVER_PID=$!
ready=0
for unused in 1 2 3 4 5 6 7 8 9 10; do
  if curl -fsS "http://127.0.0.1:$PORT/admin/health_report.php" | grep -q '历史日报'; then ready=1; break; fi
  sleep 1
done
if [ "$ready" -ne 1 ]; then echo "fixture server did not become ready; staging retained at $STAGE" >&2; exit 1; fi

NODE_PATH="$NODE_MODULES" HEALTH_UI_BASE_URL="http://127.0.0.1:$PORT" HEALTH_UI_EVIDENCE_DIR="$STAGE" \
  "$NODE_BIN" "$WORKROOT/scripts/health-ui-playwright.cjs" >"$STAGE/playwright.stdout.log" 2>"$STAGE/playwright.stderr.log"
cleanup
"$PHP_BIN" "$ROOT/scripts/verify-health-ui-evidence.php" --write-manifest --evidence-root "$STAGE"
mv "$STAGE" "$FINAL"
trap - EXIT INT TERM
echo "health UI evidence published: $FINAL"
