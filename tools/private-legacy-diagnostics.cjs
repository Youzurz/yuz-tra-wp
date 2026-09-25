const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const root=path.resolve(__dirname,'..');
for(const rel of fs.readFileSync(path.join(root,'release-files.txt'),'utf8').trim().split('\n').filter(p=>p.endsWith('.php'))) {
  const file=path.join(root,rel),old=fs.readFileSync(file,'utf8');
  const next=old.replace(/\berror_log\s*\(/g,'yuz_tra_debug_log(');
  if(old===next)continue;
  cp.execFileSync('apply_patch',[],{input:'*** Begin Patch\n*** Update File: '+file+'\n@@\n'+old.trimEnd().split('\n').map(l=>'-'+l).join('\n')+'\n'+next.trimEnd().split('\n').map(l=>'+'+l).join('\n')+'\n*** End Patch\n',stdio:'pipe'});
  console.log(rel);
}
