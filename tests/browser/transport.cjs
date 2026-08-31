// Transport regression fixtures ONLY. This is not the live translation demo.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
(async () => {
  const browser = await chromium.launch({executablePath:process.env.CHROMIUM_PATH||chromium.executablePath(),args:['--no-sandbox']});
  try {
    for (const test of [
      {name:'private origin with WP subdirectory',code:200,body:JSON.stringify({success:true,data:{rows:[],domains:[],total:0,per_page:30}}),expect:'Catalogue chargé'},
      {name:'proxy HTML 404',code:404,body:'<!doctype html><h1>Unavailable</h1>',expect:'Endpoint AJAX introuvable'},
      {name:'expired session HTML 403',code:403,body:'<!doctype html><h1>Denied</h1>',expect:'session expirée'},
      {name:'HTML 200 is not success',code:200,body:'<!doctype html><h1>Login</h1>',expect:'JSON valide'},
      {name:'JSON false is not success',code:200,body:'{"success":false,"data":{"message":"provider_failed"}}',expect:'provider_failed'},
      {name:'missing rows is not success',code:200,body:'{"success":true,"data":{}}',expect:'catalogue incomplète'},
      {name:'cross-origin nonce leak blocked',endpoint:'https://public.example/wp-admin/admin-ajax.php',expect:'autre domaine'}
    ]) {
      const page=await browser.newPage(); let calls=0; const errors=[];
      page.on('pageerror',e=>errors.push(e.message));
      await page.route('**/*', async route=>{
        const req=route.request();
        if(req.method()==='POST') {
          calls++; assert.equal(req.url(),'https://private.example/cms/wp-admin/admin-ajax.php');
          await route.fulfill({status:test.code,contentType:test.body.startsWith('<')?'text/html':'application/json',body:test.body});
        } else await route.fulfill({contentType:'text/html',body:'<div class="yuz-strings-tab"></div>'});
      });
      await page.goto('https://private.example/cms/wp-admin/admin.php?page=yuz-string-translation-editor');
      await page.evaluate(endpoint=>{window.yuzStrings={nonce:'test-only',ajax_url:endpoint,defaultLang:'fr_FR',languages:[{code:'fr_FR',name:'French'}]};},test.endpoint||'/cms/wp-admin/admin-ajax.php');
      await page.addScriptTag({path:path.resolve(__dirname,'../../release/yuz-tra/assets/js/yuz-strings-dock.js')});
      await page.locator('[data-status]').filter({hasText:test.expect}).waitFor({timeout:5000});
      assert.equal(calls,test.endpoint?0:1); assert.deepEqual(errors,[]);
      console.log('PASS '+test.name); await page.close();
    }
  } finally {await browser.close();}
})().catch(e=>{console.error(e.message);process.exitCode=1;});
