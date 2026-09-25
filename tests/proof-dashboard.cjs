const {test}=require('node:test'),assert=require('node:assert/strict');
test('HTTP dashboard serves only approved read-only routes (isolated fixture)',async(t)=>{
 const fs=require('node:fs'),path=require('node:path'),os=require('node:os');
 const dir=fs.mkdtempSync(path.join(os.tmpdir(),'yuz-dashboard-test-'));
 const report=path.join(dir,'report.json');
 fs.writeFileSync(report,JSON.stringify({created_at:'2026-09-16T00:00:00Z',commit:'fixture',dirty:true,verdict:'PARTIAL',
  results:Array.from({length:11},(_,i)=>({id:String(i),title:'Fixture',status:'NOT_TESTED',kind:'test-fixture',command:'private',log:'/home/ubuntu/private'})),
  requirements:Array.from({length:31},(_,i)=>({id:String(i),scenario_ids:[]}))}));
 const server=require('../tools/proof-dashboard.cjs').createServer(report,['127.0.0.1']);
 await new Promise(r=>server.listen(0,'127.0.0.1',r));
 t.after(()=>new Promise(r=>server.close(r)));
 const base='http://127.0.0.1:'+server.address().port;
 for(const url of ['/','/app.js','/style.css','/health','/api/report'])assert.equal((await fetch(base+url)).status,200,url);
 for(const url of ['/report.json','/TRA-WP-01.log','/.git/config','/%2e%2e/report.json','/requirements.json'])assert.equal((await fetch(base+url)).status,404,url);
 assert.equal((await fetch(base+'/api/report',{method:'POST'})).status,405);
 // Fetch rewrites Host in this runtime; use raw HTTP to test the actual header.
 const rejected=await new Promise((resolve,reject)=>{require('node:http').get(base+'/',{headers:{Host:'attacker.invalid'}},r=>{r.resume();resolve(r.statusCode);}).on('error',reject);});
 assert.equal(rejected,403);
 const r=await fetch(base+'/api/report');assert.equal(r.headers.get('cache-control'),'no-store');
 const data=await r.json();assert.equal(data.results.length,11);assert.equal(data.requirements.length,31);
 assert.ok(data.results.every(r=>!r.log&&!r.command));
 assert.ok(!JSON.stringify(data).includes('/home/ubuntu'));
});
