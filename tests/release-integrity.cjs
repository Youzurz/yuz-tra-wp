const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),os=require('node:os'),path=require('node:path'),cp=require('node:child_process');
const source=path.resolve(__dirname,'..');
function fixture(){
 const dir=fs.mkdtempSync(path.join(os.tmpdir(),'yuz-release-integrity-'));
 for(const p of ['tools/release-integrity.cjs','version.json','yuz-tra.php','readme.txt','includes/class-yuz-plugin.php']){
  fs.mkdirSync(path.dirname(path.join(dir,p)),{recursive:true});fs.copyFileSync(path.join(source,p),path.join(dir,p));
 }
 return dir;
}
function run(dir){return cp.spawnSync(process.execPath,[path.join(dir,'tools/release-integrity.cjs')],{encoding:'utf8'});}
test('canonical version, header, readme and runtime agree',()=>{assert.equal(run(fixture()).status,0);});
for(const [name,file,from,to]of [
 ['header drift','yuz-tra.php',/Version: \d+\.\d+\.\d+/,'Version: 99.0.0'],
 ['readme drift','readme.txt',/Stable tag: \d+\.\d+\.\d+/,'Stable tag: 99.0.0'],
 ['repository drift','version.json',/Youzurz\/yuz-tra-wp/,'Other/untrusted'],
 ['unsupported release channel','version.json',/"channel": "(?:stable|evaluation)"/,'"channel": "unknown"'],
 ['runtime hard-coded version','includes/class-yuz-plugin.php',/define\('YUZ_TRA_VERSION', \$version_header\['version'\]\)/,"define('YUZ_TRA_VERSION', '99.0.0')"],
])test(name+' blocks build',()=>{const dir=fixture(),p=path.join(dir,file);fs.writeFileSync(p,fs.readFileSync(p,'utf8').replace(from,to));assert.notEqual(run(dir).status,0);});
