const {test}=require('node:test'),assert=require('node:assert/strict');
const {classify,esc,scenarios}=require('../tools/proof-lab.cjs');
const {collect}=require('../tools/proof-requirements.cjs');
const path=require('node:path');
test('human gate refuses empty evidence and fabricated completeness',()=>{
 const {validate}=require('../tools/check-human-review.cjs');
 const bytes=require('node:fs').readFileSync(path.join(__dirname,'corpus.json'));
 assert.ok(validate(bytes,{}).length>0);
 assert.ok(validate(bytes,{evaluations:[{case_id:'LANG-01',scores:{meaning:4}}]}).length>0);
});
test('document claims never become full compliance from passing section tests',()=>{
 const rows=collect(path.resolve(__dirname,'..'),[{id:'TRA-WP-01',status:'PASS'}]);
 assert.ok(rows.length>20);assert.equal(new Set(rows.map(r=>r.id)).size,rows.length);
 assert.ok(rows.every(r=>r.line>0&&r.source_sha256.length===64&&r.coverage!=='PASS'));
 assert.ok(rows.some(r=>r.coverage==='UNMAPPED'));
 assert.ok(rows.some(r=>r.source_kind==='historique-limites'));
});
test('corpus is synthetic and does not fabricate human approval',()=>{
 const c=require('./corpus.json');assert.equal(c.status,'AWAITING_HUMAN_REVIEW');
 assert.equal(c.cases.length,12);assert.equal(new Set(c.cases.map(x=>x.id)).size,12);
 assert.ok(c.cases.every(x=>x.source_locale&&x.target_locale&&x.text&&x.checks.length&&!x.human_score));
});
test('no false success on launch failure, timeout or signal',()=>{
 for(const r of [{status:null},{status:1},{status:0,error:Error('timeout')},{status:0,signal:'SIGTERM'}])assert.equal(classify(r),'FAIL');
 assert.equal(classify({status:0}),'PASS');
});
test('untrusted content is escaped',()=>assert.equal(esc('<script>"&'), '&lt;script&gt;&quot;&amp;'));
test('scenario ids unique and boundaries explicit',()=>{
 assert.equal(new Set(scenarios.map(s=>s[0])).size,scenarios.length);
 assert.ok(scenarios.every(s=>s[2]&&s[3][0]==='node'));
});
