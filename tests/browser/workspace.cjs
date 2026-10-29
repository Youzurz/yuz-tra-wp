// Isolated layout regression using real editor assets, NOT a provider demo.
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const path=require('node:path');
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||chromium.executablePath(),args:['--no-sandbox']});
 try {
  const page=await browser.newPage({viewport:{width:1440,height:1000}});
  let calls=0;
  await page.route('**/*',route=>{
   if(route.request().method()==='POST'){calls++;return route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data:{rows:[{id:1,domain:'fixture',source_lang:'en',original:'Example',forms:['Exemple'],status:2}],domains:['fixture'],total:1,per_page:30}})});}
   return route.fulfill({contentType:'text/html',body:'<div id="anchor"></div><div id="yuz-editor-container"><div class="yuz-modal" style="position:fixed;left:40%;top:30%;width:505px;z-index:2147483647;background:white"><button id="strings" data-yuz-open-strings>Strings</button><textarea id="visual-draft">Visual draft</textarea></div></div>'});
  });
  await page.goto('https://workspace.example/editor');
  await page.evaluate(()=>{window.yuzStrings={nonce:'fixture',userId:17,ajax_url:'/wp-admin/admin-ajax.php',languages:[{code:'fr_FR',name:'French'}],defaultLang:'fr_FR'};});
  await page.addStyleTag({path:path.resolve(__dirname,'../../release/yuz-tra/assets/css/yuz-string-catalog.css')});
  await page.addScriptTag({path:path.resolve(__dirname,'../../release/yuz-tra/assets/js/yuz-strings-dock.js')});
  await page.locator('#visual-draft').fill('Unsaved visual');
  await page.locator('#strings').click();
  await page.locator('[data-status]').filter({hasText:'Catalogue chargé'}).waitFor();
  const left=page.locator('.yuz-work-visual'),right=page.locator('#yuz-dock');
  let l=await left.boundingBox(),r=await right.boundingBox();
  assert.ok(Math.abs(l.y-r.y)<2);assert.ok(r.x>=l.x+l.width);
  await page.locator('#visual-draft').fill('Still interactive');
  await page.locator('[data-form]').fill('Catalogue draft');
  await page.locator('[data-split]').focus();await page.keyboard.press('ArrowRight');
  assert.ok((await left.boundingBox()).width>l.width);
  const divider=await page.locator('[data-split]').boundingBox();
  await page.mouse.move(divider.x+6,divider.y+60);await page.mouse.down();await page.mouse.move(divider.x+70,divider.y+60);await page.mouse.up();
  assert.ok((await left.boundingBox()).width>l.width+60);
  const originalHeight=(await page.locator('#yuz-workspace').boundingBox()).height;
  await page.locator('[data-resize]').focus();await page.keyboard.press('ArrowDown');
  assert.ok((await page.locator('#yuz-workspace').boundingBox()).height>originalHeight);
  const stored=await page.evaluate(()=>JSON.parse(localStorage.getItem('yuz-workspace-v1:17')));
  assert.ok(stored.split>440 && stored.height>850);
  // Native focus trap includes BOTH editors; each remains keyboard reachable.
  await page.locator('#visual-draft').focus();await page.keyboard.press('Tab');
  assert.ok(await page.locator('#yuz-workspace').evaluate(e=>e.contains(document.activeElement)));
  await page.keyboard.press('Escape');
  assert.equal(await page.locator(':modal').count(),0);
  assert.equal(await page.locator('#yuz-editor-container').evaluate(e=>e.parentNode===document.body),true);
  assert.equal(await page.locator('#visual-draft').inputValue(),'Still interactive');
  await page.locator('#strings').click();assert.equal(await page.locator('[data-form]').inputValue(),'Catalogue draft');assert.equal(calls,1);
  await page.setViewportSize({width:390,height:844});
  await page.waitForTimeout(100);
  l=await left.boundingBox();r=await right.boundingBox();assert.ok(r.y>=l.y+l.height,'Phone stacks editors');
  assert.ok((await page.locator('#yuz-workspace').boundingBox()).width<=390);
  assert.equal(await page.locator('#yuz-workspace').evaluate(e=>e.scrollWidth<=e.clientWidth),true);
  await page.setViewportSize({width:1440,height:1000});
  await page.locator('[data-reset-layout]').click();
  assert.equal(await page.evaluate(()=>JSON.parse(localStorage.getItem('yuz-workspace-v1:17')).split),440);
  console.log('PASS workspace: top alignment, mouse/keyboard resizing, local preferences, both editors clickable, draft retention, focus, mobile stacking and reset');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
