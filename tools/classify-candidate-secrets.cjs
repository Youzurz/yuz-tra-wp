'use strict';
// Keep raw findings intact. Only two narrowly defined non-secret expressions are adjudicated.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),assert=require('node:assert/strict');
function classify(file,line,verifiedDigest){
 if(file==='assets/vendor/axios.js' && /^\s*allOwnKeys = _ref\d+\.allOwnKeys;\s*$/.test(line))return 'vendor JavaScript property assignment, not a credential';
 if(file==='build-provenance.json' && verifiedDigest && /^\s*"includes\/class-yuz-api-manager\.php": "[a-f0-9]{64}",?\s*$/.test(line))return 'SHA-256 of the packaged PHP file, verified against its bytes';
 return null;
}
if(require.main===module){
 const [stage,report,out]=process.argv.slice(2);assert.ok(stage&&report&&out);
 const findings=JSON.parse(fs.readFileSync(report));
 const php=fs.readFileSync(path.join(stage,'includes/class-yuz-api-manager.php'));
 const digest=crypto.createHash('sha256').update(php).digest('hex');
 const provenance=JSON.parse(fs.readFileSync(path.join(stage,'build-provenance.json')));
 const decisions=findings.map(f=>{
  assert.equal(f.RuleID,'generic-api-key');assert.ok(f.File.startsWith('/scan/'));
  const file=f.File.slice('/scan/'.length);assert.ok(!file.includes('..')&&!path.isAbsolute(file));
  const bytes=fs.readFileSync(path.join(stage,file));
  const line=bytes.toString('utf8').split('\n')[f.StartLine-1]||'';
  const reason=classify(file,line,provenance.files?.['includes/class-yuz-api-manager.php']===digest);
  return {file,line:f.StartLine,rule:f.RuleID,disposition:reason?'FALSE_POSITIVE':'UNRESOLVED',reason,sha256:crypto.createHash('sha256').update(bytes).digest('hex')};
 });
 fs.writeFileSync(out,JSON.stringify({checked_at:new Date().toISOString(),scope:'Exact extracted candidate only. Does not clear historical credentials.',decisions},null,2)+'\n');
 if(decisions.some(d=>d.disposition==='UNRESOLVED'))process.exitCode=1;
 else console.log('PASS '+decisions.length+' non-secret findings justified; raw report preserved');
}
module.exports={classify};
