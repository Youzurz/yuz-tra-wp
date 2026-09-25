'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const {authorize,existingMatches}=require('../tools/publish-release.cjs');
const {validateReviewed}=require('../tools/select-reviewed-artifact.cjs');
const hash='a'.repeat(64),commit='b'.repeat(40);
const env={GITHUB_SHA:commit,GITHUB_RUN_ID:'1',GITHUB_ACTIONS:'true',GITHUB_EVENT_NAME:'workflow_dispatch',GITHUB_REF:'refs/heads/main'};
const trace={artifact_sha256:hash,source_commit:commit,run_id:'1',verified_ci:true};
test('exact manually approved CI artifact is accepted',()=>authorize(trace,hash,hash,env));
for(const [name,delta]of [['wrong commit',{GITHUB_SHA:'c'.repeat(40)}],['untrusted branch',{GITHUB_REF:'refs/heads/other'}],['push not approval',{GITHUB_EVENT_NAME:'push'}],['wrong run',{GITHUB_RUN_ID:'2'}]]){
  test(name+' blocks publication',()=>assert.throws(()=>authorize(trace,hash,hash,{...env,...delta})));
}
test('missing checksum blocks publication',()=>assert.throws(()=>authorize(trace,'',hash,env)));
test('local build cannot publish',()=>assert.throws(()=>authorize({...trace,verified_ci:false},hash,hash,env)));
test('existing different commit is an error, not green success',()=>assert.throws(()=>existingMatches(commit,'c'.repeat(40),hash,hash)));
test('existing different bytes block overwrite',()=>assert.throws(()=>existingMatches(commit,commit,hash,'d'.repeat(64))));
test('same existing release is idempotent',()=>existingMatches(commit,commit,hash,hash));
test('reviewed archive and code cannot silently diverge',()=>{
  const bytes=Buffer.from('fixture'),files={'plugin.php':hash};
  const digest=x=>crypto.createHash('sha256').update(x).digest('hex');
  const policy={sha256:digest(bytes),source_files_sha256:digest(JSON.stringify(files))};
  validateReviewed(bytes,policy,files);
  assert.throws(()=>validateReviewed(Buffer.from('modified'),policy,files));
  assert.throws(()=>validateReviewed(bytes,policy,{'plugin.php':'changed'}));
});
