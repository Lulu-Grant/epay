#!/usr/bin/env sh
set -u

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
PHP_BIN=${PHP_BIN:-php}
CURL_BIN=${CURL_BIN:-curl}
NODE_BIN=${NODE_BIN:-node}
NPM_BIN=${NPM_BIN:-npm}
EPAY_BROWSER_SMOKE=${EPAY_BROWSER_SMOKE:-0}
EPAY_PLAYWRIGHT_VERSION=${EPAY_PLAYWRIGHT_VERSION:-1.61.0}
HOST=${EPAY_SMOKE_HOST:-127.0.0.1}
PORT=${EPAY_SMOKE_PORT:-8097}
BASE_URL="http://$HOST:$PORT"
DB_HOST=${EPAY_DB_HOST:-127.0.0.1}
DB_PORT=${EPAY_DB_PORT:-3306}
DB_SOCKET=${EPAY_DB_SOCKET:-}
DB_USER=${EPAY_DB_USER:-root}
DB_PASSWORD=${EPAY_DB_PASSWORD:-}
DB_PREFIX=${EPAY_DB_PREFIX:-pay}
DB_NAME=${EPAY_DB_NAME:-epay_php84_installed_$(date +%Y%m%d_%H%M%S)_$$}
APP_DB_USER=${EPAY_APP_DB_USER:-epayphp84_$$}
APP_DB_PASSWORD=${EPAY_APP_DB_PASSWORD:-epay_php84_pw_$$}
WORK_DIR=$(mktemp -d "${TMPDIR:-/tmp}/epay-installed-smoke.XXXXXX") || exit 1
APP_DIR="$WORK_DIR/app"
SERVER_LOG="$WORK_DIR/server.log"
SERVER_PID=""
DB_CREATED=0
APP_DB_USER_CREATED=0
failures=0

cleanup() {
  if [ -n "$SERVER_PID" ]; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
    wait "$SERVER_PID" >/dev/null 2>&1 || true
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

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
  printf 'PHP binary not found: %s\n' "$PHP_BIN" >&2
  exit 1
fi

if ! command -v "$CURL_BIN" >/dev/null 2>&1; then
  printf 'curl binary not found: %s\n' "$CURL_BIN" >&2
  exit 1
fi

DB_CREATED=1
if ! EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
  EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
  EPAY_DB_PREFIX="$DB_PREFIX" EPAY_DB_KEEP=1 \
  "$PHP_BIN" "$ROOT_DIR/tools/php84/db-fixture-check.php"; then
  printf 'Database fixture bootstrap failed; aborting installed HTTP smoke.\n' >&2
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
  printf 'Temporary application database user creation failed; aborting installed HTTP smoke.\n' >&2
  exit 1
fi

EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
  EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
  EPAY_DB_PREFIX="$DB_PREFIX" EPAY_BASE_URL="$BASE_URL" "$PHP_BIN" -r '
    $prefix = getenv("EPAY_DB_PREFIX");
    $db = getenv("EPAY_DB_NAME");
    if (!preg_match("/^[A-Za-z0-9_]+$/", $db) || !preg_match("/^[A-Za-z0-9_]+$/", $prefix)) {
        fwrite(STDERR, "Unsafe database or prefix\n");
        exit(1);
    }
    $socket = getenv("EPAY_DB_SOCKET");
    if ($socket !== false && $socket !== "") {
        $dsn = "mysql:unix_socket=".$socket.";dbname=".$db.";charset=utf8mb4";
    } else {
        $dsn = "mysql:host=".getenv("EPAY_DB_HOST").";port=".getenv("EPAY_DB_PORT").";dbname=".$db.";charset=utf8mb4";
    }
    $pass = getenv("EPAY_DB_PASSWORD");
    if ($pass === false) {
        $pass = "";
    }
    $pdo = new PDO($dsn, getenv("EPAY_DB_USER"), $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $fixturePwd = md5(md5("php84-password") . md5("1277180438"."1000"));
    $pdo->prepare("UPDATE `".$prefix."_user` SET `account`=?, `username`=?, `email`=?, `phone`=?, `pwd`=?, `status`=1, `pay`=1, `settle`=1, `refund`=1, `transfer`=1, `keylogin`=1 WHERE `uid`=1000")
        ->execute(array("fixture@example.com", "fixture-user", "fixture@example.com", "13800138000", $fixturePwd));
    $pdo->prepare("INSERT INTO `".$prefix."_user` (`uid`, `gid`, `key`, `money`, `account`, `username`, `email`, `phone`, `addtime`, `pay`, `settle`, `refund`, `transfer`, `keylogin`, `status`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?)")
        ->execute(array(1001, 0, md5("php84-other-fixture"), "0.00", "other-fixture@example.com", "other-fixture-user", "other-fixture@example.com", "13800138001", 1, 1, 1, 1, 1, 1));
    $config = array(
        "captcha_open_login" => "0",
        "close_keylogin" => "0",
        "cronkey" => "fixture-cron-key",
        "localurl" => rtrim(getenv("EPAY_BASE_URL"), "/")."/",
        "pay_maxmoney" => "0",
        "pay_minmoney" => "0",
        "cert_force" => "0",
        "forceqq" => "0",
        "pay_domain_forbid" => "0",
        "blockname" => "",
        "pay_iplimit" => "0",
        "pay_payaddstart" => "0",
        "payfee_lessthan" => "0",
        "payfee_mincost" => "0",
        "user_transfer" => "1",
        "transfer_rate" => "0",
        "transfer_minmoney" => "0",
        "transfer_maxmoney" => "0",
        "transfer_maxlimit" => "0"
    );
    $stmt = $pdo->prepare("REPLACE INTO `".$prefix."_config` (`k`, `v`) VALUES (?, ?)");
    foreach ($config as $key => $value) {
        $stmt->execute(array($key, $value));
    }
    $pdo->exec("UPDATE `".$prefix."_group` SET `info`=\"\" WHERE `gid`=0");
    $pdo->exec("UPDATE `".$prefix."_channel` SET `status`=0 WHERE `type`=1");
    $channelConfig = json_encode(array(
        "appurl" => rtrim(getenv("EPAY_BASE_URL"), "/")."/",
        "appid" => "1000",
        "appkey" => "fixture-epay-channel-key",
        "appswitch" => "0"
    ));
    $pdo->prepare("INSERT INTO `".$prefix."_channel` (`type`, `plugin`, `name`, `rate`, `status`) VALUES (?, ?, ?, ?, ?)")
        ->execute(array(1, "epay", "PHP84 local epay channel", "100.00", 1));
    $channelId = $pdo->lastInsertId();
    $pdo->prepare("UPDATE `".$prefix."_channel` SET `config`=?, `daystatus`=0 WHERE `id`=?")
        ->execute(array($channelConfig, $channelId));
    $pdo->prepare("UPDATE `".$prefix."_cache` SET `v`=\"\" WHERE `k`=\"config\"")->execute();
  '

mkdir -p "$APP_DIR"
(cd "$ROOT_DIR" && tar --exclude './.git' -cf - .) | (cd "$APP_DIR" && tar -xf -)

P1_CERT_KEYS="$WORK_DIR/p1_cert_keys.env"
EPAY_SMOKE_APP_DIR="$APP_DIR" "$PHP_BIN" -r '
  function key_body($pem) {
      return preg_replace("/-----[^-]+-----|\s+/", "", $pem);
  }
  function pem_cert_to_der($pem) {
      return base64_decode(preg_replace("/-----[^-]+-----|\s+/", "", $pem));
  }
  $appDir = getenv("EPAY_SMOKE_APP_DIR");
  $fixtures = array(
      array("YSEPAY", "plugins/ysepay/cert/businessgate.cer", "plugins/ysepay/cert/client.pfx", "fixture-ysepay-cert-password", "ysepay-smoke"),
      array("SANDPAY", "plugins/sandpay/cert/sand.cer", "plugins/sandpay/cert/client.pfx", "fixture-sandpay-cert-password", "sandpay-smoke"),
  );
  foreach ($fixtures as $fixture) {
      list($name, $certRelativePath, $pfxRelativePath, $password, $commonName) = $fixture;
      $certTarget = $appDir . "/" . $certRelativePath;
      $pfxTarget = $appDir . "/" . $pfxRelativePath;
      if (!is_dir(dirname($certTarget)) && !mkdir(dirname($certTarget), 0777, true)) {
          fwrite(STDERR, "Unable to create certificate fixture directory: " . dirname($certTarget) . "\n");
          exit(1);
      }
      $privateKey = openssl_pkey_new(array(
          "private_key_type" => OPENSSL_KEYTYPE_RSA,
          "private_key_bits" => 2048,
      ));
      if (!$privateKey) {
          fwrite(STDERR, "Unable to generate certificate fixture key\n");
          exit(1);
      }
      $csr = openssl_csr_new(array(
          "countryName" => "CN",
          "stateOrProvinceName" => "Smoke",
          "localityName" => "Smoke",
          "organizationName" => "PHP84 Smoke",
          "organizationalUnitName" => "Compatibility",
          "commonName" => $commonName,
      ), $privateKey, array("digest_alg" => "sha256"));
      if (!$csr) {
          fwrite(STDERR, "Unable to generate certificate fixture CSR\n");
          exit(1);
      }
      $cert = openssl_csr_sign($csr, null, $privateKey, 30, array("digest_alg" => "sha256"));
      if (!$cert) {
          fwrite(STDERR, "Unable to self-sign certificate fixture\n");
          exit(1);
      }
      $certPem = "";
      if (!openssl_x509_export($cert, $certPem)) {
          fwrite(STDERR, "Unable to export certificate fixture public cert\n");
          exit(1);
      }
      if (file_put_contents($certTarget, pem_cert_to_der($certPem)) === false) {
          fwrite(STDERR, "Unable to write certificate fixture public cert\n");
          exit(1);
      }
      $pkcs12 = "";
      if (!openssl_pkcs12_export($cert, $pkcs12, $privateKey, $password)) {
          fwrite(STDERR, "Unable to export certificate fixture PFX\n");
          exit(1);
      }
      if (file_put_contents($pfxTarget, $pkcs12) === false) {
          fwrite(STDERR, "Unable to write certificate fixture PFX\n");
          exit(1);
      }
      $privatePem = "";
      if (!openssl_pkey_export($privateKey, $privatePem)) {
          fwrite(STDERR, "Unable to export certificate fixture private key\n");
          exit(1);
      }
      echo $name . "_PRIVATE_KEY=" . key_body($privatePem) . "\n";
  }
' >"$P1_CERT_KEYS"
. "$P1_CERT_KEYS"

mkdir -p "$APP_DIR/plugins/php84mock"
cat > "$APP_DIR/plugins/php84mock/php84mock_plugin.php" <<'PHP'
<?php

class php84mock_plugin
{
    static public $info = [
        'name' => 'php84mock',
        'showname' => 'PHP84 smoke mock',
        'author' => 'php84 smoke',
        'link' => '',
        'types' => ['alipay', 'wxpay', 'qqpay'],
        'inputs' => [
            'shape' => [
                'name' => 'Response shape',
                'type' => 'input',
                'note' => '',
            ],
        ],
        'select' => null,
        'note' => '',
        'bindwxmp' => false,
        'bindwxa' => false,
    ];

    static public function submit()
    {
        return self::result();
    }

    static public function mapi()
    {
        return self::result();
    }

    static public function refund($order)
    {
        return ['code' => 0, 'trade_no' => $order['refund_no'], 'refund_fee' => $order['refundmoney']];
    }

    static public function transfer($channel, $bizParam)
    {
        return [
            'code' => 0,
            'status' => 1,
            'orderid' => 'MOCKPAY' . $bizParam['out_biz_no'],
            'paydate' => date('Y-m-d H:i:s'),
        ];
    }

    static public function transfer_query($channel, $bizParam)
    {
        return [
            'code' => 0,
            'status' => 1,
            'orderid' => $bizParam['orderid'],
            'paydate' => date('Y-m-d H:i:s'),
        ];
    }

    static public function transfer_proof($channel, $bizParam)
    {
        return [
            'code' => 0,
            'url' => 'https://example.test/php84/transfer-proof/' . $bizParam['out_biz_no'],
        ];
    }

    static public function balance_query($channel, $bizParam)
    {
        return ['code' => 0, 'money' => '888.88'];
    }

    static private function result()
    {
        global $channel;
        $shape = isset($channel['shape']) ? $channel['shape'] : 'jump';
        switch ($shape) {
            case 'html':
                return ['type' => 'html', 'data' => '<form id="php84mock" method="post" action="https://example.test/php84/html"><input type="hidden" name="ok" value="1"></form>'];
            case 'qrcode':
                return ['type' => 'qrcode', 'page' => 'alipay_qrcode', 'url' => 'https://example.test/php84/qrcode'];
            case 'scheme':
                return ['type' => 'scheme', 'page' => 'wxpay_mini', 'url' => 'weixin://wxpay/bizpayurl?pr=php84'];
            case 'jsapi':
                return ['type' => 'jsapi', 'data' => ['appId' => 'php84', 'timeStamp' => '1234567890', 'nonceStr' => 'php84', 'package' => 'prepay_id=php84', 'signType' => 'RSA', 'paySign' => 'php84']];
            default:
                return ['type' => 'jump', 'url' => 'https://example.test/php84/jump'];
        }
    }
}
PHP

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
    $content = "<?php\n/* Generated by tools/php84/installed-http-smoke.sh */\n\$dbconfig=" . var_export($dbconfig, true) . ";\n";
    file_put_contents(getenv("EPAY_CONFIG_FILE"), $content);
  '

mkdir -p "$APP_DIR/install"
: > "$APP_DIR/install/install.lock"
ADMIN_CODE_BACKUP="$WORK_DIR/admin-code.php"
if [ -f "$APP_DIR/admin/code.php" ]; then
  cp "$APP_DIR/admin/code.php" "$ADMIN_CODE_BACKUP"
fi
rm -f "$APP_DIR/admin/code.php"

ROUTER_FILE="$APP_DIR/.php84-router.php"
cat > "$ROUTER_FILE" <<'PHP'
<?php
chdir(__DIR__);
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$file = __DIR__ . $path;
if ($path !== "/" && is_file($file)) {
    return false;
}
if (preg_match("#^/pay/(.*)$#", $path, $matches)) {
    $_GET["s"] = $matches[1];
    require __DIR__ . "/pay.php";
    return true;
}
if (preg_match("#^/api/(.*)$#", $path, $matches)) {
    $_GET["s"] = $matches[1];
    require __DIR__ . "/api.php";
    return true;
}
if (preg_match("#^/doc/([A-Za-z0-9_-]+)\.html$#", $path, $matches)) {
    $_GET["doc"] = $matches[1];
    require __DIR__ . "/index.php";
    return true;
}
if (preg_match("#^/([A-Za-z0-9_-]+)\.html$#", $path, $matches)) {
    $_GET["mod"] = $matches[1];
    require __DIR__ . "/index.php";
    return true;
}
return false;
PHP

cat > "$APP_DIR/.php84-alipay-success.php" <<'PHP'
<?php
$nosession = true;
include __DIR__ . "/includes/common.php";

header("Content-Type: text/plain; charset=utf-8");

$trade_no = isset($_POST["out_trade_no"]) ? $_POST["out_trade_no"] : "";
if (!preg_match('/^[0-9]+$/', $trade_no)) {
    echo "fail";
    exit;
}

$order = $DB->getRow("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE trade_no=:trade_no LIMIT 1", array(":trade_no" => $trade_no));
if (!$order) {
    echo "fail";
    exit;
}

$userrow = $DB->find("user", "ordername,channelinfo", array("uid" => $order["uid"]));
$channelinfo = $userrow ? $userrow["channelinfo"] : null;
$channel = $order["subchannel"] > 0 ? \lib\Channel::getSub($order["subchannel"]) : \lib\Channel::get($order["channel"], $channelinfo);
if (!$channel || $channel["plugin"] !== "alipay") {
    echo "fail";
    exit;
}

if (!defined("PAY_ROOT")) {
    define("PAY_ROOT", PLUGIN_ROOT . "alipay/");
}
if (!defined("TRADE_NO")) {
    define("TRADE_NO", $trade_no);
}

$alipay_config = require PAY_ROOT . "inc/config.php";
$aop = new \Alipay\AlipayService($alipay_config);
if (!$aop->check($_POST)) {
    echo "fail";
    exit;
}

$api_trade_no = isset($_POST["trade_no"]) ? $_POST["trade_no"] : "";
$buyer_id = isset($_POST["buyer_id"]) ? $_POST["buyer_id"] : "";
if ($buyer_id === "" && isset($_POST["buyer_open_id"])) {
    $buyer_id = $_POST["buyer_open_id"];
}
$total_amount = isset($_POST["total_amount"]) ? $_POST["total_amount"] : "";
$trade_status = isset($_POST["trade_status"]) ? $_POST["trade_status"] : "";

if ($trade_status === "TRADE_SUCCESS" && round((float)$total_amount, 2) == round((float)$order["realmoney"], 2)) {
    processNotify($order, $api_trade_no, $buyer_id);
    echo "success";
    exit;
}

echo "fail";
PHP

cat > "$APP_DIR/.php84-alipay-return.php" <<'PHP'
<?php
$nosession = true;
include __DIR__ . "/includes/common.php";

$trade_no = isset($_GET["out_trade_no"]) ? $_GET["out_trade_no"] : "";
if (!preg_match('/^[0-9]+$/', $trade_no)) {
    sysmsg("fail");
    exit;
}

$order = $DB->getRow("SELECT A.*,B.name typename,B.showname typeshowname FROM pre_order A LEFT JOIN pre_type B ON A.type=B.id WHERE trade_no=:trade_no LIMIT 1", array(":trade_no" => $trade_no));
if (!$order) {
    sysmsg("fail");
    exit;
}

$userrow = $DB->find("user", "ordername,channelinfo", array("uid" => $order["uid"]));
$channelinfo = $userrow ? $userrow["channelinfo"] : null;
$channel = $order["subchannel"] > 0 ? \lib\Channel::getSub($order["subchannel"]) : \lib\Channel::get($order["channel"], $channelinfo);
if (!$channel || $channel["plugin"] !== "alipay") {
    sysmsg("fail");
    exit;
}

if (!defined("PAY_ROOT")) {
    define("PAY_ROOT", PLUGIN_ROOT . "alipay/");
}
if (!defined("TRADE_NO")) {
    define("TRADE_NO", $trade_no);
}

$alipay_config = require PAY_ROOT . "inc/config.php";
$aop = new \Alipay\AlipayService($alipay_config);
if (!$aop->check($_GET)) {
    sysmsg("fail");
    exit;
}

$api_trade_no = isset($_GET["trade_no"]) ? $_GET["trade_no"] : "";
$total_amount = isset($_GET["total_amount"]) ? $_GET["total_amount"] : "";

if (round((float)$total_amount, 2) == round((float)$order["realmoney"], 2)) {
    processReturn($order, $api_trade_no);
    exit;
}

sysmsg("fail");
PHP

"$PHP_BIN" -S "$HOST:$PORT" -t "$APP_DIR" "$ROUTER_FILE" >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!

ready=0
tries=0
while [ "$tries" -lt 30 ]; do
  if ! kill -0 "$SERVER_PID" >/dev/null 2>&1; then
    printf 'PHP built-in server exited early.\n' >&2
    cat "$SERVER_LOG" >&2
    exit 1
  fi
  if "$CURL_BIN" -fsS "$BASE_URL/api.php" >/dev/null 2>&1; then
    ready=1
    break
  fi
  tries=$((tries + 1))
  sleep 1
done

if [ "$ready" -ne 1 ]; then
  printf 'Installed PHP server did not become ready at %s\n' "$BASE_URL" >&2
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

json_field_equals() {
  "$PHP_BIN" -r '
    $body = file_get_contents($argv[1]);
    $field = $argv[2];
    $expected = $argv[3];
    $data = json_decode($body, true);
    if (!is_array($data) || !array_key_exists($field, $data)) {
        exit(1);
    }
    exit((string)$data[$field] === $expected ? 0 : 1);
  ' "$1" "$2" "$3"
}

json_field() {
  "$PHP_BIN" -r '
    $body = file_get_contents($argv[1]);
    $field = $argv[2];
    $data = json_decode($body, true);
    if (!is_array($data) || !array_key_exists($field, $data)) {
        exit(1);
    }
    echo $data[$field];
  ' "$1" "$2"
}

json_field_is_json() {
  "$PHP_BIN" -r '
    $body = file_get_contents($argv[1]);
    $field = $argv[2];
    $data = json_decode($body, true);
    if (!is_array($data) || !array_key_exists($field, $data) || !is_string($data[$field])) {
        exit(1);
    }
    json_decode($data[$field], true);
    exit(json_last_error() === JSON_ERROR_NONE ? 0 : 1);
  ' "$1" "$2"
}

browser_installed_smoke() {
  if [ "$EPAY_BROWSER_SMOKE" != "1" ]; then
    return
  fi
  if ! command -v "$NODE_BIN" >/dev/null 2>&1; then
    printf '[FAIL] browser_smoke: node binary not found: %s\n' "$NODE_BIN"
    failures=$((failures + 1))
    return
  fi
  if ! command -v "$NPM_BIN" >/dev/null 2>&1; then
    printf '[FAIL] browser_smoke: npm binary not found: %s\n' "$NPM_BIN"
    failures=$((failures + 1))
    return
  fi

  browser_dir="$WORK_DIR/browser-smoke"
  mkdir -p "$browser_dir"
  cat > "$browser_dir/package.json" <<EOF
{"private":true,"type":"commonjs","dependencies":{"playwright":"$EPAY_PLAYWRIGHT_VERSION"}}
EOF
  if ! "$NPM_BIN" --prefix "$browser_dir" install --silent --no-audit --no-fund; then
    printf '[FAIL] browser_smoke: unable to install playwright %s in temporary directory\n' "$EPAY_PLAYWRIGHT_VERSION"
    failures=$((failures + 1))
    return
  fi

  cat > "$browser_dir/browser-smoke.cjs" <<'NODE'
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
  console.log('[OK] browser_smoke: home, admin login/dashboard, and user password login/dashboard passed');
})().catch((error) => {
  console.error(error && error.stack ? error.stack : error);
  process.exit(1);
});
NODE

  if EPAY_BROWSER_BASE_URL="$BASE_URL" "$NODE_BIN" "$browser_dir/browser-smoke.cjs"; then
    :
  else
    printf '[FAIL] browser_smoke: Playwright checks failed\n'
    failures=$((failures + 1))
  fi
}

