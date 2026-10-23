/* Synthetic loopback application; the Alipay SDK is replaced by an in-memory fixture. */
const { chromium } = require(process.env.EPAY_PLAYWRIGHT_MODULE || 'playwright');
const { spawn, spawnSync } = require('node:child_process');
const { createHash } = require('node:crypto');
const net = require('node:net');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const php = process.env.EPAY_PHP_TEST_BIN || 'php';
const phpFlags = process.env.EPAY_PHP_TEST_EXT_DIR ? ['-d', 'extension_dir=' + process.env.EPAY_PHP_TEST_EXT_DIR, '-d', 'extension=pdo_mysql'] : [];
const fixture = path.join(__dirname, 'fixtures/merchant-defense-browser-router.php');
function command(args) {
    const result = spawnSync(php, [...phpFlags, fixture, ...args], { cwd: root, encoding: 'utf8', windowsHide: true });
    if (result.status !== 0) throw new Error('Fixture CLI failed: ' + result.stderr);
    return result.stdout;
}
function assert(value, label) { if (!value) throw new Error(label); }
function sign(fields) {
    return createHash('md5').update(Object.keys(fields).sort().filter(k => k !== 'sign' && k !== 'sign_type' && fields[k] !== '').map(k => k + '=' + fields[k]).join('&') + 'fixture-only-key').digest('hex');
}
async function freePort() {
    const server = net.createServer(); await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const port = server.address().port; await new Promise(resolve => server.close(resolve)); return port;
}
(async () => {
    let database, server, browser;
    let log = '';
    let checks = 0;
    const check = (value, label) => { assert(value, label); checks++; };
    try {
        database = JSON.parse(command(['--setup'])).database;
        console.log('Browser fixture: database ready');
        const port = await freePort(); const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, [...phpFlags, '-S', '127.0.0.1:' + port, fixture], { cwd: root, windowsHide: true,
            env: { ...process.env, EPAY_DEFENSE_TEST_DATABASE: database }, stdio: ['ignore', 'ignore', 'pipe'] });
        server.stderr.on('data', data => { log = (log + data.toString()).slice(-16000); });
        for (let attempt = 0; attempt < 80; attempt++) {
            try { if ((await fetch(origin + '/__fixture/ready')).ok) break; } catch (ignore) {}
            if (attempt === 79) throw new Error('Fixture server unavailable: ' + log);
            await new Promise(resolve => setTimeout(resolve, 100));
        }
        const options = { headless: true };
        if (process.env.EPAY_TEST_CHROME) options.executablePath = process.env.EPAY_TEST_CHROME;
        browser = await chromium.launch(options);
        console.log('Browser fixture: Chromium ready');
        async function mapi(number, pid = '1', needsDefense = true) {
            const fields = { pid: pid, type: 'alipay', out_trade_no: 'BROWSER-' + number, notify_url: 'https://fixture.invalid/notify',
                return_url: 'https://fixture.invalid/return', name: 'Synthetic item', money: '1.00', clientip: '198.51.100.9', device: 'pc',
                sub_openid: '', sub_appid: '', auth_code: '', sitename: '', param: '', method: 'web', sign_type: 'MD5' };
            fields.sign = sign(fields);
            const response = await fetch(origin + '/__fixture/mapi', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(fields) });
            const text = await response.text();
            let result; try { result = JSON.parse(text); } catch (error) { throw new Error('MAPI fixture not JSON: ' + text.slice(0, 300) + '\n' + log); }
            if (!needsDefense) { check(result.code === 1 && result.qrcode === 'https://qr.alipay.com/fixture', 'off-mode MAPI retains ordinary QR response'); return result; }
            check(result.code === 1 && result.payurl && !result.qrcode && !result.html, 'MAPI returns only verification link');
            return result.payurl.replace(/^https:/, 'http:');
        }
        const first = await mapi(1);
        console.log('Browser fixture: signed MAPI returned verification URL');
        check((await (await fetch(origin + '/__fixture/state')).json()).calls === 0, 'server MAPI does not call upstream');
        const entryUrl = new URL(first);
        const probe = await fetch(first.split('#')[0], { headers: { 'User-Agent': 'curl/8.0.0' } });
        const probeCookie = probe.headers.get('set-cookie').split(';')[0];
        const toolResponse = await fetch(first.split('#')[0], { method: 'POST', headers: { 'User-Agent': 'curl/8.0.0', Cookie: probeCookie, Origin: origin, 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'init', access: entryUrl.hash.slice(1) }) });
        check((await toolResponse.json()).reason === 'DEFENSE_VERIFY_FAILED', 'known tool denied by actual verification endpoint');
        const noJs = await browser.newContext({ javaScriptEnabled: false });
        const noJsPage = await noJs.newPage(); await noJsPage.goto(first);
        check(await noJsPage.locator('noscript').count() === 1, 'non-JS client gets verification only');
        check((await (await fetch(origin + '/__fixture/state')).json()).calls === 0, 'non-browser has no payment information');
        await noJs.close();
        console.log('Browser fixture: non-JS request blocked');
        const desktop = await browser.newContext({ viewport: { width: 1280, height: 850 }, userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36' });
        desktop.setDefaultTimeout(15000);
        const page = await desktop.newPage();
        await page.goto(first);
        try { await page.waitForURL(/submit2\.php/, { timeout: 30000 }); }
        catch (error) { throw new Error(error.message + '\nSynthetic state: ' + JSON.stringify(await (await fetch(origin + '/__fixture/state')).json())); }
        console.log('Browser fixture: desktop challenge completed');
        await page.getByText('支付宝扫码支付', { exact: true }).first().waitFor();
        console.log('Browser fixture: desktop cashier ready');
        let state = await (await fetch(origin + '/__fixture/state')).json();
        check(state.calls === 1, 'verified desktop browser gets one upstream order');
        check(state.contexts[0].ip === '127.0.0.1' && state.contexts[0].reported_ip === '198.51.100.9', 'browser and reported IP stored separately');
        await fetch(origin + '/pay.php?s=' + encodeURIComponent('submit/' + entryUrl.searchParams.get('trade_no') + '/'), { headers: { 'User-Agent': 'Mozilla/5.0 Chrome/131.0.0.0' } });
        check((await (await fetch(origin + '/__fixture/state')).json()).calls === 1, 'direct plugin route cannot bypass browser context');
        await page.reload(); await page.getByText('支付宝扫码支付', { exact: true }).first().waitFor();
        state = await (await fetch(origin + '/__fixture/state')).json(); check(state.calls === 1, 'refresh reuses payment information');
        const second = await mapi(2);
        const mobile = await browser.newContext({ viewport: { width: 390, height: 844 }, userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1' });
        mobile.setDefaultTimeout(15000);
        const mobilePage = await mobile.newPage(); await mobilePage.goto(second);
        await mobilePage.waitForURL(/submit2\.php/, { timeout: 30000 });
        await mobilePage.getByText('支付宝扫码支付', { exact: true }).first().waitFor();
        state = await (await fetch(origin + '/__fixture/state')).json(); check(state.calls === 2, 'mobile browser verification works');
        const webFields = { pid: '1', type: 'alipay', out_trade_no: 'BROWSER-WEB-4', notify_url: 'https://fixture.invalid/notify',
            return_url: 'https://fixture.invalid/return', name: 'Synthetic item', money: '1.00', sitename: '', param: '', sign_type: 'MD5' };
        webFields.sign = sign(webFields);
        const webResponse = await fetch(origin + '/__fixture/submit?' + new URLSearchParams(webFields));
        const webBody = await webResponse.text();
        const webJump = webBody.match(/window\.location\.replace\(("[^\r\n]+")\)/);
        check(webResponse.ok && !!webJump, 'signed web submit returns a usable verification jump');
        check((webResponse.headers.get('cache-control') || '').includes('no-store'), 'web verification jump cannot be cached');
        check((await (await fetch(origin + '/__fixture/state')).json()).calls === 2, 'web submit also defers upstream creation');
        await page.goto(JSON.parse(webJump[1]).replace(/^https:/, 'http:'));
        await page.waitForURL(/submit2\.php/, { timeout: 30000 });
        await page.getByText('支付宝扫码支付', { exact: true }).first().waitFor();
        check((await (await fetch(origin + '/__fixture/state')).json()).calls === 3, 'web submit completes browser verification and creates one payment');
        await desktop.addCookies([{ name: 'fixture_admin', value: '1', url: origin }]);
        await page.goto(origin + '/admin/merchant_defense.php?uid=2');
        await page.getByText('防御模式已关闭', { exact: true }).waitFor();
        check(await page.getByText('当前启用条件检查通过', { exact: true }).count() === 1, 'eligible merchant readiness visible');
        page.once('dialog', dialog => dialog.accept()); await page.getByRole('button', { name: '开启防御模式', exact: true }).click();
        await page.getByText('防御模式已开启', { exact: true }).waitFor(); checks++;
        await page.setViewportSize({ width: 390, height: 844 });
        check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2), 'admin detail has no page overflow on mobile');
        const rejected = await desktop.request.post(origin + '/admin/ajax_user.php?act=setMerchantDefense', { form: { uid: '2', target: '0', revision: '2' }, headers: { Referer: origin + '/admin/merchant_defense.php?uid=2' } });
        check((await rejected.json()).reason === 'DEFENSE_VERIFY_FAILED', 'missing CSRF rejected by actual AJAX endpoint');
        page.once('dialog', dialog => dialog.accept()); await page.getByRole('button', { name: '关闭防御模式', exact: true }).click();
        await page.getByText('防御模式已关闭', { exact: true }).waitFor(); checks++;
        await mapi(3, '2', false);
        await page.goto(origin + '/admin/ulist.php');
        const rowToggle = page.locator('.merchant-defense-toggle[data-uid="2"]');
        await rowToggle.waitFor(); check((await rowToggle.textContent()).trim() === '防御关闭', 'merchant list contains individual defense switch');
        const activation = page.waitForResponse(response => response.url().includes('act=setMerchantDefense'));
        page.on('dialog', dialog => dialog.accept()); await rowToggle.click();
        check((await (await activation).json()).code === 0, 'historical link permits new-order activation');
        await page.waitForFunction(() => document.querySelector('.merchant-defense-toggle[data-uid="2"]').textContent.trim() === '防御开启');
        check((await rowToggle.textContent()).trim() === '防御开启', 'list shows server-confirmed activation');
        await desktop.addCookies([{ name: 'fixture_uid', value: '2', url: origin }]);
        await page.goto(origin + '/user/defense.php?uid=1');
        check(await page.locator('#content').getByText(/商户 2：/).count() === 1, 'merchant view ignores foreign UID query');
        check(await page.locator('#content').locator('button, form').count() === 0, 'merchant view has no mutation control');
        check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2), 'merchant view fits mobile viewport');
        const anonymous = await fetch(origin + '/user/defense.php'); check(anonymous.status === 403, 'merchant view requires login');
        console.log('Merchant defense browser regression: ' + checks + ' checks passed; desktop/mobile, MAPI, challenge, replay/cache, admin and CSRF; loopback only.');
    } catch (error) {
        throw new Error(error.message + '\nSynthetic fixture server tail:\n' + log.slice(-4000));
    } finally {
        if (browser) await Promise.race([browser.close(), new Promise(resolve => setTimeout(resolve, 10000))]);
        if (server && server.exitCode === null && server.signalCode === null) {
            await Promise.race([new Promise(resolve => { server.once('exit', resolve); server.kill(); }), new Promise(resolve => setTimeout(resolve, 5000))]);
        }
        if (database) command(['--drop', database]);
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
