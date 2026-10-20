(function () {
    'use strict';
    var root = document.getElementById('merchant-defense');
    var retry = document.getElementById('defense-retry');
    if (!root || !retry) return;
    var message = document.getElementById('defense-message');
    var key = 'merchant-defense-entry-' + root.dataset.trade;
    var access = window.location.hash.slice(1);
    window.history.replaceState(null, '', window.location.pathname + window.location.search);
    try {
        if (/^[a-f0-9]{64}$/.test(access)) {
            sessionStorage.setItem(key, JSON.stringify({ access: access, until: Date.now() + 300000 }));
        } else {
            var stored = JSON.parse(sessionStorage.getItem(key) || 'null');
            access = stored && stored.until > Date.now() ? stored.access : '';
        }
    } catch (ignore) { /* The fragment still works when browser storage is unavailable. */ }
    var busy = false;
    function resume(url) {
        if (typeof url !== 'string' || !/^\/(submit2\.php|cashier\.php|shopping\.php)\?/.test(url)) {
            throw new Error('支付跳转无效，请返回商户重新发起支付');
        }
        window.location.replace(url);
    }
    async function request(body) {
        var response = await fetch(window.location.pathname + window.location.search, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ access: access }, body))
        });
        var result = await response.json();
        if (!response.ok || result.code !== 0) throw new Error(result.msg || '安全验证暂不可用，请稍后重试');
        return result;
    }
    async function work(token, prefix) {
        if (!/^[a-f0-9]{64}$/.test(token) || prefix !== '000') throw new Error('验证参数无效');
        var encoder = new TextEncoder();
        var start = Date.now();
        for (var number = 0; number < 100000000; number++) {
            var digest = new Uint8Array(await crypto.subtle.digest('SHA-256', encoder.encode(token + ':' + number)));
            if (digest[0] === 0 && digest[1] < 16) return String(number);
            if (Date.now() - start > 60000) throw new Error('验证超时，请重新验证');
        }
        throw new Error('验证未完成，请重新验证');
    }
    async function run() {
        if (busy) return;
        busy = true; retry.hidden = true;
        try {
            if (!/^[a-f0-9]{64}$/.test(access)) throw new Error('验证入口已失效，请返回商户重新发起支付');
            if (!window.crypto || !crypto.subtle || !window.TextEncoder || !window.fetch) {
                throw new Error('当前浏览器无法完成安全验证，请更新浏览器后重试');
            }
            message.textContent = '正在检查浏览器，请保持页面打开…';
            var init = await request({ action: 'init' });
            if (init.resume_url) { resume(init.resume_url); return; }
            var answer = await work(init.challenge, init.work_prefix);
            var result = await request({ action: 'finish', challenge: init.challenge, answer: answer });
            message.textContent = '验证通过，正在打开支付页面…';
            resume(result.resume_url);
        } catch (error) {
            message.textContent = error.message || '安全验证失败，请稍后重试';
            retry.hidden = false;
        } finally { busy = false; }
    }
    retry.addEventListener('click', run);
    run();
}());
