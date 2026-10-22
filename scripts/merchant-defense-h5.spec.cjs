/* Real browser/local routes. Capture the gateway's HTTP 303 without following external redirects. */
const { chromium } = require(process.env.EPAY_PLAYWRIGHT_MODULE || 'playwright');
const { spawn, spawnSync } = require('node:child_process');
const { createHash } = require('node:crypto');
const net = require('node:net');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const php = process.env.EPAY_PHP_TEST_BIN || 'php';
const flags = process.env.EPAY_PHP_TEST_EXT_DIR ? ['-d', 'extension_dir=' + process.env.EPAY_PHP_TEST_EXT_DIR, '-d', 'extension=pdo_mysql'] : [];
const fixture = path.join(__dirname, 'fixtures/merchant-defense-browser-router.php');
const localHold = process.argv.includes('--local-hold');
function command(args) {
    const r = spawnSync(php, [...flags, fixture, ...args], { cwd: root, encoding: 'utf8', windowsHide: true });
    if (r.status !== 0) throw new Error('Synthetic fixture failed: ' + r.stderr);
    return r.stdout;
}
(async () => {
    let database, server, browser, checks = 0, log = '';
    const check = (ok, message) => { if (!ok) throw new Error(message); checks++; };
    try {
        database = JSON.parse(command([localHold ? '--setup-local-hold' : '--setup-h5'])).database;
        const socket = net.createServer(); await new Promise(r => socket.listen(0, '127.0.0.1', r));
        const port = socket.address().port; await new Promise(r => socket.close(r));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, [...flags, '-S', '127.0.0.1:' + port, fixture], { cwd: root, windowsHide: true,
            env: { ...process.env, EPAY_DEFENSE_TEST_DATABASE: database, EPAY_WEILAILE_DEFENSE_FIXTURE: localHold ? '1' : '0' }, stdio: ['ignore', 'ignore', 'pipe'] });
        server.stderr.on('data', data => { log = (log + data).slice(-12000); });
        for (let i = 0; i < 80; i++) {
            try { if ((await fetch(origin + '/__fixture/ready')).ok) break; } catch (ignore) {}
            if (i === 79) throw new Error('Fixture server not ready');
            await new Promise(r => setTimeout(r, 100));
        }
        browser = await chromium.launch({ headless: true, ...(process.env.EPAY_TEST_CHROME ? { executablePath: process.env.EPAY_TEST_CHROME } : {}) });
        const state = async () => (await (await fetch(origin + '/__fixture/state')).json());
        let expectedCalls = 0;
        for (const mobile of [false, true]) {
            const fields = { pid: '1', type: 'alipay', out_trade_no: 'H5-' + mobile, notify_url: 'https://fixture.invalid/notify', return_url: 'https://fixture.invalid/return', name: 'Synthetic item', money: '1.00', clientip: '198.51.100.9', device: mobile ? 'mobile' : 'pc', sub_openid: '', sub_appid: '', auth_code: '', sitename: '', param: '', method: 'web', sign_type: 'MD5' };
            fields.sign = createHash('md5').update(Object.keys(fields).sort().filter(k => !['sign', 'sign_type'].includes(k) && fields[k] !== '').map(k => k + '=' + fields[k]).join('&') + 'fixture-only-key').digest('hex');
            const response = await fetch(origin + '/__fixture/mapi', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(fields) });
            const result = await response.json(); check(result.code === 1 && result.payurl && !result.qrcode, 'H5 MAPI defers payment link');
            check((await state()).calls === expectedCalls, 'no WAP signing before browser verification');
            const context = await browser.newContext({ viewport: { width: mobile ? 390 : 1280, height: 844 }, userAgent: mobile ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1' : 'Mozilla/5.0 Chrome/131.0.0.0 Safari/537.36' });
            let gatewayHits = 0;
            await context.route('https://**/*', route => route.abort());
            await context.route('**/submit2.php?*', async route => {
                const response = await route.fetch({ maxRedirects: 0 });
                if (localHold) {
                    check(response.status() === 200, 'local-gated Weilaile jump generated');
                    check((await response.text()).includes('window.location.replace("https://fixture.invalid/pay/fixture")'), 'mock PSP link only issued after challenge');
                } else {
                    check(response.status() === 303, 'real local handoff uses HTTP 303');
                    const url = new URL(response.headers().location);
                    check(url.origin === 'https://openapi.alipay.com' && url.pathname === '/gateway.do', 'official gateway pinned');
                    const biz = JSON.parse(url.searchParams.get('biz_content'));
                    check(biz.total_amount === '1.00' && biz.timeout_express === '30m' && biz.product_code === 'QUICK_WAP_WAY', 'H5 gateway amount and timeout bound');
                }
                gatewayHits++;
                await route.fulfill({ status: 200, contentType: 'text/html', body: '<p>fixture captured 303</p>' });
            });
            const page = await context.newPage();
            await page.goto(result.payurl.replace(/^https:/, 'http:'));
            await page.getByText('fixture captured 303', { exact: true }).waitFor({ timeout: 30000 });
            const afterFirst = await state(); expectedCalls++;
            check(gatewayHits === 1 && afterFirst.calls === expectedCalls, 'verified H5 first handoff: hits=' + gatewayHits + '; calls=' + afterFirst.calls + '; expected=' + expectedCalls);
            const trade = new URL(result.payurl).searchParams.get('trade_no');
            await page.goto(origin + '/submit2.php?typeid=1&trade_no=' + trade);
            await page.getByText('fixture captured 303', { exact: true }).waitFor({ timeout: 15000 });
            check(gatewayHits === 2 && (await state()).calls === expectedCalls, 'same browser retry reuses signed WAP result');
            await fetch(origin + '/pay.php?s=' + encodeURIComponent((localHold ? 'alipay/' : 'submitwap/') + trade + '/'), { headers: { 'User-Agent': 'Mozilla/5.0 Chrome/131.0.0.0' } });
            check((await state()).calls === expectedCalls, 'direct H5 route without verified cookie denied');
            await context.addCookies([{ name: 'fixture_admin', value: '1', url: origin }]);
            await page.goto(origin + '/admin/merchant_defense.php?uid=2');
            await page.locator('#defense-readiness').getByText('防御网络：已核验，模式：direct', { exact: true }).waitFor();
            check(await page.locator('#defense-readiness').getByText('旧订单接管工具：已安装', { exact: true }).count() === 1, 'structured readiness renders');
            if (localHold) check(await page.locator('#defense-readiness').getByText(/本地阻断；上游无法关单/).count() === 1, 'local hold boundary shown to operator');
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), 'readiness fits viewport');
            await context.close();
        }
        check(!/Fatal error|Uncaught|Parse error/.test(log), 'no local application fatal errors');
        console.log('Merchant defense ' + (localHold ? 'Weilaile local hold' : 'H5') + ' browser regression: ' + checks + ' checks passed; desktop/mobile; handoff captured without external requests.');
    } catch (e) { throw new Error(e.message + '\nSynthetic server: ' + log.slice(-2000)); }
    finally {
        if (browser) await browser.close();
        if (server && server.exitCode === null) await new Promise(r => { server.once('exit', r); server.kill(); });
        if (database) command(['--drop', database]);
    }
})().catch(e => { console.error(e.message); process.exitCode = 1; });
