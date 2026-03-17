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
        case 'critical': console.error(out); break;
        case 'warning':  console.warn(out);  break;
        case 'success':  console.log(out); break;
        case 'info':     console.log(out); break;
        default:         console.log(out);
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