login_user_key() {
  cookie=$1
  user_id=$2
  user_key=$3
  name=$4
  login_page="$WORK_DIR/${name}_login_page.body"
  login_body="$WORK_DIR/${name}_login.body"

  if "$CURL_BIN" -sS -c "$cookie" -b "$cookie" -o "$login_page" "$BASE_URL/user/login.php"; then
    csrf_token=$("$PHP_BIN" -r '
      $body = file_get_contents($argv[1]);
      if (preg_match("/name=\"csrf_token\" value=\"([^\"]+)\"/", $body, $matches)) {
          echo $matches[1];
      }
    ' "$login_page")
  else
    csrf_token=""
  fi

  [ -n "$csrf_token" ] &&
    "$CURL_BIN" -sS -c "$cookie" -b "$cookie" -e "$BASE_URL/user/login.php?m=key" \
      -X POST --data-urlencode "type=0" --data-urlencode "user=$user_id" --data-urlencode "pass=$user_key" --data-urlencode "csrf_token=$csrf_token" \
      -o "$login_body" "$BASE_URL/user/ajax.php?act=login" &&
    check_json "$login_body" &&
    json_field_equals "$login_body" code 0
}

order_count_equals() {
  out_trade_no=$1
  expected=$2
  EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_OUT_TRADE_NO="$out_trade_no" \
    EPAY_EXPECTED_COUNT="$expected" "$PHP_BIN" -r '
      $prefix = getenv("EPAY_DB_PREFIX");
      if (!preg_match("/^[A-Za-z0-9_]+$/", $prefix)) {
          exit(1);
      }
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
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM `".$prefix."_order` WHERE `uid`=1000 AND `out_trade_no`=?");
      $stmt->execute(array(getenv("EPAY_OUT_TRADE_NO")));
      exit((string)$stmt->fetchColumn() === (string)getenv("EPAY_EXPECTED_COUNT") ? 0 : 1);
    '
}

make_signed_query() {
  "$PHP_BIN" -r '
    $key = $argv[1];
    $data = array();
    for ($i = 2; $i < $argc; $i++) {
        $pair = explode("=", $argv[$i], 2);
        $data[$pair[0]] = isset($pair[1]) ? $pair[1] : "";
    }
    ksort($data);
    $signString = "";
    foreach ($data as $name => $value) {
        if ($name === "sign" || $name === "sign_type" || $value === "") {
            continue;
        }
        $signString .= $name."=".$value."&";
    }
    $signString = substr($signString, 0, -1).$key;
    $data["sign"] = md5($signString);
    if (!isset($data["sign_type"])) {
        $data["sign_type"] = "MD5";
    }
    echo http_build_query($data);
  ' "$@"
}

make_rsa_signed_query() {
  "$PHP_BIN" -r '
    $privateKey = "-----BEGIN PRIVATE KEY-----\n".wordwrap($argv[1], 64, "\n", true)."\n-----END PRIVATE KEY-----";
    $data = array();
    for ($i = 2; $i < $argc; $i++) {
        $pair = explode("=", $argv[$i], 2);
        $data[$pair[0]] = isset($pair[1]) ? $pair[1] : "";
    }
    if (!isset($data["timestamp"])) {
        $data["timestamp"] = time()."";
    }
    ksort($data);
    $signString = "";
    foreach ($data as $name => $value) {
        if (is_array($value) || $value === "" || $name === "sign" || $name === "sign_type") {
            continue;
        }
        $signString .= "&".$name."=".$value;
    }
    $signString = substr($signString, 1);
    $pkey = openssl_get_privatekey($privateKey);
    if (!$pkey) {
        fwrite(STDERR, "Unable to load RSA private key\n");
        exit(1);
    }
    openssl_sign($signString, $signature, $pkey, OPENSSL_ALGO_SHA256);
    $data["sign"] = base64_encode($signature);
    $data["sign_type"] = "RSA";
    echo http_build_query($data);
  ' "$@"
}

make_rsa_sha1_signed_query() {
  "$PHP_BIN" -r '
    $privateKey = "-----BEGIN PRIVATE KEY-----\n".wordwrap($argv[1], 64, "\n", true)."\n-----END PRIVATE KEY-----";
    $data = array();
    for ($i = 2; $i < $argc; $i++) {
        $pair = explode("=", $argv[$i], 2);
        $data[$pair[0]] = isset($pair[1]) ? $pair[1] : "";
    }
    ksort($data);
    $signString = "";
    foreach ($data as $name => $value) {
        if ($value === "" || $name === "sign") {
            continue;
        }
        $signString .= "&".$name."=".$value;
    }
    $signString = substr($signString, 1);
    $pkey = openssl_get_privatekey($privateKey);
    if (!$pkey) {
        fwrite(STDERR, "Unable to load RSA private key\n");
        exit(1);
    }
    openssl_sign($signString, $signature, $pkey);
    $data["sign"] = base64_encode($signature);
    echo http_build_query($data);
  ' "$@"
}

rsa_sha256_signature() {
  "$PHP_BIN" -r '
    $privateKey = "-----BEGIN PRIVATE KEY-----\n".wordwrap($argv[1], 64, "\n", true)."\n-----END PRIVATE KEY-----";
    $pkey = openssl_get_privatekey($privateKey);
    if (!$pkey) {
        fwrite(STDERR, "Unable to load RSA private key\n");
        exit(1);
    }
    openssl_sign($argv[2], $signature, $pkey, OPENSSL_ALGO_SHA256);
    echo base64_encode($signature);
  ' "$@"
}

configure_epayn_channel() {
  EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_BASE_URL="$BASE_URL" \
    EPAYN_PLATFORM_PUBLIC_KEY="$EPAYN_PLATFORM_PUBLIC_KEY" EPAYN_MERCHANT_PRIVATE_KEY="$EPAYN_MERCHANT_PRIVATE_KEY" \
    "$PHP_BIN" -r '
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
      $pass = getenv("EPAY_DB_PASSWORD");
      if ($pass === false) {
          $pass = "";
      }
      $pdo = new PDO($dsn, getenv("EPAY_DB_USER"), $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
      $pdo->exec("UPDATE `".$prefix."_channel` SET `status`=0 WHERE `type`=1");
      $channelConfig = json_encode(array(
          "appurl" => rtrim(getenv("EPAY_BASE_URL"), "/")."/",
          "appid" => "1000",
          "appkey" => getenv("EPAYN_PLATFORM_PUBLIC_KEY"),
          "appsecret" => getenv("EPAYN_MERCHANT_PRIVATE_KEY"),
          "appswitch" => "0"
      ));
      $pdo->prepare("INSERT INTO `".$prefix."_channel` (`type`, `plugin`, `name`, `rate`, `status`) VALUES (?, ?, ?, ?, ?)")
          ->execute(array(1, "epayn", "PHP84 local epayn channel", "100.00", 1));
      $channelId = $pdo->lastInsertId();
      $pdo->prepare("UPDATE `".$prefix."_channel` SET `config`=?, `daystatus`=0 WHERE `id`=?")
          ->execute(array($channelConfig, $channelId));
      $pdo->prepare("UPDATE `".$prefix."_cache` SET `v`=\"\" WHERE `k`=\"config\"")->execute();
    '
}

configure_local_epay_channel() {
  EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_BASE_URL="$BASE_URL" "$PHP_BIN" -r '
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
      $pass = getenv("EPAY_DB_PASSWORD");
      if ($pass === false) {
          $pass = "";
      }
      $pdo = new PDO($dsn, getenv("EPAY_DB_USER"), $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
      $pdo->exec("UPDATE `".$prefix."_channel` SET `status`=0 WHERE `type`=1");
      $channelConfig = json_encode(array(
          "appurl" => rtrim(getenv("EPAY_BASE_URL"), "/")."/",
          "appid" => "1000",
          "appkey" => "fixture-epay-channel-key",
          "appswitch" => "0"
      ));
      $pdo->prepare("INSERT INTO `".$prefix."_channel` (`type`, `plugin`, `name`, `rate`, `status`, `config`, `daystatus`) VALUES (?, ?, ?, ?, ?, ?, ?)")
          ->execute(array(1, "epay", "PHP84 local epay channel", "100.00", 1, $channelConfig, 0));
      $pdo->prepare("UPDATE `".$prefix."_cache` SET `v`=\"\" WHERE `k`=\"config\"")->execute();
    '
}

configure_mock_payment_shape_channel() {
  shape=$1
  EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_SHAPE="$shape" "$PHP_BIN" -r '
      $prefix = getenv("EPAY_DB_PREFIX");
      if (!preg_match("/^[A-Za-z0-9_]+$/", $prefix)) {
          fwrite(STDERR, "Unsafe prefix\n");
          exit(1);
      }
      $shape = getenv("EPAY_SHAPE");
      if (!preg_match("/^[a-z0-9_]+$/", $shape)) {
          fwrite(STDERR, "Unsafe shape\n");
          exit(1);
      }
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
      $pdo->exec("UPDATE `".$prefix."_channel` SET `status`=0 WHERE `type`=1");
      $pdo->prepare("INSERT INTO `".$prefix."_channel` (`type`, `plugin`, `name`, `rate`, `status`, `config`, `daystatus`) VALUES (?, ?, ?, ?, ?, ?, ?)")
          ->execute(array(1, "php84mock", "PHP84 mock ".$shape, "100.00", 1, json_encode(array("shape" => $shape)), 0));
      $pdo->prepare("UPDATE `".$prefix."_cache` SET `v`=\"\" WHERE `k`=\"config\"")->execute();
    '
}

configure_mock_transfer_channel() {
  EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" "$PHP_BIN" -r '
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
      $pass = getenv("EPAY_DB_PASSWORD");
      if ($pass === false) {
          $pass = "";
      }
      $pdo = new PDO($dsn, getenv("EPAY_DB_USER"), $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
      $pdo->prepare("INSERT INTO `".$prefix."_channel` (`type`, `plugin`, `name`, `rate`, `status`, `config`, `daystatus`) VALUES (?, ?, ?, ?, ?, ?, ?)")
          ->execute(array(1, "php84mock", "PHP84 mock transfer", "100.00", 1, json_encode(array("shape" => "transfer")), 0));
      $channelId = $pdo->lastInsertId();
      $stmt = $pdo->prepare("REPLACE INTO `".$prefix."_config` (`k`, `v`) VALUES (?, ?)");
      foreach (array(
          "user_transfer" => "1",
          "transfer_alipay" => (string)$channelId,
          "transfer_rate" => "0",
          "settle_rate" => "0",
          "transfer_minmoney" => "0",
          "transfer_maxmoney" => "0",
          "transfer_maxlimit" => "0",
          "settle_type" => "0"
      ) as $key => $value) {
          $stmt->execute(array($key, $value));
      }
      $pdo->prepare("UPDATE `".$prefix."_cache` SET `v`=\"\" WHERE `k`=\"config\"")->execute();
    '
}

configure_p0_submit_channel() {
  plugin=$1
  type_id=$2
  app_type=$3
  config_json=$4

  EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_PLUGIN="$plugin" EPAY_TYPE_ID="$type_id" \
    EPAY_APP_TYPE="$app_type" EPAY_CHANNEL_CONFIG="$config_json" \
    "$PHP_BIN" -r '
      $prefix = getenv("EPAY_DB_PREFIX");
      if (!preg_match("/^[A-Za-z0-9_]+$/", $prefix)) {
          fwrite(STDERR, "Unsafe prefix\n");
          exit(1);
      }
      $typeId = (int)getenv("EPAY_TYPE_ID");
      $plugin = getenv("EPAY_PLUGIN");
      if (!preg_match("/^[a-zA-Z0-9_]+$/", $plugin)) {
          fwrite(STDERR, "Unsafe plugin\n");
          exit(1);
      }
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
      $pdo->exec("UPDATE `".$prefix."_channel` SET `status`=0 WHERE `type`=".$typeId);
      $pdo->prepare("INSERT INTO `".$prefix."_channel` (`type`, `plugin`, `name`, `rate`, `status`, `apptype`, `config`) VALUES (?, ?, ?, ?, ?, ?, ?)")
          ->execute(array($typeId, $plugin, "PHP84 P0 submit ".$plugin, "100.00", 1, getenv("EPAY_APP_TYPE"), getenv("EPAY_CHANNEL_CONFIG")));
      $pdo->prepare("UPDATE `".$prefix."_cache` SET `v`=\"\" WHERE `k`=\"config\"")->execute();
    '
}

epayn_keys="$WORK_DIR/epayn_keys.env"
"$PHP_BIN" -r '
  function key_body($pem) {
      return preg_replace("/-----[^-]+-----|\s+/", "", $pem);
  }

  $platform = openssl_pkey_new(array(
      "private_key_type" => OPENSSL_KEYTYPE_RSA,
      "private_key_bits" => 2048,
  ));
  $merchant = openssl_pkey_new(array(
      "private_key_type" => OPENSSL_KEYTYPE_RSA,
      "private_key_bits" => 2048,
  ));
  $wrong = openssl_pkey_new(array(
      "private_key_type" => OPENSSL_KEYTYPE_RSA,
      "private_key_bits" => 2048,
  ));
  if (!$platform || !$merchant || !$wrong) {
      fwrite(STDERR, "Unable to generate RSA fixture keys\n");
      exit(1);
  }
  openssl_pkey_export($platform, $platformPrivate);
  openssl_pkey_export($merchant, $merchantPrivate);
  openssl_pkey_export($wrong, $wrongPrivate);
  $platformDetails = openssl_pkey_get_details($platform);
  if (!$platformDetails || empty($platformDetails["key"])) {
      fwrite(STDERR, "Unable to export RSA fixture public key\n");
      exit(1);
  }
  foreach (array(
      "EPAYN_PLATFORM_PRIVATE_KEY" => key_body($platformPrivate),
      "EPAYN_PLATFORM_PUBLIC_KEY" => key_body($platformDetails["key"]),
      "EPAYN_MERCHANT_PRIVATE_KEY" => key_body($merchantPrivate),
      "EPAYN_WRONG_PRIVATE_KEY" => key_body($wrongPrivate),
  ) as $name => $value) {
      echo $name."=".$value."\n";
  }
' >"$epayn_keys"
. "$epayn_keys"

request() {
  name=$1
  path=$2
  expected_status=$3
  expected_type=$4
  expected_body=$5
  expect_json=$6
  body="$WORK_DIR/$name.body"
  meta="$WORK_DIR/$name.meta"
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
    printf '[BODY] %s: ' "$name"
    "$PHP_BIN" -r '
      $body = file_get_contents($argv[1]);
      $body = preg_replace("/<style\b[^>]*>.*?<\/style>/is", " ", $body);
      $body = preg_replace("/<script\b[^>]*>.*?<\/script>/is", " ", $body);
      $body = preg_replace("/\s+/u", " ", strip_tags($body));
      echo mb_substr($body, 0, 1000), PHP_EOL;
    ' "$body"
  fi
}

authenticated_page() {
  name=$1
  cookie_file=$2
  path=$3
  expected_body=$4
  body="$WORK_DIR/$name.body"

  if "$CURL_BIN" -sS -b "$cookie_file" -o "$body" "$BASE_URL$path" &&
    ! grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$body" &&
    grep -Eq "$expected_body" "$body"; then
    printf '[OK] %s: authenticated page loads\n' "$name"
  else
    printf '[FAIL] %s: expected authenticated page body\n' "$name"
    failures=$((failures + 1))
  fi
}

assert_order_unpaid_without_income() {
  trade_no=$1
  EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$trade_no" "$PHP_BIN" -r '
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
      $stmt = $pdo->prepare("SELECT `status` FROM `".$prefix."_order` WHERE `trade_no`=? LIMIT 1");
      $stmt->execute(array(getenv("EPAY_TRADE_NO")));
      $status = $stmt->fetchColumn();
      $records = $pdo->prepare("SELECT COUNT(*) FROM `".$prefix."_record` WHERE `uid`=1000 AND `type`=\"订单收入\" AND `trade_no`=?");
      $records->execute(array(getenv("EPAY_TRADE_NO")));
      exit((string)$status === "0" && (int)$records->fetchColumn() === 0 ? 0 : 1);
    '
}

p0_bad_gateway_callback_check() {
  plugin=$1
  trade_no=$2
  amount=$3
  body="$WORK_DIR/p0_${plugin}_bad_notify.body"

  case "$plugin" in
    alipay)
      if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
        --data-urlencode "out_trade_no=$trade_no" \
        --data-urlencode "trade_no=${trade_no}-gateway" \
        --data-urlencode "buyer_id=fixture-buyer" \
        --data-urlencode "total_amount=$amount" \
        --data-urlencode "trade_status=TRADE_SUCCESS" \
        --data-urlencode "sign_type=RSA2" \
        --data-urlencode "sign=invalid-signature" \
        -o "$body" "$BASE_URL/pay/notify/$trade_no/" &&
        ! grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$body" &&
        grep -qx "fail" "$body" &&
        assert_order_unpaid_without_income "$trade_no"; then
        printf '[OK] p0_alipay_notify_bad_signature: rejected without state change\n'
      else
        printf '[FAIL] p0_alipay_notify_bad_signature: expected fail and unpaid order\n'
        failures=$((failures + 1))
      fi

      return_body="$WORK_DIR/p0_alipay_bad_return.body"
      if "$CURL_BIN" -sS -G \
        --data-urlencode "out_trade_no=$trade_no" \
        --data-urlencode "trade_no=${trade_no}-gateway-return" \
        --data-urlencode "total_amount=$amount" \
        --data-urlencode "sign_type=RSA2" \
        --data-urlencode "sign=invalid-signature" \
        -o "$return_body" "$BASE_URL/pay/return/$trade_no/" &&
        ! grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$return_body" &&
        grep -Eq "支付宝返回验证失败" "$return_body" &&
        assert_order_unpaid_without_income "$trade_no"; then
        printf '[OK] p0_alipay_return_bad_signature: rejected without state change\n'
      else
        printf '[FAIL] p0_alipay_return_bad_signature: expected error page and unpaid order\n'
        failures=$((failures + 1))
      fi
      ;;
    wxpay)
      xml="<xml><return_code><![CDATA[SUCCESS]]></return_code><result_code><![CDATA[SUCCESS]]></result_code><out_trade_no><![CDATA[$trade_no]]></out_trade_no><transaction_id><![CDATA[${trade_no}-gateway]]></transaction_id><openid><![CDATA[fixture-openid]]></openid><total_fee><![CDATA[111]]></total_fee><sign><![CDATA[INVALID]]></sign></xml>"
      if printf '%s' "$xml" | "$CURL_BIN" -sS -X POST -H "Content-Type: text/xml" --data-binary @- \
        -o "$body" "$BASE_URL/pay/notify/$trade_no/" &&
        ! grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$body" &&
        grep -Eq "FAIL|签名校验失败" "$body" &&
        assert_order_unpaid_without_income "$trade_no"; then
        printf '[OK] p0_wxpay_notify_bad_signature: rejected without state change\n'
      else
        printf '[FAIL] p0_wxpay_notify_bad_signature: expected failure XML and unpaid order\n'
        failures=$((failures + 1))
      fi
      ;;
    qqpay)
      xml="<xml><out_trade_no><![CDATA[$trade_no]]></out_trade_no><transaction_id><![CDATA[${trade_no}-gateway]]></transaction_id><openid><![CDATA[fixture-openid]]></openid><total_fee><![CDATA[111]]></total_fee><sign><![CDATA[INVALID]]></sign></xml>"
      if printf '%s' "$xml" | "$CURL_BIN" -sS -X POST -H "Content-Type: text/xml" --data-binary @- \
        -o "$body" "$BASE_URL/pay/notify/$trade_no/" &&
        ! grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$body" &&
        grep -Eq "FAIL|签名校验失败" "$body" &&
        assert_order_unpaid_without_income "$trade_no"; then
        printf '[OK] p0_qqpay_notify_bad_signature: rejected without state change\n'
      else
        printf '[FAIL] p0_qqpay_notify_bad_signature: expected failure XML and unpaid order\n'
        failures=$((failures + 1))
      fi

      return_body="$WORK_DIR/p0_qqpay_return_unsupported.body"
      if "$CURL_BIN" -sS -o "$return_body" "$BASE_URL/pay/return/$trade_no/" &&
        ! grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$return_body" &&
        grep -Eq "插件方法不存在:return" "$return_body" &&
        assert_order_unpaid_without_income "$trade_no"; then
        printf '[OK] p0_qqpay_return_unsupported: controlled unsupported return without state change\n'
      else
        printf '[FAIL] p0_qqpay_return_unsupported: expected controlled unsupported return and unpaid order\n'
        failures=$((failures + 1))
      fi
      ;;
  esac
}

p0_alipay_success_callback_check() {
  trade_no=$1
  amount=$2
  snapshot="$WORK_DIR/p0_alipay_success_snapshot.env"
  body="$WORK_DIR/p0_alipay_success_notify.body"
  duplicate_body="$WORK_DIR/p0_alipay_success_notify_duplicate.body"

  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$trade_no" EPAY_PLUGIN="alipay" "$PHP_BIN" -r '
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
      $stmt = $pdo->prepare("SELECT A.`status`, A.`realmoney`, A.`getmoney`, C.`plugin` FROM `".$prefix."_order` A INNER JOIN `".$prefix."_channel` C ON A.`channel`=C.`id` WHERE A.`trade_no`=? LIMIT 1");
      $stmt->execute(array(getenv("EPAY_TRADE_NO")));
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$row || (string)$row["status"] !== "0" || $row["plugin"] !== getenv("EPAY_PLUGIN")) {
          exit(1);
      }
      $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
      echo "p0_alipay_realmoney=".$row["realmoney"]."\n";
      echo "p0_alipay_getmoney=".$row["getmoney"]."\n";
      echo "p0_alipay_user_money_before=".$userMoney."\n";
    ' >"$snapshot"; then
    . "$snapshot"
  else
    printf '[FAIL] p0_alipay_notify_success_snapshot: expected unpaid alipay order before success notify\n'
    failures=$((failures + 1))
    return
  fi

  api_trade_no="${trade_no}-gateway-success"
  buyer_id="fixture-buyer-alipay"
  notify_data=$(make_rsa_signed_query "$EPAYN_PLATFORM_PRIVATE_KEY" \
    "out_trade_no=$trade_no" \
    "trade_no=$api_trade_no" \
    "buyer_id=$buyer_id" \
    "total_amount=$amount" \
    "trade_status=TRADE_SUCCESS")

  if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
    --data "$notify_data" -o "$body" "$BASE_URL/.php84-alipay-success.php" &&
    ! grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$body" &&
    grep -qx "success" "$body"; then
    printf '[OK] p0_alipay_notify_success: accepted\n'
  else
    printf '[FAIL] p0_alipay_notify_success: expected success response\n'
    "$PHP_BIN" -r '
      $body = file_get_contents($argv[1]);
      $body = preg_replace("/<style\b[^>]*>.*?<\/style>/is", " ", $body);
      $body = preg_replace("/<script\b[^>]*>.*?<\/script>/is", " ", $body);
      $body = preg_replace("/\s+/u", " ", strip_tags($body));
      echo mb_substr($body, 0, 1000), PHP_EOL;
    ' "$body"
    failures=$((failures + 1))
    return
  fi

  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$trade_no" EPAY_API_TRADE_NO="$api_trade_no" \
    EPAY_BUYER="$buyer_id" EPAY_USER_MONEY_BEFORE="$p0_alipay_user_money_before" \
    EPAY_GETMONEY="$p0_alipay_getmoney" "$PHP_BIN" -r '
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
      $order = $pdo->prepare("SELECT `status`, `api_trade_no`, `buyer` FROM `".$prefix."_order` WHERE `trade_no`=? LIMIT 1");
      $order->execute(array(getenv("EPAY_TRADE_NO")));
      $row = $order->fetch(PDO::FETCH_ASSOC);
      $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
      $records = $pdo->prepare("SELECT COUNT(*) FROM `".$prefix."_record` WHERE `uid`=1000 AND `type`=\"订单收入\" AND `trade_no`=?");
      $records->execute(array(getenv("EPAY_TRADE_NO")));
      $expectedMoney = number_format(round((float)getenv("EPAY_USER_MONEY_BEFORE") + (float)getenv("EPAY_GETMONEY"), 2), 2, ".", "");
      if (!$row || (string)$row["status"] !== "1" || $row["api_trade_no"] !== getenv("EPAY_API_TRADE_NO") || $row["buyer"] !== getenv("EPAY_BUYER")) {
          exit(1);
      }
      if (number_format((float)$userMoney, 2, ".", "") !== $expectedMoney) {
          exit(1);
      }
      if ((int)$records->fetchColumn() !== 1) {
          exit(1);
      }
    '; then
    printf '[OK] p0_alipay_notify_success_state: order paid, buyer stored, merchant balance updated once\n'
  else
    printf '[FAIL] p0_alipay_notify_success_state: unexpected paid order state\n'
    failures=$((failures + 1))
    return
  fi

  if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
    --data "$notify_data" -o "$duplicate_body" "$BASE_URL/.php84-alipay-success.php" &&
    grep -qx "success" "$duplicate_body" &&
    EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
      EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
      EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$trade_no" \
      EPAY_USER_MONEY_BEFORE="$p0_alipay_user_money_before" EPAY_GETMONEY="$p0_alipay_getmoney" "$PHP_BIN" -r '
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
        $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
        $records = $pdo->prepare("SELECT COUNT(*) FROM `".$prefix."_record` WHERE `uid`=1000 AND `type`=\"订单收入\" AND `trade_no`=?");
        $records->execute(array(getenv("EPAY_TRADE_NO")));
        $expectedMoney = number_format(round((float)getenv("EPAY_USER_MONEY_BEFORE") + (float)getenv("EPAY_GETMONEY"), 2), 2, ".", "");
        exit(number_format((float)$userMoney, 2, ".", "") === $expectedMoney && (int)$records->fetchColumn() === 1 ? 0 : 1);
      '; then
    printf '[OK] p0_alipay_notify_duplicate: idempotent\n'
  else
    printf '[FAIL] p0_alipay_notify_duplicate: expected idempotent success\n'
    failures=$((failures + 1))
    return
  fi

  return_out_trade_no="php84-alipay-return-$(date +%Y%m%d%H%M%S)-$$"
  return_payment_post=$(make_signed_query "$fixture_key" \
    "pid=1000" \
    "type=alipay" \
    "out_trade_no=$return_out_trade_no" \
    "notify_url=http://127.0.0.1:1/merchant-notify-alipay-return" \
    "return_url=http://127.0.0.1:1/merchant-return-alipay-return" \
    "name=PHP84 P0 alipay return fixture" \
    "money=1.11" \
    "clientip=127.0.0.1" \
    "device=pc" \
    "method=jump" \
    "sign_type=MD5")
  return_create_body="$WORK_DIR/p0_alipay_return_create.body"
  if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
    --data "$return_payment_post" -o "$return_create_body" "$BASE_URL/mapi.php" &&
    check_json "$return_create_body" &&
    json_field_equals "$return_create_body" code 1; then
    return_trade_no=$(json_field "$return_create_body" trade_no || true)
  else
    printf '[FAIL] p0_alipay_return_create: expected JSON code 1 with trade_no\n'
    failures=$((failures + 1))
    return
  fi

  if [ -z "$return_trade_no" ]; then
    printf '[FAIL] p0_alipay_return_create: empty trade_no\n'
    failures=$((failures + 1))
    return
  fi

  return_snapshot="$WORK_DIR/p0_alipay_return_snapshot.env"
  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$return_trade_no" EPAY_PLUGIN="alipay" "$PHP_BIN" -r '
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
      $stmt = $pdo->prepare("SELECT A.`status`, A.`realmoney`, A.`getmoney`, C.`plugin` FROM `".$prefix."_order` A INNER JOIN `".$prefix."_channel` C ON A.`channel`=C.`id` WHERE A.`trade_no`=? LIMIT 1");
      $stmt->execute(array(getenv("EPAY_TRADE_NO")));
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$row || (string)$row["status"] !== "0" || $row["plugin"] !== getenv("EPAY_PLUGIN")) {
          exit(1);
      }
      $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
      echo "p0_alipay_return_realmoney=".$row["realmoney"]."\n";
      echo "p0_alipay_return_getmoney=".$row["getmoney"]."\n";
      echo "p0_alipay_return_user_money_before=".$userMoney."\n";
    ' >"$return_snapshot"; then
    . "$return_snapshot"
  else
    printf '[FAIL] p0_alipay_return_success_snapshot: expected unpaid alipay return order\n'
    failures=$((failures + 1))
    return
  fi

  return_api_trade_no="${return_trade_no}-gateway-return"
  return_query=$(make_rsa_signed_query "$EPAYN_PLATFORM_PRIVATE_KEY" \
    "out_trade_no=$return_trade_no" \
    "trade_no=$return_api_trade_no" \
    "total_amount=$p0_alipay_return_realmoney")
  return_body="$WORK_DIR/p0_alipay_return_success.body"
  if "$CURL_BIN" -sS -o "$return_body" "$BASE_URL/.php84-alipay-return.php?$return_query" &&
    ! grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$return_body" &&
    grep -Eq "支付成功跳转页面|支付成功，正在跳转" "$return_body"; then
    printf '[OK] p0_alipay_return_success: accepted\n'
  else
    printf '[FAIL] p0_alipay_return_success: expected success return page\n'
    failures=$((failures + 1))
    return
  fi

  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$return_trade_no" EPAY_API_TRADE_NO="$return_api_trade_no" \
    EPAY_USER_MONEY_BEFORE="$p0_alipay_return_user_money_before" EPAY_GETMONEY="$p0_alipay_return_getmoney" "$PHP_BIN" -r '
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
      $order = $pdo->prepare("SELECT `status`, `api_trade_no` FROM `".$prefix."_order` WHERE `trade_no`=? LIMIT 1");
      $order->execute(array(getenv("EPAY_TRADE_NO")));
      $row = $order->fetch(PDO::FETCH_ASSOC);
      $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
      $records = $pdo->prepare("SELECT COUNT(*) FROM `".$prefix."_record` WHERE `uid`=1000 AND `type`=\"订单收入\" AND `trade_no`=?");
      $records->execute(array(getenv("EPAY_TRADE_NO")));
      $expectedMoney = number_format(round((float)getenv("EPAY_USER_MONEY_BEFORE") + (float)getenv("EPAY_GETMONEY"), 2), 2, ".", "");
      if (!$row || (string)$row["status"] !== "1" || $row["api_trade_no"] !== getenv("EPAY_API_TRADE_NO")) {
          exit(1);
      }
      if (number_format((float)$userMoney, 2, ".", "") !== $expectedMoney) {
          exit(1);
      }
      if ((int)$records->fetchColumn() !== 1) {
          exit(1);
      }
    '; then
    printf '[OK] p0_alipay_return_success_state: order paid and merchant balance updated once\n'
  else
    printf '[FAIL] p0_alipay_return_success_state: unexpected return order state\n'
    failures=$((failures + 1))
  fi
}

