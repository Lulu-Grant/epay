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
    function actionText(value) {
        return ({ switch: '开关变更', activation_scope: '防御生效范围', payment_denied: '支付验证拦截',
            reconcile_waiting: '等待付款结果', reconcile_failure: '对账异常', closure_review: '关单待核对',
            local_hold: '保留付款名额', quota_violation: '额度异常待核对', legacy_review: '旧单待核对',
            legacy_retire: '旧单阻断', legacy_local_block: '旧单本地阻断', legacy_closed: '旧单关单确认' })[value] || value;
    }
    function reasonText(value) {
        var labels = {
            enabled: '已开启', disabled: '已关闭', new_orders_only_legacy_callbacks_preserved: '保护启用后的新订单，旧单真实到账继续处理',
            upstream_trade_not_created: '上游暂未查到交易，继续保留名额并降低查询频率', awaiting_buyer_payment: '等待买家付款，名额暂时保留',
            closed_without_unpaid_close_ack: '查到关闭状态，但缺少未支付关单证明，需核对', issued_link_without_close_keep_capacity: '上游无法关单，已发链接结果待确认，名额继续保留',
            payment_reconciliation_error: '历史对账异常（旧版未细分原因）', reconcile_adapter_error: '通道初始化异常，等待重试',
            reconcile_query_error: '支付结果查询异常，等待重试', reconcile_close_error: '关单请求异常，名额继续保留',
            reconcile_confirm_error: '已查到付款，财务处理需要重试', reconcile_accounting_error: '付款计数处理异常，等待重试',
            DEFENSE_BROWSER_REQUIRED: '需要完成浏览器验证', DEFENSE_VERIFY_FAILED: '验证已失效或请求未通过校验，请重新验证',
            DEFENSE_IP_BLOCKED: '付款 IP 处于投诉封禁期', DEFENSE_DAILY_LIMIT: '该付款 IP 今天已成功支付 5 单',
            DEFENSE_PENDING_LIMIT: '付款结果待确认的订单已占满可用名额', DEFENSE_CHANNEL_UNSUPPORTED: '当前通道或收款账户不符合防御要求',
            DEFENSE_TEMP_UNAVAILABLE: '安全校验或数据访问暂不可用，等待重试', DEFENSE_VERSION_CONFLICT: '防御状态已变化，请重新验证',
            DEFENSE_QUERY_MISMATCH: '查单结果与本地订单不符，保留名额等待核对', DEFENSE_CLOSE_UNCONFIRMED: '未取得可信关单确认，名额继续保留',
            DEFENSE_LEGACY_ORDER: '旧未纳管订单不能再次发放付款信息，请重新下单', DEFENSE_ORDER_RETIRED: '付款资格已关闭，请重新下单'
        };
        var text = String(value || '');
        var parts = text.split('|');
        if (parts.length === 3 && (parts[0] === 'alipay_query_failed' || parts[0] === 'alipay_close_failed')) {
            return (parts[0] === 'alipay_query_failed' ? '支付宝查单失败' : '支付宝关单失败') + '（' + parts[1] + ' / ' + parts[2] + '），等待重试';
        }
        return labels[text] || text;
    }
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
                '防御范围：启用后的新订单；启用时间：' + (time(info.scope && info.scope.enabled_at) || '尚未启用'),
                '历史核对参考（不阻止启用）：待支付候选 ' + info.legacy.issued_unpaid + '；当日未纳管成功记录 ' + info.legacy.today_success,
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
            [time(event.created_at), actionText(event.action), reasonText(event.reason), event.actor].forEach(function (text) { textCell(row, text); });
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
            if (!window.confirm(enabled ? '开启后对新订单验证浏览器、限制每日成功 5 单并对新投诉封禁 IP 48 小时；MAPI 返回验证收银台链接。旧未纳管订单须重新下单，历史付款不追补额度，已发出的上游链接无法撤销，真实到账仍正常处理。已纳管订单的计数和占位保留；无法上游关单时，结果不明的名额跨日也不释放。继续？' : '关闭后保留计数和投诉记录，已有付款仍正常确认。继续？')) return;
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
