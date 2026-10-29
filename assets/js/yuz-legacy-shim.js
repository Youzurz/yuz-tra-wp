(function(){
  try {
    if(!window.yuztraSettings){
      Object.defineProperty(window,'yuztraSettings',{
        get:function(){
          console.warn('[YUZ] Legacy read-only shim: use yuztraGS');
          return window.yuztraGS||{};
        },
        set:function(){
          console.warn('[YUZ] Blocked overwrite of yuztraSettings');
        }
      });
    }
  }catch(e){}
})();

