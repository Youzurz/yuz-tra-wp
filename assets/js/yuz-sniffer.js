/*! YUZ Sniffer QQOQCCP – lightweight live debugger */
(function () {
  if (!/(\byuz-sniffer=1\b)/.test(location.search) && localStorage.getItem('yuzSniffer') !== 'on') return;

  const S = {
    accept: 0, reject: 0, apply: 0, miss: 0, ajaxReq: 0, ajaxResOK: 0, ajaxResKO: 0
  };
  const LOG = [];
  let paused = false;

  // UI
  const box = document.createElement('div');
  box.id = 'yuz-sniffer';
  box.innerHTML = `
    <div class="yuz-head">
      <strong>YUZ Sniffer</strong>
      <div class="yuz-actions">
        <button id="yuz-snif-pause">Pause</button>
        <button id="yuz-snif-clear">Clear</button>
        <button id="yuz-snif-dl">Export JSON</button>
        <button id="yuz-snif-off">Off</button>
      </div>
    </div>
    <div class="yuz-stats">
      ✔ <span data-k="accept">0</span> · ✖ <span data-k="reject">0</span> · ⇢ <span data-k="apply">0</span> · ☐ <span data-k="miss">0</span> ·
      ⭮ <span data-k="ajaxReq">0</span> · ☑ <span data-k="ajaxResOK">0</span> · ☒ <span data-k="ajaxResKO">0</span>
    </div>
    <div class="yuz-list"></div>
    <div class="yuz-foot">F9: toggle · click a row to highlight node</div>
  `;
  const css = document.createElement('style');
  css.textContent = `
  #yuz-sniffer{position:fixed;z-index:999999;right:10px;bottom:10px;width:420px;max-height:60vh;background:#0f172a; color:#e5e7eb;
    font:12px/1.4 ui-sans-serif,system-ui,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial; box-shadow:0 10px 20px rgba(0,0,0,.35);
    border-radius:12px; overflow:hidden; border:1px solid #1f2937}
  #yuz-sniffer .yuz-head{display:flex;justify-content:space-between;align-items:center;background:#111827;padding:8px 10px}
  #yuz-sniffer .yuz-head strong{font-size:13px}
  #yuz-sniffer .yuz-actions button{margin-left:6px;background:#374151;border:0;color:#e5e7eb;padding:4px 8px;border-radius:6px;cursor:pointer}
  #yuz-sniffer .yuz-actions button:hover{background:#4b5563}
  #yuz-sniffer .yuz-stats{padding:6px 10px;background:#0b1220;border-top:1px solid #1f2937;border-bottom:1px solid #1f2937}
  #yuz-sniffer .yuz-list{padding:6px 0;max-height:40vh;overflow:auto}
  #yuz-sniffer .row{display:grid;grid-template-columns:64px 1fr;gap:8px;padding:6px 10px;border-bottom:1px dashed #1f2937}
  #yuz-sniffer .k{opacity:.7}
  #yuz-sniffer .v{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  #yuz-sniffer .row.accept{background:rgba(34,197,94,.08)}
  #yuz-sniffer .row.reject{background:rgba(239,68,68,.06)}
  #yuz-sniffer .row.apply{background:rgba(59,130,246,.06)}
  #yuz-sniffer .row.miss{background:rgba(234,179,8,.08)}
  #yuz-sniffer .row:hover{background:rgba(148,163,184,.1);cursor:pointer}
  #yuz-sniffer .yuz-foot{padding:6px 10px;background:#0b1220;color:#9ca3af;text-align:right}
  .yuz-sniff-highlight{outline:2px solid #60a5fa; outline-offset:2px; transition: outline-color .2s}
  `;
  document.head.appendChild(css);
  document.body.appendChild(box);

  const list = box.querySelector('.yuz-list');

  function updStat() {
    for (const k in S) box.querySelector(`[data-k="${k}"]`).textContent = S[k];
  }
  function addRow(type, detail) {
    if (paused) return;
    const row = document.createElement('div');
    row.className = `row ${type}`;
    const when = new Date().toLocaleTimeString();
    const label = {
      accept:'ACCEPT', reject:'REJECT', apply:'APPLY', miss:'MISS',
      'ajax:request':'AJAX ⇢', 'ajax:response':'AJAX ⇠'
    }[type] || type;

    const txt = (detail && (detail.text || detail.original || detail.translated || detail.sample)) || '';
    row.innerHTML = `<div class="k">${label} · ${when}</div><div class="v" title="${(txt||'').replace(/"/g,'&quot;')}">${txt || '(no text)'}</div>`;
    row.__detail = detail;
    row.onclick = () => highlight(detail);
    list.prepend(row);
  }
  function highlight(detail) {
    try {
      const el = detail?.el || (detail?.path ? document.querySelector(detail.path) : null);
      if (!el) return;
      el.classList.add('yuz-sniff-highlight'); el.scrollIntoView({behavior:'smooth', block:'center'});
      setTimeout(()=>el.classList.remove('yuz-sniff-highlight'), 1200);
    } catch(e){}
  }

  // Controls
  box.querySelector('#yuz-snif-pause').onclick = () => { paused = !paused; box.querySelector('#yuz-snif-pause').textContent = paused?'Resume':'Pause'; };
  box.querySelector('#yuz-snif-clear').onclick = () => { list.innerHTML=''; LOG.length=0; for (const k in S) S[k]=0; updStat(); };
  box.querySelector('#yuz-snif-dl').onclick = () => {
    const blob = new Blob([JSON.stringify({ stats:S, events:LOG }, null, 2)], {type:'application/json'});
    const a = document.createElement('a'); a.href=URL.createObjectURL(blob); a.download='yuz-sniffer.json'; a.click();
    URL.revokeObjectURL(a.href);
  };
  box.querySelector('#yuz-snif-off').onclick = () => { localStorage.setItem('yuzSniffer','off'); box.remove(); };

  // Keyboard toggle
  window.addEventListener('keydown', (e) => { if (e.key === 'F9') { box.style.display = (box.style.display === 'none' ? '' : 'none'); } });

  // Event listeners
  function on(evt, cb) { window.addEventListener('yuz:sniff:' + evt, (e)=>cb(e.detail)); }

  on('accept', (d)=>{ S.accept++; updStat(); LOG.push({t:'accept',d}); addRow('accept', d); });
  on('reject', (d)=>{ S.reject++; updStat(); LOG.push({t:'reject',d}); addRow('reject', d); });
  on('apply',  (d)=>{ S.apply++;  updStat(); LOG.push({t:'apply', d}); addRow('apply', d); });
  on('miss',   (d)=>{ S.miss++;   updStat(); LOG.push({t:'miss',  d}); addRow('miss',  d); });
  on('ajax:request', (d)=>{ S.ajaxReq++; updStat(); LOG.push({t:'ajax:req', d}); addRow('ajax:request', d); });
  on('ajax:response', (d)=>{ (d && d.ok ? S.ajaxResOK : S.ajaxResKO)++; updStat(); LOG.push({t:'ajax:res', d}); addRow('ajax:response', d); });

  console.info('[YUZ Sniffer] ON. Add “?yuz-sniffer=1” or localStorage.setItem("yuzSniffer","on") to auto-start.');
})();

