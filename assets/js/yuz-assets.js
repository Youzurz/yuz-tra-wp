var console = window.__YUZ_RELEASE_CONSOLE__ || {log:function(){},debug:function(){},info:function(){},warn:function(){},error:function(){}}; var yuz_release_console = console;


// assets/js/yuz-assets.js
;(function(window, console){
  window.YUZ_Assets = {
    log_colored: function(level, message, context){
      const prefixes = {
        critical: '🟥 [CRITICAL]',
        warning:  '🟨 [WARNING]',
        success:  '🟩 [SUCCESS]',
        info:     '🟦 [INFO]'
      };
      const prefix = prefixes[level] || '[LOG]';
      let out = `${prefix} ${message}`;
      if (context) out += ' | Context: ' + JSON.stringify(context);
      switch(level){
        case 'critical': yuz_release_console.error(out); break;
        case 'warning':  yuz_release_console.warn(out);  break;
        case 'success':  yuz_release_console.log(out); break;
        case 'info':     yuz_release_console.log(out); break;
        default:         yuz_release_console.log(out);
      }
    },
    ensureContainer: function(containerId) {
      let container = document.getElementById(containerId);
      if (!container) {
        container = document.createElement('div');
        container.id = containerId;
        document.body.appendChild(container);
        this.log_colored('warning', `Container ${containerId} not found, dynamically added`, { timestamp: new Date().toISOString() });
      }
      return container;
    }
  };
})(window, console);

