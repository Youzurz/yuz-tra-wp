const {chromium}=require('playwright'),assert=require('node:assert/strict');
(async()=>{const b=await chromium.launch({args:['--no-sandbox']});try{
 const page=await b.newPage({viewport:{width:1440,height:1000}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto('http://127.0.0.1:8767/');await page.getByText('Rapport chargé · Couverture partielle').waitFor();
 assert.equal(await page.locator('.scenario').count(),11);assert.equal(await page.locator('details').count(),31);
 await page.locator('.improvement').first().waitFor();
 assert.equal(await page.locator('.improvement').count(),7);
 assert.ok((await page.locator('#improvements').innerText()).includes('699 avis'));
 assert.ok((await page.locator('#improvements').innerText()).includes('OBS-01'));
 assert.ok((await page.locator('#improvements').innerText()).includes('Ni approuvé ni publié'));
 await page.locator('#filter').selectOption('PASS');assert.equal(await page.locator('.scenario').count(),8);
 await page.locator('#filter').selectOption('NOT_TESTED');assert.equal(await page.locator('.scenario').count(),3);
 await page.locator('#search').fill('mémoire');assert.ok(await page.locator('details').count()>0);assert.ok(await page.locator('details').count()<31);
 await page.locator('#search').fill('');await page.locator('#filter').selectOption('all');
 await page.locator('#refresh').click();await page.getByText('Rapport chargé · Couverture partielle').waitFor();
 await page.screenshot({path:'/home/ubuntu/.ULD/.DEV/YUZ-TRA/.agent/proof-dashboard/dashboard.png',fullPage:false});
 await page.setViewportSize({width:390,height:844});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
 assert.deepEqual(errors,[]);console.log('PASS HTTP browser: 11 scenarios, 31 requirements, filters, search, refresh, mobile, no JS errors');
}finally{await b.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
