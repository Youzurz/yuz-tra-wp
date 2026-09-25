// Local, non-publishing proof runner. No provider call, no production database.
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),cp=require('node:child_process'),crypto=require('node:crypto');
const root=path.resolve(__dirname,'..');
const {collect}=require('./proof-requirements.cjs');
const scenarios=[
 ['TRA-EDIT-01','Réponses tardives et brouillons','unit-fixture',['node','--test','tests/editor-late-response.cjs']],
 ['TRA-UI-01','Alignement et redimensionnement','browser-mocked-server',['node','tests/browser/workspace.cjs']],
 ['TRA-UI-02','Ouverture différée','browser-mocked-server',['node','tests/browser/workspace-delayed.cjs']],
 ['TRA-UI-03','Superposition des éditeurs','browser-mocked-server',['node','tests/browser/overlay.cjs']],
 ['TRA-HTTP-01','Erreurs de transport','browser-mocked-server',['node','tests/browser/transport.cjs']],
 ['TRA-SEC-01','Régressions sécurité statiques','source-regression',['node','--test','tests/security-regression.cjs']],
 ['TRA-REL-01','Cohérence du versionnage','filesystem-fixture',['node','--test','tests/release-integrity.cjs']],
];
const gaps=[
 ['TRA-WP-01','Persistance SQL, gettext, droits REST','Recette WordPress jetable séparée : tools/ci-wordpress.sh. Non exécutée par ce profil local.'],
 ['TRA-AI-01','Qualité linguistique et coût fournisseur réel','Aucun appel payant. Nécessite corpus évalué et fournisseur explicitement activé.'],
 ['TRA-CRON-01','Worker et concurrence réels','Non couvert par ces tests navigateur.'],
 ['TRA-DOC-01','Couverture des spécifications GVADATA','Volume local vide au relevé initial; documents PDF/PPTX non consultés.'],
];
function classify(r){return r.error||r.signal||r.status!==0?'FAIL':'PASS';}
function esc(s){return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function hash(b){return crypto.createHash('sha256').update(b).digest('hex');}
function git(args){const r=cp.spawnSync('git',args,{cwd:root,encoding:'utf8'});if(r.status!==0)throw Error('Git provenance unavailable');return r.stdout.trim();}
function run(){
 const out=fs.mkdtempSync(path.join(os.tmpdir(),'yuz-proof-lab-'));fs.chmodSync(out,0o700);
 const files=git(['ls-files','-z']).split('\0').filter(Boolean);
 const sourceHash=hash(files.sort().map(p=>p+'\0'+hash(fs.readFileSync(path.join(root,p)))).join('\n'));
 const report={schema:1,created_at:new Date().toISOString(),commit:git(['rev-parse','HEAD']),dirty:!!git(['status','--porcelain']),tracked_files_sha256:sourceHash,node:process.version,platform:process.platform,scope:'Local regression only; NOT production certification',results:[]};
 report.runner_sha256=hash(fs.readFileSync(__filename));
 const selected=[...scenarios];
 if(process.argv.includes('--wordpress'))selected.push(['TRA-WP-01','WordPress 6.5, catalogue SQL et droits REST','disposable-wordpress-real-sql',['bash','tools/proof-wordpress.sh']]);
 for(const [id,title,kind,argv] of selected){
  const start=Date.now();const r=cp.spawnSync(argv[0],argv.slice(1),{cwd:root,encoding:'utf8',timeout:300000,maxBuffer:8*1024*1024,env:process.env});
  const log=(r.stdout||'')+(r.stderr||'')+(r.error?'\n'+r.error.message:'');
  fs.writeFileSync(path.join(out,id+'.log'),log,{mode:0o600});
  report.results.push({id,title,kind,status:classify(r),duration_ms:Date.now()-start,exit_code:r.status,signal:r.signal,command:argv,log:id+'.log',log_sha256:hash(log)});
 }
 for(const [id,title,reason]of gaps)if(!selected.some(s=>s[0]===id))report.results.push({id,title,kind:'not-executed',status:'NOT_TESTED',reason});
 report.verdict=report.results.some(r=>r.status==='FAIL')?'FAIL':'PARTIAL';
 report.requirements=collect(root,report.results);
 report.corpus={sha256:hash(fs.readFileSync(path.join(root,'tests/corpus.json'))),...JSON.parse(fs.readFileSync(path.join(root,'tests/corpus.json'),'utf8'))};
 report.coverage_scope='README.md and KNOWN-LIMITS.md paragraphs only; not all historical documents or GVADATA';
 fs.writeFileSync(path.join(out,'requirements.json'),JSON.stringify(report.requirements,null,2),{mode:0o600});
 fs.writeFileSync(path.join(out,'report.json'),JSON.stringify(report,null,2),{mode:0o600});
 const rows=report.results.map(r=>`<tr><td>${esc(r.id)}</td><td>${esc(r.title)}<small>${esc(r.kind)}</small></td><td>${esc(r.status)}</td><td>${r.log?`<a href="${esc(r.log)}">Journal</a> · ${r.duration_ms} ms`:esc(r.reason)}</td></tr>`).join('');
 fs.writeFileSync(path.join(out,'index.html'),`<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>YUZ-TRA — preuves</title><style>body{font:16px/1.6 system-ui;max-width:1120px;margin:40px auto;padding:24px;color:#14213d;background:#f7faff}h1{line-height:1.2}table{border-collapse:collapse;width:100%;background:white}td,th{text-align:left;padding:14px;border-bottom:1px solid #ddd}small{display:block;color:#555}code{overflow-wrap:anywhere}@media(max-width:700px){table,tbody,tr,td{display:block}thead{display:none}}</style><h1>YUZ-TRA — banc de preuves</h1><p>Verdict : <strong>${report.verdict}</strong>. Tests locaux, pas une certification de production ni une démonstration de traduction IA.</p><p>Commit <code>${esc(report.commit)}</code> · fichiers modifiés : ${report.dirty?'oui':'non'}<br>Empreinte des fichiers suivis : <code>${sourceHash}</code></p><p><a href="report.json">Rapport JSON complet</a></p><table><thead><tr><th>Code</th><th>Fonction</th><th>Résultat</th><th>Preuve ou limite</th></tr></thead><tbody>${rows}</tbody></table></html>`,{mode:0o600});
 const matrix=report.requirements.map(r=>`<tr><td>${esc(r.id)}<small>${esc(r.source)}:${r.line} · ${esc(r.source_kind)}</small></td><td>${esc(r.statement)}</td><td>${esc(r.coverage)}<small>${esc(r.observations.map(o=>o.id+': '+o.status).join(', '))}</small></td></tr>`).join('');
 const htmlPath=path.join(out,'index.html');
 fs.writeFileSync(htmlPath,fs.readFileSync(htmlPath,'utf8').replace('</html>',`<h2>Exigences, historique et observations</h2><p>Chaque paragraphe documentaire est indexé. Une correspondance de section ne prouve pas toutes ses assertions. UNMAPPED indique une lacune explicite.</p><p><a href="requirements.json">Registre détaillé</a> · Corpus : ${report.corpus.cases.length} cas, relecture humaine en attente.</p><table><thead><tr><th>Source</th><th>Déclaration</th><th>Couverture / observations</th></tr></thead><tbody>${matrix}</tbody></table></html>`));
 console.log(JSON.stringify({directory:out,verdict:report.verdict,requirements:report.requirements.length,results:report.results.map(({id,status})=>({id,status}))},null,2));
 if(report.verdict==='FAIL')process.exitCode=1;
}
module.exports={classify,esc,scenarios};
if(require.main===module)run();