request home "/" "200" "text/html" "聚合支付|支付|html" "no"
request admin_login "/admin/login.php" "200" "text/html" "管理员登录" "no"
request admin_protected "/admin/" "200" "text/html" "window.location.href='./login.php'" "no"
request user_login "/user/login.php" "200" "text/html" "请输入您的商户信息|登录" "no"
request user_protected "/user/" "200" "text/html" "window.location.href='./login.php'" "no"
request api_no_act "/api.php" "200" "application/json" "No Act" "json"
request submit_missing_merchant "/submit.php" "200" "text/html" "你还未配置支付接口商户" "no"
request mapi_missing_params "/mapi.php" "200" "application/json" "未传入任何参数" "json"
request cron_missing_key "/cron.php" "200" "text/html" "请先设置好监控密钥|监控密钥不正确" "no"
request admin_download_unauth "/admin/download.php?act=order&uid=1000" "200" "text/html" "window.location.href='./login.php'" "no"
request user_download_unauth "/user/download.php?act=wximg&channel=1&mediaid=fixture" "200" "text/html" "window.location.href='./login.php'" "no"
request admin_fillorder_unauth "/admin/ajax_order.php?act=fillorder" "200" "text/html" "window.location.href='./login.php'" "no"
request admin_settle_batch_unauth "/admin/ajax_settle.php?act=create_batch" "200" "text/html" "window.location.href='./login.php'" "no"
request admin_transfer_result_unauth "/admin/ajax_transfer.php?act=transfer_result&biz_no=fixture" "200" "text/html" "window.location.href='./login.php'" "no"

browser_installed_smoke

admin_cookie="$WORK_DIR/admin.cookie"
admin_login_body="$WORK_DIR/admin_login.body"
admin_login_meta="$WORK_DIR/admin_login.meta"

if [ -f "$ADMIN_CODE_BACKUP" ]; then
  cp "$ADMIN_CODE_BACKUP" "$APP_DIR/admin/code.php"
  admin_captcha_cookie="$WORK_DIR/admin_captcha.cookie"
  admin_captcha_body="$WORK_DIR/admin_captcha.body"
  admin_captcha_meta="$WORK_DIR/admin_captcha.meta"
  admin_captcha_login_body="$WORK_DIR/admin_captcha_login.body"

  if "$CURL_BIN" -sS -c "$admin_captcha_cookie" -b "$admin_captcha_cookie" \
    -o "$admin_captcha_body" -w "%{http_code}\n%{content_type}\n" \
    "$BASE_URL/admin/code.php" >"$admin_captcha_meta" &&
    [ "$(sed -n '1p' "$admin_captcha_meta")" = "200" ] &&
    sed -n '2p' "$admin_captcha_meta" | grep -qi "image/png" &&
    "$PHP_BIN" -r '
      $body = file_get_contents($argv[1]);
      exit(substr($body, 0, 8) === "\x89PNG\r\n\x1a\n" ? 0 : 1);
    ' "$admin_captcha_body"; then
    printf '[OK] admin_captcha_image: PNG generated and session cookie created\n'
  else
    printf '[FAIL] admin_captcha_image: expected PNG captcha response\n'
    failures=$((failures + 1))
  fi

  if "$CURL_BIN" -sS -c "$admin_captcha_cookie" -b "$admin_captcha_cookie" -e "$BASE_URL/admin/login.php" \
    -X POST -d "username=admin&password=123456&code=wrong" \
    -o "$admin_captcha_login_body" "$BASE_URL/admin/login.php?act=login" &&
    check_json "$admin_captcha_login_body" &&
    json_field_equals "$admin_captcha_login_body" code -1 &&
    "$PHP_BIN" -r '
      $data = json_decode(file_get_contents($argv[1]), true);
      exit(is_array($data) && isset($data["msg"]) && $data["msg"] === "验证码错误" ? 0 : 1);
    ' "$admin_captcha_login_body" &&
    ! grep -q "admin_token" "$admin_captcha_cookie"; then
    printf '[OK] admin_captcha_wrong_code: correct credentials rejected before token issue\n'
  else
    printf '[FAIL] admin_captcha_wrong_code: expected captcha rejection without admin token\n'
    failures=$((failures + 1))
  fi

  rm -f "$APP_DIR/admin/code.php"
fi

if "$CURL_BIN" -sS -c "$admin_cookie" -b "$admin_cookie" -e "$BASE_URL/admin/login.php" \
  -X POST -d "username=admin&password=wrong-password&code=" \
  -o "$admin_login_body" -w "%{http_code}\n%{content_type}\n" \
  "$BASE_URL/admin/login.php?act=login" >"$admin_login_meta" &&
  check_json "$admin_login_body" &&
  json_field_equals "$admin_login_body" code -1; then
  printf '[OK] admin_login_failure: expected JSON failure\n'
else
  printf '[FAIL] admin_login_failure: expected JSON code -1\n'
  failures=$((failures + 1))
fi

if "$CURL_BIN" -sS -c "$admin_cookie" -b "$admin_cookie" -e "$BASE_URL/admin/login.php" \
  -X POST -d "username=admin&password=123456&code=" \
  -o "$admin_login_body" -w "%{http_code}\n%{content_type}\n" \
  "$BASE_URL/admin/login.php?act=login" >"$admin_login_meta" &&
  check_json "$admin_login_body" &&
  json_field_equals "$admin_login_body" code 0; then
  printf '[OK] admin_login_success: expected JSON success\n'
else
  printf '[FAIL] admin_login_success: expected JSON code 0\n'
  failures=$((failures + 1))
fi

admin_home_body="$WORK_DIR/admin_home_authed.body"
if "$CURL_BIN" -sS -b "$admin_cookie" -o "$admin_home_body" "$BASE_URL/admin/" &&
  ! grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$admin_home_body" &&
  grep -Eq '后台管理首页|管理员信息' "$admin_home_body"; then
  printf '[OK] admin_protected_authenticated: dashboard loads\n'
else
  printf '[FAIL] admin_protected_authenticated: expected dashboard body\n'
  failures=$((failures + 1))
fi
authenticated_page admin_order_authenticated "$admin_cookie" "/admin/order.php" "订单列表|搜索"
authenticated_page admin_settle_authenticated "$admin_cookie" "/admin/settle.php" "批量结算|结算标准"
authenticated_page admin_transfer_authenticated "$admin_cookie" "/admin/transfer.php" "付款记录|新增付款"
authenticated_page admin_download_invalid_action "$admin_cookie" "/admin/download.php?act=../../config" "No Act"

admin_logout_body="$WORK_DIR/admin_logout.body"
admin_logout_check_body="$WORK_DIR/admin_logout_check.body"
if "$CURL_BIN" -sS -c "$admin_cookie" -b "$admin_cookie" -e "$BASE_URL/admin/" \
  -o "$admin_logout_body" "$BASE_URL/admin/login.php?logout" &&
  grep -Eq "window.location.href='./login.php'" "$admin_logout_body" &&
  "$CURL_BIN" -sS -b "$admin_cookie" -o "$admin_logout_check_body" "$BASE_URL/admin/" &&
  grep -Eq "window.location.href='./login.php'" "$admin_logout_check_body"; then
  printf '[OK] admin_logout: token cleared and protected page redirects\n'
else
  printf '[FAIL] admin_logout: expected logout redirect and protected-page intercept\n'
  failures=$((failures + 1))
fi

if "$CURL_BIN" -sS -c "$admin_cookie" -b "$admin_cookie" -e "$BASE_URL/admin/login.php" \
  -X POST -d "username=admin&password=123456&code=" \
  -o "$admin_login_body" -w "%{http_code}\n%{content_type}\n" \
  "$BASE_URL/admin/login.php?act=login" >"$admin_login_meta" &&
  check_json "$admin_login_body" &&
  json_field_equals "$admin_login_body" code 0; then
  printf '[OK] admin_relogin_success: expected JSON success after logout\n'
else
  printf '[FAIL] admin_relogin_success: expected JSON code 0 after logout\n'
  failures=$((failures + 1))
fi

