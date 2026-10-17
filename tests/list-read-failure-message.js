const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

const window = {
  location: { href: 'http://localhost/admin/order.php' },
  $_GET: []
};
const context = {
  window,
  history: { replaceState() {} },
  $: { fn: {} },
  console
};
context.global = context;
vm.createContext(context);
vm.runInContext(fs.readFileSync(require('path').join(__dirname, '../assets/js/custom.js'), 'utf8'), context);

const msg = context.listReadFailureMessage;
assert.strictEqual(
  msg('timeout', 'timeout', 15000, '20261017150400123abcd', '2026-10-17 15:04:00'),
  '列表查询超时，请重试。耗时 15.0 秒，请求号 20261017150400123abcd，时间 2026-10-17 15:04:00'
);
assert.strictEqual(
  msg(0, 'error', 15000, 'RID', '2026-10-17 15:04:00').indexOf('列表查询超时，请重试。'),
  0
);
assert.strictEqual(
  msg(0, 'error', 3200, 'RID', '2026-10-17 15:04:05'),
  '列表查询失败，请重试。耗时 3.2 秒，请求号 RID，时间 2026-10-17 15:04:05'
);
assert.strictEqual(
  msg(502, 'error', 800, 'RID', '2026-10-17 15:04:06'),
  '列表查询失败（HTTP 502），请重试。耗时 0.8 秒，请求号 RID，时间 2026-10-17 15:04:06'
);
assert.strictEqual(
  msg(0, 'error', 0, '', ''),
  '列表查询失败，请重试。耗时 0.0 秒，请求号 -，时间 -'
);

const fixed = new Date(2026, 9, 17, 15, 4, 1, 123);
const meta = context.listReadRequestMeta(fixed);
assert.strictEqual(meta.id.indexOf('20261017150401123'), 0);
assert.strictEqual(meta.timeText, '2026-10-17 15:04:01');
assert.match(meta.id, /^[A-Za-z0-9]{8,40}$/);
assert.strictEqual(context.LIST_READ_TIMEOUT_MS, 15000);

console.log('ok');
