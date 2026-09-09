// Regression: direct links can load Strings before the asynchronous Vue editor.
const {chromium}=require('playwright'),assert=require('node:assert/strict'),path=require('node:path');
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||chromium.executablePath(),args:['--no-sandbox']});
 try {
  const page=await browser.newPage({viewport:{width:1440,height:1000}});
  await page.route('**/*',r=>r.fulfill(r.request().method()==='POST'?{contentType:'application/json',body:JSON.stringify({success:true,data:{rows:[],domains:[],total:0,per_page:30}})}:{contentType:'text/html',body:'<main>Fixture page</main>'}));
  await page.goto('https://delayed.example/?yuz-panel=strings');
  await page.evaluate(()=>{window.yuzStrings={nonce:'fixture',userId:1,ajax_url:'/wp-admin/admin-ajax.php',languages:[{code:'fr_FR',name:'French'}],defaultLang:'fr_FR'};});
  for(const [method,file]of [['addStyleTag','assets/css/yuz-string-catalog.css'],['addScriptTag','assets/js/yuz-strings-dock.js']])await page[method]({path:path.resolve(__dirname,'../..',file)});
  await page.locator('#yuz-workspace:modal').waitFor();
  await page.evaluate(()=>{
   const root=document.createElement('div');root.id='yuz-editor-container';root.style.display='none';
   root.innerHTML='<div class="yuz-modal"><button id="strings" data-yuz-open-strings>Strings</button><textarea>Kept visual value</textarea></div>';
   document.body.append(root);setTimeout(()=>{root.style.display='block';},50);
  });
  await page.waitForFunction(()=>!!document.querySelector('#yuz-workspace #yuz-editor-container'),{},{timeout:3000});
  assert.equal(await page.locator('#yuz-workspace').evaluate(e=>e.classList.contains('yuz-work-single')),false);
  await page.keyboard.press('Escape');
  assert.equal(await page.locator('#yuz-editor-container').evaluate(e=>e.parentNode===document.body),true);
  assert.equal(await page.evaluate(()=>document.activeElement.id),'strings');
  await page.locator('#strings').click();
  await page.evaluate(()=>{document.querySelector('#yuz-editor-container').remove();document.dispatchEvent(new CustomEvent('yuz:ui:unmount'));});
  assert.equal(await page.locator('#yuz-workspace').evaluate(e=>e.classList.contains('yuz-work-single')),true);
  await page.locator('[data-close]').click();
  assert.equal(await page.locator('#yuz-editor-container').count(),0,'Must not resurrect destroyed visual editor');
  console.log('PASS delayed/hidden visual mount joins direct-link workspace; focus restored; destroyed editor never resurrected');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
