#!/usr/bin/env node
const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({headless:true});
  const failures = [];
  for (const viewport of [{name:'desktop',width:1440,height:900},{name:'mobile',width:390,height:844}]) {
    const page = await browser.newPage({viewport:{width:viewport.width,height:viewport.height}});
    page.on('dialog', async dialog => { failures.push(`${viewport.name}: unexpected dialog ${dialog.message()}`); await dialog.dismiss(); });
    await page.goto('http://127.0.0.1:18765/?view=list');
    await page.waitForLoadState('networkidle');
    if (await page.locator('.complaint-summary-item').count() !== 4) failures.push(`${viewport.name}: summary count`);
    if (await page.locator('text=<script>alert(1)</script>').count() !== 1) failures.push(`${viewport.name}: escaped order name missing`);
    if (await page.evaluate(() => window.__xss === 1)) failures.push(`${viewport.name}: list XSS executed`);
    if (await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)) failures.push(`${viewport.name}: list horizontal page overflow`);
    await page.locator('a', {hasText:'查看详情'}).click();
    await page.waitForLoadState('networkidle');
    if (await page.locator('text=138****8000').count() !== 1) failures.push(`${viewport.name}: masked phone missing`);
    if (await page.locator('button, a').filter({hasText:/退款|回复|上传|处理完成/}).count() !== 0) failures.push(`${viewport.name}: write action visible`);
    if (await page.evaluate(() => window.__xss === 1)) failures.push(`${viewport.name}: detail XSS executed`);
    if (await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)) failures.push(`${viewport.name}: detail horizontal page overflow`);
    await page.screenshot({path:`/tmp/merchant-complaint-${viewport.name}.png`,fullPage:true});
    await page.close();
  }
  await browser.close();
  if (failures.length) {
    console.error(failures.join('\n'));
    process.exit(1);
  }
  console.log('merchant complaint UI Playwright: ok');
})().catch(error => { console.error(error); process.exit(1); });