// Sniffer safe (prod-friendly)
(function(){
  const $ = window.jQuery || window.$;
  if (!$) return;
  $(document).ajaxSend(function(_e, _jqXHR, settings){
    const d = settings && settings.data;
    if (!d) return;
    const isSearch = /action=yuz_tra_tm_search/.test(typeof d === 'string' ? d : $.param(d));
    if (!isSearch) return;
    const params = typeof d === 'string' ? new URLSearchParams(d) : new URLSearchParams($.param(d));
    const scope = params.get('scope') || '';
    const q     = params.get('q') || '';
    const idx   = parseInt(params.get('index')||'0',10);
    console.groupCollapsed(`[SMART-SNIFFER] ${scope}#${idx} q="${q}"`);
  });
  $(document).ajaxComplete(async function(_e, xhr, settings){
    const d = settings && settings.data;
    if (!d) return;
    const isSearch = /action=yuz_tra_tm_search/.test(typeof d === 'string' ? d : $.param(d));
    if (!isSearch) return;
    try {
      const json = JSON.parse(xhr.responseText);
      const data = (json && json.data) || {};
      const results = Array.isArray(data.results) ? data.results : [];
      const sample = results.slice(0,3).map(x => ({
        id: x.id || x.string_id || x.translation_id,
        ctx: x.context, page: x.page_url,
        orig: (x.original_text || x.original || '').slice(0,80)
      }));
      console.log({ total: data.total, has_more: !!data.has_more, index: data.index, count: results.length, sample });
    } catch(e){}
    console.groupEnd();
  });
  console.log('[SMART-SNIFFER] prêt');
})();
