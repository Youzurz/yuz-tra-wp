'use strict';
const fs=require('node:fs'),crypto=require('node:crypto'),assert=require('node:assert/strict');
function observe(trace,bytes){
  assert.match(trace.version||'',/^\d+\.\d+\.\d+$/);
  assert.match(trace.source_commit||'',/^[a-f0-9]{40}$/);
  assert.match(trace.artifact_sha256||'',/^[a-f0-9]{64}$/);
  const actual=crypto.createHash('sha256').update(bytes).digest('hex');
  return {integration:'yuz-release',schema_version:1,timestamp:new Date().toISOString(),
    operation:'verify-artifact',plugin:'yuz-tra',version:trace.version,commit:trace.source_commit,
    artifact_sha256:actual,expected_sha256:trace.artifact_sha256,run_id:trace.run_id||null,
    result:actual!==trace.artifact_sha256?'mismatch':trace.verified_ci===true?'verified':'unverified'};
}
if(require.main===module){
  const event=observe(JSON.parse(fs.readFileSync(process.argv[2])),fs.readFileSync(process.argv[3]));
  console.log(JSON.stringify(event));
  if(event.result!=='verified')process.exitCode=1;
}
module.exports={observe};
