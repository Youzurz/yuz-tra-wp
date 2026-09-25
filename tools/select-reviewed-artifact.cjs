'use strict';
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const sha=b=>crypto.createHash('sha256').update(b).digest('hex');
function validateReviewed(bytes,policy,files){
  assert.equal(sha(bytes),policy.sha256,'Reviewed ZIP was modified');
  assert.equal(sha(JSON.stringify(files)),policy.source_files_sha256,'Plugin source changed: increment version, do not reuse reviewed ZIP');
}
function main(){
  const v=JSON.parse(fs.readFileSync(path.join(root,'version.json'))).version;
  const policy=JSON.parse(fs.readFileSync(path.join(root,'release-inputs/reviewed.json')))[v];
  const files={};
  for(const file of fs.readFileSync(path.join(root,'release-files.txt'),'utf8').trim().split('\n')){
    assert.ok(!file.startsWith('/')&&!file.includes('..'));
    files[file]=sha(fs.readFileSync(path.join(root,file)));
  }
  const target=path.join(root,'dist',`yuz-tra-${v}.zip`);
  if(policy){
    assert.equal(policy.file,`yuz-tra-${v}.zip`);
    const input=path.join(root,'release-inputs',policy.file);
    validateReviewed(fs.readFileSync(input),policy,files);
    cp.execFileSync('git',['merge-base','--is-ancestor',policy.source_commit,'HEAD'],{cwd:root});
    fs.copyFileSync(input,target);
    cp.execFileSync(process.execPath,[path.join(root,'tools/release-integrity.cjs'),'--manifest',target]);
  }
  cp.execFileSync(process.execPath,[path.join(root,'tools/release-integrity.cjs'),'--verify-archive',target,target.replace(/\.zip$/,'.manifest.json')]);
  const hash=sha(fs.readFileSync(target));
  fs.writeFileSync(target+'.sha256',`${hash}  ${path.basename(target)}\n`);
  const commit=cp.execFileSync('git',['rev-parse','HEAD'],{cwd:root,encoding:'utf8'}).trim();
  const ci=process.env.GITHUB_ACTIONS==='true';
  if(ci){assert.equal(commit,process.env.GITHUB_SHA);cp.execFileSync('git',['diff','--exit-code','HEAD','--'],{cwd:root});}
  fs.writeFileSync(path.join(root,'dist/release-trace.json'),JSON.stringify({schema_version:1,version:v,source_commit:commit,source_files_sha256:sha(JSON.stringify(files)),artifact_sha256:hash,reviewed_source_commit:policy?.source_commit||null,verified_ci:ci,run_id:ci?process.env.GITHUB_RUN_ID:null},null,2)+'\n');
  console.log(`PASS selected ${v}: ${hash}; reviewed bytes preserved=${Boolean(policy)}`);
}
if(require.main===module)main();
module.exports={validateReviewed};
