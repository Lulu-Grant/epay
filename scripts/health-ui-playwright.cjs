const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const base = process.env.HEALTH_UI_BASE_URL || 'http://127.0.0.1:18121';
const evidenceDir = process.env.HEALTH_UI_EVIDENCE_DIR;
if (!evidenceDir || !path.isAbsolute(evidenceDir)) throw new Error('HEALTH_UI_EVIDENCE_DIR must be an absolute path');

const captures = new Map();
const scenarios = [];
const actions = [];
const layoutAssertions = [];
const visibleSamples = {};

function observe(page, name, allowConsoleErrors = false) {
  const telemetry = { name, consoleErrors: [], pageErrors: [], requestFailures: [], unexpectedConsoleErrors: [] };
  page.on('console', message => {
    if (message.type() === 'error') {
      telemetry.consoleErrors.push(message.text());
      if (!allowConsoleErrors) telemetry.unexpectedConsoleErrors.push(message.text());
    }
  });
  page.on('pageerror', error => telemetry.pageErrors.push(error.message));
  page.on('requestfailed', request => telemetry.requestFailures.push({ url: request.url(), error: request.failure() }));
  scenarios.push(telemetry);
  return telemetry;
}

async function assertNoPageOverflow(page, name) {
  const fits = await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
  if (!fits) throw new Error(name + ' has horizontal page overflow');
}

async function recordMobileState(page, id, expectedText, control) {
  await assertNoPageOverflow(page, id);
  const text = await page.locator('body').innerText();
  if (!text.includes(expectedText)) throw new Error(id + ' is missing expected text: ' + expectedText);
  let controlReachable = true;
  if (control) {
    const locator = page.locator(control).last();
    controlReachable = await locator.isVisible();
    if (!controlReachable) throw new Error(id + ' primary control is not reachable');
  }
  layoutAssertions.push({
    id,
    viewport: [390, 844],
    noHorizontalOverflow: true,
    expectedTextVisible: true,
    primaryControlReachable: controlReachable,
  });
}

async function capture(page, filename, fullPage = true) {
  captures.set(filename, await page.screenshot({ fullPage }));
}

async function readyPage(browser, viewport, state, name, allowConsoleErrors = false) {
  const context = await browser.newContext({ viewport });
  if (state) await context.addCookies([{ name: 'health_ui_state', value: state, url: base }]);
  const page = await context.newPage();
  const telemetry = observe(page, name, allowConsoleErrors);
  return { context, page, telemetry };
}

function assertClean(telemetry) {
  if (telemetry.unexpectedConsoleErrors.length) throw new Error(telemetry.name + ' console errors: ' + telemetry.unexpectedConsoleErrors.join('; '));
  if (telemetry.pageErrors.length) throw new Error(telemetry.name + ' page errors: ' + telemetry.pageErrors.join('; '));
  if (telemetry.requestFailures.length) throw new Error(telemetry.name + ' request failures: ' + JSON.stringify(telemetry.requestFailures));
}

async function fixture(page, act = 'meta') {
  const response = await page.request.get(base + '/__health_fixture?act=' + encodeURIComponent(act));
  if (!response.ok()) throw new Error('fixture control failed: ' + response.status());
  const value = await response.json();
  if (value.code !== 0) throw new Error('fixture control returned an error');
  return value;
}

async function closeAdminAlert(page, expectedFocus) {
  const dialog = page.locator('dialog.admin-accessible-dialog[open]');
  const button = dialog.locator('[data-dialog-confirm]');
  await button.waitFor({ state: 'visible' });
  await button.press('Enter');
  await dialog.waitFor({ state: 'hidden' });
  if (expectedFocus) {
    await page.waitForFunction(selector => document.activeElement === document.querySelector(selector), expectedFocus);
  }
}

async function actionResult(page, id, date, buttonSelector, expected, screenshot) {
  await page.locator('#reportDate').fill(date);
  const invoker = page.locator(buttonSelector);
  await invoker.focus();
  await invoker.press('Space');
  const dialog = page.locator('dialog.admin-accessible-dialog[open]');
  await dialog.waitFor({ state: 'visible' });
  if (await dialog.getAttribute('role') !== 'alertdialog') throw new Error(id + ' feedback is not an alert dialog');
  if (await dialog.locator('[data-dialog-cancel]').isVisible()) throw new Error(id + ' alert dialog exposes an inapplicable cancel action');
  const text = await dialog.innerText();
  if (!text.includes(expected)) throw new Error(id + ' returned unexpected feedback: ' + text);
  await page.waitForTimeout(300);
  await capture(page, screenshot, false);
  actions.push({ id, passed: true, feedback: expected });
  await closeAdminAlert(page, buttonSelector);
}