user_cookie="$WORK_DIR/user.cookie"
user_login_page="$WORK_DIR/user_login_page.body"
user_login_body="$WORK_DIR/user_login.body"
if "$CURL_BIN" -sS -c "$user_cookie" -b "$user_cookie" -o "$user_login_page" "$BASE_URL/user/login.php"; then
  csrf_token=$("$PHP_BIN" -r '
    $body = file_get_contents($argv[1]);
    if (preg_match("/name=\"csrf_token\" value=\"([^\"]+)\"/", $body, $matches)) {
        echo $matches[1];
    }
  ' "$user_login_page")
else
  csrf_token=""
fi

if [ -z "$csrf_token" ]; then
  printf '[FAIL] user_login_csrf: token not found\n'
  failures=$((failures + 1))
else
  fixture_key=$("$PHP_BIN" -r 'echo md5("php84-fixture");')
  if "$CURL_BIN" -sS -c "$user_cookie" -b "$user_cookie" -e "$BASE_URL/user/login.php?m=key" \
    -X POST --data-urlencode "type=0" --data-urlencode "user=1000" --data-urlencode "pass=wrong-key" --data-urlencode "csrf_token=$csrf_token" \
    -o "$user_login_body" "$BASE_URL/user/ajax.php?act=login" &&
    check_json "$user_login_body" &&
    json_field_equals "$user_login_body" code -1; then
    printf '[OK] user_login_failure: expected JSON failure\n'
  else
    printf '[FAIL] user_login_failure: expected JSON code -1\n'
    failures=$((failures + 1))
  fi

  if "$CURL_BIN" -sS -c "$user_cookie" -b "$user_cookie" -e "$BASE_URL/user/login.php?m=key" \
    -X POST --data-urlencode "type=0" --data-urlencode "user=1000" --data-urlencode "pass=$fixture_key" --data-urlencode "csrf_token=$csrf_token" \
    -o "$user_login_body" "$BASE_URL/user/ajax.php?act=login" &&
    check_json "$user_login_body" &&
    json_field_equals "$user_login_body" code 0; then
    printf '[OK] user_login_success: expected JSON success\n'
  else
    printf '[FAIL] user_login_success: expected JSON code 0\n'
    failures=$((failures + 1))
  fi

  user_home_body="$WORK_DIR/user_home_authed.body"
  if "$CURL_BIN" -sS -b "$user_cookie" -o "$user_home_body" "$BASE_URL/user/" &&
    ! grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$user_home_body" &&
    grep -Eq '用户中心|商户信息|欢迎回来' "$user_home_body"; then
    printf '[OK] user_protected_authenticated: dashboard loads\n'
  else
    printf '[FAIL] user_protected_authenticated: expected user center body\n'
    failures=$((failures + 1))
  fi
  authenticated_page user_order_authenticated "$user_cookie" "/user/order.php" "订单记录|搜索"
  authenticated_page user_settle_authenticated "$user_cookie" "/user/settle.php" "结算记录"
  authenticated_page user_transfer_authenticated "$user_cookie" "/user/transfer.php" "代付管理|代付记录"
  authenticated_page user_download_invalid_action "$user_cookie" "/user/download.php?act=../../config" "No Act"

  user_logout_body="$WORK_DIR/user_logout.body"
  user_logout_check_body="$WORK_DIR/user_logout_check.body"
  if "$CURL_BIN" -sS -c "$user_cookie" -b "$user_cookie" -e "$BASE_URL/user/" \
    -o "$user_logout_body" "$BASE_URL/user/login.php?logout" &&
    grep -Eq "window.location.href='./login.php'" "$user_logout_body" &&
    "$CURL_BIN" -sS -b "$user_cookie" -o "$user_logout_check_body" "$BASE_URL/user/" &&
    grep -Eq "window.location.href='./login.php'" "$user_logout_check_body"; then
    printf '[OK] user_logout: token cleared and protected page redirects\n'
  else
    printf '[FAIL] user_logout: expected logout redirect and protected-page intercept\n'
    failures=$((failures + 1))
  fi
fi

fixture_key=$("$PHP_BIN" -r 'echo md5("php84-fixture");')

refund_fixture="$WORK_DIR/refund_fixture.env"
if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
  EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
  EPAY_DB_PREFIX="$DB_PREFIX" "$PHP_BIN" -r '
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
    $pass = getenv("EPAY_DB_PASSWORD");
    if ($pass === false) {
        $pass = "";
    }
    $pdo = new PDO($dsn, getenv("EPAY_DB_USER"), $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $stmt = $pdo->query("SELECT `refund_no`, `out_refund_no`, `trade_no`, `money` FROM `".$prefix."_refundorder` WHERE `uid`=1000 AND `status`=1 ORDER BY `addtime` DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        exit(1);
    }
    foreach (array(
        "fixture_refund_no" => $row["refund_no"],
        "fixture_out_refund_no" => $row["out_refund_no"],
        "fixture_refund_trade_no" => $row["trade_no"],
        "fixture_refund_money" => $row["money"],
    ) as $name => $value) {
        echo $name."=".escapeshellarg((string)$value)."\n";
    }
  ' >"$refund_fixture"; then
  . "$refund_fixture"
  printf '[OK] refund_fixture_loaded: refund_no=%s\n' "$fixture_refund_no"
else
  printf '[FAIL] refund_fixture_loaded: expected a paid refund fixture\n'
  failures=$((failures + 1))
  fixture_refund_no=""
  fixture_out_refund_no=""
  fixture_refund_trade_no=""
  fixture_refund_money=""
fi

api_query_body="$WORK_DIR/api_query.body"
if "$CURL_BIN" -sS -o "$api_query_body" "$BASE_URL/api.php?act=query&pid=1000&key=$fixture_key" &&
  check_json "$api_query_body" &&
  json_field_equals "$api_query_body" code 1; then
  printf '[OK] api_query: merchant account query works\n'
else
  printf '[FAIL] api_query: expected JSON code 1\n'
  failures=$((failures + 1))
fi

api_settle_body="$WORK_DIR/api_settle.body"
if "$CURL_BIN" -sS -o "$api_settle_body" "$BASE_URL/api.php?act=settle&pid=1000&key=$fixture_key&limit=5" &&
  check_json "$api_settle_body" &&
  json_field_equals "$api_settle_body" code 1; then
  printf '[OK] api_settle: settlement list query works\n'
else
  printf '[FAIL] api_settle: expected JSON code 1\n'
  failures=$((failures + 1))
fi

if [ -n "$fixture_refund_trade_no" ]; then
  api_order_body="$WORK_DIR/api_order.body"
  if "$CURL_BIN" -sS -o "$api_order_body" "$BASE_URL/api.php?act=order&pid=1000&key=$fixture_key&trade_no=$fixture_refund_trade_no" &&
    check_json "$api_order_body" &&
    json_field_equals "$api_order_body" code 1 &&
    json_field_equals "$api_order_body" status 2; then
    printf '[OK] api_order: refunded order query works\n'
  else
    printf '[FAIL] api_order: expected JSON code 1 and status 2\n'
    failures=$((failures + 1))
  fi
fi

other_fixture_key=$("$PHP_BIN" -r 'echo md5("php84-other-fixture");')

if [ -n "$fixture_refund_trade_no" ]; then
  api_order_cross_body="$WORK_DIR/api_order_cross_merchant.body"
  if "$CURL_BIN" -sS -o "$api_order_cross_body" "$BASE_URL/api.php?act=order&pid=1001&key=$other_fixture_key&trade_no=$fixture_refund_trade_no" &&
    check_json "$api_order_cross_body" &&
    json_field_equals "$api_order_cross_body" code -1; then
    printf '[OK] security_order_cross_merchant: other merchant cannot read order\n'
  else
    printf '[FAIL] security_order_cross_merchant: expected JSON code -1\n'
    failures=$((failures + 1))
  fi
fi

api_orders_body="$WORK_DIR/api_orders.body"
if "$CURL_BIN" -sS -o "$api_orders_body" "$BASE_URL/api.php?act=orders&pid=1000&key=$fixture_key&limit=5" &&
  check_json "$api_orders_body" &&
  json_field_equals "$api_orders_body" code 1; then
  printf '[OK] api_orders: order list query works\n'
else
  printf '[FAIL] api_orders: expected JSON code 1\n'
  failures=$((failures + 1))
fi

if [ -n "$fixture_out_refund_no" ]; then
  refundquery_post=$(make_signed_query "$fixture_key" \
    "pid=1000" \
    "out_refund_no=$fixture_out_refund_no" \
    "timestamp=$(date +%s)" \
    "sign_type=MD5")
  refundquery_body="$WORK_DIR/api_refundquery.body"
  if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
    --data "$refundquery_post" -o "$refundquery_body" "$BASE_URL/api/pay/refundquery" &&
    check_json "$refundquery_body" &&
    json_field_equals "$refundquery_body" code 0 &&
    json_field_equals "$refundquery_body" status 1; then
    printf '[OK] api_refundquery: signed refund query works\n'
  else
    printf '[FAIL] api_refundquery: expected JSON code 0 and status 1\n'
    failures=$((failures + 1))
  fi

  refund_duplicate_post=$(make_signed_query "$fixture_key" \
    "pid=1000" \
    "trade_no=$fixture_refund_trade_no" \
    "out_refund_no=$fixture_out_refund_no" \
    "money=$fixture_refund_money" \
    "timestamp=$(date +%s)" \
    "sign_type=MD5")
  refund_duplicate_body="$WORK_DIR/api_refund_duplicate.body"
  if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
    --data "$refund_duplicate_post" -o "$refund_duplicate_body" "$BASE_URL/api/pay/refund" &&
    check_json "$refund_duplicate_body" &&
    json_field_equals "$refund_duplicate_body" code 0 &&
    grep -Eq "已存在相同退款单号" "$refund_duplicate_body"; then
    printf '[OK] api_refund_duplicate: signed duplicate refund returns existing order\n'
  else
    printf '[FAIL] api_refund_duplicate: expected duplicate refund success JSON\n'
    failures=$((failures + 1))
  fi

  refundquery_cross_post=$(make_signed_query "$other_fixture_key" \
    "pid=1001" \
    "out_refund_no=$fixture_out_refund_no" \
    "timestamp=$(date +%s)" \
    "sign_type=MD5")
  refundquery_cross_body="$WORK_DIR/api_refundquery_cross_merchant.body"
  if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
    --data "$refundquery_cross_post" -o "$refundquery_cross_body" "$BASE_URL/api/pay/refundquery" &&
    check_json "$refundquery_cross_body" &&
    json_field_equals "$refundquery_cross_body" code -1; then
    printf '[OK] security_refund_cross_merchant: other merchant cannot read refund\n'
  else
    printf '[FAIL] security_refund_cross_merchant: expected JSON code -1\n'
    failures=$((failures + 1))
  fi
fi

refund_unsupported_fixture="$WORK_DIR/refund_unsupported_fixture.env"
if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
  EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
  EPAY_DB_PREFIX="$DB_PREFIX" "$PHP_BIN" -r '
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
    $pass = getenv("EPAY_DB_PASSWORD");
    if ($pass === false) {
        $pass = "";
    }
    $pdo = new PDO($dsn, getenv("EPAY_DB_USER"), $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $pdo->prepare("INSERT INTO `".$prefix."_channel` (`type`, `plugin`, `name`, `rate`, `status`, `config`) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute(array(1, "kayixin", "No refund", "100.00", 0, "{}"));
    $channelId = $pdo->lastInsertId();
    $tradeNo = date("YmdHis") . random_int(10000, 99999);
    $stmt = $pdo->prepare("INSERT INTO `".$prefix."_order` (`trade_no`, `out_trade_no`, `uid`, `type`, `channel`, `name`, `money`, `realmoney`, `getmoney`, `addtime`, `endtime`, `date`, `status`, `api_trade_no`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), CURDATE(), ?, ?)");
    $stmt->execute(array($tradeNo, "norefund-".$tradeNo, 1000, 1, $channelId, "No refund fixture", "1.11", "1.11", "1.11", 1, "UNSUPPORTED".$tradeNo));
    $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
    $refundCount = $pdo->query("SELECT COUNT(*) FROM `".$prefix."_refundorder` WHERE `uid`=1000")->fetchColumn();
    echo "unsupported_refund_trade_no=".escapeshellarg((string)$tradeNo)."\n";
    echo "unsupported_refund_user_money_before=".escapeshellarg((string)$userMoney)."\n";
    echo "unsupported_refund_count_before=".escapeshellarg((string)$refundCount)."\n";
  ' >"$refund_unsupported_fixture"; then
  . "$refund_unsupported_fixture"
  printf '[OK] refund_unsupported_fixture: paid order created on unsupported refund channel\n'
else
  printf '[FAIL] refund_unsupported_fixture: unable to create unsupported refund order\n'
  failures=$((failures + 1))
  unsupported_refund_trade_no=""
  unsupported_refund_user_money_before=""
  unsupported_refund_count_before=""
fi

if [ -n "$unsupported_refund_trade_no" ]; then
  unsupported_refund_post=$(make_signed_query "$fixture_key" \
    "pid=1000" \
    "trade_no=$unsupported_refund_trade_no" \
    "out_refund_no=unsupported-$unsupported_refund_trade_no" \
    "money=0.11" \
    "timestamp=$(date +%s)" \
    "sign_type=MD5")
  unsupported_refund_body="$WORK_DIR/api_refund_unsupported.body"
  if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
    --data "$unsupported_refund_post" -o "$unsupported_refund_body" "$BASE_URL/api/pay/refund" &&
    check_json "$unsupported_refund_body" &&
    json_field_equals "$unsupported_refund_body" code -1 &&
    grep -Eq "不支持API退款" "$unsupported_refund_body" &&
    EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
      EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
      EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$unsupported_refund_trade_no" \
      EPAY_USER_MONEY_BEFORE="$unsupported_refund_user_money_before" \
      EPAY_REFUND_COUNT_BEFORE="$unsupported_refund_count_before" "$PHP_BIN" -r '
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
        $stmt = $pdo->prepare("SELECT `status`, `refundmoney` FROM `".$prefix."_order` WHERE `trade_no`=? LIMIT 1");
        $stmt->execute(array(getenv("EPAY_TRADE_NO")));
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
        $refundCount = $pdo->query("SELECT COUNT(*) FROM `".$prefix."_refundorder` WHERE `uid`=1000")->fetchColumn();
        if (!$order || (string)$order["status"] !== "1" || number_format((float)$order["refundmoney"], 2, ".", "") !== "0.00") {
            exit(1);
        }
        if (number_format((float)$userMoney, 2, ".", "") !== number_format((float)getenv("EPAY_USER_MONEY_BEFORE"), 2, ".", "")) {
            exit(1);
        }
        exit((string)$refundCount === (string)getenv("EPAY_REFUND_COUNT_BEFORE") ? 0 : 1);
      '; then
    printf '[OK] api_refund_unsupported_plugin: rejected without state change\n'
  else
    printf '[FAIL] api_refund_unsupported_plugin: expected unsupported refund error without state change\n'
    failures=$((failures + 1))
  fi
fi

transfer_fixture="$WORK_DIR/transfer_fixture.env"
if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
  EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
  EPAY_DB_PREFIX="$DB_PREFIX" "$PHP_BIN" -r '
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
    $pass = getenv("EPAY_DB_PASSWORD");
    if ($pass === false) {
        $pass = "";
    }
    $pdo = new PDO($dsn, getenv("EPAY_DB_USER"), $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $stmt = $pdo->query("SELECT `biz_no` FROM `".$prefix."_transfer` WHERE `uid`=1000 AND `status`=1 ORDER BY `biz_no` DESC LIMIT 1");
    $bizNo = $stmt->fetchColumn();
    if (!$bizNo) {
        exit(1);
    }
    echo "fixture_transfer_biz_no=".escapeshellarg((string)$bizNo)."\n";
  ' >"$transfer_fixture"; then
  . "$transfer_fixture"
  printf '[OK] transfer_fixture_loaded: biz_no=%s\n' "$fixture_transfer_biz_no"
else
  printf '[FAIL] transfer_fixture_loaded: expected a successful transfer fixture\n'
  failures=$((failures + 1))
  fixture_transfer_biz_no=""
fi

transfer_balance_post=$(make_signed_query "$fixture_key" \
  "pid=1000" \
  "timestamp=$(date +%s)" \
  "sign_type=MD5")
transfer_balance_body="$WORK_DIR/api_transfer_balance.body"
if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
  --data "$transfer_balance_post" -o "$transfer_balance_body" "$BASE_URL/api/transfer/balance" &&
  check_json "$transfer_balance_body" &&
  json_field_equals "$transfer_balance_body" code 0; then
  printf '[OK] api_transfer_balance: signed transfer balance query works\n'
else
  printf '[FAIL] api_transfer_balance: expected JSON code 0\n'
  failures=$((failures + 1))
fi

if [ -n "$fixture_transfer_biz_no" ]; then
  transfer_query_post=$(make_signed_query "$fixture_key" \
    "pid=1000" \
    "out_biz_no=$fixture_transfer_biz_no" \
    "timestamp=$(date +%s)" \
    "sign_type=MD5")
  transfer_query_body="$WORK_DIR/api_transfer_query.body"
  if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
    --data "$transfer_query_post" -o "$transfer_query_body" "$BASE_URL/api/transfer/query" &&
    check_json "$transfer_query_body" &&
    json_field_equals "$transfer_query_body" code 0 &&
    json_field_equals "$transfer_query_body" status 1; then
    printf '[OK] api_transfer_query: signed successful transfer query works\n'
  else
    printf '[FAIL] api_transfer_query: expected JSON code 0 and status 1\n'
    failures=$((failures + 1))
  fi

  transfer_query_cross_post=$(make_signed_query "$other_fixture_key" \
    "pid=1001" \
    "out_biz_no=$fixture_transfer_biz_no" \
    "timestamp=$(date +%s)" \
    "sign_type=MD5")
  transfer_query_cross_body="$WORK_DIR/api_transfer_query_cross_merchant.body"
  if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
    --data "$transfer_query_cross_post" -o "$transfer_query_cross_body" "$BASE_URL/api/transfer/query" &&
    check_json "$transfer_query_cross_body" &&
    json_field_equals "$transfer_query_cross_body" code -1; then
    printf '[OK] security_transfer_cross_merchant: other merchant cannot read transfer\n'
  else
    printf '[FAIL] security_transfer_cross_merchant: expected JSON code -1\n'
    failures=$((failures + 1))
  fi
fi

if configure_mock_transfer_channel; then
  api_transfer_submit_snapshot="$WORK_DIR/api_transfer_submit_snapshot.env"
  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" "$PHP_BIN" -r '
      $prefix = getenv("EPAY_DB_PREFIX");
      if (!preg_match("/^[A-Za-z0-9_]+$/", $prefix)) {
          exit(1);
      }
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
      echo "transfer_submit_user_money_before=".escapeshellarg((string)$pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn())."\n";
    ' >"$api_transfer_submit_snapshot"; then
    . "$api_transfer_submit_snapshot"
  else
    transfer_submit_user_money_before=""
  fi

  transfer_submit_biz_no="$(date +%Y%m%d%H%M%S)$(awk 'BEGIN{srand(); printf "%05d", int(rand()*89999)+10000}')"
  transfer_submit_post=$(make_signed_query "$fixture_key" \
    "pid=1000" \
    "type=alipay" \
    "out_biz_no=$transfer_submit_biz_no" \
    "account=transfer-submit@example.com" \
    "name=PHP84 transfer submit" \
    "money=1.01" \
    "remark=PHP84 submit" \
    "timestamp=$(date +%s)" \
    "sign_type=MD5")
  transfer_submit_body="$WORK_DIR/api_transfer_submit.body"
  if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
    --data "$transfer_submit_post" -o "$transfer_submit_body" "$BASE_URL/api/transfer/submit" &&
    check_json "$transfer_submit_body" &&
    json_field_equals "$transfer_submit_body" code 0 &&
    json_field_equals "$transfer_submit_body" status 1 &&
    json_field_equals "$transfer_submit_body" out_biz_no "$transfer_submit_biz_no" &&
    EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
      EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
      EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRANSFER_BIZ_NO="$transfer_submit_biz_no" \
      EPAY_USER_MONEY_BEFORE="$transfer_submit_user_money_before" "$PHP_BIN" -r '
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
        $stmt = $pdo->prepare("SELECT `uid`, `type`, `money`, `costmoney`, `status`, `pay_order_no` FROM `".$prefix."_transfer` WHERE `biz_no`=? LIMIT 1");
        $stmt->execute(array(getenv("EPAY_TRANSFER_BIZ_NO")));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
        if (!$row || (string)$row["uid"] !== "1000" || $row["type"] !== "alipay" || number_format((float)$row["money"], 2, ".", "") !== "1.01" || number_format((float)$row["costmoney"], 2, ".", "") !== "1.01" || (string)$row["status"] !== "1") {
            exit(1);
        }
        if (strpos($row["pay_order_no"], "MOCKPAY") !== 0) {
            exit(1);
        }
        $expectedMoney = number_format(round((float)getenv("EPAY_USER_MONEY_BEFORE") - 1.01, 2), 2, ".", "");
        exit(number_format((float)$userMoney, 2, ".", "") === $expectedMoney ? 0 : 1);
      '; then
    printf '[OK] api_transfer_submit: signed transfer submit created successful transfer and deducted balance\n'
  else
    printf '[FAIL] api_transfer_submit: expected successful transfer submit and state changes\n'
    "$PHP_BIN" -r '
      $body = file_get_contents($argv[1]);
      $body = preg_replace("/\s+/u", " ", strip_tags($body));
      echo mb_substr($body, 0, 300), PHP_EOL;
    ' "$transfer_submit_body"
    failures=$((failures + 1))
  fi

  transfer_proof_post=$(make_signed_query "$fixture_key" \
    "pid=1000" \
    "out_biz_no=$transfer_submit_biz_no" \
    "timestamp=$(date +%s)" \
    "sign_type=MD5")
  transfer_proof_body="$WORK_DIR/api_transfer_proof.body"
  if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
    --data "$transfer_proof_post" -o "$transfer_proof_body" "$BASE_URL/api/transfer/proof" &&
    check_json "$transfer_proof_body" &&
    json_field_equals "$transfer_proof_body" code 0 &&
    json_field_equals "$transfer_proof_body" url "https://example.test/php84/transfer-proof/$transfer_submit_biz_no"; then
    printf '[OK] api_transfer_proof: signed transfer proof returns proof URL\n'
  else
    printf '[FAIL] api_transfer_proof: expected proof URL JSON\n'
    "$PHP_BIN" -r '
      $body = file_get_contents($argv[1]);
      $body = preg_replace("/\s+/u", " ", strip_tags($body));
      echo mb_substr($body, 0, 300), PHP_EOL;
    ' "$transfer_proof_body"
    failures=$((failures + 1))
  fi
else
  printf '[FAIL] api_transfer_submit_fixture: unable to configure mock transfer channel\n'
  failures=$((failures + 1))
fi

transfer_action_fixture="$WORK_DIR/transfer_action_fixture.env"
if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
  EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
  EPAY_DB_PREFIX="$DB_PREFIX" "$PHP_BIN" -r '
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
    $pass = getenv("EPAY_DB_PASSWORD");
    if ($pass === false) {
        $pass = "";
    }
    $pdo = new PDO($dsn, getenv("EPAY_DB_USER"), $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $channelId = $pdo->query("SELECT `id` FROM `".$prefix."_channel` WHERE `plugin`=\"epay\" ORDER BY `id` DESC LIMIT 1")->fetchColumn();
    if (!$channelId) {
        exit(1);
    }
    $base = date("YmdHis");
    $statusBizNo = $base . random_int(10000, 99999);
    $refundBizNo = $base . random_int(10000, 99999);
    $stmt = $pdo->prepare("INSERT INTO `".$prefix."_transfer` (`biz_no`, `pay_order_no`, `uid`, `type`, `channel`, `account`, `username`, `money`, `costmoney`, `paytime`, `status`, `desc`, `result`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)");
    $stmt->execute(array($statusBizNo, "PAY".$statusBizNo, 1000, "alipay", $channelId, "transfer-status@example.com", "PHP84 transfer status", "1.00", "1.00", 0, "status fixture", "PHP84 transfer result fixture"));
    $stmt->execute(array($refundBizNo, "PAY".$refundBizNo, 1000, "alipay", $channelId, "transfer-refund@example.com", "PHP84 transfer refund", "1.23", "1.23", 0, "refund fixture", "pending refund"));
    $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
    echo "transfer_status_biz_no=".escapeshellarg((string)$statusBizNo)."\n";
    echo "transfer_refund_biz_no=".escapeshellarg((string)$refundBizNo)."\n";
    echo "transfer_refund_user_money_before=".escapeshellarg((string)$userMoney)."\n";
  ' >"$transfer_action_fixture"; then
  . "$transfer_action_fixture"
  printf '[OK] transfer_action_fixture: pending transfer rows created\n'
else
  printf '[FAIL] transfer_action_fixture: unable to create pending transfer rows\n'
  failures=$((failures + 1))
  transfer_status_biz_no=""
  transfer_refund_biz_no=""
  transfer_refund_user_money_before=""
fi

if [ -n "$transfer_status_biz_no" ]; then
  transfer_result_body="$WORK_DIR/admin_transfer_result.body"
  if "$CURL_BIN" -sS -b "$admin_cookie" -e "$BASE_URL/admin/transfer.php" \
    -o "$transfer_result_body" "$BASE_URL/admin/ajax_transfer.php?act=transfer_result&biz_no=$transfer_status_biz_no" &&
    check_json "$transfer_result_body" &&
    json_field_equals "$transfer_result_body" code 0 &&
    grep -Eq "PHP84 transfer result fixture" "$transfer_result_body"; then
    printf '[OK] admin_transfer_result: transfer result can be read\n'
  else
    printf '[FAIL] admin_transfer_result: expected stored transfer result\n'
    failures=$((failures + 1))
  fi

  transfer_status_body="$WORK_DIR/admin_transfer_status.body"
  if "$CURL_BIN" -sS -b "$admin_cookie" -e "$BASE_URL/admin/transfer.php" \
    -X POST --data-urlencode "biz_no=$transfer_status_biz_no" --data-urlencode "status=1" \
    -o "$transfer_status_body" "$BASE_URL/admin/ajax_transfer.php?act=setTransferStatus" &&
    check_json "$transfer_status_body" &&
    json_field_equals "$transfer_status_body" code 0 &&
    EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
      EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
      EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRANSFER_BIZ_NO="$transfer_status_biz_no" "$PHP_BIN" -r '
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
        $stmt = $pdo->prepare("SELECT `status` FROM `".$prefix."_transfer` WHERE `biz_no`=? LIMIT 1");
        $stmt->execute(array(getenv("EPAY_TRANSFER_BIZ_NO")));
        exit((string)$stmt->fetchColumn() === "1" ? 0 : 1);
      '; then
    printf '[OK] admin_transfer_status: transfer status updated\n'
  else
    printf '[FAIL] admin_transfer_status: expected JSON code 0 and status 1\n'
    failures=$((failures + 1))
  fi
fi

if [ -n "$transfer_refund_biz_no" ]; then
  transfer_refund_body="$WORK_DIR/admin_transfer_refund.body"
  transfer_refund_duplicate_body="$WORK_DIR/admin_transfer_refund_duplicate.body"
  if "$CURL_BIN" -sS -b "$admin_cookie" -e "$BASE_URL/admin/transfer.php" \
    -X POST --data-urlencode "biz_no=$transfer_refund_biz_no" \
    -o "$transfer_refund_body" "$BASE_URL/admin/ajax_transfer.php?act=refundTransfer" &&
    check_json "$transfer_refund_body" &&
    json_field_equals "$transfer_refund_body" code 0 &&
    "$CURL_BIN" -sS -b "$admin_cookie" -e "$BASE_URL/admin/transfer.php" \
      -X POST --data-urlencode "biz_no=$transfer_refund_biz_no" \
      -o "$transfer_refund_duplicate_body" "$BASE_URL/admin/ajax_transfer.php?act=refundTransfer" &&
    check_json "$transfer_refund_duplicate_body" &&
    json_field_equals "$transfer_refund_duplicate_body" code 0 &&
    EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
      EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
      EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRANSFER_BIZ_NO="$transfer_refund_biz_no" \
      EPAY_USER_MONEY_BEFORE="$transfer_refund_user_money_before" "$PHP_BIN" -r '
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
        $stmt = $pdo->prepare("SELECT `status`, `costmoney` FROM `".$prefix."_transfer` WHERE `biz_no`=? LIMIT 1");
        $stmt->execute(array(getenv("EPAY_TRANSFER_BIZ_NO")));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
        if (!$row || (string)$row["status"] !== "2") {
            exit(1);
        }
        $expectedMoney = number_format(round((float)getenv("EPAY_USER_MONEY_BEFORE") + (float)$row["costmoney"], 2), 2, ".", "");
        exit(number_format((float)$userMoney, 2, ".", "") === $expectedMoney ? 0 : 1);
      '; then
    printf '[OK] admin_transfer_refund: transfer refund is idempotent\n'
  else
    printf '[FAIL] admin_transfer_refund: expected one balance refund after duplicate action\n'
    failures=$((failures + 1))
  fi
fi

settle_action_fixture="$WORK_DIR/settle_action_fixture.env"
if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
  EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
  EPAY_DB_PREFIX="$DB_PREFIX" "$PHP_BIN" -r '
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
    $pass = getenv("EPAY_DB_PASSWORD");
    if ($pass === false) {
        $pass = "";
    }
    $pdo = new PDO($dsn, getenv("EPAY_DB_USER"), $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $stmt = $pdo->prepare("INSERT INTO `".$prefix."_settle` (`uid`, `type`, `account`, `username`, `money`, `realmoney`, `addtime`, `status`) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)");
    $stmt->execute(array(1000, 1, "settle-status@example.com", "PHP84 settle status", "4.00", "3.98", 0));
    $statusId = $pdo->lastInsertId();
    $stmt->execute(array(1000, 1, "settle-batch@example.com", "PHP84 settle batch", "2.35", "2.34", 0));
    $batchId = $pdo->lastInsertId();
    echo "settle_status_id=".escapeshellarg((string)$statusId)."\n";
    echo "settle_batch_id=".escapeshellarg((string)$batchId)."\n";
  ' >"$settle_action_fixture"; then
  . "$settle_action_fixture"
  printf '[OK] settle_action_fixture: pending settle rows created\n'
else
  printf '[FAIL] settle_action_fixture: unable to create pending settle rows\n'
  failures=$((failures + 1))
  settle_status_id=""
  settle_batch_id=""
fi

if [ -n "$settle_status_id" ]; then
  settle_status_body="$WORK_DIR/admin_settle_status.body"
  if "$CURL_BIN" -sS -b "$admin_cookie" -e "$BASE_URL/admin/settle.php" \
    -o "$settle_status_body" "$BASE_URL/admin/ajax_settle.php?act=setSettleStatus&id=$settle_status_id&status=1" &&
    check_json "$settle_status_body" &&
    json_field_equals "$settle_status_body" code 200 &&
    ! grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$settle_status_body" &&
    EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
      EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
      EPAY_DB_PREFIX="$DB_PREFIX" EPAY_SETTLE_ID="$settle_status_id" "$PHP_BIN" -r '
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
        $stmt = $pdo->prepare("SELECT `status`, `endtime` FROM `".$prefix."_settle` WHERE `id`=? LIMIT 1");
        $stmt->execute(array(getenv("EPAY_SETTLE_ID")));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        exit($row && (string)$row["status"] === "1" && !empty($row["endtime"]) && $row["endtime"] !== "0000-00-00 00:00:00" ? 0 : 1);
      '; then
    printf '[OK] admin_settle_status: single settle status completed with endtime\n'
  else
    printf '[FAIL] admin_settle_status: expected JSON code 200 and completed settle row\n'
    failures=$((failures + 1))
  fi
fi

user_other_cookie="$WORK_DIR/user_other.cookie"
if login_user_key "$user_other_cookie" 1001 "$other_fixture_key" user_other; then
  printf '[OK] user_center_other_login: second merchant logged in\n'
else
  printf '[FAIL] user_center_other_login: expected second merchant login success\n'
  failures=$((failures + 1))
fi

if [ -f "$user_other_cookie" ] && [ -n "$fixture_refund_trade_no" ]; then
  user_cross_order_body="$WORK_DIR/user_cross_order.body"
  if "$CURL_BIN" -sS -b "$user_other_cookie" -e "$BASE_URL/user/order.php" \
    -o "$user_cross_order_body" "$BASE_URL/user/ajax2.php?act=order&trade_no=$fixture_refund_trade_no" &&
    check_json "$user_cross_order_body" &&
    json_field_equals "$user_cross_order_body" code -1; then
    printf '[OK] security_user_order_cross_merchant: other user cannot read order detail\n'
  else
    printf '[FAIL] security_user_order_cross_merchant: expected JSON code -1\n'
    failures=$((failures + 1))
  fi

  user_cross_order_list_body="$WORK_DIR/user_cross_order_list.body"
  if "$CURL_BIN" -sS -b "$user_other_cookie" -e "$BASE_URL/user/order.php" \
    -X POST --data-urlencode "offset=0" --data-urlencode "limit=20" --data-urlencode "type=1" --data-urlencode "kw=$fixture_refund_trade_no" \
    -o "$user_cross_order_list_body" "$BASE_URL/user/ajax2.php?act=orderList" &&
    check_json "$user_cross_order_list_body" &&
    json_field_equals "$user_cross_order_list_body" total 0; then
    printf '[OK] security_user_order_list_cross_merchant: other user list excludes order\n'
  else
    printf '[FAIL] security_user_order_list_cross_merchant: expected empty order list\n'
    failures=$((failures + 1))
  fi
fi

if [ -f "$user_other_cookie" ] && [ -n "$settle_status_id" ]; then
  user_cross_settle_body="$WORK_DIR/user_cross_settle_result.body"
  if "$CURL_BIN" -sS -b "$user_other_cookie" -e "$BASE_URL/user/settle.php" \
    -o "$user_cross_settle_body" "$BASE_URL/user/ajax2.php?act=settle_result&id=$settle_status_id" &&
    check_json "$user_cross_settle_body" &&
    json_field_equals "$user_cross_settle_body" code -1; then
    printf '[OK] security_user_settle_cross_merchant: other user cannot read settle result\n'
  else
    printf '[FAIL] security_user_settle_cross_merchant: expected JSON code -1\n'
    failures=$((failures + 1))
  fi

  user_cross_settle_list_body="$WORK_DIR/user_cross_settle_list.body"
  if "$CURL_BIN" -sS -b "$user_other_cookie" -e "$BASE_URL/user/settle.php" \
    -X POST --data-urlencode "offset=0" --data-urlencode "limit=20" \
    -o "$user_cross_settle_list_body" "$BASE_URL/user/ajax2.php?act=settleList" &&
    check_json "$user_cross_settle_list_body" &&
    json_field_equals "$user_cross_settle_list_body" total 0; then
    printf '[OK] security_user_settle_list_cross_merchant: other user list excludes settlements\n'
  else
    printf '[FAIL] security_user_settle_list_cross_merchant: expected empty settle list\n'
    failures=$((failures + 1))
  fi
fi

if [ -f "$user_other_cookie" ] && [ -n "$transfer_status_biz_no" ]; then
  user_cross_transfer_body="$WORK_DIR/user_cross_transfer_result.body"
  if "$CURL_BIN" -sS -b "$user_other_cookie" -e "$BASE_URL/user/transfer.php" \
    -o "$user_cross_transfer_body" "$BASE_URL/user/ajax2.php?act=transfer_result&biz_no=$transfer_status_biz_no" &&
    check_json "$user_cross_transfer_body" &&
    json_field_equals "$user_cross_transfer_body" code -1; then
    printf '[OK] security_user_transfer_cross_merchant: other user cannot read transfer result\n'
  else
    printf '[FAIL] security_user_transfer_cross_merchant: expected JSON code -1\n'
    failures=$((failures + 1))
  fi

  user_cross_transfer_query_body="$WORK_DIR/user_cross_transfer_query.body"
  if "$CURL_BIN" -sS -b "$user_other_cookie" -e "$BASE_URL/user/transfer.php" \
    -o "$user_cross_transfer_query_body" "$BASE_URL/user/ajax2.php?act=transfer_query&biz_no=$transfer_status_biz_no" &&
    check_json "$user_cross_transfer_query_body" &&
    json_field_equals "$user_cross_transfer_query_body" code -1; then
    printf '[OK] security_user_transfer_query_cross_merchant: other user cannot refresh transfer status\n'
  else
    printf '[FAIL] security_user_transfer_query_cross_merchant: expected JSON code -1\n'
    failures=$((failures + 1))
  fi

  user_cross_transfer_proof_body="$WORK_DIR/user_cross_transfer_proof.body"
  if "$CURL_BIN" -sS -b "$user_other_cookie" -e "$BASE_URL/user/transfer.php" \
    -X POST --data-urlencode "biz_no=$transfer_status_biz_no" \
    -o "$user_cross_transfer_proof_body" "$BASE_URL/user/ajax2.php?act=transfer_proof" &&
    check_json "$user_cross_transfer_proof_body" &&
    json_field_equals "$user_cross_transfer_proof_body" code -1; then
    printf '[OK] security_user_transfer_proof_cross_merchant: other user cannot request transfer proof\n'
  else
    printf '[FAIL] security_user_transfer_proof_cross_merchant: expected JSON code -1\n'
    failures=$((failures + 1))
  fi

  user_cross_transfer_list_body="$WORK_DIR/user_cross_transfer_list.body"
  if "$CURL_BIN" -sS -b "$user_other_cookie" -e "$BASE_URL/user/transfer.php" \
    -X POST --data-urlencode "offset=0" --data-urlencode "limit=20" --data-urlencode "value=$transfer_status_biz_no" \
    -o "$user_cross_transfer_list_body" "$BASE_URL/user/ajax2.php?act=transferList" &&
    check_json "$user_cross_transfer_list_body" &&
    json_field_equals "$user_cross_transfer_list_body" total 0; then
    printf '[OK] security_user_transfer_list_cross_merchant: other user list excludes transfer\n'
  else
    printf '[FAIL] security_user_transfer_list_cross_merchant: expected empty transfer list\n'
    failures=$((failures + 1))
  fi
fi

if [ -n "$settle_batch_id" ]; then
  settle_batch_body="$WORK_DIR/admin_settle_create_batch.body"
  if "$CURL_BIN" -sS -b "$admin_cookie" -e "$BASE_URL/admin/settle.php" \
    -o "$settle_batch_body" "$BASE_URL/admin/ajax_settle.php?act=create_batch" &&
    check_json "$settle_batch_body" &&
    json_field_equals "$settle_batch_body" code 0 &&
    ! grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$settle_batch_body"; then
    settle_batch_no=$(json_field "$settle_batch_body" batch || true)
  else
    printf '[FAIL] admin_settle_create_batch: expected JSON code 0\n'
    failures=$((failures + 1))
    settle_batch_no=""
  fi

  if [ -n "$settle_batch_no" ] &&
    EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
      EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
      EPAY_DB_PREFIX="$DB_PREFIX" EPAY_SETTLE_ID="$settle_batch_id" EPAY_SETTLE_BATCH="$settle_batch_no" "$PHP_BIN" -r '
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
        $stmt = $pdo->prepare("SELECT `status`, `batch` FROM `".$prefix."_settle` WHERE `id`=? LIMIT 1");
        $stmt->execute(array(getenv("EPAY_SETTLE_ID")));
        $settle = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare("SELECT `count`, `allmoney`, `status` FROM `".$prefix."_batch` WHERE `batch`=? LIMIT 1");
        $stmt->execute(array(getenv("EPAY_SETTLE_BATCH")));
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$settle || !$batch || (string)$settle["status"] !== "2" || $settle["batch"] !== getenv("EPAY_SETTLE_BATCH")) {
            exit(1);
        }
        if ((int)$batch["count"] !== 1 || number_format((float)$batch["allmoney"], 2, ".", "") !== "2.34" || (string)$batch["status"] !== "0") {
            exit(1);
        }
      '; then
    printf '[OK] admin_settle_create_batch: pending settle row moved into batch %s\n' "$settle_batch_no"
  else
    printf '[FAIL] admin_settle_create_batch_state: expected status 2 and batch summary\n'
    failures=$((failures + 1))
  fi

  settle_complete_body="$WORK_DIR/admin_settle_complete_batch.body"
  if [ -n "$settle_batch_no" ] &&
    "$CURL_BIN" -sS -b "$admin_cookie" -e "$BASE_URL/admin/settle.php" \
      -X POST --data-urlencode "batch=$settle_batch_no" \
      -o "$settle_complete_body" "$BASE_URL/admin/ajax_settle.php?act=complete_batch" &&
    check_json "$settle_complete_body" &&
    json_field_equals "$settle_complete_body" code 0 &&
    EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
      EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
      EPAY_DB_PREFIX="$DB_PREFIX" EPAY_SETTLE_ID="$settle_batch_id" "$PHP_BIN" -r '
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
        $stmt = $pdo->prepare("SELECT `status` FROM `".$prefix."_settle` WHERE `id`=? LIMIT 1");
        $stmt->execute(array(getenv("EPAY_SETTLE_ID")));
        exit((string)$stmt->fetchColumn() === "1" ? 0 : 1);
      '; then
    printf '[OK] admin_settle_complete_batch: batch settle row marked complete\n'
  else
    printf '[FAIL] admin_settle_complete_batch: expected JSON code 0 and status 1\n'
    failures=$((failures + 1))
  fi
fi

invalid_merchant_post=$(make_signed_query "missing-merchant-key" \
  "pid=999999" \
  "type=alipay" \
  "out_trade_no=php84-bad-merchant-$(date +%Y%m%d%H%M%S)-$$" \
  "notify_url=$BASE_URL/merchant-notify-bad-merchant" \
  "return_url=$BASE_URL/merchant-return-bad-merchant" \
  "name=Bad merchant fixture" \
  "money=1.00" \
  "clientip=127.0.0.1" \
  "device=pc" \
  "method=jump" \
  "sign_type=MD5")
invalid_merchant_body="$WORK_DIR/payment_create_invalid_merchant.body"
if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
  --data "$invalid_merchant_post" -o "$invalid_merchant_body" "$BASE_URL/mapi.php" &&
  check_json "$invalid_merchant_body" &&
  json_field_equals "$invalid_merchant_body" code -1 &&
  grep -Eq "商户不存在" "$invalid_merchant_body"; then
  printf '[OK] payment_create_invalid_merchant: rejected as JSON\n'
else
  printf '[FAIL] payment_create_invalid_merchant: expected merchant missing JSON\n'
  failures=$((failures + 1))
fi

bad_sign_out_trade_no="php84-badsign-$(date +%Y%m%d%H%M%S)-$$"
bad_sign_post=$(make_signed_query "wrong-fixture-key" \
  "pid=1000" \
  "type=alipay" \
  "out_trade_no=$bad_sign_out_trade_no" \
  "notify_url=$BASE_URL/merchant-notify-badsign" \
  "return_url=$BASE_URL/merchant-return-badsign" \
  "name=Bad signature fixture" \
  "money=1.00" \
  "clientip=127.0.0.1" \
  "device=pc" \
  "method=jump" \
  "sign_type=MD5")
bad_sign_body="$WORK_DIR/payment_create_bad_signature.body"
if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
  --data "$bad_sign_post" -o "$bad_sign_body" "$BASE_URL/mapi.php" &&
  check_json "$bad_sign_body" &&
  json_field_equals "$bad_sign_body" code -3 &&
  grep -Eq "签名校验失败" "$bad_sign_body" &&
  order_count_equals "$bad_sign_out_trade_no" 0; then
  printf '[OK] payment_create_bad_signature: rejected without order\n'
else
  printf '[FAIL] payment_create_bad_signature: expected JSON code -3 and no order\n'
  failures=$((failures + 1))
fi

invalid_money_out_trade_no="php84-badmoney-$(date +%Y%m%d%H%M%S)-$$"
invalid_money_post=$(make_signed_query "$fixture_key" \
  "pid=1000" \
  "type=alipay" \
  "out_trade_no=$invalid_money_out_trade_no" \
  "notify_url=$BASE_URL/merchant-notify-badmoney" \
  "return_url=$BASE_URL/merchant-return-badmoney" \
  "name=Bad money fixture" \
  "money=abc" \
  "clientip=127.0.0.1" \
  "device=pc" \
  "method=jump" \
  "sign_type=MD5")
invalid_money_body="$WORK_DIR/payment_create_invalid_money.body"
if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
  --data "$invalid_money_post" -o "$invalid_money_body" "$BASE_URL/mapi.php" &&
  check_json "$invalid_money_body" &&
  json_field_equals "$invalid_money_body" code -1 &&
  grep -Eq "金额不合法" "$invalid_money_body" &&
  order_count_equals "$invalid_money_out_trade_no" 0; then
  printf '[OK] payment_create_invalid_money: rejected without order\n'
else
  printf '[FAIL] payment_create_invalid_money: expected invalid money JSON and no order\n'
  failures=$((failures + 1))
fi

duplicate_out_trade_no="php84-dup-$(date +%Y%m%d%H%M%S)-$$"
duplicate_post=$(make_signed_query "$fixture_key" \
  "pid=1000" \
  "type=alipay" \
  "out_trade_no=$duplicate_out_trade_no" \
  "notify_url=$BASE_URL/merchant-notify-dup" \
  "return_url=$BASE_URL/merchant-return-dup" \
  "name=Duplicate fixture" \
  "money=1.27" \
  "clientip=127.0.0.1" \
  "device=pc" \
  "method=jump" \
  "sign_type=MD5")
duplicate_first_body="$WORK_DIR/payment_create_duplicate_first.body"
duplicate_second_body="$WORK_DIR/payment_create_duplicate_second.body"
if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
  --data "$duplicate_post" -o "$duplicate_first_body" "$BASE_URL/mapi.php" &&
  "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
  --data "$duplicate_post" -o "$duplicate_second_body" "$BASE_URL/mapi.php" &&
  check_json "$duplicate_first_body" &&
  check_json "$duplicate_second_body" &&
  json_field_equals "$duplicate_first_body" code 1 &&
  json_field_equals "$duplicate_second_body" code 1 &&
  order_count_equals "$duplicate_out_trade_no" 1; then
  duplicate_first_trade_no=$(json_field "$duplicate_first_body" trade_no || true)
  duplicate_second_trade_no=$(json_field "$duplicate_second_body" trade_no || true)
  if [ -n "$duplicate_first_trade_no" ] && [ "$duplicate_first_trade_no" = "$duplicate_second_trade_no" ]; then
    printf '[OK] payment_create_duplicate_same_params: reused existing unpaid order\n'
  else
    printf '[FAIL] payment_create_duplicate_same_params: expected same trade_no\n'
    failures=$((failures + 1))
  fi
else
  printf '[FAIL] payment_create_duplicate_same_params: expected one reusable unpaid order\n'
  failures=$((failures + 1))
fi

duplicate_changed_post=$(make_signed_query "$fixture_key" \
  "pid=1000" \
  "type=alipay" \
  "out_trade_no=$duplicate_out_trade_no" \
  "notify_url=$BASE_URL/merchant-notify-dup" \
  "return_url=$BASE_URL/merchant-return-dup" \
  "name=Duplicate fixture" \
  "money=1.28" \
  "clientip=127.0.0.1" \
  "device=pc" \
  "method=jump" \
  "sign_type=MD5")
duplicate_changed_body="$WORK_DIR/payment_create_duplicate_changed.body"
if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
  --data "$duplicate_changed_post" -o "$duplicate_changed_body" "$BASE_URL/mapi.php" &&
  check_json "$duplicate_changed_body" &&
  json_field_equals "$duplicate_changed_body" code -1 &&
  grep -Eq "支付参数有变化" "$duplicate_changed_body" &&
  order_count_equals "$duplicate_out_trade_no" 1; then
  printf '[OK] payment_create_duplicate_changed_params: rejected without new order\n'
else
  printf '[FAIL] payment_create_duplicate_changed_params: expected parameter-change rejection\n'
  failures=$((failures + 1))
fi

if configure_mock_payment_shape_channel html; then
  shape_html_post=$(make_signed_query "$fixture_key" \
    "pid=1000" \
    "type=alipay" \
    "out_trade_no=php84-shape-html-$(date +%Y%m%d%H%M%S)-$$" \
    "notify_url=$BASE_URL/merchant-notify-shape-html" \
    "return_url=$BASE_URL/merchant-return-shape-html" \
    "name=Shape html fixture" \
    "money=1.31" \
    "clientip=127.0.0.1" \
    "device=pc" \
    "method=web" \
    "sign_type=MD5")
  shape_html_body="$WORK_DIR/payment_shape_html.body"
  if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
    --data "$shape_html_post" -o "$shape_html_body" "$BASE_URL/mapi.php" &&
    check_json "$shape_html_body" &&
    json_field_equals "$shape_html_body" code 1 &&
    grep -Eq '"html":.*php84mock' "$shape_html_body"; then
    printf '[OK] payment_create_shape_html: mapi returns html payload\n'
  else
    printf '[FAIL] payment_create_shape_html: expected JSON code 1 with html payload\n'
    failures=$((failures + 1))
  fi
else
  printf '[FAIL] payment_create_shape_html_fixture: unable to configure mock channel\n'
  failures=$((failures + 1))
fi

if configure_mock_payment_shape_channel qrcode; then
  shape_qrcode_post=$(make_signed_query "$fixture_key" \
    "pid=1000" \
    "type=alipay" \
    "out_trade_no=php84-shape-qrcode-$(date +%Y%m%d%H%M%S)-$$" \
    "notify_url=$BASE_URL/merchant-notify-shape-qrcode" \
    "return_url=$BASE_URL/merchant-return-shape-qrcode" \
    "name=Shape qrcode fixture" \
    "money=1.32" \
    "clientip=127.0.0.1" \
    "device=pc" \
    "method=web" \
    "sign_type=MD5")
  shape_qrcode_body="$WORK_DIR/payment_shape_qrcode.body"
  if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
    --data "$shape_qrcode_post" -o "$shape_qrcode_body" "$BASE_URL/mapi.php" &&
    check_json "$shape_qrcode_body" &&
    json_field_equals "$shape_qrcode_body" code 1 &&
    json_field_equals "$shape_qrcode_body" qrcode "https://example.test/php84/qrcode"; then
    printf '[OK] payment_create_shape_qrcode: mapi returns qrcode payload\n'
  else
    printf '[FAIL] payment_create_shape_qrcode: expected JSON code 1 with qrcode payload\n'
    failures=$((failures + 1))
  fi
else
  printf '[FAIL] payment_create_shape_qrcode_fixture: unable to configure mock channel\n'
  failures=$((failures + 1))
fi

if configure_mock_payment_shape_channel scheme; then
  shape_scheme_post=$(make_signed_query "$fixture_key" \
    "pid=1000" \
    "type=alipay" \
    "out_trade_no=php84-shape-scheme-$(date +%Y%m%d%H%M%S)-$$" \
    "notify_url=$BASE_URL/merchant-notify-shape-scheme" \
    "return_url=$BASE_URL/merchant-return-shape-scheme" \
    "name=Shape scheme fixture" \
    "money=1.33" \
    "clientip=127.0.0.1" \
    "device=pc" \
    "method=web" \
    "sign_type=MD5")
  shape_scheme_body="$WORK_DIR/payment_shape_scheme.body"
  if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
    --data "$shape_scheme_post" -o "$shape_scheme_body" "$BASE_URL/mapi.php" &&
    check_json "$shape_scheme_body" &&
    json_field_equals "$shape_scheme_body" code 1 &&
    json_field_equals "$shape_scheme_body" urlscheme "weixin://wxpay/bizpayurl?pr=php84"; then
    printf '[OK] payment_create_shape_scheme: mapi returns urlscheme payload\n'
  else
    printf '[FAIL] payment_create_shape_scheme: expected JSON code 1 with urlscheme payload\n'
    failures=$((failures + 1))
  fi
else
  printf '[FAIL] payment_create_shape_scheme_fixture: unable to configure mock channel\n'
  failures=$((failures + 1))
fi

if configure_mock_payment_shape_channel jsapi; then
  shape_jsapi_post=$(make_signed_query "$fixture_key" \
    "pid=1000" \
    "type=alipay" \
    "out_trade_no=php84-shape-jsapi-$(date +%Y%m%d%H%M%S)-$$" \
    "notify_url=$BASE_URL/merchant-notify-shape-jsapi" \
    "return_url=$BASE_URL/merchant-return-shape-jsapi" \
    "name=Shape jsapi fixture" \
    "money=1.34" \
    "clientip=127.0.0.1" \
    "device=pc" \
    "method=web" \
    "timestamp=$(date +%s)" \
    "sign_type=MD5")
  shape_jsapi_body="$WORK_DIR/payment_shape_jsapi.body"
  if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
    --data "$shape_jsapi_post" -o "$shape_jsapi_body" "$BASE_URL/api/pay/create" &&
    check_json "$shape_jsapi_body" &&
    json_field_equals "$shape_jsapi_body" code 0 &&
    json_field_equals "$shape_jsapi_body" pay_type jsapi &&
    json_field_is_json "$shape_jsapi_body" pay_info; then
    printf '[OK] payment_create_shape_json: api/pay/create returns signed JSON wrapper with jsapi payload\n'
  else
    printf '[FAIL] payment_create_shape_json: expected JSON code 0 with jsapi pay_info JSON\n'
    failures=$((failures + 1))
  fi
else
  printf '[FAIL] payment_create_shape_json_fixture: unable to configure mock channel\n'
  failures=$((failures + 1))
fi

if configure_mock_payment_shape_channel refund; then
  user_refund_fixture="$WORK_DIR/user_refund_fixture.env"
  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" "$PHP_BIN" -r '
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
      $pass = getenv("EPAY_DB_PASSWORD");
      if ($pass === false) {
          $pass = "";
      }
      $pdo = new PDO($dsn, getenv("EPAY_DB_USER"), $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
      $channelId = $pdo->query("SELECT `id` FROM `".$prefix."_channel` WHERE `plugin`=\"php84mock\" ORDER BY `id` DESC LIMIT 1")->fetchColumn();
      if (!$channelId) {
          exit(1);
      }
      $tradeNo = date("YmdHis") . random_int(10000, 99999);
      $stmt = $pdo->prepare("INSERT INTO `".$prefix."_order` (`trade_no`, `out_trade_no`, `uid`, `type`, `channel`, `name`, `money`, `realmoney`, `getmoney`, `addtime`, `endtime`, `date`, `status`, `api_trade_no`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), CURDATE(), ?, ?)");
      $stmt->execute(array($tradeNo, "user-refund-".$tradeNo, 1000, 1, $channelId, "User refund fixture", "1.35", "1.35", "1.35", 1, "MOCKREFUND".$tradeNo));
      $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
      $refundCount = $pdo->query("SELECT COUNT(*) FROM `".$prefix."_refundorder` WHERE `uid`=1000")->fetchColumn();
      echo "user_refund_trade_no=".escapeshellarg((string)$tradeNo)."\n";
      echo "user_refund_money_before=".escapeshellarg((string)$userMoney)."\n";
      echo "user_refund_count_before=".escapeshellarg((string)$refundCount)."\n";
    ' >"$user_refund_fixture"; then
    . "$user_refund_fixture"
    printf '[OK] user_refund_fixture: paid mock-refundable order created\n'
  else
    printf '[FAIL] user_refund_fixture: unable to create mock-refundable order\n'
    failures=$((failures + 1))
    user_refund_trade_no=""
    user_refund_money_before=""
    user_refund_count_before=""
  fi
else
  printf '[FAIL] user_refund_fixture_channel: unable to configure mock refund channel\n'
  failures=$((failures + 1))
  user_refund_trade_no=""
  user_refund_money_before=""
  user_refund_count_before=""
fi

user_refund_cookie="$WORK_DIR/user_refund.cookie"
if [ -n "$user_refund_trade_no" ] && login_user_key "$user_refund_cookie" 1000 "$fixture_key" user_refund; then
  user_refund_query_body="$WORK_DIR/user_refund_query.body"
  if "$CURL_BIN" -sS -b "$user_refund_cookie" -e "$BASE_URL/user/order.php" \
    -X POST --data-urlencode "trade_no=$user_refund_trade_no" \
    -o "$user_refund_query_body" "$BASE_URL/user/ajax2.php?act=refund_query" &&
    check_json "$user_refund_query_body" &&
    json_field_equals "$user_refund_query_body" code 0 &&
    json_field_equals "$user_refund_query_body" money "1.35"; then
    printf '[OK] user_refund_query: refundable amount returned\n'
  else
    printf '[FAIL] user_refund_query: expected refundable amount 1.35\n'
    failures=$((failures + 1))
  fi

  user_refund_wrong_pwd_body="$WORK_DIR/user_refund_wrong_pwd.body"
  if "$CURL_BIN" -sS -b "$user_refund_cookie" -e "$BASE_URL/user/order.php" \
    -X POST --data-urlencode "trade_no=$user_refund_trade_no" --data-urlencode "money=1.35" --data-urlencode "pwd=wrong-password" \
    -o "$user_refund_wrong_pwd_body" "$BASE_URL/user/ajax2.php?act=refund_submit" &&
    check_json "$user_refund_wrong_pwd_body" &&
    json_field_equals "$user_refund_wrong_pwd_body" code -1 &&
    grep -Eq "登录密码输入错误" "$user_refund_wrong_pwd_body"; then
    printf '[OK] user_refund_wrong_password: refund rejected\n'
  else
    printf '[FAIL] user_refund_wrong_password: expected password error\n'
    failures=$((failures + 1))
  fi

  user_refund_submit_body="$WORK_DIR/user_refund_submit.body"
  if "$CURL_BIN" -sS -b "$user_refund_cookie" -e "$BASE_URL/user/order.php" \
    -X POST --data-urlencode "trade_no=$user_refund_trade_no" --data-urlencode "money=1.35" --data-urlencode "pwd=php84-password" \
    -o "$user_refund_submit_body" "$BASE_URL/user/ajax2.php?act=refund_submit" &&
    check_json "$user_refund_submit_body" &&
    json_field_equals "$user_refund_submit_body" code 0 &&
    EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
      EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
      EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$user_refund_trade_no" \
      EPAY_USER_MONEY_BEFORE="$user_refund_money_before" EPAY_REFUND_COUNT_BEFORE="$user_refund_count_before" "$PHP_BIN" -r '
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
        $stmt = $pdo->prepare("SELECT `status`, `refundmoney`, `getmoney` FROM `".$prefix."_order` WHERE `trade_no`=? LIMIT 1");
        $stmt->execute(array(getenv("EPAY_TRADE_NO")));
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
        $refundCount = $pdo->query("SELECT COUNT(*) FROM `".$prefix."_refundorder` WHERE `uid`=1000")->fetchColumn();
        if (!$order || (string)$order["status"] !== "2" || number_format((float)$order["refundmoney"], 2, ".", "") !== "1.35") {
            exit(1);
        }
        $expectedMoney = number_format(round((float)getenv("EPAY_USER_MONEY_BEFORE") - (float)$order["getmoney"], 2), 2, ".", "");
        if (number_format((float)$userMoney, 2, ".", "") !== $expectedMoney) {
            exit(1);
        }
        exit((int)$refundCount === (int)getenv("EPAY_REFUND_COUNT_BEFORE") + 1 ? 0 : 1);
      '; then
    printf '[OK] user_refund_submit: user center refund completed with balance and refund-order state\n'
  else
    printf '[FAIL] user_refund_submit: expected completed refund state\n'
    failures=$((failures + 1))
  fi
else
  printf '[FAIL] user_refund_login: expected merchant login for refund checks\n'
  failures=$((failures + 1))
fi

if configure_local_epay_channel; then
  printf '[OK] payment_shape_fixture_restore: local epay channel restored\n'
else
  printf '[FAIL] payment_shape_fixture_restore: unable to restore local epay channel\n'
  failures=$((failures + 1))
fi

fillorder_out_trade_no="php84-fill-$(date +%Y%m%d%H%M%S)-$$"
fillorder_post=$(make_signed_query "$fixture_key" \
  "pid=1000" \
  "type=alipay" \
  "out_trade_no=$fillorder_out_trade_no" \
  "notify_url=http://127.0.0.1:1/merchant-notify-fill" \
  "return_url=http://127.0.0.1:1/merchant-return-fill" \
  "name=PHP84 fillorder fixture" \
  "money=1.25" \
  "clientip=127.0.0.1" \
  "device=pc" \
  "method=jump" \
  "sign_type=MD5")
fillorder_create_body="$WORK_DIR/fillorder_create.body"
if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
  --data "$fillorder_post" -o "$fillorder_create_body" "$BASE_URL/mapi.php" &&
  check_json "$fillorder_create_body" &&
  json_field_equals "$fillorder_create_body" code 1; then
  fillorder_trade_no=$(json_field "$fillorder_create_body" trade_no || true)
  printf '[OK] fillorder_create: unpaid order created trade_no=%s\n' "$fillorder_trade_no"
else
  printf '[FAIL] fillorder_create: expected JSON code 1 with trade_no\n'
  failures=$((failures + 1))
  fillorder_trade_no=""
fi

if [ -n "$fillorder_trade_no" ]; then
  fillorder_snapshot="$WORK_DIR/fillorder_snapshot.env"
  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$fillorder_trade_no" "$PHP_BIN" -r '
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
      $order = $pdo->prepare("SELECT `status`, `getmoney`, `channel` FROM `".$prefix."_order` WHERE `trade_no`=? LIMIT 1");
      $order->execute(array(getenv("EPAY_TRADE_NO")));
      $row = $order->fetch(PDO::FETCH_ASSOC);
      if (!$row || (string)$row["status"] !== "0") {
          exit(1);
      }
      $channel = $pdo->prepare("SELECT `plugin` FROM `".$prefix."_channel` WHERE `id`=? LIMIT 1");
      $channel->execute(array($row["channel"]));
      if ($channel->fetchColumn() !== "epay") {
          exit(1);
      }
      $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
      echo "fillorder_getmoney=".$row["getmoney"]."\n";
      echo "fillorder_user_money_before=".$userMoney."\n";
    ' >"$fillorder_snapshot"; then
    . "$fillorder_snapshot"
    printf '[OK] fillorder_db_created: unpaid order persisted through epay channel\n'
  else
    printf '[FAIL] fillorder_db_created: expected unpaid epay order\n'
    failures=$((failures + 1))
    fillorder_getmoney=""
    fillorder_user_money_before=""
  fi

  fillorder_body="$WORK_DIR/fillorder.body"
  if "$CURL_BIN" -sS -b "$admin_cookie" -e "$BASE_URL/admin/order.php" \
    -X POST --data-urlencode "trade_no=$fillorder_trade_no" \
    -o "$fillorder_body" "$BASE_URL/admin/ajax_order.php?act=fillorder" &&
    check_json "$fillorder_body" &&
    json_field_equals "$fillorder_body" code 0; then
    printf '[OK] fillorder_admin_action: manual fill order succeeded\n'
  else
    printf '[FAIL] fillorder_admin_action: expected JSON code 0\n'
    failures=$((failures + 1))
  fi

  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$fillorder_trade_no" \
    EPAY_USER_MONEY_BEFORE="$fillorder_user_money_before" EPAY_GETMONEY="$fillorder_getmoney" "$PHP_BIN" -r '
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
      $order = $pdo->prepare("SELECT `status`, `endtime`, `date` FROM `".$prefix."_order` WHERE `trade_no`=? LIMIT 1");
      $order->execute(array(getenv("EPAY_TRADE_NO")));
      $row = $order->fetch(PDO::FETCH_ASSOC);
      $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
      $records = $pdo->prepare("SELECT COUNT(*) FROM `".$prefix."_record` WHERE `uid`=1000 AND `type`=\"订单收入\" AND `trade_no`=?");
      $records->execute(array(getenv("EPAY_TRADE_NO")));
      $expectedMoney = number_format(round((float)getenv("EPAY_USER_MONEY_BEFORE") + (float)getenv("EPAY_GETMONEY"), 2), 2, ".", "");
      if (!$row || (string)$row["status"] !== "1") {
          exit(1);
      }
      if (empty($row["endtime"]) || $row["endtime"] === "0000-00-00 00:00:00" || empty($row["date"]) || $row["date"] === "0000-00-00") {
          exit(1);
      }
      if (number_format((float)$userMoney, 2, ".", "") !== $expectedMoney) {
          exit(1);
      }
      if ((int)$records->fetchColumn() !== 1) {
          exit(1);
      }
    '; then
    printf '[OK] fillorder_state: order paid, endtime set, merchant balance updated once\n'
  else
    printf '[FAIL] fillorder_state: unexpected manual fill order state\n'
    failures=$((failures + 1))
  fi
fi

merchant_out_trade_no="php84-flow-$(date +%Y%m%d%H%M%S)-$$"
payment_post=$(make_signed_query "$fixture_key" \
  "pid=1000" \
  "type=alipay" \
  "out_trade_no=$merchant_out_trade_no" \
  "notify_url=$BASE_URL/merchant-notify" \
  "return_url=$BASE_URL/merchant-return" \
  "name=PHP84 payment fixture" \
  "money=1.23" \
  "clientip=127.0.0.1" \
  "device=pc" \
  "method=jump" \
  "sign_type=MD5")
payment_create_body="$WORK_DIR/payment_create.body"
if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
  --data "$payment_post" -o "$payment_create_body" "$BASE_URL/mapi.php" &&
  check_json "$payment_create_body" &&
  json_field_equals "$payment_create_body" code 1; then
  trade_no=$(json_field "$payment_create_body" trade_no || true)
  payurl=$(json_field "$payment_create_body" payurl || true)
  if [ -n "$trade_no" ] && printf '%s' "$payurl" | grep -Eq "/pay/submit/$trade_no/"; then
    printf '[OK] payment_create: mapi jump order created trade_no=%s\n' "$trade_no"
  else
    printf '[FAIL] payment_create: unexpected trade_no or payurl\n'
    failures=$((failures + 1))
  fi
else
  printf '[FAIL] payment_create: expected JSON code 1 with payurl\n'
  "$PHP_BIN" -r '
    $body = file_get_contents($argv[1]);
    $body = preg_replace("/\s+/u", " ", strip_tags($body));
    echo mb_substr($body, 0, 300), PHP_EOL;
  ' "$payment_create_body"
  failures=$((failures + 1))
  trade_no=""
fi

if [ -n "$trade_no" ]; then
  payment_snapshot="$WORK_DIR/payment_snapshot.env"
  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$trade_no" "$PHP_BIN" -r '
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
      $order = $pdo->prepare("SELECT `status`, `realmoney`, `getmoney`, `channel` FROM `".$prefix."_order` WHERE `trade_no`=? LIMIT 1");
      $order->execute(array(getenv("EPAY_TRADE_NO")));
      $row = $order->fetch(PDO::FETCH_ASSOC);
      if (!$row || (string)$row["status"] !== "0") {
          exit(1);
      }
      $channel = $pdo->prepare("SELECT `plugin` FROM `".$prefix."_channel` WHERE `id`=? LIMIT 1");
      $channel->execute(array($row["channel"]));
      if ($channel->fetchColumn() !== "epay") {
          exit(1);
      }
      $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
      echo "realmoney=".$row["realmoney"]."\n";
      echo "getmoney=".$row["getmoney"]."\n";
      echo "user_money_before=".$userMoney."\n";
    ' >"$payment_snapshot"; then
    . "$payment_snapshot"
    printf '[OK] payment_db_created: order persisted through epay channel\n'
  else
    printf '[FAIL] payment_db_created: expected unpaid order on epay channel\n'
    failures=$((failures + 1))
    realmoney=""
    getmoney=""
    user_money_before=""
  fi

  bad_notify_query=$(make_signed_query "wrong-fixture-key" \
    "pid=1000" \
    "trade_no=EPAYAPI$trade_no" \
    "out_trade_no=$trade_no" \
    "type=alipay" \
    "name=PHP84 payment fixture" \
    "money=$realmoney" \
    "trade_status=TRADE_SUCCESS" \
    "sign_type=MD5")
  bad_notify_body="$WORK_DIR/payment_notify_bad.body"
  if "$CURL_BIN" -sS -o "$bad_notify_body" "$BASE_URL/pay/notify/$trade_no/?$bad_notify_query" &&
    grep -qx "fail" "$bad_notify_body"; then
    printf '[OK] payment_notify_bad_signature: rejected\n'
  else
    printf '[FAIL] payment_notify_bad_signature: expected fail\n'
    failures=$((failures + 1))
  fi

  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$trade_no" "$PHP_BIN" -r '
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
      $stmt = $pdo->prepare("SELECT `status` FROM `".$prefix."_order` WHERE `trade_no`=? LIMIT 1");
      $stmt->execute(array(getenv("EPAY_TRADE_NO")));
      exit((string)$stmt->fetchColumn() === "0" ? 0 : 1);
    '; then
    printf '[OK] payment_notify_bad_signature_state: order remains unpaid\n'
  else
    printf '[FAIL] payment_notify_bad_signature_state: order changed unexpectedly\n'
    failures=$((failures + 1))
  fi

  good_notify_query=$(make_signed_query "fixture-epay-channel-key" \
    "pid=1000" \
    "trade_no=EPAYAPI$trade_no" \
    "out_trade_no=$trade_no" \
    "type=alipay" \
    "name=PHP84 payment fixture" \
    "money=$realmoney" \
    "trade_status=TRADE_SUCCESS" \
    "sign_type=MD5")
  good_notify_body="$WORK_DIR/payment_notify_good.body"
  if "$CURL_BIN" -sS -o "$good_notify_body" "$BASE_URL/pay/notify/$trade_no/?$good_notify_query" &&
    grep -qx "success" "$good_notify_body"; then
    printf '[OK] payment_notify_success: accepted\n'
  else
    printf '[FAIL] payment_notify_success: expected success\n'
    failures=$((failures + 1))
  fi

  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$trade_no" EPAY_API_TRADE_NO="EPAYAPI$trade_no" \
    EPAY_USER_MONEY_BEFORE="$user_money_before" EPAY_GETMONEY="$getmoney" "$PHP_BIN" -r '
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
      $order = $pdo->prepare("SELECT `status`, `api_trade_no` FROM `".$prefix."_order` WHERE `trade_no`=? LIMIT 1");
      $order->execute(array(getenv("EPAY_TRADE_NO")));
      $row = $order->fetch(PDO::FETCH_ASSOC);
      $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
      $records = $pdo->prepare("SELECT COUNT(*) FROM `".$prefix."_record` WHERE `uid`=1000 AND `type`=\"订单收入\" AND `trade_no`=?");
      $records->execute(array(getenv("EPAY_TRADE_NO")));
      $expectedMoney = number_format(round((float)getenv("EPAY_USER_MONEY_BEFORE") + (float)getenv("EPAY_GETMONEY"), 2), 2, ".", "");
      if (!$row || (string)$row["status"] !== "1" || $row["api_trade_no"] !== getenv("EPAY_API_TRADE_NO")) {
          exit(1);
      }
      if (number_format((float)$userMoney, 2, ".", "") !== $expectedMoney) {
          exit(1);
      }
      if ((int)$records->fetchColumn() !== 1) {
          exit(1);
      }
    '; then
    printf '[OK] payment_notify_success_state: order paid, merchant balance updated once\n'
  else
    printf '[FAIL] payment_notify_success_state: unexpected paid order state\n'
    failures=$((failures + 1))
  fi

  duplicate_notify_body="$WORK_DIR/payment_notify_duplicate.body"
  if "$CURL_BIN" -sS -o "$duplicate_notify_body" "$BASE_URL/pay/notify/$trade_no/?$good_notify_query" &&
    grep -qx "success" "$duplicate_notify_body" &&
    EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
      EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
      EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$trade_no" \
      EPAY_USER_MONEY_BEFORE="$user_money_before" EPAY_GETMONEY="$getmoney" "$PHP_BIN" -r '
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
        $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
        $records = $pdo->prepare("SELECT COUNT(*) FROM `".$prefix."_record` WHERE `uid`=1000 AND `type`=\"订单收入\" AND `trade_no`=?");
        $records->execute(array(getenv("EPAY_TRADE_NO")));
        $expectedMoney = number_format(round((float)getenv("EPAY_USER_MONEY_BEFORE") + (float)getenv("EPAY_GETMONEY"), 2), 2, ".", "");
        exit(number_format((float)$userMoney, 2, ".", "") === $expectedMoney && (int)$records->fetchColumn() === 1 ? 0 : 1);
      '; then
    printf '[OK] payment_notify_duplicate: idempotent\n'
  else
    printf '[FAIL] payment_notify_duplicate: expected idempotent success\n'
    failures=$((failures + 1))
  fi
fi

return_out_trade_no="php84-return-$(date +%Y%m%d%H%M%S)-$$"
return_payment_post=$(make_signed_query "$fixture_key" \
  "pid=1000" \
  "type=alipay" \
  "out_trade_no=$return_out_trade_no" \
  "notify_url=$BASE_URL/merchant-notify-return" \
  "return_url=$BASE_URL/merchant-return" \
  "name=PHP84 return fixture" \
  "money=1.24" \
  "clientip=127.0.0.1" \
  "device=pc" \
  "method=jump" \
  "sign_type=MD5")
return_create_body="$WORK_DIR/payment_return_create.body"
if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
  --data "$return_payment_post" -o "$return_create_body" "$BASE_URL/mapi.php" &&
  check_json "$return_create_body" &&
  json_field_equals "$return_create_body" code 1; then
  return_trade_no=$(json_field "$return_create_body" trade_no || true)
  printf '[OK] payment_return_create: mapi jump order created trade_no=%s\n' "$return_trade_no"
else
  printf '[FAIL] payment_return_create: expected JSON code 1 with trade_no\n'
  failures=$((failures + 1))
  return_trade_no=""
fi

if [ -n "$return_trade_no" ]; then
  return_snapshot="$WORK_DIR/payment_return_snapshot.env"
  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$return_trade_no" "$PHP_BIN" -r '
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
      $order = $pdo->prepare("SELECT `status`, `realmoney`, `getmoney`, `channel` FROM `".$prefix."_order` WHERE `trade_no`=? LIMIT 1");
      $order->execute(array(getenv("EPAY_TRADE_NO")));
      $row = $order->fetch(PDO::FETCH_ASSOC);
      if (!$row || (string)$row["status"] !== "0") {
          exit(1);
      }
      $channel = $pdo->prepare("SELECT `plugin` FROM `".$prefix."_channel` WHERE `id`=? LIMIT 1");
      $channel->execute(array($row["channel"]));
      if ($channel->fetchColumn() !== "epay") {
          exit(1);
      }
      $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
      echo "return_realmoney=".$row["realmoney"]."\n";
      echo "return_getmoney=".$row["getmoney"]."\n";
      echo "return_user_money_before=".$userMoney."\n";
    ' >"$return_snapshot"; then
    . "$return_snapshot"
    printf '[OK] payment_return_db_created: order persisted through epay channel\n'
  else
    printf '[FAIL] payment_return_db_created: expected unpaid return order on epay channel\n'
    failures=$((failures + 1))
    return_realmoney=""
    return_getmoney=""
    return_user_money_before=""
  fi

  bad_return_query=$(make_signed_query "wrong-fixture-key" \
    "pid=1000" \
    "trade_no=EPAYRETURN$return_trade_no" \
    "out_trade_no=$return_trade_no" \
    "type=alipay" \
    "name=PHP84 return fixture" \
    "money=$return_realmoney" \
    "trade_status=TRADE_SUCCESS" \
    "sign_type=MD5")
  bad_return_body="$WORK_DIR/payment_return_bad.body"
  if "$CURL_BIN" -sS -o "$bad_return_body" "$BASE_URL/pay/return/$return_trade_no/?$bad_return_query" &&
    grep -Eq "验证失败" "$bad_return_body"; then
    printf '[OK] payment_return_bad_signature: rejected\n'
  else
    printf '[FAIL] payment_return_bad_signature: expected validation failure page\n'
    failures=$((failures + 1))
  fi

  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$return_trade_no" "$PHP_BIN" -r '
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
      $stmt = $pdo->prepare("SELECT `status` FROM `".$prefix."_order` WHERE `trade_no`=? LIMIT 1");
      $stmt->execute(array(getenv("EPAY_TRADE_NO")));
      exit((string)$stmt->fetchColumn() === "0" ? 0 : 1);
    '; then
    printf '[OK] payment_return_bad_signature_state: order remains unpaid\n'
  else
    printf '[FAIL] payment_return_bad_signature_state: order changed unexpectedly\n'
    failures=$((failures + 1))
  fi

  good_return_query=$(make_signed_query "fixture-epay-channel-key" \
    "pid=1000" \
    "trade_no=EPAYRETURN$return_trade_no" \
    "out_trade_no=$return_trade_no" \
    "type=alipay" \
    "name=PHP84 return fixture" \
    "money=$return_realmoney" \
    "trade_status=TRADE_SUCCESS" \
    "sign_type=MD5")
  good_return_body="$WORK_DIR/payment_return_good.body"
  if "$CURL_BIN" -sS -o "$good_return_body" "$BASE_URL/pay/return/$return_trade_no/?$good_return_query" &&
    ! grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$good_return_body" &&
    grep -Eq "支付成功跳转页面|支付成功，正在跳转" "$good_return_body"; then
    printf '[OK] payment_return_success: accepted\n'
  else
    printf '[FAIL] payment_return_success: expected success return page\n'
    failures=$((failures + 1))
  fi

  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$return_trade_no" EPAY_API_TRADE_NO="EPAYRETURN$return_trade_no" \
    EPAY_USER_MONEY_BEFORE="$return_user_money_before" EPAY_GETMONEY="$return_getmoney" "$PHP_BIN" -r '
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
      $order = $pdo->prepare("SELECT `status`, `api_trade_no` FROM `".$prefix."_order` WHERE `trade_no`=? LIMIT 1");
      $order->execute(array(getenv("EPAY_TRADE_NO")));
      $row = $order->fetch(PDO::FETCH_ASSOC);
      $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
      $records = $pdo->prepare("SELECT COUNT(*) FROM `".$prefix."_record` WHERE `uid`=1000 AND `type`=\"订单收入\" AND `trade_no`=?");
      $records->execute(array(getenv("EPAY_TRADE_NO")));
      $expectedMoney = number_format(round((float)getenv("EPAY_USER_MONEY_BEFORE") + (float)getenv("EPAY_GETMONEY"), 2), 2, ".", "");
      if (!$row || (string)$row["status"] !== "1" || $row["api_trade_no"] !== getenv("EPAY_API_TRADE_NO")) {
          exit(1);
      }
      if (number_format((float)$userMoney, 2, ".", "") !== $expectedMoney) {
          exit(1);
      }
      if ((int)$records->fetchColumn() !== 1) {
          exit(1);
      }
    '; then
    printf '[OK] payment_return_success_state: order paid and merchant balance updated once\n'
  else
    printf '[FAIL] payment_return_success_state: unexpected return order state\n'
    failures=$((failures + 1))
  fi
fi

if configure_epayn_channel; then
  printf '[OK] epayn_channel_fixture: local RSA channel configured\n'
else
  printf '[FAIL] epayn_channel_fixture: unable to configure local RSA channel\n'
  failures=$((failures + 1))
fi

epayn_out_trade_no="php84-epayn-$(date +%Y%m%d%H%M%S)-$$"
epayn_payment_post=$(make_signed_query "$fixture_key" \
  "pid=1000" \
  "type=alipay" \
  "out_trade_no=$epayn_out_trade_no" \
  "notify_url=$BASE_URL/merchant-notify-epayn" \
  "return_url=$BASE_URL/merchant-return-epayn" \
  "name=PHP84 epayn payment fixture" \
  "money=2.34" \
  "clientip=127.0.0.1" \
  "device=pc" \
  "method=jump" \
  "sign_type=MD5")
epayn_create_body="$WORK_DIR/epayn_create.body"
if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
  --data "$epayn_payment_post" -o "$epayn_create_body" "$BASE_URL/mapi.php" &&
  check_json "$epayn_create_body" &&
  json_field_equals "$epayn_create_body" code 1; then
  epayn_trade_no=$(json_field "$epayn_create_body" trade_no || true)
  epayn_payurl=$(json_field "$epayn_create_body" payurl || true)
  if [ -n "$epayn_trade_no" ] && printf '%s' "$epayn_payurl" | grep -Eq "/pay/submit/$epayn_trade_no/"; then
    printf '[OK] epayn_payment_create: mapi jump order created trade_no=%s\n' "$epayn_trade_no"
  else
    printf '[FAIL] epayn_payment_create: unexpected trade_no or payurl\n'
    failures=$((failures + 1))
  fi
else
  printf '[FAIL] epayn_payment_create: expected JSON code 1 with payurl\n'
  "$PHP_BIN" -r '
    $body = file_get_contents($argv[1]);
    $body = preg_replace("/\s+/u", " ", strip_tags($body));
    echo mb_substr($body, 0, 300), PHP_EOL;
  ' "$epayn_create_body"
  failures=$((failures + 1))
  epayn_trade_no=""
fi

if [ -n "$epayn_trade_no" ]; then
  epayn_snapshot="$WORK_DIR/epayn_snapshot.env"
  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$epayn_trade_no" "$PHP_BIN" -r '
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
      $order = $pdo->prepare("SELECT `status`, `realmoney`, `getmoney`, `channel` FROM `".$prefix."_order` WHERE `trade_no`=? LIMIT 1");
      $order->execute(array(getenv("EPAY_TRADE_NO")));
      $row = $order->fetch(PDO::FETCH_ASSOC);
      if (!$row || (string)$row["status"] !== "0") {
          exit(1);
      }
      $channel = $pdo->prepare("SELECT `plugin` FROM `".$prefix."_channel` WHERE `id`=? LIMIT 1");
      $channel->execute(array($row["channel"]));
      if ($channel->fetchColumn() !== "epayn") {
          exit(1);
      }
      $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
      echo "epayn_realmoney=".$row["realmoney"]."\n";
      echo "epayn_getmoney=".$row["getmoney"]."\n";
      echo "epayn_user_money_before=".$userMoney."\n";
    ' >"$epayn_snapshot"; then
    . "$epayn_snapshot"
    printf '[OK] epayn_payment_db_created: order persisted through epayn channel\n'
  else
    printf '[FAIL] epayn_payment_db_created: expected unpaid order on epayn channel\n'
    failures=$((failures + 1))
    epayn_realmoney=""
    epayn_getmoney=""
    epayn_user_money_before=""
  fi

  epayn_bad_notify_query=$(make_rsa_signed_query "$EPAYN_WRONG_PRIVATE_KEY" \
    "pid=1000" \
    "trade_no=EPAYNV2$epayn_trade_no" \
    "out_trade_no=$epayn_trade_no" \
    "type=alipay" \
    "name=PHP84 epayn payment fixture" \
    "money=$epayn_realmoney" \
    "buyer=fixture-buyer-epayn" \
    "trade_status=TRADE_SUCCESS")
  epayn_bad_notify_body="$WORK_DIR/epayn_notify_bad.body"
  if "$CURL_BIN" -sS -o "$epayn_bad_notify_body" "$BASE_URL/pay/notify/$epayn_trade_no/?$epayn_bad_notify_query" &&
    grep -qx "fail" "$epayn_bad_notify_body"; then
    printf '[OK] epayn_notify_bad_signature: rejected\n'
  else
    printf '[FAIL] epayn_notify_bad_signature: expected fail\n'
    failures=$((failures + 1))
  fi

  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$epayn_trade_no" "$PHP_BIN" -r '
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
      $stmt = $pdo->prepare("SELECT `status` FROM `".$prefix."_order` WHERE `trade_no`=? LIMIT 1");
      $stmt->execute(array(getenv("EPAY_TRADE_NO")));
      exit((string)$stmt->fetchColumn() === "0" ? 0 : 1);
    '; then
    printf '[OK] epayn_notify_bad_signature_state: order remains unpaid\n'
  else
    printf '[FAIL] epayn_notify_bad_signature_state: order changed unexpectedly\n'
    failures=$((failures + 1))
  fi

  epayn_good_notify_query=$(make_rsa_signed_query "$EPAYN_PLATFORM_PRIVATE_KEY" \
    "pid=1000" \
    "trade_no=EPAYNV2$epayn_trade_no" \
    "out_trade_no=$epayn_trade_no" \
    "type=alipay" \
    "name=PHP84 epayn payment fixture" \
    "money=$epayn_realmoney" \
    "buyer=fixture-buyer-epayn" \
    "trade_status=TRADE_SUCCESS")
  epayn_good_notify_body="$WORK_DIR/epayn_notify_good.body"
  if "$CURL_BIN" -sS -o "$epayn_good_notify_body" "$BASE_URL/pay/notify/$epayn_trade_no/?$epayn_good_notify_query" &&
    grep -qx "success" "$epayn_good_notify_body"; then
    printf '[OK] epayn_notify_success: accepted\n'
  else
    printf '[FAIL] epayn_notify_success: expected success\n'
    failures=$((failures + 1))
  fi

  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$epayn_trade_no" EPAY_API_TRADE_NO="EPAYNV2$epayn_trade_no" \
    EPAY_USER_MONEY_BEFORE="$epayn_user_money_before" EPAY_GETMONEY="$epayn_getmoney" "$PHP_BIN" -r '
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
      $order = $pdo->prepare("SELECT `status`, `api_trade_no`, `buyer` FROM `".$prefix."_order` WHERE `trade_no`=? LIMIT 1");
      $order->execute(array(getenv("EPAY_TRADE_NO")));
      $row = $order->fetch(PDO::FETCH_ASSOC);
      $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
      $records = $pdo->prepare("SELECT COUNT(*) FROM `".$prefix."_record` WHERE `uid`=1000 AND `type`=\"订单收入\" AND `trade_no`=?");
      $records->execute(array(getenv("EPAY_TRADE_NO")));
      $expectedMoney = number_format(round((float)getenv("EPAY_USER_MONEY_BEFORE") + (float)getenv("EPAY_GETMONEY"), 2), 2, ".", "");
      if (!$row || (string)$row["status"] !== "1" || $row["api_trade_no"] !== getenv("EPAY_API_TRADE_NO") || $row["buyer"] !== "fixture-buyer-epayn") {
          exit(1);
      }
      if (number_format((float)$userMoney, 2, ".", "") !== $expectedMoney) {
          exit(1);
      }
      if ((int)$records->fetchColumn() !== 1) {
          exit(1);
      }
    '; then
    printf '[OK] epayn_notify_success_state: order paid, buyer stored, merchant balance updated once\n'
  else
    printf '[FAIL] epayn_notify_success_state: unexpected paid order state\n'
    failures=$((failures + 1))
  fi

  epayn_duplicate_notify_body="$WORK_DIR/epayn_notify_duplicate.body"
  if "$CURL_BIN" -sS -o "$epayn_duplicate_notify_body" "$BASE_URL/pay/notify/$epayn_trade_no/?$epayn_good_notify_query" &&
    grep -qx "success" "$epayn_duplicate_notify_body" &&
    EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
      EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
      EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$epayn_trade_no" \
      EPAY_USER_MONEY_BEFORE="$epayn_user_money_before" EPAY_GETMONEY="$epayn_getmoney" "$PHP_BIN" -r '
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
        $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
        $records = $pdo->prepare("SELECT COUNT(*) FROM `".$prefix."_record` WHERE `uid`=1000 AND `type`=\"订单收入\" AND `trade_no`=?");
        $records->execute(array(getenv("EPAY_TRADE_NO")));
        $expectedMoney = number_format(round((float)getenv("EPAY_USER_MONEY_BEFORE") + (float)getenv("EPAY_GETMONEY"), 2), 2, ".", "");
        exit(number_format((float)$userMoney, 2, ".", "") === $expectedMoney && (int)$records->fetchColumn() === 1 ? 0 : 1);
      '; then
    printf '[OK] epayn_notify_duplicate: idempotent\n'
  else
    printf '[FAIL] epayn_notify_duplicate: expected idempotent success\n'
    failures=$((failures + 1))
  fi
fi

p0_submit_runtime_check() {
  plugin=$1
  type_name=$2
  type_id=$3
  app_type=$4
  config_json=$5

  if configure_p0_submit_channel "$plugin" "$type_id" "$app_type" "$config_json"; then
    printf '[OK] p0_%s_channel_fixture: submit channel configured\n' "$plugin"
  else
    printf '[FAIL] p0_%s_channel_fixture: unable to configure submit channel\n' "$plugin"
    failures=$((failures + 1))
    return
  fi

  p0_out_trade_no="php84-${plugin}-$(date +%Y%m%d%H%M%S)-$$"
  p0_payment_post=$(make_signed_query "$fixture_key" \
    "pid=1000" \
    "type=$type_name" \
    "out_trade_no=$p0_out_trade_no" \
    "notify_url=http://127.0.0.1:1/merchant-notify-$plugin" \
    "return_url=http://127.0.0.1:1/merchant-return-$plugin" \
    "name=PHP84 P0 $plugin submit fixture" \
    "money=1.11" \
    "clientip=127.0.0.1" \
    "device=pc" \
    "method=jump" \
    "sign_type=MD5")
  p0_create_body="$WORK_DIR/p0_${plugin}_create.body"
  if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
    --data "$p0_payment_post" -o "$p0_create_body" "$BASE_URL/mapi.php" &&
    check_json "$p0_create_body" &&
    json_field_equals "$p0_create_body" code 1; then
    p0_trade_no=$(json_field "$p0_create_body" trade_no || true)
  else
    printf '[FAIL] p0_%s_create: expected JSON code 1 with trade_no\n' "$plugin"
    failures=$((failures + 1))
    return
  fi

  if [ -z "$p0_trade_no" ]; then
    printf '[FAIL] p0_%s_create: empty trade_no\n' "$plugin"
    failures=$((failures + 1))
    return
  fi

  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$p0_trade_no" EPAY_PLUGIN="$plugin" "$PHP_BIN" -r '
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
      $stmt = $pdo->prepare("SELECT A.`status`, C.`plugin` FROM `".$prefix."_order` A INNER JOIN `".$prefix."_channel` C ON A.`channel`=C.`id` WHERE A.`trade_no`=? LIMIT 1");
      $stmt->execute(array(getenv("EPAY_TRADE_NO")));
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      exit($row && (string)$row["status"] === "0" && $row["plugin"] === getenv("EPAY_PLUGIN") ? 0 : 1);
    '; then
    printf '[OK] p0_%s_db_created: unpaid order persisted through plugin channel\n' "$plugin"
  else
    printf '[FAIL] p0_%s_db_created: expected unpaid order through plugin channel\n' "$plugin"
    failures=$((failures + 1))
    return
  fi

  p0_submit_body="$WORK_DIR/p0_${plugin}_submit.body"
  if "$CURL_BIN" -sS -o "$p0_submit_body" "$BASE_URL/pay/submit/$p0_trade_no/" &&
    ! grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$p0_submit_body" &&
    grep -Eq "pay/qrcode/$p0_trade_no|window.location.replace" "$p0_submit_body"; then
    printf '[OK] p0_%s_submit_runtime: submit path returns controlled jump page\n' "$plugin"
  else
    printf '[FAIL] p0_%s_submit_runtime: expected controlled submit jump without PHP error output\n' "$plugin"
    "$PHP_BIN" -r '
      $body = file_get_contents($argv[1]);
      $body = preg_replace("/\s+/u", " ", strip_tags($body));
      echo mb_substr($body, 0, 300), PHP_EOL;
    ' "$p0_submit_body"
    failures=$((failures + 1))
  fi

  case "$plugin" in
    alipay)
      p0_bad_gateway_callback_check "$plugin" "$p0_trade_no" "1.11"
      p0_alipay_success_callback_check "$p0_trade_no" "1.11"
      ;;
    wxpay|qqpay)
      p0_bad_gateway_callback_check "$plugin" "$p0_trade_no" "1.11"
      ;;
  esac
}

alipay_p0_config='{"appid":"fixture-alipay-appid","appkey":"'"$EPAYN_PLATFORM_PUBLIC_KEY"'","appsecret":"'"$EPAYN_MERCHANT_PRIVATE_KEY"'"}'
p0_submit_runtime_check "alipay" "alipay" "1" "3" "$alipay_p0_config"
p0_submit_runtime_check "wxpay" "wxpay" "2" "1" '{"appid":"fixture-wxpay-appid","appmchid":"fixture-mchid","appkey":"fixture-wxpay-key"}'
p0_submit_runtime_check "qqpay" "qqpay" "3" "1" '{"appid":"fixture-qqpay-mchid","appkey":"fixture-qqpay-key","appurl":"fixture-operator","appmchid":"fixture-password"}'

p1_bad_gateway_callback_check() {
  plugin=$1
  trade_no=$2
  amount_fen=$3
  body="$WORK_DIR/p1_${plugin}_bad_notify.body"

  case "$plugin" in
    kuaiqian)
      if "$CURL_BIN" -sS -G \
        --data-urlencode "merchantAcctId=fixture-kuaiqian-account01" \
        --data-urlencode "version=v2.0" \
        --data-urlencode "language=1" \
        --data-urlencode "signType=4" \
        --data-urlencode "payType=10" \
        --data-urlencode "orderId=$trade_no" \
        --data-urlencode "orderTime=$(date +%Y%m%d%H%M%S)" \
        --data-urlencode "orderAmount=$amount_fen" \
        --data-urlencode "dealId=${trade_no}-gateway" \
        --data-urlencode "dealTime=$(date +%Y%m%d%H%M%S)" \
        --data-urlencode "payAmount=$amount_fen" \
        --data-urlencode "fee=0" \
        --data-urlencode "payResult=10" \
        -o "$body" "$BASE_URL/pay/notify/$trade_no/" &&
        ! grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$body" &&
        grep -Eq "<result>0</result>" "$body" &&
        assert_order_unpaid_without_income "$trade_no"; then
        printf '[OK] p1_kuaiqian_notify_missing_signature: rejected without state change\n'
      else
        printf '[FAIL] p1_kuaiqian_notify_missing_signature: expected rejection and unpaid order\n'
        failures=$((failures + 1))
      fi
      ;;
    ysepay)
      if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
        --data-urlencode "out_trade_no=$trade_no" \
        --data-urlencode "trade_no=${trade_no}-gateway" \
        --data-urlencode "buyer_user_id=fixture-buyer" \
        --data-urlencode "total_amount=1.12" \
        --data-urlencode "trade_status=TRADE_SUCCESS" \
        --data-urlencode "sign=invalid-signature" \
        -o "$body" "$BASE_URL/pay/notify/$trade_no/" &&
        ! grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$body" &&
        grep -qx "fail" "$body" &&
        assert_order_unpaid_without_income "$trade_no"; then
        printf '[OK] p1_ysepay_notify_bad_signature: rejected without state change\n'
      else
        printf '[FAIL] p1_ysepay_notify_bad_signature: expected fail and unpaid order\n'
        failures=$((failures + 1))
      fi
      ;;
    sandpay)
      sandpay_biz_data='{"orderStatus":"success","outOrderNo":"'"$trade_no"'","sandSerialNo":"'"$trade_no"'-gateway","amount":"1.12","payer":{"payerAccNo":"fixture-buyer"}}'
      if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
        --data-urlencode "bizData=$sandpay_biz_data" \
        --data-urlencode "sign=invalid-signature" \
        -o "$body" "$BASE_URL/pay/notify/$trade_no/" &&
        ! grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$body" &&
        grep -qx "respCode=020002" "$body" &&
        assert_order_unpaid_without_income "$trade_no"; then
        printf '[OK] p1_sandpay_notify_bad_signature: rejected without state change\n'
      else
        printf '[FAIL] p1_sandpay_notify_bad_signature: expected failure response and unpaid order\n'
        failures=$((failures + 1))
      fi
      ;;
    unionpay|swiftpass|swiftpass2)
      xml="<xml><status><![CDATA[0]]></status><result_code><![CDATA[0]]></result_code><out_trade_no><![CDATA[$trade_no]]></out_trade_no><transaction_id><![CDATA[${trade_no}-gateway]]></transaction_id><openid><![CDATA[fixture-openid]]></openid><total_fee><![CDATA[$amount_fen]]></total_fee><sign><![CDATA[INVALID]]></sign></xml>"
      if printf '%s' "$xml" | "$CURL_BIN" -sS -X POST -H "Content-Type: text/xml" --data-binary @- \
        -o "$body" "$BASE_URL/pay/notify/$trade_no/" &&
        ! grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$body" &&
        grep -Eq "sign_error|failure|fail|FAIL" "$body" &&
        assert_order_unpaid_without_income "$trade_no"; then
        printf '[OK] p1_%s_notify_bad_signature: rejected without state change\n' "$plugin"
      else
        printf '[FAIL] p1_%s_notify_bad_signature: expected rejection and unpaid order\n' "$plugin"
        failures=$((failures + 1))
      fi
      ;;
  esac
}

p1_success_gateway_callback_check() {
  plugin=$1
  trade_no=$2
  snapshot="$WORK_DIR/p1_${plugin}_success_snapshot.env"
  body="$WORK_DIR/p1_${plugin}_success_notify.body"
  duplicate_body="$WORK_DIR/p1_${plugin}_success_notify_duplicate.body"

  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$trade_no" EPAY_PLUGIN="$plugin" "$PHP_BIN" -r '
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
      $stmt = $pdo->prepare("SELECT A.`status`, A.`realmoney`, A.`getmoney`, C.`plugin` FROM `".$prefix."_order` A INNER JOIN `".$prefix."_channel` C ON A.`channel`=C.`id` WHERE A.`trade_no`=? LIMIT 1");
      $stmt->execute(array(getenv("EPAY_TRADE_NO")));
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$row || (string)$row["status"] !== "0" || $row["plugin"] !== getenv("EPAY_PLUGIN")) {
          exit(1);
      }
      $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
      echo "p1_realmoney=".$row["realmoney"]."\n";
      echo "p1_getmoney=".$row["getmoney"]."\n";
      echo "p1_user_money_before=".$userMoney."\n";
    ' >"$snapshot"; then
    . "$snapshot"
  else
    printf '[FAIL] p1_%s_success_snapshot: expected unpaid order before success notify\n' "$plugin"
    failures=$((failures + 1))
    return
  fi

  case "$plugin" in
    ysepay)
      api_trade_no="${trade_no}-gateway-success"
      buyer_id="fixture-buyer-ysepay"
      notify_data=$(make_rsa_sha1_signed_query "$YSEPAY_PRIVATE_KEY" \
        "out_trade_no=$trade_no" \
        "trade_no=$api_trade_no" \
        "buyer_user_id=$buyer_id" \
        "total_amount=$p1_realmoney" \
        "trade_status=TRADE_SUCCESS")
      success_pattern="success"
      ;;
    sandpay)
      api_trade_no="${trade_no}-gateway-success"
      buyer_id="fixture-buyer-sandpay"
      sandpay_biz_data='{"orderStatus":"success","outOrderNo":"'"$trade_no"'","sandSerialNo":"'"$api_trade_no"'","amount":"'"$p1_realmoney"'","payer":{"payerAccNo":"'"$buyer_id"'"}}'
      sandpay_sign=$(rsa_sha256_signature "$SANDPAY_PRIVATE_KEY" "$sandpay_biz_data")
      notify_data=$(printf 'bizData=%s&sign=%s' \
        "$("$PHP_BIN" -r 'echo rawurlencode($argv[1]);' "$sandpay_biz_data")" \
        "$("$PHP_BIN" -r 'echo rawurlencode($argv[1]);' "$sandpay_sign")")
      success_pattern="respCode=000000"
      ;;
    *)
      return
      ;;
  esac

  if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
    --data "$notify_data" -o "$body" "$BASE_URL/pay/notify/$trade_no/" &&
    ! grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$body" &&
    grep -qx "$success_pattern" "$body"; then
    printf '[OK] p1_%s_notify_success: accepted\n' "$plugin"
  else
    printf '[FAIL] p1_%s_notify_success: expected success response\n' "$plugin"
    failures=$((failures + 1))
    return
  fi

  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$trade_no" EPAY_API_TRADE_NO="$api_trade_no" \
    EPAY_BUYER="$buyer_id" EPAY_USER_MONEY_BEFORE="$p1_user_money_before" EPAY_GETMONEY="$p1_getmoney" "$PHP_BIN" -r '
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
      $order = $pdo->prepare("SELECT `status`, `api_trade_no`, `buyer` FROM `".$prefix."_order` WHERE `trade_no`=? LIMIT 1");
      $order->execute(array(getenv("EPAY_TRADE_NO")));
      $row = $order->fetch(PDO::FETCH_ASSOC);
      $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
      $records = $pdo->prepare("SELECT COUNT(*) FROM `".$prefix."_record` WHERE `uid`=1000 AND `type`=\"订单收入\" AND `trade_no`=?");
      $records->execute(array(getenv("EPAY_TRADE_NO")));
      $expectedMoney = number_format(round((float)getenv("EPAY_USER_MONEY_BEFORE") + (float)getenv("EPAY_GETMONEY"), 2), 2, ".", "");
      if (!$row || (string)$row["status"] !== "1" || $row["api_trade_no"] !== getenv("EPAY_API_TRADE_NO") || $row["buyer"] !== getenv("EPAY_BUYER")) {
          exit(1);
      }
      if (number_format((float)$userMoney, 2, ".", "") !== $expectedMoney) {
          exit(1);
      }
      if ((int)$records->fetchColumn() !== 1) {
          exit(1);
      }
    '; then
    printf '[OK] p1_%s_notify_success_state: order paid, buyer stored, merchant balance updated once\n' "$plugin"
  else
    printf '[FAIL] p1_%s_notify_success_state: unexpected paid order state\n' "$plugin"
    failures=$((failures + 1))
    return
  fi

  if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
    --data "$notify_data" -o "$duplicate_body" "$BASE_URL/pay/notify/$trade_no/" &&
    grep -qx "$success_pattern" "$duplicate_body" &&
    EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
      EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
      EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$trade_no" \
      EPAY_USER_MONEY_BEFORE="$p1_user_money_before" EPAY_GETMONEY="$p1_getmoney" "$PHP_BIN" -r '
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
        $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
        $records = $pdo->prepare("SELECT COUNT(*) FROM `".$prefix."_record` WHERE `uid`=1000 AND `type`=\"订单收入\" AND `trade_no`=?");
        $records->execute(array(getenv("EPAY_TRADE_NO")));
        $expectedMoney = number_format(round((float)getenv("EPAY_USER_MONEY_BEFORE") + (float)getenv("EPAY_GETMONEY"), 2), 2, ".", "");
        exit(number_format((float)$userMoney, 2, ".", "") === $expectedMoney && (int)$records->fetchColumn() === 1 ? 0 : 1);
      '; then
    printf '[OK] p1_%s_notify_duplicate: idempotent\n' "$plugin"
  else
    printf '[FAIL] p1_%s_notify_duplicate: expected idempotent success\n' "$plugin"
    failures=$((failures + 1))
  fi
}

