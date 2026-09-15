'use strict';
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const inventory=[
  ['vue','2.7.16','assets/vendor/vue/vue.js'],
  ['vue-router','3.5.3','assets/vendor/vue-router/vue-router.js'],
  ['axios','1.11.0','assets/vendor/axios.js'],
  ['he','1.2.0','assets/vendor/he.min.js'],
  ['select2','4.1.0','assets/vendor/select2/js/select2.full.js']
];
const components=inventory.map(([name,version,file])=>{
  const bytes=fs.readFileSync(path.join(root,file));
  assert.ok(bytes.toString('utf8').includes(version),'Dependency header/version drift: '+file);
  return {type:'library',name,version,purl:`pkg:npm/${name}@${version}`,licenses:[{license:{id:'MIT'}}],hashes:[{alg:'SHA-256',content:crypto.createHash('sha256').update(bytes).digest('hex')}],properties:[{name:'youzurz:source-file',value:file}]};
});
components.push({type:'data',name:'flag-icons',licenses:[{license:{id:'MIT'}}],properties:[{name:'youzurz:version-status',value:'unknown; not covered by version-based vulnerability matching'}]});
fs.mkdirSync(path.join(root,'dist'),{recursive:true});
fs.writeFileSync(path.join(root,'dist/bundled.cdx.json'),JSON.stringify({bomFormat:'CycloneDX',specVersion:'1.5',version:1,components},null,2)+'\n');
console.log('SBOM: 5 version-checked browser libraries; flag-icons version explicitly unknown');
