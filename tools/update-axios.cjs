'use strict';
// One-time, pinned vendor refresh. No npm lifecycle scripts; integrity before extraction.
const fs=require('node:fs'),cp=require('node:child_process'),crypto=require('node:crypto'),path=require('node:path');
const root=path.resolve(__dirname,'..');
async function main(){
 const response=await fetch('https://registry.npmjs.org/axios/-/axios-1.20.0.tgz');
 if(!response.ok)throw Error('Registry HTTP '+response.status);
 const bytes=Buffer.from(await response.arrayBuffer());
 const expected='r8aOh8j9cGKpgQAqpzrUHnSIc6a59Y3Xf/cv8sy1DrHCkZHzQGEuoq1tARk6qSyDdtQGSDgpb9kFlruzPvrgwg==';
 if(crypto.createHash('sha512').update(bytes).digest('base64')!==expected)throw Error('Registry integrity mismatch');
 for(const [member,target]of [['package/dist/axios.js','assets/vendor/axios.js'],['package/dist/axios.min.js','assets/vendor/axios.min.js'],['package/LICENSE','assets/vendor/licenses/axios-1.20.0.txt']]){
  const next=cp.execFileSync('tar',['-xzOf','-',member],{input:bytes,maxBuffer:4*1024*1024}).toString('utf8').trimEnd();
  const file=path.join(root,target),old=fs.existsSync(file)?fs.readFileSync(file,'utf8').trimEnd():null;
  if(old===next)continue;
  const patch='*** Begin Patch\n'+(old===null?'*** Add File: '+file+'\n':'*** Update File: '+file+'\n@@\n'+old.split('\n').map(l=>'-'+l).join('\n')+'\n')+next.split('\n').map(l=>'+'+l).join('\n')+'\n*** End Patch';
  cp.execFileSync('apply_patch',[],{input:patch,maxBuffer:4*1024*1024});
 }
 console.log('PASS axios 1.20.0 official package SHA-512 verified; only browser bundles and licence updated');
}
main().catch(e=>{console.error(e.message);process.exitCode=1;});