p1_ysepay_return_success_check() {
  out_trade_no="php84-p1-ysepay-return-$(date +%Y%m%d%H%M%S)-$$"
  payment_post=$(make_signed_query "$fixture_key" \
    "pid=1000" \
    "type=alipay" \
    "out_trade_no=$out_trade_no" \
    "notify_url=$BASE_URL/merchant-notify-p1-ysepay-return" \
    "return_url=$BASE_URL/merchant-return-p1-ysepay-return" \
    "name=PHP84 P1 ysepay return fixture" \
    "money=1.13" \
    "clientip=127.0.0.1" \
    "device=pc" \
    "method=jump" \
    "sign_type=MD5")
  create_body="$WORK_DIR/p1_ysepay_return_create.body"

  if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
    --data "$payment_post" -o "$create_body" "$BASE_URL/mapi.php" &&
    check_json "$create_body" &&
    json_field_equals "$create_body" code 1; then
    return_trade_no=$(json_field "$create_body" trade_no || true)
  else
    printf '[FAIL] p1_ysepay_return_create: expected JSON code 1 with trade_no\n'
    failures=$((failures + 1))
    return
  fi

  if [ -z "$return_trade_no" ]; then
    printf '[FAIL] p1_ysepay_return_create: empty trade_no\n'
    failures=$((failures + 1))
    return
  fi

  snapshot="$WORK_DIR/p1_ysepay_return_snapshot.env"
  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$return_trade_no" "$PHP_BIN" -r '
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
      $stmt = $pdo->prepare("SELECT A.`status`, A.`realmoney`, A.`getmoney`, C.`plugin` FROM `".$prefix."_order` A INNER JOIN `".$prefix."_channel` C ON A.`channel`=C.`id` WHERE A.`trade_no`=? LIMIT 1");
      $stmt->execute(array(getenv("EPAY_TRADE_NO")));
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$row || (string)$row["status"] !== "0" || $row["plugin"] !== "ysepay") {
          exit(1);
      }
      $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
      echo "ysepay_return_realmoney=".$row["realmoney"]."\n";
      echo "ysepay_return_getmoney=".$row["getmoney"]."\n";
      echo "ysepay_return_user_money_before=".$userMoney."\n";
    ' >"$snapshot"; then
    . "$snapshot"
    printf '[OK] p1_ysepay_return_db_created: unpaid return order persisted through plugin channel\n'
  else
    printf '[FAIL] p1_ysepay_return_db_created: expected unpaid ysepay order\n'
    failures=$((failures + 1))
    return
  fi

  api_trade_no="${return_trade_no}-gateway-return"
  return_query=$(make_rsa_sha1_signed_query "$YSEPAY_PRIVATE_KEY" \
    "out_trade_no=$return_trade_no" \
    "trade_no=$api_trade_no" \
    "total_amount=$ysepay_return_realmoney" \
    "trade_status=TRADE_SUCCESS")
  return_body="$WORK_DIR/p1_ysepay_return_success.body"

  if "$CURL_BIN" -sS -o "$return_body" "$BASE_URL/pay/return/$return_trade_no/?$return_query" &&
    ! grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$return_body"; then
    printf '[OK] p1_ysepay_return_success: accepted without PHP error output\n'
  else
    printf '[FAIL] p1_ysepay_return_success: expected accepted return without PHP error output\n'
    failures=$((failures + 1))
    return
  fi

  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$return_trade_no" EPAY_API_TRADE_NO="$api_trade_no" \
    EPAY_USER_MONEY_BEFORE="$ysepay_return_user_money_before" EPAY_GETMONEY="$ysepay_return_getmoney" "$PHP_BIN" -r '
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
      $order = $pdo->prepare("SELECT `status`, `api_trade_no` FROM `".$prefix."_order` WHERE `trade_no`=? LIMIT 1");
      $order->execute(array(getenv("EPAY_TRADE_NO")));
      $row = $order->fetch(PDO::FETCH_ASSOC);
      $userMoney = $pdo->query("SELECT `money` FROM `".$prefix."_user` WHERE `uid`=1000")->fetchColumn();
      $records = $pdo->prepare("SELECT COUNT(*) FROM `".$prefix."_record` WHERE `uid`=1000 AND `type`=\"订单收入\" AND `trade_no`=?");
      $records->execute(array(getenv("EPAY_TRADE_NO")));
      $expectedMoney = number_format(round((float)getenv("EPAY_USER_MONEY_BEFORE") + (float)getenv("EPAY_GETMONEY"), 2), 2, ".", "");
      if (!$row || (string)$row["status"] !== "1" || $row["api_trade_no"] !== getenv("EPAY_API_TRADE_NO")) {
          exit(1);
      }
      if (number_format((float)$userMoney, 2, ".", "") !== $expectedMoney) {
          exit(1);
      }
      if ((int)$records->fetchColumn() !== 1) {
          exit(1);
      }
    '; then
    printf '[OK] p1_ysepay_return_success_state: order paid and merchant balance updated once\n'
  else
    printf '[FAIL] p1_ysepay_return_success_state: unexpected paid order state\n'
    failures=$((failures + 1))
  fi
}

