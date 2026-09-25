const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const sha=b=>crypto.createHash('sha256').update(b).digest('hex');
// Section-level mapping is deliberately conservative: a passing subset is not compliance.
const mappings={
 'Atelier ajustable':['TRA-UI-01','TRA-UI-02','TRA-UI-03'],
 'Installation':['TRA-WP-01'],
 'Relecture et publication':['TRA-WP-01','TRA-AI-01'],
 'Mémoire approuvée, glossaire et Ollama':['TRA-AI-01'],
 'Traduction silencieuse et consommation':['TRA-CRON-01'],
 'Périmètre et intégration':['TRA-WP-01'],
};
function collect(root,results){
 const sources=[['README.md','declaration-produit'],['KNOWN-LIMITS.md','historique-limites']];
 const requirements=[];
 for(const [file,kind]of sources){
  const content=fs.readFileSync(path.join(root,file),'utf8');let section='Introduction',line=0;
  const blocks=content.split(/\n\s*\n/);
  for(const block of blocks){
   const start=content.indexOf(block,line);line=start+block.length;
   if(/^##? /.test(block)){section=block.replace(/^#+ /,'').trim();continue;}
   if(!block.trim())continue;
   const ids=mappings[section]||[];
   const key=sha(file+'\0'+block).slice(0,12);
   requirements.push({id:'REQ-'+key,source:file,source_kind:kind,source_sha256:sha(content),line:content.slice(0,start).split('\n').length,section,statement:block,scenario_ids:ids.length?ids:['SPEC-'+key],planned_scenario:ids.length?null:{id:'SPEC-'+key,status:'NOT_IMPLEMENTED',precondition:'Environnement jetable et données synthétiques; aucun accès production',expected:block,procedure:'Décomposer cette déclaration en assertions indépendantes; exercer les chemins concernés et les refus; capturer état avant/après et journal. Ne pas valider par simple présence de code.'},observations:ids.map(id=>({id,status:results.find(r=>r.id===id)?.status||'NOT_TESTED'})),coverage:ids.length?'PARTIAL_MAPPING':'UNMAPPED',note:'Déclaration documentaire, pas conformité prouvée. Correspondance de section à affiner assertion par assertion.'});
  }
 }
 return requirements;
}
module.exports={collect};
