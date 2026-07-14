#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB_PORT="${SHOP_TEST_WEB_PORT:-18082}"
DB_PORT="${SHOP_TEST_DB_PORT:-33080}"
TMP_ROOT="$(mktemp -d /tmp/epay-shop-shadow-acceptance.XXXXXX)"
SITE_DIR="$TMP_ROOT/site"
DB_DIR="$TMP_ROOT/mysql"
COOKIE_FILE="$TMP_ROOT/cookies.txt"
SCREEN_DIR="$TMP_ROOT/screens"
PHP_PID=""
DB_SESSION_PID=""

MYSQL_ROOT=(mysql --no-defaults -h127.0.0.1 -P"$DB_PORT" -uroot)
MYSQL_APP=(mysql --no-defaults -h127.0.0.1 -P"$DB_PORT" -uepaytest -pepaypass epay_acceptance -N -B)

pass(){ printf 'PASS %s\n' "$1"; }
fail(){ printf 'FAIL %s\n' "$1" >&2; exit 1; }
contains(){ local hay="$1" needle="$2" label="$3"; [[ "$hay" == *"$needle"* ]] && pass "$label" || fail "$label"; }
not_contains(){ local hay="$1" needle="$2" label="$3"; [[ "$hay" != *"$needle"* ]] && pass "$label" || fail "$label"; }

cleanup(){
  if [[ -n "$PHP_PID" ]] && kill -0 "$PHP_PID" 2>/dev/null; then
    kill "$PHP_PID" 2>/dev/null || true
    wait "$PHP_PID" 2>/dev/null || true
  fi
  mysqladmin --no-defaults -h127.0.0.1 -P"$DB_PORT" -uroot shutdown >/dev/null 2>&1 || true
  if [[ -n "$DB_SESSION_PID" ]]; then
    wait "$DB_SESSION_PID" 2>/dev/null || true
  fi
  pkill -f "$TMP_ROOT" >/dev/null 2>&1 || true
  if [[ "${KEEP_SHOP_TEST_TMP:-0}" == "1" ]]; then
    printf 'KEEP_SHOP_TEST_TMP=1, kept temp dir: %s\n' "$TMP_ROOT"
  else
    rm -rf "$TMP_ROOT"
  fi
}
trap cleanup EXIT

for cmd in rsync php curl mysql mysqladmin mariadb-install-db mariadbd; do
  command -v "$cmd" >/dev/null 2>&1 || fail "missing command: $cmd"
done

mkdir -p "$SITE_DIR" "$DB_DIR" "$SCREEN_DIR"
rsync -a --exclude='.git' "$ROOT_DIR"/ "$SITE_DIR"/
mariadb-install-db --no-defaults --auth-root-authentication-method=normal --skip-test-db --datadir="$DB_DIR/data" >/dev/null
mariadbd --no-defaults --datadir="$DB_DIR/data" --socket="$DB_DIR/mysql.sock" --pid-file="$DB_DIR/mysql.pid" --bind-address=127.0.0.1 --port="$DB_PORT" --log-error="$DB_DIR/mysql.err" &
DB_SESSION_PID=$!
for _ in $(seq 1 60); do
  "${MYSQL_ROOT[@]}" -e 'SELECT 1' >/dev/null 2>&1 && break
  sleep 0.25
done
"${MYSQL_ROOT[@]}" -e 'SELECT 1' >/dev/null 2>&1 || fail "temporary MariaDB did not start"

"${MYSQL_ROOT[@]}" <<SQL
CREATE DATABASE epay_acceptance DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS 'epaytest'@'127.0.0.1' IDENTIFIED BY 'epaypass';
GRANT ALL PRIVILEGES ON epay_acceptance.* TO 'epaytest'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
sed 's/pre_/pay_/g' "$SITE_DIR/install/install.sql" | "${MYSQL_ROOT[@]}" epay_acceptance
sed 's/pre_/pay_/g' "$SITE_DIR/install/addon_shop.sql" | "${MYSQL_ROOT[@]}" epay_acceptance
sed 's/pre_/pay_/g' "$SITE_DIR/install/addon_shop.sql" | "${MYSQL_ROOT[@]}" epay_acceptance

