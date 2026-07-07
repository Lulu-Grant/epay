(function(window){
    if(window.layer) return;
    var layer = {};
    layer.alert = function(message, options, callback){
        window.alert(String(message).replace(/<[^>]+>/g, ''));
        if(typeof options === 'function') options();
        if(typeof callback === 'function') callback();
        return 0;
    };
    layer.msg = function(message){
        if(window.console && console.log) console.log(String(message).replace(/<[^>]+>/g, ''));
        return 0;
    };
    layer.confirm = function(message, options, yes, cancel){
        var ok = window.confirm(String(message).replace(/<[^>]+>/g, ''));
        if(ok && typeof yes === 'function') yes(0);
        if(!ok && typeof cancel === 'function') cancel(0);
        return 0;
    };
    layer.prompt = function(options, callback){
        var title = typeof options === 'object' && options.title ? options.title : '';
        var value = window.prompt(title, '');
        if(value !== null && typeof callback === 'function') callback(value, 0);
        return 0;
    };
    layer.open = function(options){
        if(options && options.content) layer.alert(options.content);
        return 0;
    };
    layer.load = function(){ return 0; };
    layer.close = function(){};
    layer.closeAll = function(){};
    window.layer = layer;
})(window);
