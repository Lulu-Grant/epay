'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '../assets/js/list-read-ui.js'), 'utf8');
const failures = [];
function check(ok, label) {
  if (ok) console.log('PASS ' + label);
  else {
    failures.push(label);
    console.log('FAIL ' + label);
  }
}

function load() {
  const ajaxCalls = [];
  let statusText = '';
  const chain = {
    length: 0,
    on() { return chain; },
    appendTo() { return chain; },
    insertBefore() { return chain; },
    toggleClass() { return chain; },
    closest() { return chain; },
    data() { return null; },
    find(sel) {
      if (sel === 'span') return { text(value) { if (value !== undefined) statusText = value; return statusText; } };
      return { text() { return chain; } };
    }
  };
  function $(sel) {
    if (typeof sel === 'string' && sel.charAt(0) === '<') return chain;
    return chain;
  }
  $.ajax = function (options) {
    ajaxCalls.push(options);
    return { abort() {} };
  };
  $.extend = function () {
    const out = {};
    for (const src of arguments) if (src) Object.assign(out, src);
    return out;
  };
  const window = { location: { href: 'http://127.0.0.1/admin/order.php' } };
  const history = { replaceState() {} };
  vm.runInNewContext(source, { window, jQuery: $, URL, history, console });
  return {
    ui: window.ListReadUI,
    ajaxCalls,
    status() { return statusText; }
  };
}

const fixed = new Date(2026, 9, 17, 15, 4, 5, 7);
const ui = load().ui;
const meta = ui.requestMeta(fixed);
check(/^20261017150405007[0-9a-f]{4}$/.test(meta.id), 'request id is a timestamp plus 4 hex digits');
check(meta.timeText === '2026-10-17 15:04:05', 'request time is local wall time');
const sample = { id: '20261017150405007ab12', timeText: '2026-10-17 15:04:05', started: fixed.getTime() };
check(ui.failureText('timeout', 15000, sample, 0) === '列表查询超时，请重试。耗时 15.0 秒，请求号 20261017150405007ab12，时间 2026-10-17 15:04:05', 'timeout copy leads with the existing sentence');
check(ui.failureText('error', 320, sample, 0) === '列表查询失败，请重试。耗时 0.3 秒，请求号 20261017150405007ab12，时间 2026-10-17 15:04:05', 'network failure names elapsed time and request id');
check(ui.failureText('error', 800, sample, 502).startsWith('列表查询失败（HTTP 502），请重试。耗时 0.8 秒'), 'HTTP status stays in the lead sentence');

const harness = load();
let errored = false;
harness.ui.ajax('#listTable')({
  url: 'ajax_order.php?act=orderList',
  data: { offset: 0, limit: 30 },
  success() {},
  error() { errored = true; }
});
const sent = harness.ajaxCalls[0];
check(sent.timeout === 15000, 'list timeout stays 15 seconds');
check(/[?&]rid=2026\d{13}[0-9a-f]{4}/.test(sent.url), 'request id is on the query string');
check(sent.headers['X-Request-Id'] === decodeURIComponent(sent.url.split('rid=')[1]), 'request id header matches the query');
sent.error({ status: 0 }, 'timeout');
check(harness.status().startsWith('列表查询超时，请重试。') && harness.status().includes('请求号 ' + sent.headers['X-Request-Id']) && errored, 'timeout handler shows elapsed time and the same request id');

const jsonError = load();
jsonError.ui.ajax('#listTable')({
  url: 'ajax_order.php?act=orderList?rid=already',
  data: {},
  success() {},
  error() {}
});
check(jsonError.ajaxCalls[0].url === 'ajax_order.php?act=orderList?rid=already', 'an existing rid is not duplicated');
jsonError.ajaxCalls[0].success({ code: -1, msg: '订单查询失败，请重试' });
check(jsonError.status().startsWith('订单查询失败，请重试。耗时 ') && jsonError.status().includes('请求号 '), 'JSON errors keep the server message and append the tail');

const ok = load();
let rows;
ok.ui.ajax('#listTable')({
  url: 'ajax_order.php?act=orderList',
  data: {},
  success(result) { rows = result.rows; },
  error() { rows = null; }
});
ok.ajaxCalls[0].success({ total: 1, rows: [{ trade_no: '1' }], meta: { total_as_of: Date.now(), offset: 0, limit: 30 } });
check(Array.isArray(rows) && rows.length === 1 && ok.status().startsWith('列表实时查询；总数统计于 '), 'successful list text is unchanged');

if (failures.length) {
  console.error(failures.length + ' failed');
  process.exit(1);
}
console.log('LIST_READ_FAILURE_MESSAGE_OK');