perl -0pi -e "s/'host' => 'localhost'/'host' => '127.0.0.1'/; s/'port' => 3306/'port' => $DB_PORT/; s/'user' => ''/'user' => 'epaytest'/; s/'pwd' => ''/'pwd' => 'epaypass'/; s/'dbname' => ''/'dbname' => 'epay_acceptance'/;" "$SITE_DIR/config.php"
touch "$SITE_DIR/install/install.lock"
mv "$SITE_DIR/admin/code.php" "$SITE_DIR/admin/code.php.disabled"

"${MYSQL_APP[@]}" <<'SQL'
INSERT INTO pay_user (uid,gid,`key`,money,email,phone,addtime,pay,settle,keylogin,apply,status,mode)
VALUES
(1000,0,'includedkey',0.00,'included@example.test','13800000000',NOW(),1,1,1,0,1,0),
(1001,0,'excludedkey',0.00,'excluded@example.test','13900000000',NOW(),1,1,1,0,1,0)
ON DUPLICATE KEY UPDATE pay=1,status=1,mode=0;
INSERT INTO pay_channel (id,mode,type,plugin,name,rate,status,apptype,daystatus,paymin,paymax)
VALUES (1,0,1,'alipay','Shadow acceptance channel',100.00,1,'3',0,1,3000)
ON DUPLICATE KEY UPDATE status=1,apptype='3',daystatus=0,rate=100.00,plugin='alipay',type=1,paymin=1,paymax=3000;
UPDATE pay_config SET v='0' WHERE k='captcha_open';
UPDATE pay_cache SET v='' WHERE k='config';
SQL

php -S "127.0.0.1:$WEB_PORT" -t "$SITE_DIR" >"$TMP_ROOT/php.log" 2>&1 &
PHP_PID=$!
BASE="http://127.0.0.1:$WEB_PORT"
for _ in $(seq 1 60); do
  curl -sS "$BASE/shopping.php" >/dev/null 2>&1 && break
  sleep 0.25
done
curl -sS "$BASE/shopping.php" >/dev/null 2>&1 || fail "PHP server did not start"
cli_http_status=$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/scripts/shop-shadow-reconcile.php")
[[ "$cli_http_status" == "404" ]] || fail "reconciliation script rejects HTTP access"
pass "reconciliation script rejects HTTP access"

home_headers=$(curl -sS -D - -o "$TMP_ROOT/home-body.html" "$BASE/")
contains "$home_headers" "302 Found" "system homepage redirects"
contains "$home_headers" "Location: /shopping.php" "system homepage still exposes catalog"
doc_headers=$(curl -sS -D - -o "$TMP_ROOT/doc-body.html" "$BASE/index.php?doc=index")
contains "$doc_headers" "200 OK" "developer documentation route remains available"
not_contains "$doc_headers" "Location: /shopping.php" "developer documentation route is not redirected"

login=$(curl -sS -c "$COOKIE_FILE" -b "$COOKIE_FILE" -e "$BASE/admin/login.php" -d 'username=admin&password=123456&code=' "$BASE/admin/login.php?act=login")
contains "$login" '"code":0' "admin login succeeds"
config_page=$(curl -sS -c "$COOKIE_FILE" -b "$COOKIE_FILE" "$BASE/admin/shop_config.php")
csrf=$(printf '%s' "$config_page" | perl -ne 'if(/name="csrf_token" value="([^"]+)"/){print $1; exit}')
[[ -n "$csrf" ]] || fail "csrf token extracted"
contains "$config_page" "无感影子订单" "config exposes shadow mode"
contains "$config_page" "排除商户 UID" "config uses exclusion model"
not_contains "$config_page" "收款商户 UID" "central pay uid remains removed"