p1_submit_runtime_check() {
  plugin=$1
  type_name=$2
  type_id=$3
  app_type=$4
  config_json=$5

  if configure_p0_submit_channel "$plugin" "$type_id" "$app_type" "$config_json"; then
    printf '[OK] p1_%s_channel_fixture: submit channel configured\n' "$plugin"
  else
    printf '[FAIL] p1_%s_channel_fixture: unable to configure submit channel\n' "$plugin"
    failures=$((failures + 1))
    return
  fi

  p1_out_trade_no="php84-p1-${plugin}-$(date +%Y%m%d%H%M%S)-$$"
  p1_payment_post=$(make_signed_query "$fixture_key" \
    "pid=1000" \
    "type=$type_name" \
    "out_trade_no=$p1_out_trade_no" \
    "notify_url=$BASE_URL/merchant-notify-p1-$plugin" \
    "return_url=$BASE_URL/merchant-return-p1-$plugin" \
    "name=PHP84 P1 $plugin submit fixture" \
    "money=1.12" \
    "clientip=127.0.0.1" \
    "device=pc" \
    "method=jump" \
    "sign_type=MD5")
  p1_create_body="$WORK_DIR/p1_${plugin}_create.body"
  if "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
    --data "$p1_payment_post" -o "$p1_create_body" "$BASE_URL/mapi.php" &&
    check_json "$p1_create_body" &&
    json_field_equals "$p1_create_body" code 1; then
    p1_trade_no=$(json_field "$p1_create_body" trade_no || true)
  else
    printf '[FAIL] p1_%s_create: expected JSON code 1 with trade_no\n' "$plugin"
    failures=$((failures + 1))
    return
  fi

  if [ -z "$p1_trade_no" ]; then
    printf '[FAIL] p1_%s_create: empty trade_no\n' "$plugin"
    failures=$((failures + 1))
    return
  fi

  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$p1_trade_no" EPAY_PLUGIN="$plugin" "$PHP_BIN" -r '
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
      $stmt = $pdo->prepare("SELECT A.`status`, C.`plugin` FROM `".$prefix."_order` A INNER JOIN `".$prefix."_channel` C ON A.`channel`=C.`id` WHERE A.`trade_no`=? LIMIT 1");
      $stmt->execute(array(getenv("EPAY_TRADE_NO")));
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      exit($row && (string)$row["status"] === "0" && $row["plugin"] === getenv("EPAY_PLUGIN") ? 0 : 1);
    '; then
    printf '[OK] p1_%s_db_created: unpaid order persisted through plugin channel\n' "$plugin"
  else
    printf '[FAIL] p1_%s_db_created: expected unpaid order through plugin channel\n' "$plugin"
    failures=$((failures + 1))
    return
  fi

  p1_submit_body="$WORK_DIR/p1_${plugin}_submit.body"
  if "$CURL_BIN" -sS -o "$p1_submit_body" "$BASE_URL/pay/submit/$p1_trade_no/" &&
    ! grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$p1_submit_body" &&
    grep -Eq "pay/qrcode/$p1_trade_no|window.location.replace" "$p1_submit_body"; then
    printf '[OK] p1_%s_submit_runtime: submit path returns controlled jump page\n' "$plugin"
  else
    printf '[FAIL] p1_%s_submit_runtime: expected controlled submit jump without PHP error output\n' "$plugin"
    "$PHP_BIN" -r '
      $body = file_get_contents($argv[1]);
      $body = preg_replace("/\s+/u", " ", strip_tags($body));
      echo mb_substr($body, 0, 300), PHP_EOL;
    ' "$p1_submit_body"
    failures=$((failures + 1))
  fi

  case "$plugin" in
    unionpay|swiftpass|swiftpass2|kuaiqian|ysepay|sandpay)
      p1_bad_gateway_callback_check "$plugin" "$p1_trade_no" "112"
      ;;
  esac

  case "$plugin" in
    ysepay|sandpay)
      p1_success_gateway_callback_check "$plugin" "$p1_trade_no"
      ;;
  esac

  case "$plugin" in
    ysepay)
      p1_ysepay_return_success_check
      ;;
  esac
}

