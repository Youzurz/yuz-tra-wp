// Idempotent normalization of existing scalar sanitizers, not of arbitrary content.
const fs=require('node:fs'), path=require('node:path'), cp=require('node:child_process');
const root=path.resolve(__dirname,'..');
for(const rel of fs.readFileSync(path.join(root,'release-files.txt'),'utf8').trim().split('\n').filter(p=>p.endsWith('.php'))) {
  const file=path.join(root,rel), old=fs.readFileSync(file,'utf8');
  const next=old.replace(/((?:sanitize_text_field|sanitize_key|sanitize_textarea_field|esc_url_raw)\(\s*)((?:\(string\)\s*)?\$_(?:GET|POST|REQUEST|SERVER)\[(?:'[^']+'|"[^"]+"|\$\w+)\](?:\s*\?\?\s*(?:'[^']*'|"[^"]*"))?)(\s*\))/g, '$1wp_unslash($2)$3');
  if(next===old)continue;
  cp.execFileSync('apply_patch',[],{input:'*** Begin Patch\n*** Update File: '+file+'\n@@\n'+old.trimEnd().split('\n').map(l=>'-'+l).join('\n')+'\n'+next.trimEnd().split('\n').map(l=>'+'+l).join('\n')+'\n*** End Patch\n',stdio:'pipe'});
  console.log(rel);
}