invalid_excluded=$(curl -sS -c "$COOKIE_FILE" -b "$COOKIE_FILE" -e "$BASE/admin/shop_config.php" \
  --data-urlencode "csrf_token=$csrf" --data-urlencode 'shop_status=1' --data-urlencode 'shop_flow_mode=shadow' \
  --data-urlencode 'shop_name=Shadow Shop' --data-urlencode 'shop_excluded_uids=1001,bad' \
  "$BASE/admin/ajax_shop.php?act=saveConfig")
contains "$invalid_excluded" "只能填写正整数" "invalid exclusion uid rejected"
save_config=$(curl -sS -c "$COOKIE_FILE" -b "$COOKIE_FILE" -e "$BASE/admin/shop_config.php" \
  --data-urlencode "csrf_token=$csrf" --data-urlencode 'shop_status=1' --data-urlencode 'shop_flow_mode=shadow' \
  --data-urlencode 'shop_name=Shadow Shop' --data-urlencode 'shop_desc=Invisible merchant order projection' \
  --data-urlencode $'shop_excluded_uids=1001\n1002,1001' --data-urlencode 'shop_query_verify=1' \
  "$BASE/admin/ajax_shop.php?act=saveConfig")
contains "$save_config" '"code":0' "shadow config saved"
flow_config=$("${MYSQL_APP[@]}" -e "SELECT CONCAT((SELECT v FROM pay_shop_config WHERE k='shop_flow_mode'),'|',(SELECT v FROM pay_shop_config WHERE k='shop_excluded_uids'),'|',(SELECT IF(v<>'',1,0) FROM pay_shop_config WHERE k='shop_shadow_started_at'))")
[[ "$flow_config" == "shadow|1001,1002|1" ]] || fail "shadow mode start time and exclusions persisted"
pass "shadow mode start time and exclusions persisted"

make_sign(){
  local pid="$1" key="$2" out="$3" name="$4" money="$5" type="$6" notify="$7" return_url="$8" param="$9"
  cd "$SITE_DIR"
  PID="$pid" KEY="$key" OUT="$out" NAME="$name" MONEY="$money" TYPE="$type" NOTIFY="$notify" RETURN_URL="$return_url" PARAM="$param" php <<'PHP'
<?php
include 'includes/common.php';
$data = array(
    'pid'=>getenv('PID'),'type'=>getenv('TYPE'),'out_trade_no'=>getenv('OUT'),
    'notify_url'=>getenv('NOTIFY'),'return_url'=>getenv('RETURN_URL'),'name'=>getenv('NAME'),
    'money'=>getenv('MONEY'),'sitename'=>'Acceptance Merchant','param'=>getenv('PARAM'),'sign_type'=>'MD5',
);
echo \lib\Payment::makeSign($data, getenv('KEY'));
PHP
}

submit_web_order(){
  local pid="$1" key="$2" out="$3" name="$4" money="$5" type="$6" param="$7"
  local notify="$BASE/shopping.php?act=notify" return_url="$BASE/" sign
  sign=$(make_sign "$pid" "$key" "$out" "$name" "$money" "$type" "$notify" "$return_url" "$param")
  curl -sS -e "$BASE/" \
    --data-urlencode "pid=$pid" --data-urlencode "type=$type" --data-urlencode "out_trade_no=$out" \
    --data-urlencode "notify_url=$notify" --data-urlencode "return_url=$return_url" \
    --data-urlencode "name=$name" --data-urlencode "money=$money" \
    --data-urlencode 'sitename=Acceptance Merchant' --data-urlencode "param=$param" \
    --data-urlencode 'sign_type=MD5' --data-urlencode "sign=$sign" "$BASE/submit.php"
}

