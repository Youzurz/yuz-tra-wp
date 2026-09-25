// Validates supplied evidence structure; cannot certify a reviewer's identity or competence.
const fs=require('node:fs'),crypto=require('node:crypto');
const sha=x=>crypto.createHash('sha256').update(x).digest('hex');
function validate(corpusBytes,packet){
 const corpus=JSON.parse(corpusBytes),errors=[];
 if(packet.corpus_sha256!==sha(corpusBytes))errors.push('corpus hash mismatch');
 if(!packet.budget_authorization?.owner||!(packet.budget_authorization?.limit_minor>0)||!packet.budget_authorization?.currency)errors.push('missing dedicated budget authorization');
 const rows=packet.evaluations||[];
 if(rows.length!==corpus.cases.length||new Set(rows.map(x=>x.case_id)).size!==rows.length)errors.push('missing or duplicate evaluations');
 for(const c of corpus.cases){
  const row=rows.find(x=>x.case_id===c.id);
  if(!row){errors.push('missing '+c.id);continue;}
  if(!row.output||row.output_sha256!==sha(row.output))errors.push('output hash '+c.id);
  if(!row.reviewer||!row.reviewed_at||!Number.isFinite(Date.parse(row.reviewed_at)))errors.push('review attribution '+c.id);
  if(!['meaning','terminology','fluency'].every(k=>Number.isInteger(row.scores?.[k])&&row.scores[k]>=0&&row.scores[k]<=4))errors.push('scores '+c.id);
  if(!Array.isArray(row.critical_errors)||row.critical_errors.length)errors.push('critical errors '+c.id);
  if(!row.provider_run_id)errors.push('provider evidence '+c.id);
 }
 return errors;
}
module.exports={validate};
if(require.main===module){
 try{const errors=validate(fs.readFileSync(require('node:path').join(__dirname,'../tests/corpus.json')),JSON.parse(fs.readFileSync(process.argv[2])));console.log(JSON.stringify({structurally_valid:errors.length===0,errors,warning:'Does not authenticate human identity, provider billing or linguistic quality.'},null,2));process.exitCode=errors.length?1:0;}
 catch(e){console.error('Human/provider evidence unavailable: '+e.message);process.exitCode=1;}
}
