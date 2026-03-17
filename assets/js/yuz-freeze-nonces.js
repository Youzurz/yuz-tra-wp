var console = window.__YUZ_RELEASE_CONSOLE__ || {log:function(){},debug:function(){},info:function(){},warn:function(){},error:function(){}}; var yuz_release_console = console;

(function freezeYuzNonces(globals){
  function tryFreeze(obj){
    try{
      if (obj && obj.nonces && Object.isExtensible(obj.nonces)) {
        Object.freeze(obj.nonces);
      }
    }catch(e){}
  }

  function sweep(){
    var pending = 0;
    for (var i=0;i<globals.length;i++){
      var g = globals[i];
      var ref = (typeof window[g] !== 'undefined') ? window[g] : null;
      if (ref && ref.nonces && !Object.isFrozen(ref.nonces)){
        tryFreeze(ref);
      }
      if (ref && ref.nonces && !Object.isFrozen(ref.nonces)) {
        pending++;
      }
    }
    return pending;
  }

  // 1er passage immédiat
  sweep();
  // Repassages courts jusqu’à ce que tout soit figé (scripts chargés après)
  var iv = setInterval(function(){
    if (sweep() === 0) { clearInterval(iv); }
  }, 50);
})(['yuzGS','yuzTS','yuzTE','yuzAI','yuzSW','yuzAB','yuzAT','yuzGB']); // ajoute ici d’autres globals si besoin