included_out="shadow-included-$(date +%s)"
included_response=$(submit_web_order 1000 includedkey "$included_out" 'Original Merchant Product' 31.00 'alipay' 'original-param-value')
not_contains "$included_response" "shopping.php?act=checkout" "shadow web payment bypasses purchase confirmation"
contains "$included_response" "/pay/qrcode/" "shadow web payment keeps original plugin response"
pay_trade_no=$("${MYSQL_APP[@]}" -e "SELECT trade_no FROM pay_order WHERE uid=1000 AND out_trade_no='$included_out' LIMIT 1")
[[ -n "$pay_trade_no" ]] || fail "original payment order created"
shop_row=$("${MYSQL_APP[@]}" -e "SELECT CONCAT(S.shop_trade_no,'|',S.query_token,'|',S.goods_id,'|',S.pay_type,'|',S.money,'|',S.quantity) FROM pay_shop_orders S WHERE S.pay_trade_no='$pay_trade_no'")
IFS='|' read -r shop_trade_no query_token goods_id shop_pay_type shop_money shop_quantity <<<"$shop_row"
[[ -n "$shop_trade_no" && -n "$query_token" ]] || fail "shadow order linked in background"
pass "shadow order linked in background"
[[ "$shop_pay_type|$shop_money|$shop_quantity" == "1|31.00|1" ]] || fail "shadow order mirrors route amount and quantity"
pass "shadow order mirrors route amount and quantity"
status_times=$("${MYSQL_APP[@]}" -e "SELECT status_times FROM pay_shop_orders WHERE pay_trade_no='$pay_trade_no'")
contains "$status_times" '"source":"merchant_order_shadow"' "shadow source recorded"
contains "$status_times" '"payment_started"' "payment route timestamp recorded"
not_contains "$status_times" '"confirmed"' "shadow order has no confirmation step"
order_invariants=$("${MYSQL_APP[@]}" -e "SELECT CONCAT(P.uid,'|',P.money,'|',P.name,'|',P.param,'|',P.type,'|',P.channel,'|',S.pay_trade_no,'|',S.money) FROM pay_order P INNER JOIN pay_shop_orders S ON S.pay_trade_no=P.trade_no WHERE P.trade_no='$pay_trade_no'")
[[ "$order_invariants" == "1000|31.00|Original Merchant Product|original-param-value|1|1|$pay_trade_no|31.00" ]] || fail "original merchant fields and route preserved"
pass "original merchant fields and route preserved"
random_goods_match=$("${MYSQL_APP[@]}" -e "SELECT COUNT(*) FROM pay_shop_orders S INNER JOIN pay_shop_goods G ON G.id=S.goods_id AND G.name=S.goods_name AND G.image=S.goods_image WHERE S.pay_trade_no='$pay_trade_no' AND G.status=1 AND G.deleted=0")
[[ "$random_goods_match" == "1" ]] || fail "shadow order receives catalog snapshot"
pass "shadow order receives catalog snapshot"

repeat_response=$(submit_web_order 1000 includedkey "$included_out" 'Original Merchant Product' 31.00 'alipay' 'original-param-value')
not_contains "$repeat_response" "shopping.php?act=checkout" "merchant retry remains invisible"
repeat_counts=$("${MYSQL_APP[@]}" -e "SELECT CONCAT((SELECT COUNT(*) FROM pay_order WHERE out_trade_no='$included_out'),'|',(SELECT COUNT(*) FROM pay_shop_orders WHERE pay_trade_no='$pay_trade_no'))")
[[ "$repeat_counts" == "1|1" ]] || fail "merchant retry remains idempotent"
pass "merchant retry remains idempotent"

