(function () {
    'use strict';
    var csrf = document.querySelector('meta[name="merchant-defense-csrf"]');
    if (!csrf) return;
    var detail = document.getElementById('merchant-defense-admin');
    var status = document.getElementById('defense-admin-status');
    async function api(action, data) {
        var body = new URLSearchParams(Object.assign({ csrf_token: csrf.content }, data));
        var response = await fetch('./ajax_user.php?act=' + action, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString()
        });
        var result = await response.json();
        if (result.code !== 0) throw new Error(result.msg || '操作失败，请刷新页面');
        return result;
    }
    function textCell(row, text) {
        var cell = document.createElement('td'); cell.textContent = text == null ? '' : String(text);
        row.appendChild(cell); return cell;
    }
    function time(value) { return value ? new Date(Number(value) * 1000).toLocaleString('zh-CN', { timeZone: 'Asia/Shanghai' }) : ''; }
    async function refresh() {
        if (!detail) {
            if (window.jQuery) window.jQuery('#listTable').bootstrapTable('refresh', { silent: true });
            return;
        }
        var result = await api('getMerchantDefense', { uid: detail.dataset.uid });
        var enabled = Number(result.merchant.defense_mode) === 1;
        status.textContent = enabled ? '防御模式已开启' : '防御模式已关闭';
        var toggle = document.getElementById('defense-detail-toggle');
        toggle.dataset.uid = detail.dataset.uid; toggle.dataset.target = enabled ? '0' : '1';
        toggle.dataset.revision = result.merchant.defense_revision;
        toggle.textContent = enabled ? '关闭防御模式' : '开启防御模式'; toggle.disabled = false;
        var blockers = document.getElementById('defense-blockers'); blockers.replaceChildren();
        (result.blockers.length ? result.blockers : ['当前启用条件检查通过']).forEach(function (text) {
            var item = document.createElement('li'); item.textContent = text; blockers.appendChild(item);
        });
        var readiness = document.getElementById('defense-readiness');
        if (readiness && result.readiness) {
            readiness.replaceChildren();
            var info = result.readiness;
            var lines = ['付款 IP 采集：' + (info.network.payer_capture ? '已配置' : '未就绪'),
                '防御网络：' + (info.network.ready ? '已核验' : '未就绪') + '，模式：' + (info.network.mode || '未设置'),
                '未纳管待支付候选：' + info.legacy.issued_unpaid + '；当日未纳管成功记录：' + info.legacy.today_success,
                '旧订单接管工具：' + (info.legacy.takeover_installed ? '已安装' : '尚未迁移')];
            info.channels.forEach(function (channel) { lines.push('通道 ' + channel.id + '：' + channel.reason); });
            lines.forEach(function (text) { var item = document.createElement('li'); item.textContent = text; readiness.appendChild(item); });
        }
        var bans = document.getElementById('defense-bans'); bans.replaceChildren();
        result.bans.forEach(function (ban) {
            var row = document.createElement('tr'); textCell(row, ban.ip); textCell(row, ban.trade_no);
            textCell(row, time(ban.observed_at)); textCell(row, time(ban.expires_at));
            var released = !!ban.released_at;
            var expired = Number(ban.expires_at) * 1000 <= Date.now();
            var cell = textCell(row, released ? '已人工解除' : expired ? '已到期' : '封禁中');
            if (!released && !expired) {
                var button = document.createElement('button'); button.type = 'button';
                button.className = 'btn btn-xs btn-warning merchant-defense-release';
                button.dataset.event = ban.event_key; button.textContent = '解除此事件'; cell.appendChild(button);
            }
            bans.appendChild(row);
        });
        var audit = document.getElementById('defense-audit'); audit.replaceChildren();
        result.audit.forEach(function (event) {
            var row = document.createElement('tr');
            [time(event.created_at), event.action, event.reason, event.actor].forEach(function (text) { textCell(row, text); });
            audit.appendChild(row);
        });
    }
    document.addEventListener('click', async function (event) {
        var toggle = event.target.closest('.merchant-defense-toggle');
        var release = event.target.closest('.merchant-defense-release');
        var button = toggle || release;
        if (!button || button.disabled) return;
        var data, action;
        if (toggle) {
            var enabled = toggle.dataset.target === '1';
            if (!window.confirm(enabled ? '开启后将验证浏览器、限制每日成功 5 单并对新投诉封禁 IP 48 小时；MAPI 将返回验证收银台链接。继续？' : '关闭后保留计数和投诉记录，已有付款仍正常确认。继续？')) return;
            action = 'setMerchantDefense'; data = { uid: toggle.dataset.uid, target: toggle.dataset.target, revision: toggle.dataset.revision };
        } else {
            var reason = window.prompt('请输入人工解除此投诉封禁的原因（其他有效封禁不会一并解除）');
            if (!reason || !reason.trim()) return;
            action = 'releaseMerchantDefense'; data = { uid: detail.dataset.uid, event_key: release.dataset.event, reason: reason.trim() };
        }
        button.disabled = true;
        try { await api(action, data); await refresh(); }
        catch (error) {
            if (status) status.textContent = error.message; else window.alert(error.message);
            button.disabled = false;
        }
    });
    if (detail) refresh().catch(function (error) { status.textContent = error.message; });
}());
