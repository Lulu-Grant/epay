// Run with Node.js + Playwright; BROWSER_CHANNEL defaults to installed Edge.
// All HTTPS requests are intercepted fixtures. No production account or DB is used.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const admin = 'https://admin.sso.test';
const merchant = 'https://merchant.sso.test';
function policy(file) {
  const source = fs.readFileSync(path.join(root, file), 'utf8');
  return source.match(/header\('Referrer-Policy: ([^']+)'\)/)[1];
}
async function handoff(browser, policies) {
  const context = await browser.newContext();
  const page = await context.newPage();
  const received = [];
  // Include private-looking URL components to verify they never enter Referer.
  const urls = [admin + '/lpao/sso.php?uid=fixture', admin + '/lpao/sso.php',
    merchant + '/user/sso.php', admin + '/lpao/sso.php', merchant + '/user/sso.php'];
  await context.route('**/*', async route => {
    const index = received.length;
    const request = route.request();
    received.push({ url: request.url(), method: request.method(),
      origin: request.headers().origin, referer: request.headers().referer });
    const currentPolicy = request.url().startsWith(admin) ? policies.admin : policies.merchant;
    const body = index === 4 ? '<title>done</title>' :
      `<form id="sso-handoff" method="post" action="${urls[index + 1]}"><input type="hidden" name="ticket" value="fixture-only"></form><script>document.getElementById('sso-handoff').submit()</script>`;
    await route.fulfill({ status: 200, contentType: 'text/html',
      headers: { 'Referrer-Policy': currentPolicy, 'Cache-Control': 'no-store' }, body });
  });
  try {
    await page.goto(urls[0]);
    await page.waitForFunction(() => document.title === 'done');
    assert.equal(received.length, 5);
    received.forEach((request, index) => assert.equal(request.url, urls[index]));
    return received.slice(1);
  } finally { await context.close(); }
}
(async () => {
  const browser = await chromium.launch({ channel: process.env.BROWSER_CHANNEL || 'msedge', headless: true });
  try {
    const broken = await handoff(browser, { admin: 'no-referrer', merchant: 'no-referrer' });
    broken.forEach(request => assert.equal(request.origin, 'null'));
    // A later Nginx same-origin response header overrides the PHP header.
    const overridden = await handoff(browser, { admin: 'strict-origin, same-origin', merchant: 'strict-origin, same-origin' });
    assert.equal(overridden[0].origin, admin);
    overridden.slice(1).forEach(request => assert.equal(request.origin, 'null'));
    const fixed = await handoff(browser, { admin: policy('admin/sso.php'), merchant: policy('user/sso.php') });
    const expected = [admin, admin, merchant, admin];
    fixed.forEach((request, index) => {
      assert.equal(request.method, 'POST');
      assert.equal(request.origin, expected[index]);
      assert.equal(request.referer, expected[index] + '/');
    });
    // Exercise the actual PHP validator: missing/null/foreign origins stay rejected.
    const php = `require 'includes/lib/AdminSso.php'; $expected='${admin}';
      foreach (['', 'null', 'https://evil.sso.test', '${admin}.evil.test', '${merchant}', '${admin}'] as $origin) {
        $_SERVER['HTTP_ORIGIN']=$origin;
        if (\\lib\\AdminSso::requestOriginMatches($expected) !== ($origin === $expected)) exit(1);
      } echo 'origin validator passed';`;
    const result = execFileSync(process.env.PHP_BINARY || 'php', ['-r', php], { cwd: root, encoding: 'utf8' });
    assert.equal(result, 'origin validator passed');
    console.log('PASS: no-referrer and overriding same-origin reproduce null Origin; fixed same-origin start and all three cross-origin handoffs preserve exact origins; Referer exposes no path/query; missing/null/foreign origins rejected.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