excluded_out="shadow-excluded-$(date +%s)"
excluded_response=$(submit_web_order 1001 excludedkey "$excluded_out" 'Excluded Merchant Product' 22.00 '' 'excluded-param')
contains "$excluded_response" "cashier.php?trade_no=" "excluded merchant stays on original cashier"
excluded_trade_no=$("${MYSQL_APP[@]}" -e "SELECT trade_no FROM pay_order WHERE uid=1001 AND out_trade_no='$excluded_out' LIMIT 1")
excluded_shop_count=$("${MYSQL_APP[@]}" -e "SELECT COUNT(*) FROM pay_shop_orders WHERE pay_trade_no='$excluded_trade_no'")
[[ "$excluded_shop_count" == "0" ]] || fail "excluded merchant creates no shadow record"
pass "excluded merchant creates no shadow record"

mapi_out="shadow-mapi-$(date +%s)"
mapi_sign=$(cd "$SITE_DIR" && MAPI_OUT="$mapi_out" BASE_URL="$BASE" php <<'PHP'
<?php
include 'includes/common.php';
$data = array(
    'pid'=>'1000','type'=>'alipay','out_trade_no'=>getenv('MAPI_OUT'),
    'notify_url'=>getenv('BASE_URL').'/shopping.php?act=notify','return_url'=>getenv('BASE_URL').'/',
    'name'=>'MAPI Original Product','money'=>'18.80','clientip'=>'127.0.0.1','device'=>'pc',
    'method'=>'jump','sitename'=>'Acceptance Merchant','param'=>'mapi-original-param','sign_type'=>'MD5',
);
echo \lib\Payment::makeSign($data, 'includedkey');
PHP
)
mapi_response=$(curl -sS \
  --data-urlencode 'pid=1000' --data-urlencode 'type=alipay' --data-urlencode "out_trade_no=$mapi_out" \
  --data-urlencode "notify_url=$BASE/shopping.php?act=notify" --data-urlencode "return_url=$BASE/" \
  --data-urlencode 'name=MAPI Original Product' --data-urlencode 'money=18.80' \
  --data-urlencode 'clientip=127.0.0.1' --data-urlencode 'device=pc' --data-urlencode 'method=jump' \
  --data-urlencode 'sitename=Acceptance Merchant' --data-urlencode 'param=mapi-original-param' \
  --data-urlencode 'sign_type=MD5' --data-urlencode "sign=$mapi_sign" "$BASE/mapi.php")
not_contains "$mapi_response" "shopping.php?act=checkout" "MAPI bypasses purchase confirmation"
contains "$mapi_response" '"payurl"' "MAPI keeps original API response contract"
mapi_pay_trade_no=$("${MYSQL_APP[@]}" -e "SELECT trade_no FROM pay_order WHERE uid=1000 AND out_trade_no='$mapi_out' LIMIT 1")
mapi_state=$("${MYSQL_APP[@]}" -e "SELECT CONCAT(P.type,'|',P.channel,'|',S.pay_type,'|',S.money) FROM pay_order P INNER JOIN pay_shop_orders S ON S.pay_trade_no=P.trade_no WHERE P.trade_no='$mapi_pay_trade_no'")
[[ "$mapi_state" == "1|1|1|18.80" ]] || fail "MAPI route and shadow order stay linked"
pass "MAPI route and shadow order stay linked"

