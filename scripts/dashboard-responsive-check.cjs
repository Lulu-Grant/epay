#!/usr/bin/env node
// Checks the shared dashboard styles at real emulated CSS viewport widths.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { spawn } = require('node:child_process');

const chrome = process.env.CHROME_PATH || (process.platform === 'win32'
  ? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' : 'google-chrome');
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'epay-dashboard-check-'));
const fixtureUrl = pathToFileURL(path.join(__dirname, 'dashboard-responsive-fixture.html')).href;
const overlayRoot = process.env.LP_OVERLAY_ROOT;
const overlayCss = overlayRoot ? ['tokens.css','bootstrap3.css','plugins.css','admin.css']
  .map(file => fs.readFileSync(path.join(overlayRoot, 'assets', 'css', 'lp-dark', file), 'utf8')).join('\n') : null;
const rollPage = fs.readFileSync(path.join(__dirname, '..', 'admin', 'pay_roll.php'), 'utf8');
const rollHelper = rollPage.slice(rollPage.indexOf('var rollRowIndex = 0;'), rollPage.indexOf('function editInfo(id){'));
const rollHandlers = rollPage.slice(rollPage.indexOf('$(document).on("click", ".pay-append"'), rollPage.lastIndexOf('</script>'));
if (!rollHelper.startsWith('var rollRowIndex') || !rollHandlers.startsWith('$(document).on'))
  throw new Error('Could not locate current roll UI source');
let processHandle;

const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
async function readyPort() {
  for (let i = 0; i < 100; i++) {
    const portFile = path.join(profile, 'DevToolsActivePort');
    if (fs.existsSync(portFile)) return Number(fs.readFileSync(portFile, 'utf8').split(/\r?\n/)[0]);
    if (processHandle.exitCode !== null) throw new Error('Chrome exited before DevTools was ready');
    await pause(100);
  }
  throw new Error('Chrome DevTools did not start');
}

async function connect(url) {
  const socket = new WebSocket(url);
  const pending = new Map();
  let nextId = 0;
  await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
  });
  socket.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (!message.id || !pending.has(message.id)) return;
    const item = pending.get(message.id);
    pending.delete(message.id);
    if (message.error) item.reject(new Error(message.error.message));
    else item.resolve(message.result);
  });
  return {
    send(method, params = {}) {
      const id = ++nextId;
      return new Promise((resolve, reject) => {
        pending.set(id, { resolve, reject });
        socket.send(JSON.stringify({ id, method, params }));
      });
    },
    close() { socket.close(); }
  };
}

