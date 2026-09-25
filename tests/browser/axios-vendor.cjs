'use strict';
const {chromium}=require('playwright'),assert=require('node:assert/strict'),path=require('node:path');
(async()=>{const browser=await chromium.launch({args:['--no-sandbox']});try{
 const page=await browser.newPage();
 await page.route('http://axios.fixture/**',async route=>{
  const url=route.request().url();
  if(url.endsWith('/'))return route.fulfill({contentType:'text/html',body:'<!doctype html><title>Axios fixture</title>'});
  if(url.endsWith('/failure'))return route.fulfill({status:502,contentType:'text/html',body:'<h1>Gateway failure</h1>'});
  if(url.endsWith('/slow'))await new Promise(r=>setTimeout(r,150));
  await route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,text:'Bonjour %s — 日本語',method:route.request().method()})});
 });
 await page.goto('http://axios.fixture/');
 await page.addScriptTag({path:path.resolve(__dirname,'../../assets/vendor/axios.min.js')});
 const result=await page.evaluate(async()=>{
  const client=axios.create({baseURL:'http://axios.fixture',timeout:2000});
  const good=await client.post('/ok',{text:'Hello %s'});
  let failure,timeout;
  try{await client.get('/failure');}catch(e){failure=e.response.status;}
  try{await client.get('/slow',{timeout:20});}catch(e){timeout=e.code;}
  return {version:axios.VERSION,good:good.data,failure,timeout};
 });
 assert.equal(result.version,'1.20.0');assert.equal(result.good.text,'Bonjour %s — 日本語');assert.equal(result.good.method,'POST');
 assert.equal(result.failure,502);assert.equal(result.timeout,'ECONNABORTED');
 console.log('PASS Axios 1.20.0 real browser bundle, mocked HTTP: POST/Unicode/placeholders/502/timeout');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
