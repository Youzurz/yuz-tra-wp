#!/usr/bin/env node
// Fail closed on version drift; build provenance and detached archive manifest.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),cp=require('node:child_process'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const sha=b=>crypto.createHash('sha256').update(b).digest('hex');
function check(){
 const m=JSON.parse(read('version.json'));
 assert.equal(m.schema_version,1);assert.equal(m.slug,'yuz-tra');
 assert.match(m.version,/^\d+\.\d+\.\d+$/);assert.equal(m.channel,'evaluation');
 assert.equal(m.repository,'https://github.com/Youzurz/yuz-tra-wp');
 const header=read('yuz-tra.php'),readme=read('readme.txt');
 for(const [field,value]of [['Version',m.version],['Requires at least',m.requires_wordpress],['Requires PHP',m.requires_php],['Plugin URI',m.repository],['Update URI',m.repository]])
  assert.equal(header.match(new RegExp('^ \\* '+field+': (.+)$','m'))?.[1],value,'Header '+field);
 assert.equal(readme.match(/^Stable tag: (.+)$/m)?.[1],m.version,'readme Stable tag');
 assert.equal(readme.match(/^Requires at least: (.+)$/m)?.[1],m.requires_wordpress);
 assert.equal(readme.match(/^Requires PHP: (.+)$/m)?.[1],m.requires_php);
 assert.match(read('includes/class-yuz-plugin.php'),/define\('YUZ_TRA_VERSION', \$version_header\['version'\]\)/,'Runtime version must use header');
 return m;
}
function provenance(m){
 let commit=null;
 if(process.env.GITHUB_ACTIONS==='true'){
  commit=cp.execFileSync('git',['rev-parse','HEAD'],{cwd:root,encoding:'utf8'}).trim();
  assert.match(commit,/^[a-f0-9]{40}$/);assert.equal(commit,process.env.GITHUB_SHA,'CI commit mismatch');
  cp.execFileSync('git',['diff','--exit-code','HEAD','--'],{cwd:root,stdio:'pipe'});
 }
 const files=read('release-files.txt').trim().split('\n');
 const hashes={};for(const file of files){assert.ok(!file.startsWith('/')&&!file.includes('..'));hashes[file]=sha(fs.readFileSync(path.join(root,file)));}
 return {schema_version:1,version:m.version,tag:'v'+m.version,repository:m.repository,source_commit:commit,build_mode:commit?'github-actions':'local-unverified',source_files_sha256:sha(JSON.stringify(hashes)),files:hashes};
}
const m=check();
if(process.argv[2]==='--stage'){
 const target=process.argv[3];assert.ok(target&&fs.statSync(target).isDirectory());
 fs.writeFileSync(path.join(target,'build-provenance.json'),JSON.stringify(provenance(m),null,2)+'\n');
}else if(process.argv[2]==='--manifest'){
 const zip=process.argv[3];assert.equal(path.basename(zip),'yuz-tra-'+m.version+'.zip');
 const build=JSON.parse(cp.execFileSync('unzip',['-p',zip,'yuz-tra/build-provenance.json'],{encoding:'utf8'}));
 assert.equal(build.version,m.version);
 const out={schema_version:1,version:m.version,tag:'v'+m.version,channel:m.channel,repository:m.repository,source_commit:build.source_commit,build_mode:build.build_mode,source_files_sha256:build.source_files_sha256,artifact:{name:path.basename(zip),bytes:fs.statSync(zip).size,sha256:sha(fs.readFileSync(zip)),url:m.repository+'/releases/download/v'+m.version+'/'+path.basename(zip)}};
 fs.writeFileSync(zip.replace(/\.zip$/,'.manifest.json'),JSON.stringify(out,null,2)+'\n');
}else if(process.argv[2]==='--verify-archive'){
 const zip=process.argv[3],manifest=JSON.parse(fs.readFileSync(process.argv[4],'utf8'));
 assert.equal(manifest.version,m.version);assert.equal(manifest.tag,'v'+m.version);
 assert.equal(manifest.artifact.sha256,sha(fs.readFileSync(zip)));assert.equal(manifest.artifact.bytes,fs.statSync(zip).size);
 assert.equal(manifest.artifact.url,m.repository+'/releases/download/v'+m.version+'/'+path.basename(zip));
 const build=JSON.parse(cp.execFileSync('unzip',['-p',zip,'yuz-tra/build-provenance.json'],{encoding:'utf8'}));
 assert.equal(build.source_commit,manifest.source_commit);assert.equal(build.version,m.version);
 assert.equal(build.source_files_sha256,sha(JSON.stringify(build.files)));
 assert.equal(build.source_files_sha256,manifest.source_files_sha256);
 for(const [file,hash]of Object.entries(build.files)){
  assert.ok(!file.startsWith('/')&&!file.includes('..'));
  assert.equal(sha(cp.execFileSync('unzip',['-p',zip,'yuz-tra/'+file],{maxBuffer:20*1024*1024})),hash,file);
 }
}
console.log('PASS version integrity '+m.version+(process.argv[2]?' '+process.argv[2]:''));
