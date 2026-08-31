// Deterministic concurrency tests of the actual visual editor methods.
const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const src=fs.readFileSync(__dirname+'/../release/yuz-tra/assets/js/widgets/yuz-translation-editor.js','utf8');
function method(start,end,name,globals){const a=src.indexOf(start),b=src.indexOf(end,a);assert.ok(a>=0&&b>a);return vm.runInNewContext(`({${src.slice(a,b)}}).${name}`,globals);}
for(const change of ['edit','selection','language','none']) test('late auto response: '+change,async()=>{
  let resolve,applied=0,saves=0;
  const item={id:1,original:'Order',translations:{fr:{translated:'',edited:''}}};
  const globals={stripCssJsNoise:s=>s,window:{crypto:{randomUUID:()=> 'test'}},toast(){},log(){},console,tmTranslateAjax:()=>new Promise(r=>{resolve=r;})};
  const translate=method('        async autoTranslateCurrent(', '        autoTranslateIfAppropriate(', 'autoTranslateCurrent',globals);
  const app={index:0,current:item,sourceLanguage:'en',lang:'fr',getCurrentLanguage(){return this.lang;},ensureTranslationEntry(){applied++;},dirtySet:new Set(),throttledSaveOne(){saves++;}};
  const pending=translate.call(app);
  if(change==='edit')item.translations.fr.edited='Saisie humaine récente';
  if(change==='selection')app.current={id:2};
  if(change==='language')app.lang='de';
  resolve({translated_text:'Commande'});
  assert.equal(await pending,change==='none');
  assert.equal(applied,change==='none'?1:0);assert.equal(saves,change==='none'?1:0);
});
test('late save acknowledgement keeps newer edited text and dirty marker',async()=>{
  let resolve,options;
  const item={id:1,translations:{fr:{translated:'Ancien',edited:'Premier brouillon',status:'1'}}};
  const globals={pickTranslationId:()=>9,diagProbe(){},ACTION:{TE_SAVE:'save'},ajaxPromise:()=>new Promise(r=>{resolve=r;}),toInt:Number,canonicalOrigin:x=>x,document:{dispatchEvent(){}},CustomEvent:class{}};
  const save=method('        saveTranslationForItem(', '        publishTarget(', 'saveTranslationForItem',globals);
  const app={dirtySet:new Set([1]),buildSavePayload:()=>({translated_text:'Premier brouillon',status:1,origin:'manual'}),ensureTranslationEntry(it,lang,patch,opts){options=opts;Object.assign(it.translations[lang],patch);if(opts.clearEdited)delete it.translations[lang].edited;}};
  const pending=save.call(app,item,'fr','Premier brouillon');
  item.translations.fr.edited='Second brouillon';
  resolve({success:true,data:{id:9}});await pending;
  assert.equal(options.clearEdited,false);assert.equal(item.translations.fr.edited,'Second brouillon');assert.ok(app.dirtySet.has(1));
});
