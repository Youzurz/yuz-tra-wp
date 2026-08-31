(function(){
  try {
    if(!window.yuzTraSettings){
      Object.defineProperty(window,'yuzTraSettings',{
        get:function(){
          console.warn('[YUZ] Legacy read-only shim: use yuzGS');
          return window.yuzGS||{};
        },
        set:function(){
          console.warn('[YUZ] Blocked overwrite of yuzTraSettings');
        }
      });
    }
  }catch(e){}
})();

