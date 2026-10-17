(function (window, $) {
    'use strict';
    var states = {};
    function state(selector) {
        if (!states[selector]) states[selector] = { sequence: 0, fresh: false, request: null };
        return states[selector];
    }
    function note(selector) {
        var s = state(selector);
        if (!s.note) {
            var table = $(selector);
            s.note = $('<div class="list-read-status" role="status" aria-live="polite"><span></span> </div>');
            $('<button type="button" class="btn btn-default btn-sm">刷新</button>').on('click', function () {
                window.ListReadUI.refresh(selector);
            }).appendTo(s.note);
            var wrapper = table.closest('.bootstrap-table');
            s.note.insertBefore(wrapper.length ? wrapper : table);
        }
        return s.note;
    }
    function display(selector, text, failed) {
        var target = note(selector);
        target.toggleClass('text-danger', !!failed).find('span').text(text);
        target.find('button').text(failed ? '重试' : '刷新');
    }
    function timeText(value) {
        var time = new Date(value);
        return isNaN(time.getTime()) ? '刚刚' : time.toLocaleTimeString('zh-CN', { hour12: false });
    }
    function pad(n, w) {
        var s = String(n);
        while (s.length < w) s = '0' + s;
        return s;
    }
    function requestMeta(date) {
        var d = date || new Date();
        var stamp = pad(d.getFullYear(), 4) + pad(d.getMonth() + 1, 2) + pad(d.getDate(), 2)
            + pad(d.getHours(), 2) + pad(d.getMinutes(), 2) + pad(d.getSeconds(), 2)
            + pad(d.getMilliseconds(), 3);
        var rand = Math.floor(Math.random() * 0x10000).toString(16);
        while (rand.length < 4) rand = '0' + rand;
        return {
            id: stamp + rand,
            timeText: pad(d.getFullYear(), 4) + '-' + pad(d.getMonth() + 1, 2) + '-' + pad(d.getDate(), 2)
                + ' ' + pad(d.getHours(), 2) + ':' + pad(d.getMinutes(), 2) + ':' + pad(d.getSeconds(), 2),
            started: d.getTime()
        };
    }
    function timingTail(elapsedMs, meta) {
        var elapsed = elapsedMs > 0 ? elapsedMs : 0;
        return '耗时 ' + (elapsed / 1000).toFixed(1) + ' 秒，请求号 ' + meta.id + '，时间 ' + meta.timeText;
    }
    function failureText(kind, elapsedMs, meta, httpStatus) {
        var lead = kind === 'timeout' ? '列表查询超时，请重试。' : '列表查询失败，请重试。';
        if (kind !== 'timeout' && httpStatus) lead = '列表查询失败（HTTP ' + httpStatus + '），请重试。';
        return lead + timingTail(elapsedMs, meta);
    }
    window.ListReadUI = {
        timeText: timeText,
        requestMeta: requestMeta,
        failureText: failureText,
        summaryText: function (meta) { return meta ? '统计于 ' + timeText(meta.as_of) : ''; },
        refresh: function (selector, options) {
            state(selector).fresh = true;
            $(selector).bootstrapTable('refresh', options || {});
            $(selector).trigger('listread:refresh');
            return false;
        },
        ajax: function (selector) {
            return function (params) {
                var s = state(selector);
                var sequence = ++s.sequence;
                if (s.request) s.request.abort();
                var data = typeof params.data === 'string' ? params.data : $.extend({}, params.data);
                if (s.fresh) {
                    if (typeof data === 'string') data += '&fresh=1';
                    else data.fresh = 1;
                }
                s.fresh = false;
                var req = requestMeta(new Date());
                var url = params.url || '';
                if (url.indexOf('rid=') < 0) {
                    url += (url.indexOf('?') >= 0 ? '&' : '?') + 'rid=' + encodeURIComponent(req.id);
                }
                display(selector, '正在查询列表…', false);
                s.request = $.ajax($.extend({}, params, {
                    url: url,
                    headers: { 'X-Request-Id': req.id },
                    data: data,
                    timeout: 15000,
                    success: function (result) {
                        if (sequence !== s.sequence) return;
                        if (!result || result.code != null && result.code !== 0 || !Array.isArray(result.rows)) {
                            var lead = result && result.msg || '列表查询失败，请重试。';
                            if (!/[。！？]$/.test(lead)) lead += '。';
                            display(selector, lead + timingTail(Date.now() - req.started, req), true);
                            params.error({ status: 200, responseJSON: result });
                            return;
                        }
                        var meta = result.meta;
                        if (meta && meta.limit > 0) {
                            var table = $(selector).data('bootstrap.table');
                            var page = Math.floor(meta.offset / meta.limit) + 1;
                            if (table && table.options.pageNumber !== page) {
                                table.options.pageNumber = page;
                                var url = new URL(window.location.href);
                                url.searchParams.set('pageNumber', page);
                                history.replaceState({}, '', url.toString());
                            }
                        }
                        display(selector, meta ? '列表实时查询；总数统计于 ' + timeText(meta.total_as_of) : '列表已更新', false);
                        params.success(result);
                    },
                    error: function (xhr, status) {
                        if (sequence !== s.sequence || status === 'abort') return;
                        var httpStatus = xhr && xhr.status ? xhr.status : 0;
                        display(selector, failureText(status === 'timeout' ? 'timeout' : 'error', Date.now() - req.started, req, status === 'timeout' ? 0 : httpStatus), true);
                        params.error(xhr);
                    }
                }));
            };
        }
    };
    // These wrappers only exist on pages which explicitly opt in to this script.
    var searchSubmit = window.searchSubmit;
    if (searchSubmit) window.searchSubmit = function (fresh) {
        if (fresh === true) state('#listTable').fresh = true;
        return searchSubmit.apply(this, arguments);
    };
    var searchClear = window.searchClear;
    if (searchClear) window.searchClear = function () {
        state('#listTable').fresh = true;
        return searchClear.apply(this, arguments);
    };
}(window, jQuery));
