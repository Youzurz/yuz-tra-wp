const fs = require('node:fs'), path = require('node:path'), cp = require('node:child_process');
async function main() {
  for (const [upstream, relative] of [
    ['dist/js/select2.full.js','assets/vendor/select2/js/select2.full.js'],
    ['dist/js/select2.full.min.js','assets/vendor/select2/js/select2.full.min.js'],
    ['dist/css/select2.min.css','assets/vendor/select2/css/select2.min.css'],
    ['LICENSE.md','assets/vendor/licenses/select2-4.1.0.txt'],
  ]) {
    const response = await fetch('https://raw.githubusercontent.com/select2/select2/4.1.0/' + upstream);
    if (!response.ok) throw new Error('Upstream HTTP ' + response.status);
    const next = await response.text(), file = path.resolve(__dirname, '..', relative);
    const exists = fs.existsSync(file), old = exists ? fs.readFileSync(file,'utf8') : '';
    const patch = '*** Begin Patch\n*** ' + (exists ? 'Update' : 'Add') + ' File: ' + file + '\n' + (exists ? '@@\n' + old.trimEnd().split('\n').map(l=>'-'+l).join('\n')+'\n' : '') + next.trimEnd().split('\n').map(l=>'+'+l).join('\n') + '\n*** End Patch';
    cp.execFileSync('apply_patch', [], {input:patch, maxBuffer:1024*1024});
    console.log('Updated ' + relative);
  }
}
main().catch(e=>{console.error(e.message); process.exitCode=1;});
