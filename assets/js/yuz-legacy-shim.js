

(function(){
  try {
    if(!window.yuzTraSettings){
      Object.defineProperty(window,'yuzTraSettings',{
        get:function(){
          return window.yuzGS||{};
        },
        set:function(){
        }
      });
    }
  }catch(e){}
})();

