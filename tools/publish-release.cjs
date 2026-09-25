'use strict';
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
function authorize(trace,expected,digest,env){
  assert.match(expected||'',/^[a-f0-9]{64}$/,'Explicit SHA-256 approval required');
  assert.equal(digest,expected,'Approved SHA differs from artifact');
  assert.equal(trace.artifact_sha256,digest);
  assert.equal(trace.verified_ci,true,'Local build is not a verified CI run');
  assert.equal(trace.source_commit,env.GITHUB_SHA,'Commit mismatch');
  assert.equal(String(trace.run_id),env.GITHUB_RUN_ID,'Run mismatch');
  assert.equal(env.GITHUB_ACTIONS,'true');
  assert.equal(env.GITHUB_EVENT_NAME,'workflow_dispatch');
  assert.equal(env.GITHUB_REF,'refs/heads/main');
}
function existingMatches(tagCommit,expectedCommit,digest,expectedDigest){
  assert.equal(tagCommit,expectedCommit,'Existing release belongs to another commit');
  assert.equal(digest,expectedDigest,'Existing release bytes differ; never overwrite');
}
function protectedRelease(environment,rules){
  assert.equal(environment.deployment_branch_policy?.protected_branches,true,'Release must require a protected branch');
  assert.ok(environment.protection_rules?.some(r=>r.type==='required_reviewers'&&r.reviewers?.length>0),'Release environment has no required reviewer');
  for(const type of ['pull_request','deletion','non_fast_forward'])assert.ok(rules.some(r=>r.type===type),'Missing branch rule: '+type);
  assert.ok(rules.some(r=>r.type==='required_status_checks'&&r.parameters?.strict_required_status_checks_policy===true&&r.parameters.required_status_checks?.some(c=>c.context==='Release gate'&&c.integration_id===15368)),'Mandatory strict GitHub Actions Release gate absent');
}
function main(){
  const version=JSON.parse(fs.readFileSync(path.join(root,'version.json'))).version;
  const tag='v'+version,repo=process.env.GITHUB_REPOSITORY;
  assert.equal(repo,'Youzurz/yuz-tra-wp','Unexpected publication repository');
  const zip=path.join(root,'dist',`yuz-tra-${version}.zip`);
  const hash=crypto.createHash('sha256').update(fs.readFileSync(zip)).digest('hex');
  const trace=JSON.parse(fs.readFileSync(path.join(root,'dist/release-trace.json')));
  authorize(trace,process.env.EXPECTED_SHA256,hash,process.env);
  assert.equal(trace.version,version);
  cp.execFileSync(process.execPath,[path.join(root,'tools/release-integrity.cjs'),'--verify-archive',zip,zip.replace(/\.zip$/,'.manifest.json')],{stdio:'inherit'});
  const gh=(args)=>cp.execFileSync('gh',args,{encoding:'utf8'});
  protectedRelease(JSON.parse(gh(['api',`repos/${repo}/environments/release`])),JSON.parse(gh(['api',`repos/${repo}/rules/branches/main`])));
  if(process.argv.includes('--check')){console.log('PASS explicit artifact/commit/run authorization and remote protections');return;}
  const result=cp.spawnSync('gh',['api',`repos/${repo}/releases/tags/${tag}`],{encoding:'utf8'});
  if(result.error)throw result.error;
  if(result.status===0){
    const release=JSON.parse(result.stdout);
    const asset=release.assets.find(a=>a.name===path.basename(zip));
    assert.ok(asset,'Existing release lacks expected ZIP');
    let obj=JSON.parse(gh(['api',`repos/${repo}/git/ref/tags/${tag}`])).object;
    for(let i=0;obj.type==='tag'&&i<5;i++)obj=JSON.parse(gh(['api',`repos/${repo}/git/tags/${obj.sha}`])).object;
    assert.equal(obj.type,'commit');
    // GitHub's server-computed digest is mandatory; absent evidence is not a success.
    existingMatches(obj.sha,process.env.GITHUB_SHA,asset.digest,'sha256:'+hash);
    console.log('PASS existing immutable release has same commit and artifact; no write');return;
  }
  if(!/\(HTTP 404\)/.test(result.stderr||''))throw new Error('Cannot inspect existing release; publication stopped');
  gh(['release','create',tag,zip,zip+'.sha256',zip.replace(/\.zip$/,'.manifest.json'),path.join(root,'dist/release-trace.json'),
    path.join(root,'dist/bundled.cdx.json'),path.join(root,'dist/dependency-findings.json'),
    '--repo',repo,'--target',process.env.GITHUB_SHA,'--title',`YUZ-TRA ${version}`,'--notes-file',path.join(root,'RELEASE-NOTES.md')]);
  console.log('Created release without replacement: '+tag);
}
if(require.main===module)main();
module.exports={authorize,existingMatches,protectedRelease};
