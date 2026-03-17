var console = window.__YUZ_RELEASE_CONSOLE__ || {log:function(){},debug:function(){},info:function(){},warn:function(){},error:function(){}}; var yuz_release_console = console;


(function(){
  try {
    if(!window.yuzTraSettings){
      Object.defineProperty(window,'yuzTraSettings',{
        get:function(){
          yuz_release_console.warn('[YUZ] Legacy read-only shim: use yuzGS');
          return window.yuzGS||{};
        },
        set:function(){
          yuz_release_console.warn('[YUZ] Blocked overwrite of yuzTraSettings');
        }
      });
    }
  }catch(e){}
})();

