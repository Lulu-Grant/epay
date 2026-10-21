/* Real application routes, synthetic loopback MariaDB and fake Alipay SDK. No production hosts. */
const { spawn, spawnSync } = require('node:child_process');
const { createHash } = require('node:crypto');
const net = require('node:net');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const fixture = path.join(__dirname, 'fixtures/merchant-defense-browser-router.php');
const php = process.env.EPAY_PHP_TEST_BIN || 'php';
const authMode = process.argv.includes('--origin-auth');
const flags = process.env.EPAY_PHP_TEST_EXT_DIR ? ['-d', 'extension_dir=' + process.env.EPAY_PHP_TEST_EXT_DIR, '-d', 'extension=pdo_mysql'] : [];
const html = { 'User-Agent': 'Mozilla/5.0 Chrome/131.0.0.0 Safari/537.36', Accept: 'text/html', 'Ali-Cdn-Real-Ip': '9.9.9.9', 'Sec-Fetch-Dest': 'document' };
function cli(args) {
    const result = spawnSync(php, [...flags, fixture, ...args], { cwd: root, encoding: 'utf8', windowsHide: true });
    if (result.status !== 0) throw new Error('Fixture CLI failed: ' + result.stderr);
    return result.stdout;
}
function sign(fields) { return createHash('md5').update(Object.keys(fields).sort().filter(k => !['sign', 'sign_type'].includes(k) && fields[k] !== '').map(k => k + '=' + fields[k]).join('&') + 'fixture-only-key').digest('hex'); }
async function freePort() {
    const socket = net.createServer(); await new Promise(resolve => socket.listen(0, '127.0.0.1', resolve));
    const port = socket.address().port; await new Promise(resolve => socket.close(resolve)); return port;
}
(async () => {
    let database, server, log = '', checks = 0;
    const check = (value, label) => { if (!value) throw new Error(label); checks++; };
    try {
        database = JSON.parse(cli(['--setup'])).database;
        const port = await freePort(); const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, [...flags, '-S', '127.0.0.1:' + port, fixture], { cwd: root, windowsHide: true,
            env: { ...process.env, EPAY_DEFENSE_TEST_DATABASE: database, EPAY_PAYER_IP_FIXTURE: '1', EPAY_PAYER_IP_AUTH_FIXTURE: authMode ? '1' : '0' }, stdio: ['ignore', 'ignore', 'pipe'] });
        server.stderr.on('data', data => { log = (log + data).slice(-16000); });
        for (let attempt = 0; attempt < 80; attempt++) {
            try { if ((await fetch(origin + '/__fixture/ready')).ok) break; } catch (ignore) {}
            if (attempt === 79) throw new Error('Fixture server unavailable');
            await new Promise(resolve => setTimeout(resolve, 100));
        }
        const state = async () => (await (await fetch(origin + '/__fixture/state')).json());
        const row = async number => (await state()).orders.find(order => order.out_trade_no === 'PAYER-' + number);
        function fields(number) {
            const data = { pid: '2', type: 'alipay', out_trade_no: 'PAYER-' + number, notify_url: 'https://fixture.invalid/notify',
                return_url: 'https://fixture.invalid/return', name: 'Synthetic item', money: '1.00', clientip: '127.0.0.1', device: 'pc',
                sub_openid: '', sub_appid: '', auth_code: '', sitename: '', param: '', method: 'web', sign_type: 'MD5' };
            data.sign = sign(data); return data;
        }
        async function mapi(number) {
            // Even a server request deliberately wearing browser/ESA headers must not capture payer_ip.
            const response = await fetch(origin + '/__fixture/mapi', { method: 'POST', headers: { ...html, 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(fields(number)) });
            const text = await response.text(); let result;
            try { result = JSON.parse(text); } catch (error) { throw new Error('MAPI response not JSON: ' + text.slice(0, 200)); }
            check(result.code === 1 && result.qrcode === 'https://qr.alipay.com/fixture', 'MAPI retains original payment response');
            const order = await row(number); check(order && order.ip === '127.0.0.1' && order.payer_ip === null, 'MAPI preserves merchant IP, no payer observation'); return order;
        }
        let number = 1;
        for (const entry of ['submit', 'qrcode', 'cashier', 'submit2']) {
            const order = await mapi(number);
            const route = ['submit', 'qrcode'].includes(entry) ? '/pay/' + entry + '/' + order.trade_no + '/' : '/' + entry + '.php?typeid=1&trade_no=' + order.trade_no;
            const response = await fetch(origin + route, { headers: html, redirect: 'manual' }); await response.text();
            check(response.status < 400, 'payment page remains available: ' + entry);
            const observed = await row(number);
            check(observed.payer_ip === '9.9.9.9' && observed.payer_ip_peer === '8.8.8.8' && observed.payer_ip_source === 'esa:ali-cdn-real-ip' && observed.payer_ip_at, 'actual route captures trusted payer: ' + entry);
            check(observed.ip === '127.0.0.1' && Number(observed.status) === 0 && observed.channel === order.channel && observed.realmoney === order.realmoney, 'original fields unchanged: ' + entry);
            const refresh = await fetch(origin + route, { headers: { ...html, 'Ali-Cdn-Real-Ip': '1.1.1.1' }, redirect: 'manual' }); await refresh.text();
            check((await row(number)).payer_ip === '9.9.9.9', 'refresh does not replace payer: ' + entry); number++;
        }
        const untrusted = await mapi(number); const route = '/pay/submit/' + untrusted.trade_no + '/';
        for (const headers of [ { ...html, 'X-Fixture-Esa-Peer': 'untrusted' }, { ...html, 'Ali-Cdn-Real-Ip': '', 'X-Forwarded-For': '9.9.9.9' }, { ...html, 'User-Agent': 'curl/8.0.0' } ]) {
            const response = await fetch(origin + route, { headers, redirect: 'manual' }); await response.text();
            check((await row(number)).payer_ip === null, 'untrusted/header-missing/tool visit never pins payer');
        }
        const valid = await fetch(origin + route, { headers: html, redirect: 'manual' }); await valid.text();
        check((await row(number)).payer_ip === '9.9.9.9', 'trusted browser can capture after rejected probes');
        if (authMode) {
            number++; const guarded = await mapi(number); const guardedRoute = '/pay/submit/' + guarded.trade_no + '/';
            for (const headers of [{ ...html, 'X-Fixture-Origin-Auth': 'deny', 'Epay-Esa-Origin-Auth': '1', 'Epay-Esa-Origin-Tls': 'on' }, { ...html, 'X-Fixture-Origin-Tls': 'off', 'X-Forwarded-Proto': 'https' }]) {
                const response = await fetch(origin + guardedRoute, { headers, redirect: 'manual' }); await response.text();
                check((await row(number)).payer_ip === null, 'client claims cannot replace server auth and transport proof');
            }
        }
        number++;
        const web = await fetch(origin + '/__fixture/submit', { method: 'POST', headers: { ...html, 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(fields(number)), redirect: 'manual' });
        const webText = await web.text(); const webOrder = await row(number);
        check(web.status < 400 && webOrder?.payer_ip === '9.9.9.9', 'actual browser POST submit captures payer; status=' + web.status + '; order=' + JSON.stringify(webOrder) + '; response=' + webText.slice(0, 800));
        check((await state()).contexts.length === 0, 'passive observations do not become defense verification contexts');
        check(!/Fatal error|Uncaught|capture_storage_failed/.test(log), 'HTTP fixture has no application or capture storage failure');
        console.log('Payer IP HTTP regression (' + (authMode ? 'origin auth' : 'CIDR') + '): ' + checks + ' checks passed; real MAPI, submit/qrcode, cashier, submit2 and browser POST; loopback only.');
    } catch (error) { throw new Error(error.message + '\nSynthetic fixture server tail:\n' + log.slice(-4000)); }
    finally {
        if (server && server.exitCode === null && server.signalCode === null) await Promise.race([new Promise(resolve => { server.once('exit', resolve); server.kill(); }), new Promise(resolve => setTimeout(resolve, 5000))]);
        if (database) cli(['--drop', database]);
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