p1_submit_runtime_check "unionpay" "alipay" "1" "1" '{"appid":"fixture-unionpay-mchid","appkey":"fixture-unionpay-key","appurl":"","appswitch":"0"}'
swiftpass_p1_config='{"appid":"fixture-swiftpass-mchid","appkey":"'"$EPAYN_PLATFORM_PUBLIC_KEY"'","appsecret":"'"$EPAYN_MERCHANT_PRIVATE_KEY"'","appurl":"","appswitch":"0"}'
p1_submit_runtime_check "swiftpass" "alipay" "1" "1" "$swiftpass_p1_config"
p1_submit_runtime_check "swiftpass2" "alipay" "1" "1" '{"appid":"fixture-swiftpass2-mchid","appkey":"fixture-swiftpass2-key","appurl":"","appswitch":"0"}'
p1_submit_runtime_check "kuaiqian" "alipay" "1" "2" '{"appid":"fixture-kuaiqian-account","appkey":"fixture-kuaiqian-cert-password","appsecret":"fixture-kuaiqian-ssl-password","merchant_id":"fixture-kuaiqian-merchant","terminal_id":"fixture-kuaiqian-terminal","appmchid":"","own_channel":"0"}'
p1_submit_runtime_check "ysepay" "alipay" "1" "1" '{"appid":"fixture-ysepay-service-mchid","appkey":"fixture-ysepay-cert-password","appmchid":"fixture-ysepay-seller","appurl":"fixture-ysepay-business"}'
p1_submit_runtime_check "sandpay" "alipay" "1" "1" '{"appid":"fixture-sandpay-mid","appkey":"fixture-sandpay-cert-password","appswitch":"1","product":"QZF"}'
p1_submit_runtime_check "stripe" "alipay" "1" "1" '{"appid":"sk_test_fixture","appkey":"whsec_fixture","appswitch":"1"}'