balance_before=$("${MYSQL_APP[@]}" -e "SELECT money FROM pay_user WHERE uid=1000")
payment_process=$(cd "$SITE_DIR" && PAY_NO="$pay_trade_no" php <<'PHP'
<?php
include 'includes/common.php';
$channel = $DB->getRow("SELECT * FROM pay_channel WHERE id=1 LIMIT 1");
$order = $DB->getRow("SELECT * FROM pay_order WHERE trade_no=:trade_no LIMIT 1", array(':trade_no'=>getenv('PAY_NO')));
$order['typeshowname'] = '支付宝';
\lib\Payment::processOrder(true, $order, 'api-shadow-1', null);
$fresh = $DB->getRow("SELECT * FROM pay_order WHERE trade_no=:trade_no LIMIT 1", array(':trade_no'=>getenv('PAY_NO')));
$fresh['typeshowname'] = '支付宝';
\lib\Payment::processOrder(true, $fresh, 'api-shadow-1', null);
echo 'shadow-process-ok';
PHP
)
contains "$payment_process" "shadow-process-ok" "shadow payment uses original payment process"
balance_delta=$("${MYSQL_APP[@]}" -e "SELECT ROUND(money-$balance_before,2) FROM pay_user WHERE uid=1000")
[[ "$balance_delta" == "31.00" ]] || fail "payment credits original merchant once"
pass "payment credits original merchant once"
paid_state=$("${MYSQL_APP[@]}" -e "SELECT CONCAT(P.status,'|',P.notify,'|',P.param,'|',S.pay_status,'|',S.order_status,'|',COALESCE(S.pay_api_trade_no,'')) FROM pay_order P INNER JOIN pay_shop_orders S ON S.pay_trade_no=P.trade_no WHERE P.trade_no='$pay_trade_no'")
[[ "$paid_state" == "1|0|original-param-value|1|2|api-shadow-1" ]] || fail "shadow auto shipment and merchant callback both succeed"
pass "shadow auto shipment and merchant callback both succeed"

failure_out="shadow-write-failure-$(date +%s)"
"${MYSQL_APP[@]}" -e "RENAME TABLE pay_shop_orders TO pay_shop_orders_unavailable"
failure_response=$(submit_web_order 1000 includedkey "$failure_out" 'Failure Injection Product' 26.00 'alipay' 'failure-param')
set +e
missing_table_reconcile=$(cd "$SITE_DIR" && php scripts/shop-shadow-reconcile.php --limit=200)
missing_table_rc=$?
set -e
"${MYSQL_APP[@]}" -e "RENAME TABLE pay_shop_orders_unavailable TO pay_shop_orders"
not_contains "$failure_response" "shopping.php?act=checkout" "shop write failure never exposes checkout"
contains "$failure_response" "/pay/qrcode/" "shop write failure does not block original payment"
[[ "$missing_table_rc" == "1" ]] || fail "CLI reports reconciliation database failures"
contains "$missing_table_reconcile" '"code":1' "CLI reports reconciliation database failures"
failure_trade_no=$("${MYSQL_APP[@]}" -e "SELECT trade_no FROM pay_order WHERE uid=1000 AND out_trade_no='$failure_out' LIMIT 1")
failure_shop_before=$("${MYSQL_APP[@]}" -e "SELECT COUNT(*) FROM pay_shop_orders WHERE pay_trade_no='$failure_trade_no'")
[[ "$failure_shop_before" == "0" ]] || fail "failure injection leaves a compensatable gap"
pass "failure injection leaves a compensatable gap"

paid_missing_trade="$(date +%Y%m%d%H%M%S)77777"
"${MYSQL_APP[@]}" -e "INSERT INTO pay_order (trade_no,out_trade_no,uid,tid,addtime,name,money,type,channel,subchannel,realmoney,getmoney,notify_url,return_url,param,domain,ip,status,endtime) VALUES ('$paid_missing_trade','shadow-paid-missing',1000,0,NOW(),'Paid Missing Shadow',19.00,1,1,0,19.00,19.00,'$BASE/shopping.php?act=notify','$BASE/','paid-missing-param','127.0.0.1','127.0.0.1',1,NOW())"
reconcile_output=$(cd "$SITE_DIR" && php scripts/shop-shadow-reconcile.php --limit=200)
contains "$reconcile_output" '"code":0' "CLI reconciliation completes"
failure_shop_after=$("${MYSQL_APP[@]}" -e "SELECT COUNT(*) FROM pay_shop_orders WHERE pay_trade_no='$failure_trade_no'")
[[ "$failure_shop_after" == "1" ]] || fail "CLI repairs missing unpaid shadow order"
pass "CLI repairs missing unpaid shadow order"
paid_missing_state=$("${MYSQL_APP[@]}" -e "SELECT CONCAT(pay_status,'|',order_status,'|',pay_type) FROM pay_shop_orders WHERE pay_trade_no='$paid_missing_trade'")
[[ "$paid_missing_state" == "1|2|1" ]] || fail "CLI repairs paid order as paid and shipped"
pass "CLI repairs paid order as paid and shipped"
excluded_after_reconcile=$("${MYSQL_APP[@]}" -e "SELECT COUNT(*) FROM pay_shop_orders WHERE pay_trade_no='$excluded_trade_no'")
[[ "$excluded_after_reconcile" == "0" ]] || fail "CLI respects excluded merchants"
pass "CLI respects excluded merchants"

