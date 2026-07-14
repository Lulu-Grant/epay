const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const baseUrl = process.env.SHOP_TEST_BASE_URL;
const shopTradeNo = process.env.SHOP_TEST_SHOP_TRADE_NO;
const queryToken = process.env.SHOP_TEST_QUERY_TOKEN;
const screenDir = process.env.SHOP_TEST_SCREEN_DIR;

if (!baseUrl || !shopTradeNo || !queryToken || !screenDir) {
  throw new Error('Missing required SHOP_TEST_* environment variables');
}

fs.mkdirSync(screenDir, { recursive: true });

function collectErrors(page, errors) {
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(`console: ${message.text()}`);
  });
}

async function assertText(target, text) {
  const locator = target.getByText(text, { exact: false }).first();
  await locator.waitFor({ state: 'visible', timeout: 10000 }).catch(() => {
    throw new Error(`Missing text: ${text}`);
  });
}

async function assertNoOverflow(page, label) {
  const size = await page.evaluate(() => ({ client: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth }));
  if (size.scroll > size.client + 1) throw new Error(`${label} overflow: ${size.scroll} > ${size.client}`);
}

(async () => {
  const errors = [];
  const browser = await chromium.launch({ headless: true });
  try {
    const mobile = await browser.newContext({
      viewport: { width: 390, height: 844 },
      userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 Version/18.5 Mobile/15E148 Safari/604.1'
    });
    const mobileQuery = await mobile.newPage();
    collectErrors(mobileQuery, errors);
    await mobileQuery.goto(`${baseUrl}/shopping.php?act=query&trade_no=${encodeURIComponent(shopTradeNo)}&token=${encodeURIComponent(queryToken)}`, { waitUntil: 'networkidle' });
    await assertText(mobileQuery, shopTradeNo);
    await assertText(mobileQuery, '已支付');
    await assertText(mobileQuery, '已发货');
    await assertNoOverflow(mobileQuery, 'mobile query');
    await mobileQuery.screenshot({ path: path.join(screenDir, 'shadow-query-mobile.png'), fullPage: true });
    await mobile.close();

    const desktop = await browser.newContext({ viewport: { width: 1366, height: 900 } });
    const catalog = await desktop.newPage();
    collectErrors(catalog, errors);
    await catalog.goto(`${baseUrl}/shopping.php`, { waitUntil: 'networkidle' });
    await assertText(catalog, 'ChatGPT Token 充值');
    await assertText(catalog, 'DeepSeek API 额度充值');
    await assertNoOverflow(catalog, 'desktop catalog');
    await catalog.screenshot({ path: path.join(screenDir, 'shadow-catalog-desktop.png'), fullPage: true });
    await catalog.close();

    const admin = await desktop.newPage();
    collectErrors(admin, errors);
    await admin.goto(`${baseUrl}/admin/login.php`, { waitUntil: 'networkidle' });
    await admin.locator('input[name="user"]').fill('admin');
    await admin.locator('input[name="pass"]').fill('123456');
    await admin.locator('input[type="submit"]').click();
    await admin.waitForURL(/\/admin\/(?:index\.php)?$/, { timeout: 10000 });
    await admin.goto(`${baseUrl}/admin/shop_orders.php`, { waitUntil: 'networkidle' });
    await assertText(admin, '原商户UID');
    await assertText(admin, '记录模式');
    await assertText(admin, '无感影子订单');
    const shadowRow = admin.locator('tr').filter({ hasText: '无感影子订单' }).first();
    await shadowRow.getByRole('button', { name: '详情/物流' }).click();
    await admin.locator('#order-modal').waitFor({ state: 'visible' });
    await assertText(admin.locator('#order-modal'), '无感影子订单');
    await assertNoOverflow(admin, 'admin shadow order list');
    await admin.screenshot({ path: path.join(screenDir, 'shadow-admin-desktop.png'), fullPage: true });
    await desktop.close();
  } finally {
    await browser.close();
  }

  if (errors.length) throw new Error(errors.join('\n'));
  for (const file of ['shadow-query-mobile.png', 'shadow-catalog-desktop.png', 'shadow-admin-desktop.png']) {
    const target = path.join(screenDir, file);
    if (!fs.existsSync(target) || fs.statSync(target).size === 0) throw new Error(`Missing screenshot: ${file}`);
  }
  process.stdout.write(`PLAYWRIGHT_SHADOW_ACCEPTANCE_OK screens=${screenDir}\n`);
})().catch((error) => {
  process.stderr.write(`${error.stack || error.message}\n`);
  process.exit(1);
});