time_to_ms() {
  "$PHP_BIN" -r 'echo number_format((float)$argv[1] * 1000, 2, ".", "");' "$1"
}

perf_get() {
  name=$1
  cookie_file=$2
  path=$3
  body="$WORK_DIR/perf_${name}.body"
  meta="$WORK_DIR/perf_${name}.meta"

  if [ "$cookie_file" = "-" ]; then
    curl_ok=0
    "$CURL_BIN" -sS -L -o "$body" -w "%{http_code}\n%{time_total}\n" "$BASE_URL$path" >"$meta" || curl_ok=1
  else
    curl_ok=0
    "$CURL_BIN" -sS -L -b "$cookie_file" -o "$body" -w "%{http_code}\n%{time_total}\n" "$BASE_URL$path" >"$meta" || curl_ok=1
  fi

  status=$(sed -n '1p' "$meta" 2>/dev/null)
  elapsed=$(sed -n '2p' "$meta" 2>/dev/null)
  elapsed_ms=$(time_to_ms "${elapsed:-0}")
  printf '[PERF] %s http=%s ms=%s\n' "$name" "${status:-curl_error}" "$elapsed_ms"

  if [ "$curl_ok" -ne 0 ] || [ "$status" != "200" ] || grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$body"; then
    printf '[FAIL] perf_%s: expected HTTP 200 without PHP error output\n' "$name"
    failures=$((failures + 1))
    return 1
  fi
  PERF_LAST_BODY="$body"
  return 0
}

perf_post() {
  name=$1
  path=$2
  data=$3
  body="$WORK_DIR/perf_${name}.body"
  meta="$WORK_DIR/perf_${name}.meta"
  curl_ok=0

  "$CURL_BIN" -sS -X POST -H "Content-Type: application/x-www-form-urlencoded" \
    --data "$data" -o "$body" -w "%{http_code}\n%{time_total}\n" "$BASE_URL$path" >"$meta" || curl_ok=1

  status=$(sed -n '1p' "$meta" 2>/dev/null)
  elapsed=$(sed -n '2p' "$meta" 2>/dev/null)
  elapsed_ms=$(time_to_ms "${elapsed:-0}")
  printf '[PERF] %s http=%s ms=%s\n' "$name" "${status:-curl_error}" "$elapsed_ms"

  if [ "$curl_ok" -ne 0 ] || [ "$status" != "200" ] || grep -Eiq 'Fatal error|Parse error|Deprecated:|Warning:' "$body"; then
    printf '[FAIL] perf_%s: expected HTTP 200 without PHP error output\n' "$name"
    failures=$((failures + 1))
    return 1
  fi
  PERF_LAST_BODY="$body"
  return 0
}

printf '[PERF] runtime=%s\n' "$("$PHP_BIN" -r 'echo PHP_VERSION;')"
perf_get "home" "-" "/"
perf_get "admin_home" "$admin_cookie" "/admin/"
perf_get "user_home" "$user_cookie" "/user/"

perf_epay_config='{"appurl":"'"$BASE_URL"'/","appid":"1000","appkey":"fixture-epay-channel-key","appswitch":"0"}'
configure_p0_submit_channel "epay" "1" "1" "$perf_epay_config" || {
  printf '[FAIL] perf_epay_channel_fixture: unable to configure local epay channel\n'
  failures=$((failures + 1))
}

perf_out_trade_no="php84-perf-$(date +%Y%m%d%H%M%S)-$$"
perf_payment_post=$(make_signed_query "$fixture_key" \
  "pid=1000" \
  "type=alipay" \
  "out_trade_no=$perf_out_trade_no" \
  "notify_url=http://127.0.0.1:1/merchant-notify-perf" \
  "return_url=http://127.0.0.1:1/merchant-return-perf" \
  "name=PHP84 performance fixture" \
  "money=1.26" \
  "clientip=127.0.0.1" \
  "device=pc" \
  "method=jump" \
  "sign_type=MD5")

perf_trade_no=""
if perf_post "create_order" "/mapi.php" "$perf_payment_post" &&
  check_json "$PERF_LAST_BODY" &&
  json_field_equals "$PERF_LAST_BODY" code 1; then
  perf_trade_no=$(json_field "$PERF_LAST_BODY" trade_no || true)
else
  printf '[FAIL] perf_create_order: expected JSON code 1\n'
  failures=$((failures + 1))
fi

if [ -n "$perf_trade_no" ]; then
  perf_snapshot="$WORK_DIR/perf_snapshot.env"
  if EPAY_DB_HOST="$DB_HOST" EPAY_DB_PORT="$DB_PORT" EPAY_DB_SOCKET="$DB_SOCKET" \
    EPAY_DB_USER="$DB_USER" EPAY_DB_PASSWORD="$DB_PASSWORD" EPAY_DB_NAME="$DB_NAME" \
    EPAY_DB_PREFIX="$DB_PREFIX" EPAY_TRADE_NO="$perf_trade_no" "$PHP_BIN" -r '
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
      $stmt = $pdo->prepare("SELECT `realmoney` FROM `".$prefix."_order` WHERE `trade_no`=? LIMIT 1");
      $stmt->execute(array(getenv("EPAY_TRADE_NO")));
      $realmoney = $stmt->fetchColumn();
      if (!$realmoney) {
          exit(1);
      }
      echo "perf_realmoney=".$realmoney."\n";
    ' >"$perf_snapshot"; then
    . "$perf_snapshot"
    perf_notify_query=$(make_signed_query "fixture-epay-channel-key" \
      "pid=1000" \
      "trade_no=EPAYPERF$perf_trade_no" \
      "out_trade_no=$perf_trade_no" \
      "type=alipay" \
      "name=PHP84 performance fixture" \
      "money=$perf_realmoney" \
      "trade_status=TRADE_SUCCESS" \
      "sign_type=MD5")
    if perf_get "payment_notify" "-" "/pay/notify/$perf_trade_no/?$perf_notify_query" &&
      grep -qx "success" "$PERF_LAST_BODY"; then
      :
    else
      printf '[FAIL] perf_payment_notify: expected success\n'
      failures=$((failures + 1))
    fi
  else
    printf '[FAIL] perf_snapshot: unable to read performance order\n'
    failures=$((failures + 1))
  fi
fi

perf_get "cron" "-" "/cron.php?key=fixture-cron-key"

if [ "$failures" -ne 0 ]; then
  printf '\nServer log tail:\n' >&2
  tail -n 120 "$SERVER_LOG" >&2
  exit 1
fi

printf 'Installed HTTP smoke checks passed at %s using %s and database %s\n' "$BASE_URL" "$PHP_BIN" "$DB_NAME"