admin_list=$(curl -sS -c "$COOKIE_FILE" -b "$COOKIE_FILE" -e "$BASE/admin/shop_orders.php" -d 'offset=0&limit=20&keyword=1000&pay_status=-1&order_status=-1' "$BASE/admin/ajax_shop.php?act=orderList")
contains "$admin_list" '"record_source_text":"无感影子订单"' "admin identifies shadow order source"
admin_order_id=$("${MYSQL_APP[@]}" -e "SELECT id FROM pay_shop_orders WHERE pay_trade_no='$pay_trade_no'")
query_page=$(curl -sS "$BASE/shopping.php?act=query&trade_no=$shop_trade_no&token=$query_token")
contains "$query_page" "$shop_trade_no" "public query accepts order token"
contains "$query_page" "已支付" "public query shows synchronized paid status"
contains "$query_page" "已发货" "public query shows automatic shipment"

catalog_page=$(curl -sS "$BASE/shopping.php")
for product_name in 'ChatGPT Token 充值' 'OpenAI API 额度充值' 'Claude API 额度充值' 'Gemini API 额度充值' 'Qwen API 额度充值' 'DeepSeek API 额度充值'; do
  contains "$catalog_page" "$product_name" "catalog contains $product_name"
done
catalog_invariants=$("${MYSQL_APP[@]}" -e "SELECT CONCAT(COUNT(*),'|',SUM(price=0.00),'|',SUM(stock=-1),'|',SUM(image LIKE '%.webp')) FROM pay_shop_goods")
[[ "$catalog_invariants" == "6|6|6|6" ]] || fail "recharge catalog remains dynamic and local WebP"
pass "recharge catalog remains dynamic and local WebP"

"${MYSQL_APP[@]}" -e "UPDATE pay_shop_goods SET status=0"
fallback_out="shadow-catalog-fallback-$(date +%s)"
fallback_response=$(submit_web_order 1000 includedkey "$fallback_out" 'Fallback Original Product' 14.00 'alipay' 'fallback-param')
contains "$fallback_response" "/pay/qrcode/" "empty catalog does not block original payment"
fallback_state=$("${MYSQL_APP[@]}" -e "SELECT CONCAT(S.goods_id,'|',S.goods_name,'|',S.money) FROM pay_shop_orders S INNER JOIN pay_order P ON P.trade_no=S.pay_trade_no WHERE P.out_trade_no='$fallback_out'")
[[ "$fallback_state" == "0|Fallback Original Product|14.00" ]] || fail "empty catalog stores original product fallback"
pass "empty catalog stores original product fallback"
"${MYSQL_APP[@]}" -e "UPDATE pay_shop_goods SET status=1"

rollback_config=$(curl -sS -c "$COOKIE_FILE" -b "$COOKIE_FILE" -e "$BASE/admin/shop_config.php" \
  --data-urlencode "csrf_token=$csrf" --data-urlencode 'shop_status=1' --data-urlencode 'shop_flow_mode=checkout' \
  --data-urlencode 'shop_name=Shadow Shop' --data-urlencode 'shop_desc=Rollback check' \
  --data-urlencode 'shop_excluded_uids=1001,1002' --data-urlencode 'shop_query_verify=1' \
  "$BASE/admin/ajax_shop.php?act=saveConfig")
