const {test}=require('node:test'),assert=require('node:assert/strict'),{classify}=require('../tools/classify-candidate-secrets.cjs');
test('candidate-only false-positive classification stays narrow',()=>{
 assert.ok(classify('assets/vendor/axios.js','      allOwnKeys = _ref4.allOwnKeys;',false));
 assert.equal(classify('assets/vendor/axios.js','const api_key="synthetic-test-credential";',false),null);
 assert.equal(classify('includes/settings.php','allOwnKeys = _ref4.allOwnKeys;',false),null);
 const line='"includes/class-yuz-api-manager.php": "'+'a'.repeat(64)+'",';
 assert.equal(classify('build-provenance.json',line,false),null);
 assert.ok(classify('build-provenance.json',line,true));
 assert.equal(classify('build-provenance.json','"api_key": "'+'a'.repeat(64)+'"',true),null);
});
