'use strict';
let report;
const $=id=>document.getElementById(id);
const labels={PASS:'Réussi',FAIL:'Échec',NOT_TESTED:'Non testé'};
function el(tag,text,cls){const node=document.createElement(tag);node.textContent=text;if(cls)node.className=cls;return node;}
function render(){
 $('metrics').replaceChildren();
 for(const [value,label]of [[report.results.filter(r=>r.status==='PASS').length,'groupes réussis'],[report.results.filter(r=>r.status!=='PASS').length,'groupes non validés'],[report.requirements.length,'blocs documentaires indexés']]){const n=el('div','','metric');n.append(el('strong',value),el('span',label));$('metrics').append(n);}
 $('scenarios').replaceChildren();
 for(const r of report.results.filter(r=>$('filter').value==='all'||r.status===$('filter').value)){const n=el('article','','scenario'),text=el('div','');text.append(el('strong',r.title),el('small',r.id+' · '+r.kind));if(r.reason)text.append(el('small',r.reason));if(r.duration_ms!==undefined)text.append(el('small',(r.duration_ms/1000).toFixed(1)+' s'));n.append(text,el('span',labels[r.status]||r.status,'badge '+r.status));$('scenarios').append(n);}
 $('requirements').replaceChildren();
 const q=$('search').value.toLocaleLowerCase();
 for(const r of report.requirements.filter(r=>(r.statement+' '+r.section+' '+r.id).toLocaleLowerCase().includes(q))){const d=el('details','');d.append(el('summary',r.section+' · '+r.id),el('small',r.source+':'+r.line+' · '+r.source_kind),el('p',r.statement),el('small',r.coverage+' · '+r.scenario_ids.join(', ')));$('requirements').append(d);}
 $('provenance').textContent='Mesures du '+new Date(report.created_at).toLocaleString('fr-FR')+' · Commit '+report.commit+' · Copie modifiée : '+(report.dirty?'oui':'non')+' · Corpus : '+report.corpus.count+' cas, validation humaine en attente.';
}
async function refresh(){
 $('refresh').disabled=true;
 try{const response=await fetch('/api/report',{cache:'no-store'});if(!response.ok)throw Error('HTTP '+response.status);report=await response.json();render();$('connection').textContent='Rapport chargé · Couverture partielle';await improvements();}
 catch(e){$('connection').textContent='Chargement impossible : '+e.message+' — les anciennes données éventuelles ne sont pas actualisées.';}
 finally{$('refresh').disabled=false;}
}
async function improvements(){
 try{
  const response=await fetch('/improvements.json',{cache:'no-store'});if(!response.ok)throw Error('HTTP '+response.status);
  const data=await response.json();$('improvements').replaceChildren();
  $('improvements-date').textContent='Constats du '+new Date(data.measured_at).toLocaleString('fr-FR')+'. '+data.scope+' '+data.goal;
  for(const item of data.items){const node=el('article','','improvement');node.append(el('h3',item.id+' · '+item.priority+' · '+item.status),el('small',item.origin+' · '+item.version),el('p','Acceptation : '+item.acceptance),el('p','Preuve / limite : '+item.evidence),el('small','Dépendance : '+item.dependency));$('improvements').append(node);}
 }catch(e){$('improvements-date').textContent='Suivi indisponible : '+e.message+' ; aucune actualisation des preuves.';}
}
$('refresh').addEventListener('click',refresh);$('filter').addEventListener('change',()=>report&&render());$('search').addEventListener('input',()=>report&&render());refresh();