contains "$rollback_config" '"code":0' "checkout rollback mode saves"
rollback_out="shadow-rollback-$(date +%s)"
rollback_response=$(submit_web_order 1000 includedkey "$rollback_out" 'Rollback Product' 16.00 '' 'rollback-param')
contains "$rollback_response" "shopping.php?act=checkout" "checkout rollback mode remains available"

NODE_BIN="${SHOP_TEST_NODE_BIN:-$HOME/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node}"
NODE_ROOT="$(cd "$(dirname "$NODE_BIN")/.." 2>/dev/null && pwd || true)"
NODE_PATH_VALUE="${SHOP_TEST_NODE_PATH:-$NODE_ROOT/node_modules}"
if [[ -x "$NODE_BIN" ]] && NODE_PATH="$NODE_PATH_VALUE" "$NODE_BIN" -e "require('playwright')" >/dev/null 2>&1; then
  SHOP_TEST_BASE_URL="$BASE" SHOP_TEST_SHOP_TRADE_NO="$shop_trade_no" SHOP_TEST_QUERY_TOKEN="$query_token" \
  SHOP_TEST_ADMIN_ORDER_ID="$admin_order_id" SHOP_TEST_SCREEN_DIR="$SCREEN_DIR" \
  NODE_PATH="$NODE_PATH_VALUE" "$NODE_BIN" "$ROOT_DIR/scripts/shop-feature-browser-acceptance.cjs"
  pass "playwright shadow acceptance"
elif [[ "${SHOP_TEST_REQUIRE_BROWSER:-0}" == "1" ]]; then
  fail "Playwright unavailable"
else
  printf 'SKIP Playwright unavailable\n'
fi

off_config=$(curl -sS -c "$COOKIE_FILE" -b "$COOKIE_FILE" -e "$BASE/admin/shop_config.php" \
  --data-urlencode "csrf_token=$csrf" --data-urlencode 'shop_status=0' --data-urlencode 'shop_flow_mode=shadow' \
  --data-urlencode 'shop_name=Shadow Shop' --data-urlencode 'shop_desc=Disabled check' \
  --data-urlencode 'shop_excluded_uids=1001,1002' --data-urlencode 'shop_query_verify=1' \
  "$BASE/admin/ajax_shop.php?act=saveConfig")
contains "$off_config" '"code":0' "shop can be fully disabled"
disabled_out="shadow-disabled-$(date +%s)"
disabled_response=$(submit_web_order 1000 includedkey "$disabled_out" 'Disabled Shop Product' 12.00 '' 'disabled-param')
contains "$disabled_response" "cashier.php?trade_no=" "disabled shop preserves original cashier"
disabled_trade_no=$("${MYSQL_APP[@]}" -e "SELECT trade_no FROM pay_order WHERE out_trade_no='$disabled_out' LIMIT 1")
disabled_shop_count=$("${MYSQL_APP[@]}" -e "SELECT COUNT(*) FROM pay_shop_orders WHERE pay_trade_no='$disabled_trade_no'")
[[ "$disabled_shop_count" == "0" ]] || fail "disabled shop creates no shadow record"
pass "disabled shop creates no shadow record"

unexpected_fatals=$(grep -Ei 'Fatal error|Uncaught|Parse error' "$TMP_ROOT/php.log" | grep -Ev 'Cannot redeclare class [a-zA-Z0-9_]+_plugin' || true)
if [[ -n "$unexpected_fatals" ]]; then
  printf '%s\n' "$unexpected_fatals" >&2
  fail "PHP server log contains fatal errors"
fi
pass "PHP server log contains no shadow-flow fatal errors"

printf 'SHOP_SHADOW_ACCEPTANCE_OK temp=%s shop_trade_no=%s pay_trade_no=%s\n' "$TMP_ROOT" "$shop_trade_no" "$pay_trade_no"
