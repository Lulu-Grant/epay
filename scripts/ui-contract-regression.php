#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') exit(1);

$root = dirname(__DIR__);
$failures = [];

function uiSource(string $root, string $relative): string
{
    $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $source = @file_get_contents($path);
    if ($source === false) throw new RuntimeException('cannot read '.$path);
    return $source;
}

function uiContains(string $root, string $relative, array $needles): void
{
    global $failures;
    $source = uiSource($root, $relative);
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) $failures[] = $relative.' missing '.var_export($needle, true);
    }
}

function uiExcludes(string $root, string $relative, array $needles): void
{
    global $failures;
    $source = uiSource($root, $relative);
    foreach ($needles as $needle) {
        if (str_contains($source, $needle)) $failures[] = $relative.' still contains '.var_export($needle, true);
    }
}

foreach (['user/login.php','user/findpwd.php','user/reg.php','user/head.php'] as $page) {
    uiContains($root, $page, ['<meta name="viewport" content="width=device-width, initial-scale=1"']);
    uiExcludes($root, $page, ['maximum-scale', 'user-scalable']);
}
uiContains($root, 'user/login.php', [
    'random_bytes(32)', 'class="container w-xxl w-auto-xs auth-shell"',
    'class="nav nav-tabs login-methods"', 'aria-current="page"',
    'for="login-account"', 'autocomplete="username"',
    'for="login-password"', 'autocomplete="current-password"',
]);
uiContains($root, 'user/findpwd.php', [
    'class="container w-xxl w-auto-xs auth-shell"', 'for="recovery-account"',
    'autocomplete="one-time-code"', 'autocomplete="new-password"',
]);
uiContains($root, 'user/reg.php', [
    'class="container w-xxl w-auto-xs auth-shell"', 'for="registration-email"',
    'autocomplete="one-time-code"', 'autocomplete="new-password"',
]);
uiContains($root, 'admin/login.php', ["'samesite'=>'Lax'"]);
uiExcludes($root, 'admin/login.php', ["?'None':'Lax'"]);

uiContains($root, 'admin/uset.php', [
    'id="merchant-add-password" type="password"',
    'type="password" class="form-control" name="pwd"',
]);
uiContains($root, 'admin/complain_info.php', ['id="complaint-refund-password" type="password"']);
uiContains($root, 'user/order.php', ['id="order-refund-password" type="password"']);
uiContains($root, 'user/transfer_add.php', ['type="password" name="paypwd"']);

uiContains($root, 'user/head.php', [
    'body class="dashboard-responsive"', 'id="merchant-nav-toggle"',
    'aria-controls="merchant-primary-nav" aria-expanded="false"',
    'id="merchant-account-toggle"', 'class="admin-sso-indicator" role="status"',
    '该商户账户已停用，请联系平台管理员。', '结算受限',
]);
uiContains($root, 'user/foot.php', ["setAttribute('aria-expanded'", 'MutationObserver']);
uiContains($root, 'user/index.php', [
    'id="dashboard-data-status"', 'role="status" aria-live="polite"',
    'merchant-stat-grid', 'merchant-stat-card', 'dashboard-empty-state',
    '首页统计加载失败，请刷新页面重试。',
]);
uiContains($root, 'user/apply.php', [
    '$can_apply_settle', '暂时不能申请提现',
    "<fieldset <?php echo \$can_apply_settle?'':'disabled'?>>",
    'if(!$can_apply_settle)',
]);

foreach (['user/order.php','user/record.php'] as $page) {
    uiContains($root, $page, [
        'class="form-control filter-keyword"', 'formatLoadingMessage:',
        'formatNoMatches:', 'onLoadError:',
    ]);
    uiExcludes($root, $page, ['min-width: 300px']);
}
uiContains($root, 'shopping.php', [
    '.thumb img{width:100%;height:100%;object-fit:contain}',
    'class="card-action"', 'class="hero query-panel"',
    'id="query-auth-help"', '商城订单号', '手机号后四位',
    '购买入口由发起订单的商户页面提供',
]);
uiContains($root, 'help.php', [
    '--action-bg:', '--active-line:', '--quote-bg:', '--code-ink:',
    '--pre-bg:', '--stripe:', 'max-width: 70ch',
    '<details class="doc-menu" open>', 'aria-current="page"',
    'aria-label="帮助页操作"',
]);
uiContains($root, 'admin/pay_roll.php', [
    '实际候选通道还会受启停状态、限额等运行条件影响',
    '权重是相对份额，不是流量百分比',
    "layero.attr({'role':'dialog','aria-modal':'true'",
    "attr('aria-label','关闭轮询配置')", 'btn-remove',
]);
uiContains($root, 'assets/css/dashboard-responsive.css', [
    'body.dashboard-responsive .filter-keyword',
    'body.dashboard-responsive .merchant-stat-grid',
    'body.dashboard-responsive .roll-row',
    '@media (max-width: 767px)',
]);

$overlayRoot = $argv[1] ?? '';
if ($overlayRoot !== '') {
    $overlayRoot = rtrim($overlayRoot, "\\/");
    uiContains($overlayRoot, 'assets/css/lp-dark/login.css', [
        'html.lp-dark .auth-shell', 'html.lp-dark .login-methods',
        'min-height: 44px', 'background-color: #3D6FE6',
    ]);
    uiContains($overlayRoot, 'assets/css/lp-dark/bootstrap3.css', [
        'background-color: #3D6FE6 !important',
        'html.lp-dark .btn-danger { background-color: #FF5C7A !important; border-color: #FF5C7A !important; color: #0B0F14 !important; }',
    ]);
    uiExcludes($overlayRoot, 'assets/css/lp-dark/tokens.css', [
        'html.lp-dark [class*="col-"]',
        'padding-left: 2px !important',
        'html.lp-dark .form-inline input.form-control[style]',
    ]);
    uiExcludes($overlayRoot, 'assets/css/lp-dark/admin.css', [
        'html.lp-dark[data-lp-theme="admin"] .col-xs-12',
        'max-width: none !important',
    ]);
    foreach (['user/login.php','user/findpwd.php','user/reg.php','user/head.php','admin/head.php','shopping.php'] as $page) {
        uiContains($overlayRoot, $page, ['lp18']);
    }
    $overlayIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($overlayRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($overlayIterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php' && str_contains((string)file_get_contents($file->getPathname()), 'lp17')) {
            $failures[] = str_replace('\\', '/', substr($file->getPathname(), strlen($overlayRoot) + 1)).' still references the stale lp17 theme cache key';
        }
    }
    foreach (['user/login.php','user/findpwd.php','user/reg.php','user/connect.php','user/oauth.php','user/wxlogin.php','user/head.php','admin/head.php','shopping.php','help.php'] as $page) {
        uiExcludes($overlayRoot, $page, ['maximum-scale', 'user-scalable']);
    }
    uiContains($overlayRoot, 'user/head.php', ['../assets/css/dashboard-responsive.css?v=1']);
    uiContains($overlayRoot, 'admin/head.php', ['../assets/css/dashboard-responsive.css?v=1']);
    uiContains($overlayRoot, 'help.php', [
        '--action-bg:', '--active-line:', '--quote-bg:', '--code-ink:', '--pre-bg:', '--stripe:',
    ]);
}

if ($failures) {
    fwrite(STDERR, "UI contract regression failed:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo 'UI contract regression: ok'.($overlayRoot !== '' ? ' (main + lp-dark overlay)' : ' (main)').PHP_EOL;
