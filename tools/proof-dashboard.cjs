const http=require('node:http'),fs=require('node:fs'),path=require('node:path');
const assetRoot=path.join(__dirname,'proof-dashboard');
function publicReport(file){
 const r=JSON.parse(fs.readFileSync(file,'utf8'));
 if(!Array.isArray(r.results)||!Array.isArray(r.requirements))throw Error('Invalid report');
 return {created_at:r.created_at,commit:r.commit,dirty:r.dirty,verdict:r.verdict,
  results:r.results.map(({id,title,status,kind,duration_ms,reason,log_sha256})=>({id,title,status,kind,duration_ms,reason,log_sha256})),
  requirements:r.requirements.map(({id,source,source_kind,line,section,statement,coverage,scenario_ids})=>({id,source,source_kind,line,section,statement,coverage,scenario_ids})),
  corpus:{status:r.corpus?.status,count:r.corpus?.cases?.length||0}};
}
function createServer(report,allowedHosts){return http.createServer((req,res)=>{
 const hostname=(req.headers.host||'').split(':')[0];
 const headers={'Cache-Control':'no-store','X-Content-Type-Options':'nosniff','Referrer-Policy':'no-referrer','Content-Security-Policy':"default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'"};
 const send=(status,type,body)=>{res.writeHead(status,{...headers,'Content-Type':type});res.end(body);};
 if(!allowedHosts.includes(hostname))return send(403,'text/plain','Forbidden host');
 if(req.method!=='GET'&&req.method!=='HEAD')return send(405,'text/plain','Read only');
 const route=req.url.split('?')[0];
 try{
  if(route==='/api/report')return send(200,'application/json; charset=utf-8',JSON.stringify(publicReport(report)));
  if(route==='/health')return send(200,'application/json','{"status":"ok"}');
  const assets={'/':['index.html','text/html'],'/app.js':['app.js','text/javascript'],'/style.css':['style.css','text/css'],'/improvements.json':['improvements.json','application/json']};
  if(!Object.hasOwn(assets,route))return send(404,'text/plain','Not found');
  const [file,type]=assets[route];send(200,type+'; charset=utf-8',fs.readFileSync(path.join(assetRoot,file)));
 }catch{send(503,'text/plain','Report temporarily unavailable');}
});}
module.exports={createServer,publicReport};
if(require.main===module){
 const report=process.argv[2];if(!report)throw Error('Report file required');publicReport(report);
 const hosts=(process.env.YUZ_PROOF_HOSTS||'127.0.0.1').split(',');
 if(hosts.some(h=>!['127.0.0.1','100.64.0.5'].includes(h)))throw Error('Only configured loopback/VPN addresses permitted');
 const port=Number(process.env.YUZ_PROOF_PORT||8767);
 for(const host of hosts){const s=createServer(report,[...hosts,'localhost']);s.on('error',e=>{console.error(e.code);process.exit(1);});s.listen(port,host,()=>console.log('Dashboard listening http://'+host+':'+port));}
}