async function main() {
  processHandle = spawn(chrome, [
    '--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run',
    '--allow-file-access-from-files', '--remote-debugging-port=0',
    `--user-data-dir=${profile}`, 'about:blank'
  ], { stdio: 'ignore', windowsHide: true });
  const port = await readyPort();
  const pages = await (await fetch(`http://127.0.0.1:${port}/json`)).json();
  const page = pages.find(item => item.type === 'page');
  if (!page) throw new Error('Chrome page target missing');
  const devtools = await connect(page.webSocketDebuggerUrl);
  try {
    await devtools.send('Page.enable');
    await devtools.send('Runtime.enable');
    for (const [width, height] of [[320,568],[360,800],[390,844],[414,896],[667,375],[768,1024],[1440,900]]) {
      await devtools.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: width < 768 });
      await devtools.send('Page.navigate', { url: fixtureUrl });
      let metrics;
      for (let attempt = 0; attempt < 80; attempt++) {
        const evaluated = await devtools.send('Runtime.evaluate', {
          expression: "document.body.dataset.metrics || ''", returnByValue: true
        });
        const value = evaluated.result.value;
        if (value) { metrics = JSON.parse(value); break; }
        await pause(50);
      }
      if (!metrics) throw new Error(`${width}px fixture did not load`);
      if (metrics.viewport !== width || metrics.pageWidth > width + 1 ||
          metrics.formWidth > metrics.mainWidth + 1 || metrics.rowWidth > metrics.rowClientWidth + 1) {
        throw new Error(`${width}px layout failed: ${JSON.stringify(metrics)}`);
      }
      console.log(`${width}px: page=${metrics.pageWidth}, form=${metrics.formWidth}, row=${metrics.rowWidth}`);
      if (overlayCss) {
        const overlayResult = await devtools.send('Runtime.evaluate', { returnByValue: true, expression: `(() => {
          document.documentElement.classList.add('lp-dark');
          document.documentElement.setAttribute('data-lp-theme', 'admin');
          const style = document.createElement('style');
          style.textContent = ${JSON.stringify(overlayCss)};
          document.head.appendChild(style);
          const row = document.querySelector('.roll-row');
          const main = document.querySelector('main');
          return JSON.stringify({ viewport: innerWidth, pageWidth: document.documentElement.scrollWidth,
            formWidth: document.getElementById('filters').scrollWidth, mainWidth: main.clientWidth,
            rowWidth: row.scrollWidth, rowClientWidth: row.clientWidth });
        })()` });
        const overlaid = JSON.parse(overlayResult.result.value);
        if (overlaid.pageWidth > width + 1 || overlaid.formWidth > overlaid.mainWidth + 1 ||
            overlaid.rowWidth > overlaid.rowClientWidth + 1) {
          throw new Error(`${width}px lp-dark layout failed: ${JSON.stringify(overlaid)}`);
        }
        console.log(`${width}px lp-dark: page=${overlaid.pageWidth}, form=${overlaid.formWidth}, row=${overlaid.rowWidth}`);
      }
    }
    const uiResult = await devtools.send('Runtime.evaluate', { returnByValue: true, expression: `(() => {
      document.body.innerHTML = '<select id="channel"><option value="901">甲</option><option value="902">乙</option></select>' +
        '<form id="form-info"><input name="originalKind" value="1"><dl class="fieldlist"></dl>' +
        '<button type="button" class="pay-append">追加</button></form>';
      return new Function(${JSON.stringify(rollHelper + rollHandlers + `
        for (var i=0; i<10; i++) addRollRow(i%2 ? 902 : 901, i%2 ? 20 : 80);
        var rows = $('#form-info .fieldlist > dd');
        if(rows.length !== 10 || $('#form-info .fieldlist dd dd').length) throw new Error('rows nested or missing');
        rows.eq(4).find('.btn-remove').trigger('click');
        if($('#form-info .fieldlist > dd').length !== 9) throw new Error('remove affected sibling');
        $('#form-info .fieldlist > dd .btn-remove').each(function(){ $(this).trigger('click'); });
        if($('#form-info .fieldlist > dd').length) throw new Error('could not remove all rows');
        $('.pay-append').trigger('click');
        var last = $('#form-info .fieldlist > dd');
        if(last.length !== 1 || last.find('.roll-weight').val() !== '1') throw new Error('re-add default failed');
        last.find('.roll-channel').val('902'); last.find('.roll-weight').val('20');
        var values = $('#form-info').serializeArray();
        var channel = values.find(item => /\\[channel\\]$/.test(item.name));
        var weight = values.find(item => /\\[weight\\]$/.test(item.name));
        if(!channel || !weight || channel.value !== '902' || weight.value !== '20' ||
           channel.name.replace('channel','') !== weight.name.replace('weight',''))
          throw new Error('channel and weight serialized out of sync');
        return 'roll UI: 10 rows, remove all, re-add, serialization ok';
      `)})();
    })()` });
    if (uiResult.exceptionDetails) throw new Error(uiResult.exceptionDetails.text);
    console.log(uiResult.result.value);
  } finally {
    devtools.close();
  }
}

main().catch(error => { console.error(error); process.exitCode = 1; }).finally(async () => {
  if (processHandle && processHandle.exitCode === null) processHandle.kill();
  await pause(300);
  const tempRoot = path.resolve(os.tmpdir()) + path.sep;
  if (path.resolve(profile).startsWith(tempRoot)) {
    try { fs.rmSync(profile, { recursive: true, force: true }); } catch (_) { /* Chrome may still hold a temporary file. */ }
  }
});
