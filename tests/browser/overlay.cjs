// Isolated stacking/keyboard regression fixture; not a simulated live demo.
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const path=require('node:path');
(async()=>{
  const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||chromium.executablePath(),args:['--no-sandbox']});
  try {
    for(const inline of [false,true]) {
      const page=await browser.newPage({viewport:{width:1280,height:900}});
      let calls=0;
      await page.route('**/*',route=>{
        if(route.request().method()==='POST') {
          calls++;
          return route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data:{rows:[{id:1,domain:'test-only',source_lang:'en',original:'Example',forms:['Exemple'],status:2}],domains:['test-only'],total:1,per_page:30}})});
        }
        return route.fulfill({contentType:'text/html',body:inline?'<div class="yuz-strings-tab"></div>':'<button id="open" data-yuz-open-strings>Strings</button>'});
      });
      await page.goto('https://overlay.example/editor');
      await page.evaluate(()=>{window.yuzStrings={nonce:'fixture-only',ajax_url:'/wp-admin/admin-ajax.php',languages:[{code:'fr_FR',name:'French'}],defaultLang:'fr_FR'};});
      await page.addStyleTag({path:path.resolve(__dirname,'../../release/yuz-tra/assets/css/yuz-string-catalog.css')});
      await page.addScriptTag({path:path.resolve(__dirname,'../../release/yuz-tra/assets/js/yuz-strings-dock.js')});
      if(inline) {
        await page.locator('[data-status]').filter({hasText:'Catalogue chargé'}).waitFor();
        assert.equal(await page.locator('#yuz-dock').evaluate(e=>e.tagName),'SECTION');
        assert.equal(await page.locator(':modal').count(),0);
        console.log('PASS inline catalog stays in its admin page');await page.close();continue;
      }
      // Mount a competing visual editor AFTER the catalog, at maximum z-index.
      await page.evaluate(()=>{
        const editor=document.createElement('div');editor.id='visual';
        editor.style.cssText='position:fixed;inset:0;z-index:2147483647;background:white';
        editor.innerHTML='<button id="strings" data-yuz-open-strings>Strings</button><textarea id="draft">Visual draft</textarea>';
        document.body.append(editor);window.visualShortcuts=0;
        document.addEventListener('keydown',e=>{if(e.key==='Escape'||(e.ctrlKey&&e.key==='s'))window.visualShortcuts++;});
      });
      await page.locator('#draft').fill('Unsaved visual draft');
      await page.locator('#strings').click();
      await page.locator('[data-status]').filter({hasText:'Catalogue chargé'}).waitFor();
      assert.equal(await page.locator('#yuz-workspace').evaluate(e=>e.matches(':modal')),true);
      assert.equal(await page.locator('#yuz-dock').evaluate(e=>{const b=e.getBoundingClientRect();return e.contains(document.elementFromPoint(b.x+80,b.y+80));}),true);
      await page.locator('[data-form]').fill('Saisie catalogue non enregistrée');
      await page.keyboard.press('Control+s');
      for(let i=0;i<20;i++){await page.keyboard.press('Tab');assert.equal(await page.evaluate(()=>document.querySelector('#yuz-dock').contains(document.activeElement)),true);}
      await page.keyboard.press('Escape');
      assert.equal(await page.locator(':modal').count(),0);
      assert.equal(await page.evaluate(()=>document.activeElement.id),'strings');
      assert.equal(await page.locator('#draft').inputValue(),'Unsaved visual draft');
      assert.equal(await page.evaluate(()=>window.visualShortcuts),0);
      await page.locator('#strings').click();
      assert.equal(await page.locator('[data-form]').inputValue(),'Saisie catalogue non enregistrée');
      await page.locator('[data-close]').click();
      assert.equal(await page.locator(':modal').count(),0);
      assert.equal(calls,1,'Reopening must not reload and erase the draft');
      console.log('PASS top layer, actual hit testing, focus trap/return, isolated shortcuts, both drafts preserved, close/reopen');
      await page.close();
    }
  } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