async function openReportByDate(page, date) {
  const row = page.locator('#reportRows tr').filter({ hasText: date });
  await row.waitFor({ state: 'visible' });
  await row.getByRole('button', { name: '查看', exact: true }).click();
  await page.locator('#reportModal').waitFor({ state: 'visible' });
  await page.locator('#reportModal').evaluate(element => { element.scrollTop = 0; });
  await page.waitForTimeout(250);
  return page.locator('#reportDetail').innerText();
}

async function closeReport(page) {
  await page.locator('#reportModal button.close').click();
  await page.locator('#reportModal').waitFor({ state: 'hidden' });
}

async function assertMobileHistoryCard(page) {
  const row = page.locator('#reportRows tr').first();
  const action = row.getByRole('button', { name: '查看', exact: true });
  await action.scrollIntoViewIfNeeded();
  const layout = await page.evaluate(() => {
    const actionButton = document.querySelector('#reportRows tr:first-child td[data-label="操作"] button');
    const actionCell = document.querySelector('#reportRows tr:first-child td[data-label="操作"]');
    if (!actionButton || !actionCell) return null;
    const rect = actionButton.getBoundingClientRect();
    return {
      scrollLeft: document.documentElement.scrollLeft || document.body.scrollLeft || 0,
      actionCellDisplay: window.getComputedStyle(actionCell).display,
      actionLeft: rect.left,
      actionRight: rect.right,
      actionHeight: rect.height,
    };
  });
  if (!layout || layout.scrollLeft !== 0 || layout.actionCellDisplay !== 'grid' || layout.actionLeft < 0 || layout.actionRight > 390 || layout.actionHeight < 38) {
    throw new Error('Mobile history action is not visible and tappable without horizontal scrolling: ' + JSON.stringify(layout));
  }
  layoutAssertions.push({
    id: 'mobile-history-card-action', viewport: [390, 844], noHorizontalOverflow: true,
    expectedTextVisible: true, primaryControlReachable: true,
  });
}

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROME || undefined });
  try {
    const desktop = await readyPage(browser, { width: 1440, height: 1000 }, 'ready', 'desktop-operator-workflow');
    const meta = await fixture(desktop.page);
    const reviewSamples = await fixture(desktop.page, 'review_samples');
    if (!reviewSamples.samples || !reviewSamples.samples.telegram || !reviewSamples.samples.reportPayloads || !reviewSamples.samples.ruleInputs) throw new Error('Review samples are incomplete');
    await desktop.page.goto(base + '/admin/health_report.php');
    await desktop.page.waitForLoadState('networkidle');
    await assertNoPageOverflow(desktop.page, desktop.telemetry.name);
    const viewportContent = await desktop.page.locator('meta[name="viewport"]').getAttribute('content');
    if (!viewportContent || /maximum-scale|user-scalable\s*=\s*no/i.test(viewportContent)) throw new Error('Viewport prevents user zoom: ' + viewportContent);
    if (await desktop.page.getByText('曾支付成功率', { exact: true }).count() !== 1) throw new Error('KPI label is missing');
    if (await desktop.page.getByRole('button', { name: '生成AI建议' }).isDisabled()) throw new Error('AI action should be enabled in the interaction fixture');
    if (await desktop.page.getByRole('button', { name: '加入发送队列' }).isDisabled()) throw new Error('Queue action should be enabled in the interaction fixture');
    const queueContrast = await desktop.page.getByRole('button', { name: '加入发送队列' }).evaluate(element => {
      function rgb(value) { const m = value.match(/[\d.]+/g); return m ? m.slice(0, 3).map(Number) : null; }
      function luminance(values) { return values.map(v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }).reduce((sum, value, index) => sum + value * [.2126, .7152, .0722][index], 0); }
      const style = getComputedStyle(element), fg = rgb(style.color), bg = rgb(style.backgroundColor);
      if (!fg || !bg) return 0;
      const a = luminance(fg), b = luminance(bg);
      return (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
    });
    if (queueContrast < 4.5) throw new Error('Queue button contrast is below 4.5:1: ' + queueContrast);
    actions.push({ id: 'accessibility-contrast-zoom', passed: true, feedback: '发送按钮对比度达标且页面允许缩放' });
    await capture(desktop.page, 'desktop-overview.png');

    let detail = await openReportByDate(desktop.page, meta.dates.overview);
    if (!detail.includes('退款：17') || !detail.includes('冻结：2') || !detail.includes('预授权：3')) throw new Error('Outcome counters are missing');
    if (!detail.includes('符合条件 7600') || !detail.includes('重试中 8') || !detail.includes('最终失败 3')) throw new Error('Merchant callback evidence is missing');
    if (!detail.includes('AI 只选择优先复核项')) throw new Error('AI boundary copy is missing');
    if (!detail.includes('优选 H5') || !detail.includes('30-100：49/80')) throw new Error('Channel details are missing');
    if (!detail.includes('7日基线成功率=69.2') || !detail.includes('当前成功率=63')) throw new Error('Localized rule evidence is missing');
    if (detail.includes('baseline_rate=') || detail.includes('frozen_orders=') || detail.includes('paid_orders=') || detail.includes('<b>warning</b>')) throw new Error('Raw rule labels leaked into the detail UI');
    await capture(desktop.page, 'desktop-detail.png', false);
    await closeReport(desktop.page);

    const reviewCases = [
      ['healthy', meta.dates.review_healthy, '状态：正常', '曾支付成功率：90%', 'desktop-review-healthy.png'],
      ['degraded', meta.dates.review_degraded, '状态：告警', '成功率较基线下降30个百分点', 'desktop-review-degraded.png'],
		['currentIncomplete', meta.dates.review_incomplete, '状态：严重（数据不足）', '最终通知失败', 'desktop-review-current-incomplete.png'],
      ['baselineMissing', meta.dates.review_baseline_missing, '状态：数据不足', '最近7日基线快照不完整', 'desktop-review-baseline-missing.png'],
		['sampleInsufficient', meta.dates.review_sample_insufficient, '状态：告警（数据不足）', '冻结订单', 'desktop-review-sample-insufficient.png'],
    ];
    for (const item of reviewCases) {
      detail = await openReportByDate(desktop.page, item[1]);
      if (!detail.includes(item[2]) || !detail.includes(item[3])) throw new Error(item[0] + ' persisted detail is inconsistent: ' + detail);
      visibleSamples['desktopReview-' + item[0]] = detail;
      await capture(desktop.page, item[4], false);
      await closeReport(desktop.page);
    }

    detail = await openReportByDate(desktop.page, meta.dates.delivered);
    if (!detail.includes('Telegram：已送达') || !detail.includes('送达时间：')) throw new Error('Successful delivery evidence is missing');
    visibleSamples.deliverySuccess = detail;
    await capture(desktop.page, 'desktop-delivery-success.png', false);
    await closeReport(desktop.page);

    detail = await openReportByDate(desktop.page, meta.dates.failure);
    if (!detail.includes('发送失败，需人工复核') || !detail.includes('复核路径')) throw new Error('Deterministic delivery failure guidance is missing');
    await capture(desktop.page, 'desktop-delivery-failure.png', false);
    visibleSamples.deliveryFailure = detail;
    await closeReport(desktop.page);
    detail = await openReportByDate(desktop.page, meta.dates.uncertain);
    if (!detail.includes('送达不确定，需人工复核') || !detail.includes('不要盲目重发')) throw new Error('Uncertain delivery guidance is missing');
    await capture(desktop.page, 'desktop-delivery-uncertain.png', false);
    visibleSamples.deliveryUncertain = detail;
    await closeReport(desktop.page);
    for (const item of [
      [meta.dates.missing_id, 'desktop-queue-missing-id.png'],
      [meta.dates.missing_row, 'desktop-queue-missing-row.png'],
      [meta.dates.unsupported, 'desktop-queue-unsupported.png'],
    ]) {
      detail = await openReportByDate(desktop.page, item[0]);
      if (!detail.includes('队列状态未知，需人工复核') || !detail.includes('无法证明是否已送达') || !detail.includes('不要盲目重发')) throw new Error('Unknown queue-state guidance is missing for ' + item[0]);
      await capture(desktop.page, item[1], false);
      await closeReport(desktop.page);
    }
    actions.push({ id: 'unknown-queue-states', passed: true, feedback: '缺失和不支持状态均按送达未知处理' });

    await desktop.page.locator('#reportDate').fill(meta.dates.overview);
    const queueButton = desktop.page.getByRole('button', { name: '加入发送队列' });
    await queueButton.focus();
    await queueButton.press('Enter');
    let confirm = desktop.page.getByRole('dialog', { name: '确认加入队列' });
    await confirm.waitFor({ state: 'visible' });
    if (!(await confirm.getAttribute('aria-describedby'))) throw new Error('Queue confirmation lacks an accessible description');
    if (!(await confirm.locator('[data-dialog-cancel]').evaluate(element => element === document.activeElement))) throw new Error('Focus did not enter the confirmation dialog');
    await desktop.page.keyboard.press('Shift+Tab');
    if (!(await confirm.locator('[data-dialog-confirm]').evaluate(element => element === document.activeElement))) throw new Error('Shift+Tab escaped the confirmation dialog');
    await desktop.page.keyboard.press('Tab');
    if (!(await confirm.locator('[data-dialog-cancel]').evaluate(element => element === document.activeElement))) throw new Error('Tab escaped the confirmation dialog');
    await desktop.page.keyboard.press('Escape');
    await confirm.waitFor({ state: 'hidden' });
    await desktop.page.waitForFunction(() => document.activeElement && document.activeElement.textContent.includes('加入发送队列'));
    await queueButton.press('Space');
    confirm = desktop.page.getByRole('dialog', { name: '确认加入队列' });
    await confirm.waitFor({ state: 'visible' });
    const confirmText = await confirm.innerText();
    if (!confirmText.includes('入队成功不代表已送达') || !confirmText.includes('123456789')) throw new Error('Queue confirmation lacks destination or delivery warning');
    await desktop.page.waitForTimeout(300);
    await capture(desktop.page, 'desktop-queue-confirm.png', false);
    const queueRequestPromise = desktop.page.waitForRequest(request => {
      const url = new URL(request.url());
      if (url.pathname.endsWith('/admin/ajax_health_report.php') && url.searchParams.get('act') === 'generate' && request.method() === 'POST') {
        const body = new URLSearchParams(request.postData() || '');
        return body.get('send') === '1';
      }
      return false;
    });
    await confirm.locator('[data-dialog-confirm]').press('Enter');
    const queueRequest = await queueRequestPromise;
    const queuePost = new URLSearchParams(queueRequest.postData() || '');
    if (queuePost.get('use_ai') !== '0') throw new Error('Queue action silently enabled AI');
    const queueFeedback = desktop.page.getByRole('alertdialog').filter({ hasText: '队列状态：' });
    await queueFeedback.waitFor({ state: 'visible' });
    const queueFeedbackText = await queueFeedback.innerText();
    if (!queueFeedbackText.includes('已加入发送队列（尚未送达）')) throw new Error('Queue acceptance returned unexpected feedback: ' + queueFeedbackText);
    await desktop.page.waitForTimeout(300);
    await capture(desktop.page, 'desktop-queue-accepted.png', false);
    actions.push({ id: 'queue-report', passed: true, feedback: '已加入发送队列（尚未送达）' });
    actions.push({ id: 'accessibility-dialog-keyboard', passed: true, feedback: '焦点、Tab、Shift+Tab、Enter、Space、Escape 与焦点恢复均通过' });
    await closeAdminAlert(desktop.page, 'button.health-queue-btn');
    await desktop.page.locator('#reportRows tr').filter({ hasText: meta.dates.overview }).getByText('排队中', { exact: true }).waitFor();
    detail = await openReportByDate(desktop.page, meta.dates.overview);
    if (!detail.includes('Telegram：排队中') || !detail.includes('队列 ID：')) throw new Error('Queued report detail is incomplete');
    await capture(desktop.page, 'desktop-queued-detail.png', false);
    await closeReport(desktop.page);

    await actionResult(desktop.page, 'generate-rule', meta.dates.rule, 'button[onclick^="generateReport(false"]', '规则报告已生成', 'desktop-rule-generated.png');
    await desktop.page.locator('#reportRows tr').filter({ hasText: meta.dates.rule }).waitFor();
    await actionResult(desktop.page, 'ai-degraded', meta.dates.ai_degraded, 'button[onclick^="generateReport(true"]', 'AI 服务未完成，已保留本地规则结果', 'desktop-ai-degraded.png');
    await actionResult(desktop.page, 'ai-budget', meta.dates.ai_budget, 'button[onclick^="generateReport(true"]', '已达到当日 AI 调用上限', 'desktop-ai-budget.png');
    await actionResult(desktop.page, 'ai-incomplete', meta.dates.ai_incomplete, 'button[onclick^="generateReport(true"]', '当前统计窗口快照不完整，AI 分析已跳过', 'desktop-ai-incomplete.png');
    assertClean(desktop.telemetry);
    await desktop.context.close();

    const zoom = await readyPage(browser, { width: 390, height: 844 }, 'ready', 'mobile-200-percent-text');
    await zoom.page.goto(base + '/admin/health_report.php');
    await zoom.page.waitForLoadState('networkidle');
    await zoom.page.locator('.health-wrap').evaluate(element => element.classList.add('health-text-zoom'));
    await assertNoPageOverflow(zoom.page, zoom.telemetry.name);
    const zoomQueue = zoom.page.getByRole('button', { name: '加入发送队列' });
    if (!(await zoomQueue.isVisible())) throw new Error('Queue control is not visible at 200% text size');
    await zoomQueue.focus();
    await zoomQueue.press('Space');
    const zoomDialog = zoom.page.getByRole('dialog', { name: '确认加入队列' });
    await zoomDialog.waitFor({ state: 'visible' });
    const zoomDialogBounds = await zoomDialog.evaluate(element => { const rect = element.getBoundingClientRect(); return { left: rect.left, right: rect.right, width: rect.width }; });
    if (zoomDialogBounds.left < 0 || zoomDialogBounds.right > 390 || zoomDialogBounds.width < 280) throw new Error('Dialog is not readable at 200% text size: ' + JSON.stringify(zoomDialogBounds));
    await zoom.page.keyboard.press('Escape');
    await zoomDialog.waitFor({ state: 'hidden' });
    await zoom.page.waitForFunction(() => document.activeElement && document.activeElement.textContent.includes('加入发送队列'));
    actions.push({ id: 'accessibility-200-percent-text', passed: true, feedback: '200% 文字缩放下历史记录、控制按钮和对话框保持可读可操作' });
    assertClean(zoom.telemetry);
    await zoom.context.close();

    const settings = await readyPage(browser, { width: 390, height: 844 }, null, 'mobile-settings-workflow');
    await settings.page.goto(base + '/admin/health_report_set.php');
    await settings.page.waitForLoadState('networkidle');
    await assertNoPageOverflow(settings.page, settings.telemetry.name);
    const unlabelled = await settings.page.locator('input:not([type="hidden"]):not([aria-label]), select:not([aria-label])').count();
    if (unlabelled !== 0) throw new Error('Unlabelled settings controls: ' + unlabelled);
    if (await settings.page.getByText('关闭只停止未来自动生成和新入队', { exact: false }).count() !== 1) throw new Error('Disable semantics are not disclosed');
    await settings.page.locator('.health-settings-wrap').evaluate(element => element.classList.add('health-text-zoom'));
    await assertNoPageOverflow(settings.page, 'mobile-settings-200-percent-text');
    if (!(await settings.page.locator('[name="health_report_chat_id"]').isVisible()) || !(await settings.page.locator('#healthSettingForm button[type="submit"]').isVisible())) throw new Error('Settings controls are not operable at 200% text size');
    await settings.page.locator('.health-settings-wrap').evaluate(element => element.classList.remove('health-text-zoom'));
    await capture(settings.page, 'mobile-settings.png', false);

    await settings.page.locator('[name="health_report_chat_id"]').fill('bad-chat');
    await settings.page.locator('#healthSettingForm button[type="submit"]').click();
    let settingFeedback = settings.page.getByRole('alertdialog').filter({ hasText: 'Chat ID 格式不正确' });
    await settingFeedback.waitFor({ state: 'visible' });
    if (!(await settingFeedback.innerText()).includes('Chat ID 格式不正确')) throw new Error('Invalid settings were not rejected');
    actions.push({ id: 'settings-validation', passed: true, feedback: 'Chat ID 格式不正确' });
    await closeAdminAlert(settings.page, '#healthSettingForm button[type="submit"]');
    await settings.page.locator('[name="health_report_chat_id"]').fill('123456789');
    await settings.page.locator('[name="health_report_enabled"]').selectOption('0');
    await settings.page.locator('#healthSettingForm button[type="submit"]').click();
    settingFeedback = settings.page.getByRole('alertdialog').filter({ hasText: '设置已保存' });
    await settingFeedback.waitFor({ state: 'visible' });
    if (!(await settingFeedback.innerText()).includes('设置已保存')) throw new Error('Valid settings were not saved');
    await settings.page.waitForTimeout(300);
    await capture(settings.page, 'mobile-settings-saved.png', false);
    visibleSamples.reportDisabled = await settings.page.locator('body').innerText();
    actions.push({ id: 'settings-save-disable', passed: true, feedback: '设置已保存' });
    await closeAdminAlert(settings.page);
    await settings.page.waitForLoadState('networkidle');
    if (await settings.page.locator('[name="health_report_enabled"]').inputValue() !== '0') throw new Error('Disabled setting was not persisted');
    assertClean(settings.telemetry);
    await settings.context.close();

    const stateContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const statePage = await stateContext.newPage();
    const state = await fixture(statePage, 'state');
    if (state.report_enabled !== false) throw new Error('Fixture report setting did not remain disabled');
    const pending = state.rows.find(row => row.report_date === meta.dates.overview);
    if (!pending || Number(pending.telegram_status) !== 1 || Number(pending.queue_status) !== 0) throw new Error('Disabling future reports mutated the queued delivery');
    actions.push({ id: 'disable-preserves-queue', passed: true, feedback: '已排队任务保持待发送' });
    await stateContext.close();

    const mobile = await readyPage(browser, { width: 390, height: 844 }, 'ready', 'mobile-overview');
    await mobile.page.goto(base + '/admin/health_report.php');
    await mobile.page.waitForLoadState('networkidle');
    await assertNoPageOverflow(mobile.page, mobile.telemetry.name);
    if (!(await mobile.page.getByRole('button', { name: '加入发送队列' }).isDisabled())) throw new Error('Queue action must be disabled after daily reports are disabled');
    await assertMobileHistoryCard(mobile.page);
    await mobile.page.evaluate(() => window.scrollTo(0, 0));
    await capture(mobile.page, 'mobile-overview.png');
    const mobileDetail = await openReportByDate(mobile.page, meta.dates.overview);
    if (!mobileDetail.includes('Telegram：排队中') || !mobileDetail.includes('商户回调：符合条件 7600')) throw new Error('Mobile queued report detail is missing');
    const channelCard = await mobile.page.locator('.health-channel-table tbody tr').first().evaluate(row => ({
      labels: Array.from(row.querySelectorAll('td')).map(cell => cell.getAttribute('data-label')),
      displays: Array.from(row.querySelectorAll('td')).map(cell => window.getComputedStyle(cell).display),
      width: row.getBoundingClientRect().width,
    }));
    const expectedChannelLabels = ['通道', '订单', '曾支付', '成功率', '连续未支付', '回调等待/失败', '金额区间'];
    if (JSON.stringify(channelCard.labels) !== JSON.stringify(expectedChannelLabels) || channelCard.displays.some(value => value !== 'grid') || channelCard.width > 390) {
      throw new Error('Mobile channel details are not readable cards: ' + JSON.stringify(channelCard));
    }
    layoutAssertions.push({ id: 'mobile-channel-detail-cards', viewport: [390, 844], noHorizontalOverflow: true, expectedTextVisible: true, primaryControlReachable: true });
    await assertNoPageOverflow(mobile.page, 'mobile-detail');
    await capture(mobile.page, 'mobile-detail.png', false);
    visibleSamples.mobileOverviewDetail = mobileDetail;
    await recordMobileState(mobile.page, 'mobile-overview-detail', 'Telegram：排队中', '#reportModal button.close');
    await closeReport(mobile.page);

    for (const item of reviewCases) {
      const reviewDetail = await openReportByDate(mobile.page, item[1]);
      if (!reviewDetail.includes(item[2]) || !reviewDetail.includes(item[3])) throw new Error('Mobile ' + item[0] + ' persisted detail is inconsistent: ' + reviewDetail);
      await recordMobileState(mobile.page, 'mobile-review-' + item[0], item[3], '#reportModal button.close');
      visibleSamples['mobileReview-' + item[0]] = reviewDetail;
      await capture(mobile.page, item[4].replace('desktop-', 'mobile-'), false);
      await closeReport(mobile.page);
    }

    let mobileAdverse = await openReportByDate(mobile.page, meta.dates.delivered);
    await recordMobileState(mobile.page, 'mobile-delivery-success', 'Telegram：已送达', '#reportModal button.close');
    visibleSamples.mobileDeliverySuccess = mobileAdverse;
    await capture(mobile.page, 'mobile-delivery-success.png', false);
    await closeReport(mobile.page);

    mobileAdverse = await openReportByDate(mobile.page, meta.dates.failure);
    await recordMobileState(mobile.page, 'mobile-delivery-failure', '发送失败，需人工复核', '#reportModal button.close');
    visibleSamples.mobileDeliveryFailure = mobileAdverse;
    await capture(mobile.page, 'mobile-delivery-failure.png', false);
    await closeReport(mobile.page);
    mobileAdverse = await openReportByDate(mobile.page, meta.dates.uncertain);
    await recordMobileState(mobile.page, 'mobile-delivery-uncertain', '送达不确定，需人工复核', '#reportModal button.close');
    visibleSamples.mobileDeliveryUncertain = mobileAdverse;
    await capture(mobile.page, 'mobile-delivery-uncertain.png', false);
    await closeReport(mobile.page);
    for (const item of [
      [meta.dates.missing_id, 'mobile-queue-missing-id', 'mobile-queue-missing-id.png'],
      [meta.dates.missing_row, 'mobile-queue-missing-row', 'mobile-queue-missing-row.png'],
      [meta.dates.unsupported, 'mobile-queue-unsupported', 'mobile-queue-unsupported.png'],
    ]) {
      mobileAdverse = await openReportByDate(mobile.page, item[0]);
      await recordMobileState(mobile.page, item[1], '队列状态未知，需人工复核', '#reportModal button.close');
      visibleSamples[item[1]] = mobileAdverse;
      await capture(mobile.page, item[2], false);
      await closeReport(mobile.page);
    }
    for (const item of [
      [meta.dates.ai_degraded, 'mobile-ai-degraded', 'AI 服务未完成，已保留本地规则结果', 'mobile-ai-degraded.png'],
      [meta.dates.ai_budget, 'mobile-ai-budget', '已达到当日 AI 调用上限', 'mobile-ai-budget.png'],
      [meta.dates.ai_incomplete, 'mobile-ai-incomplete', '当前统计窗口快照不完整，AI 分析已跳过', 'mobile-ai-incomplete.png'],
    ]) {
      await mobile.page.locator('#reportDate').fill(item[0]);
      await mobile.page.getByRole('button', { name: '生成AI建议' }).click();
      const dialog = mobile.page.getByRole('alertdialog');
      await dialog.waitFor({ state: 'visible' });
      const feedback = await dialog.innerText();
      if (!feedback.includes(item[2])) throw new Error(item[1] + ' returned unexpected feedback: ' + feedback);
      await recordMobileState(mobile.page, item[1], item[2], 'dialog.admin-accessible-dialog [data-dialog-confirm]');
      visibleSamples[item[1]] = feedback;
      await capture(mobile.page, item[3], false);
      await closeAdminAlert(mobile.page, 'button[onclick^="generateReport(true"]');
    }
    assertClean(mobile.telemetry);
    await mobile.context.close();

    const loading = await readyPage(browser, { width: 1440, height: 1000 }, null, 'desktop-loading');
    let releaseLoading;
    await loading.page.route('**/admin/ajax_health_report.php?act=list', route => new Promise(resolve => {
      releaseLoading = async () => { await route.fulfill({ status: 200, contentType: 'application/json', body: '{"code":0,"data":[]}' }); resolve(); };
    }));
    await loading.page.goto(base + '/admin/health_report.php', { waitUntil: 'domcontentloaded' });
    await loading.page.getByText('正在加载', { exact: true }).waitFor();
    if (await loading.page.getByRole('status').count() !== 1 || await loading.page.locator('#reportHistoryRegion').getAttribute('aria-busy') !== 'true') throw new Error('Loading state is not announced exactly once');
    await assertNoPageOverflow(loading.page, loading.telemetry.name);
    await capture(loading.page, 'desktop-loading.png');
    await releaseLoading();
    await loading.page.getByText('暂无报告', { exact: true }).waitFor();
    if (await loading.page.locator('#reportHistoryRegion').getAttribute('aria-busy') !== 'false' || !(await loading.page.getByRole('status').innerText()).includes('历史日报为空')) throw new Error('Empty state did not clear busy status or announce completion');
    assertClean(loading.telemetry);
    await loading.context.close();

    const empty = await readyPage(browser, { width: 1440, height: 1000 }, 'empty', 'desktop-empty');
    await empty.page.goto(base + '/admin/health_report.php');
    await empty.page.getByText('暂无报告', { exact: true }).waitFor();
    if (await empty.page.getByRole('status').count() !== 1 || await empty.page.locator('#reportHistoryRegion').getAttribute('aria-busy') !== 'false') throw new Error('Empty history state accessibility metadata is invalid');
    await assertNoPageOverflow(empty.page, empty.telemetry.name);
    await capture(empty.page, 'desktop-empty.png');
    assertClean(empty.telemetry);
    await empty.context.close();

    const failure = await readyPage(browser, { width: 1440, height: 1000 }, 'failure', 'desktop-failure', true);
    await failure.page.goto(base + '/admin/health_report.php');
    await failure.page.getByText('加载失败，请刷新重试', { exact: true }).waitFor();
    if (await failure.page.getByRole('status').count() !== 1 || await failure.page.locator('#reportHistoryRegion').getAttribute('aria-busy') !== 'false' || !(await failure.page.getByRole('status').innerText()).includes('加载失败')) throw new Error('Failure state was not announced and cleared');
    await assertNoPageOverflow(failure.page, failure.telemetry.name);
    await capture(failure.page, 'desktop-failure.png');
    if (failure.telemetry.pageErrors.length || failure.telemetry.requestFailures.length) throw new Error('Failure state caused a page or transport crash');
    await failure.context.close();

    const mobileLoading = await readyPage(browser, { width: 390, height: 844 }, null, 'mobile-loading');
    let releaseMobileLoading;
    await mobileLoading.page.route('**/admin/ajax_health_report.php?act=list', route => new Promise(resolve => {
      releaseMobileLoading = async () => { await route.fulfill({ status: 200, contentType: 'application/json', body: '{"code":0,"data":[]}' }); resolve(); };
    }));
    await mobileLoading.page.goto(base + '/admin/health_report.php', { waitUntil: 'domcontentloaded' });
    await mobileLoading.page.getByText('正在加载', { exact: true }).waitFor();
    await recordMobileState(mobileLoading.page, 'mobile-loading', '正在加载', 'a[href="health_report_set.php"]');
    visibleSamples.mobileLoading = await mobileLoading.page.locator('body').innerText();
    await capture(mobileLoading.page, 'mobile-loading.png');
    await releaseMobileLoading();
    await mobileLoading.page.getByText('暂无报告', { exact: true }).waitFor();
    assertClean(mobileLoading.telemetry);
    await mobileLoading.context.close();

    const mobileEmpty = await readyPage(browser, { width: 390, height: 844 }, 'empty', 'mobile-empty');
    await mobileEmpty.page.goto(base + '/admin/health_report.php');
    await mobileEmpty.page.getByText('暂无报告', { exact: true }).waitFor();
    await recordMobileState(mobileEmpty.page, 'mobile-empty', '暂无报告', 'a[href="health_report_set.php"]');
    visibleSamples.mobileEmpty = await mobileEmpty.page.locator('body').innerText();
    await capture(mobileEmpty.page, 'mobile-empty.png');
    assertClean(mobileEmpty.telemetry);
    await mobileEmpty.context.close();

    const mobileFailure = await readyPage(browser, { width: 390, height: 844 }, 'failure', 'mobile-failure', true);
    await mobileFailure.page.goto(base + '/admin/health_report.php');
    await mobileFailure.page.getByText('加载失败，请刷新重试', { exact: true }).waitFor();
    await recordMobileState(mobileFailure.page, 'mobile-failure', '加载失败，请刷新重试', 'button[onclick^="generateReport(false"]');
    visibleSamples.mobileFailure = await mobileFailure.page.locator('body').innerText();
    await capture(mobileFailure.page, 'mobile-failure.png');
    if (mobileFailure.telemetry.pageErrors.length || mobileFailure.telemetry.requestFailures.length) throw new Error('Mobile failure state caused a page or transport crash');
    actions.push({ id: 'accessibility-live-status', passed: true, feedback: '加载、空数据与失败状态均通过单一 live region 播报并正确更新 aria-busy' });
    await mobileFailure.context.close();

    fs.mkdirSync(evidenceDir, { recursive: true, mode: 0o755 });
    for (const [filename, buffer] of captures) fs.writeFileSync(path.join(evidenceDir, filename), buffer, { mode: 0o644 });
    const result = {
      format: 'epay-health-ui-browser-result-v2', passed: true, generatedAt: new Date().toISOString(),
      baseOrigin: 'loopback-isolated-database', endpointMode: 'actual-admin-endpoint', browserVersion: browser.version(),
      viewports: { desktop: [1440, 1000], mobile: [390, 844] }, scenarios, actions, layoutAssertions,
      reviewSamples: { server: reviewSamples.samples, visibleUi: visibleSamples },
      captures: Array.from(captures.keys()).sort(),
    };
    fs.writeFileSync(path.join(evidenceDir, 'browser-results.json'), JSON.stringify(result, null, 2) + '\n', { mode: 0o644 });
  } finally {
    await browser.close();
  }
  process.stdout.write('health UI playwright: ok\n');
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exit(1); });
