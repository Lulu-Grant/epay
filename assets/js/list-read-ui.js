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
    window.ListReadUI = {
        timeText: timeText,
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
                display(selector, '正在查询列表…', false);
                s.request = $.ajax($.extend({}, params, {
                    data: data,
                    timeout: 15000,
                    success: function (result) {
                        if (sequence !== s.sequence) return;
                        if (!result || result.code != null && result.code !== 0 || !Array.isArray(result.rows)) {
                            display(selector, result && result.msg || '列表查询失败，请重试。', true);
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
                        display(selector, status === 'timeout' ? '列表查询超时，请重试。' : '列表查询失败，请重试。', true);
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
