/* YUZ-TRA catalog editor: no framework, no provider calls on page load. */
(() => {
  'use strict';
  const cfg = window.yuzStrings || {};
  if (!cfg.nonce) return;
  const query = new URLSearchParams(window.location.search);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const state = { page: 1, lang: cfg.editorLang || cfg.defaultLang, rows: [], dirty: new Set(), busy: false, request: 0 };
  const mount = document.querySelector('.yuz-strings-tab');
  // The two real editors share one top-layer workspace. Never clone Vue's DOM:
  // move its root intact and restore it on close, preserving state/listeners.
  const root = document.createElement('section');
  root.id = 'yuz-dock'; root.className = 'yuz-catalog hidden notranslate';
  root.setAttribute('translate', 'no');
  root.setAttribute('data-yuz', 'no-translate');
  root.setAttribute('aria-labelledby', 'yuz-catalog-title');
  root.tabIndex = -1;
  root.innerHTML = `
    <header><h2 id="yuz-catalog-title">Catalogue des chaînes</h2><button type="button" data-close aria-label="Fermer">×</button></header>
    <div class="yuz-catalog-controls">
      <label>Langue <select data-lang>${(cfg.languages || []).map(l => `<option value="${esc(l.code)}">${esc(l.name)} (${esc(l.code)})</option>`).join('')}</select></label>
      <label>Domaine <select data-domain><option value="">Tous</option></select></label>
      <label>État <select data-filter><option value="-1">Tous</option><option value="0">Non traduit</option><option value="1">Brouillon</option><option value="2">À relire</option><option value="3">Révisé</option><option value="4">Publié</option><option value="5">Archivé</option></select></label>
      <label>Rechercher <input type="search" data-q></label>
      <button type="button" data-search>Rechercher</button>
      <button type="button" data-scan ${cfg.canScan ? '' : 'hidden'}>Scanner WordPress, plugins et thèmes</button>
      <button type="button" data-translate>Traduire la sélection (5 max.)</button>
    </div>
    <details ${cfg.canScan ? '' : 'hidden'}>
      <summary>Langue source et glossaire approuvé</summary>
      <p>Par défaut, le catalogue suit la convention Gettext : source anglaise (en). Pour un plugin écrit dans une autre langue, sélectionnez son domaine puis déclarez sa langue source.</p>
      <label>Locale source <input data-source placeholder="fr_FR"></label><button type="button" data-source-save>Déclarer pour ce domaine</button>
      <p>Glossaire CSV : source_lang,target_lang,domain,context,source,target. Les termes sont utilisés par Ollama ; aucune traduction automatique ne devient une approbation humaine.</p>
      <textarea data-glossary rows="4" aria-label="Glossaire CSV"></textarea>
      <label><input type="checkbox" data-glossary-approved> J’ai vérifié et approuvé ces termes.</label>
      <button type="button" data-glossary-save>Importer le glossaire approuvé</button>
    </details>
    <p data-status role="status" aria-live="polite"></p><p data-usage></p>
    <div data-rows></div>
    <footer><button type="button" data-prev>Précédent</button><span data-page></span><button type="button" data-next>Suivant</button></footer>`;
  (mount || document.body).appendChild(root);
  if (mount) root.classList.add('yuz-catalog-inline');
  const el = name => root.querySelector('[data-' + name + ']');
  el('q').value = (query.get('yuz_q') || '').slice(0,200);
  if ((cfg.languages || []).some(language => language.code === query.get('yuz_lang'))) state.lang = query.get('yuz_lang');
  el('lang').value = state.lang;
  if (!el('lang').value) state.lang = el('lang').value = cfg.languages?.[0]?.code || '';
  const status = text => { el('status').textContent = text; };
  async function request(op, payload = {}) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 120000);
    try {
      const endpoint = new URL(cfg.ajax_url || window.ajaxurl, window.location.href);
      if (endpoint.origin !== window.location.origin) {
        throw new Error('Adresse AJAX sur un autre domaine. Rechargez l’éditeur après correction de sa configuration. Aucune requête envoyée.');
      }
      const response = await fetch(endpoint.href, {
        method: 'POST', credentials: 'same-origin', signal: controller.signal,
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: new URLSearchParams({ action: 'yuz_tra_strings', nonce: cfg.nonce, op, lang: state.lang, ...payload })
      });
      const raw = await response.text();
      const httpError = response.status === 401 || response.status === 403
        ? 'Accès refusé ou session expirée. Reconnectez-vous puis rechargez l’éditeur.'
        : response.status === 404
          ? 'Endpoint AJAX introuvable. Vérifiez le routage WordPress du domaine utilisé.'
          : 'Le serveur ou le proxy n’a pas renvoyé une réponse JSON valide.';
      let data;
      try { data = JSON.parse(raw); }
      catch (_) { throw new Error(`HTTP ${response.status} — ${httpError} Aucune opération confirmée.`); }
      if (!response.ok || !data || data.success !== true) {
        throw new Error(typeof data?.data?.message === 'string' ? data.data.message : `HTTP ${response.status} — ${httpError} Aucune opération confirmée.`);
      }
      if (!data.data || typeof data.data !== 'object') throw new Error('Réponse AJAX incomplète. Aucune opération confirmée.');
      return data.data;
    } catch (error) {
      if (error.name === 'AbortError') throw new Error('Délai de réponse dépassé. Rechargez le catalogue pour vérifier l’état avant de relancer une opération.');
      throw error;
    } finally { clearTimeout(timer); }
  }
  function usage(u) {
    if (!u) return;
    el('usage').textContent = `Consommation UTC : ${u.characters}/${u.char_limit} caractères aujourd’hui · ${u.minute_requests}/${u.requests_limit} requêtes cette minute · ${u.failures} échecs · ${u.cache_hits} réponses en cache.`;
    if(u.measured_requests) el('usage').textContent += ` Ollama : ${u.input_tokens} tokens d’entrée, ${u.output_tokens} de sortie, ${u.measured_requests} réponses mesurées, ${(u.duration_ms/1000).toFixed(1)} s HTTP cumulées. Coût monétaire non mesuré.`;
  }
  function lock(locked) {
    state.busy = locked;
    root.querySelectorAll('button,select,input,textarea').forEach(e => { e.disabled = locked; });
  }
  function render(data) {
    if (!Array.isArray(data.rows) || !Array.isArray(data.domains) || !Number.isFinite(Number(data.total)) || !(Number(data.per_page) > 0)) {
      throw new Error('Réponse du catalogue incomplète. Rechargez la page ; aucun résultat ne peut être confirmé.');
    }
    state.rows = data.rows; state.dirty.clear();
    const selected = el('domain').value;
    el('domain').innerHTML = '<option value="">Tous</option>' + data.domains.map(d => `<option value="${esc(d)}">${esc(d)}</option>`).join('');
    el('domain').value = selected;
    el('rows').innerHTML = data.rows.length ? data.rows.map(row => `
      <article class="yuz-catalog-row" data-id="${row.id}">
        <header><label><input type="checkbox" data-check> #${row.id} · ${esc(row.domain)}</label><span>${esc(row.context || 'Sans contexte')}</span><span>${esc(({catalog:'Catalogue de langue natif',machine:'Traduction automatique',manual:'Saisie ou relecture manuelle'})[row.origin] || 'Non traduit')}</span></header>
        <div class="yuz-catalog-original"><strong>Original (${esc(row.source_lang)})</strong><pre>${esc(row.original)}</pre>${row.plural_original ? `<strong>Pluriel original</strong><pre>${esc(row.plural_original)}</pre>` : ''}</div>
        <div>${row.forms.map((text, i) => `<label>${row.plural_original ? 'Forme plurielle '+i : 'Traduction'}<textarea data-form="${i}" rows="3">${esc(text)}</textarea></label>`).join('')}</div>
        <div class="yuz-catalog-actions">
          <select data-state>${[[1,'Brouillon'],[2,'À relire'],[3,'Révisé'],[4,'Publié'],[5,'Archivé']].map(([value,label]) => `<option value="${value}" ${Number(row.status) === value ? 'selected' : ''}>${label}</option>`).join('')}</select>
          <button type="button" data-save>Enregistrer</button><button type="button" data-publish>Publier</button>
          <button type="button" data-approve>Approuver pour la mémoire</button>
          <span data-row-status>${row.last_error ? esc(row.last_error) : ''}</span>
        </div>
      </article>`).join('') : '<p>Aucune chaîne pour ce filtre. Lancez un scan ou visitez une page WordPress pour collecter ses chaînes Gettext.</p>';
    el('page').textContent = `Page ${state.page} / ${Math.max(1, Math.ceil(data.total / data.per_page))} — ${data.total} chaînes`;
    el('prev').disabled = state.page <= 1;
    el('next').disabled = state.page * data.per_page >= data.total;
    usage(data.usage);
  }
  async function load() {
    if (state.busy) return;
    const serial = ++state.request;
    lock(true); status('Chargement…');
    try {
      const data = await request('search', { q: el('q').value, domain: el('domain').value, status: el('filter').value, page: state.page });
      if (serial === state.request) { lock(false); render(data); status('Catalogue chargé. Les traductions automatiques restent à relire avant publication.'); }
    } catch (e) { lock(false); status(e.message); }
  }
  function canLeave() { return !state.dirty.size || window.confirm('Abandonner les modifications non enregistrées ?'); }
  let returnFocus = null;
  let workspace = null, visual = null, placeholder = null;
  const prefKey = 'yuz-workspace-v1:' + (cfg.userId || 'session');
  let prefs = {width: 1440, height: 850, split: 440};
  try {
    const saved = JSON.parse(localStorage.getItem(prefKey));
    for (const key of Object.keys(prefs)) if (Number.isFinite(saved?.[key])) prefs[key] = saved[key];
  } catch (_) { /* Storage may be disabled. Layout must still work. */ }
  const clamp = (value, min, max) => Math.max(min, Math.min(value, Math.max(min, max)));
  function sizeWorkspace() {
    if (!workspace) return;
    const width = clamp(prefs.width, Math.min(920, innerWidth - 24), innerWidth - 24);
    const height = clamp(prefs.height, Math.min(360, innerHeight - 24), innerHeight - 24);
    const split = clamp(prefs.split, 320, width - 420);
    workspace.style.setProperty('--yuz-work-width', width + 'px');
    workspace.style.setProperty('--yuz-work-height', height + 'px');
    workspace.style.setProperty('--yuz-work-left', split + 'px');
    const divider = workspace.querySelector('[data-split]');
    divider.setAttribute('aria-valuenow', Math.round(split));
    divider.setAttribute('aria-valuemax', Math.max(320, Math.round(width - 420)));
  }
  function saveLayout() {
    try { localStorage.setItem(prefKey, JSON.stringify(prefs)); } catch (_) {}
  }
  function createWorkspace() {
    workspace = document.createElement('dialog');
    workspace.id = 'yuz-workspace';
    workspace.className = 'yuz-workspace notranslate';
    workspace.setAttribute('translate', 'no');
    workspace.setAttribute('data-yuz', 'no-translate');
    workspace.setAttribute('aria-labelledby', 'yuz-work-title');
    workspace.innerHTML = `<header class="yuz-work-bar"><div><span class="yuz-work-brand">YUZ</span><strong id="yuz-work-title">Atelier de traduction</strong><span class="yuz-work-subtitle">Visuel + chaînes</span></div><button type="button" data-reset-layout>Réinitialiser la disposition</button></header><div class="yuz-work-panels"><div class="yuz-work-visual"></div><div data-split role="separator" tabindex="0" aria-label="Largeur de l’éditeur visuel, flèches gauche et droite" aria-orientation="vertical" aria-valuemin="320"></div></div><footer class="yuz-work-footer"><span>Vos saisies restent dans leur éditeur · Échap : revenir au visuel</span><button type="button" data-resize aria-label="Redimensionner l’atelier, utilisez les flèches" title="Glisser le coin ou utiliser les flèches">↘</button></footer>`;
    document.body.appendChild(workspace);
    workspace.querySelector('.yuz-work-panels').appendChild(root);
    workspace.addEventListener('cancel', e => { e.preventDefault(); close(); });
    workspace.querySelector('[data-reset-layout]').addEventListener('click', () => {
      prefs = {width: 1440, height: 850, split: 440}; sizeWorkspace(); saveLayout();
    });
    function resizeHandle(selector, corner) {
      const handle = workspace.querySelector(selector);
      let drag = null;
      handle.addEventListener('pointerdown', e => {
        if (e.button !== 0) return;
        const box = workspace.getBoundingClientRect();
        drag = {x:e.clientX, y:e.clientY, width:box.width, height:box.height, split:parseFloat(workspace.style.getPropertyValue('--yuz-work-left'))};
        handle.setPointerCapture(e.pointerId); e.preventDefault();
      });
      handle.addEventListener('pointermove', e => {
        if (!drag) return;
        if (corner) {
          prefs.width = clamp(drag.width + 2 * (e.clientX - drag.x), Math.min(920, innerWidth - 24), innerWidth - 24);
          prefs.height = clamp(drag.height + 2 * (e.clientY - drag.y), Math.min(360, innerHeight - 24), innerHeight - 24);
        } else prefs.split = clamp(drag.split + e.clientX - drag.x, 320, workspace.clientWidth - 420);
        sizeWorkspace();
      });
      for (const name of ['pointerup','pointercancel','lostpointercapture']) handle.addEventListener(name, () => { if (drag) { drag = null; saveLayout(); } });
      handle.addEventListener('keydown', e => {
        const amount = e.shiftKey ? 50 : 20;
        if (!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Home'].includes(e.key)) return;
        e.preventDefault(); e.stopPropagation();
        if (e.key === 'Home') prefs = {width:1440,height:850,split:440};
        else if (!corner && ['ArrowLeft','ArrowRight'].includes(e.key)) prefs.split = clamp(prefs.split + (e.key === 'ArrowLeft' ? -amount : amount), 320, workspace.clientWidth - 420);
        else if (corner) {
          if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') prefs.width = clamp(workspace.clientWidth + (e.key === 'ArrowLeft' ? -amount : amount), Math.min(920, innerWidth-24), innerWidth-24);
          else prefs.height = clamp(workspace.clientHeight + (e.key === 'ArrowUp' ? -amount : amount), Math.min(360, innerHeight-24), innerHeight-24);
        }
        sizeWorkspace(); saveLayout();
      });
    }
    resizeHandle('[data-split]', false); resizeHandle('[data-resize]', true);
    window.addEventListener('resize', sizeWorkspace);
    document.addEventListener('yuz:ui:unmount', () => {
      // The main editor was explicitly destroyed: do not resurrect its detached
      // DOM when closing Strings. Keep the independent catalog and its draft.
      placeholder?.remove(); placeholder = null; visual = null;
      workspace.classList.add('yuz-work-single');
    });
  }
  function open() {
    const wasHidden = root.classList.contains('hidden');
    if (!mount && wasHidden) returnFocus = document.activeElement;
    root.classList.remove('hidden');
    if (!mount && !workspace?.open) {
      if (!workspace) createWorkspace();
      visual = document.querySelector('#yuz-editor-container');
      const modal = visual?.querySelector('.yuz-modal');
      const hasVisual = !!modal && !!modal.getClientRects().length;
      workspace.classList.toggle('yuz-work-single', !hasVisual);
      if (hasVisual) {
        placeholder = document.createComment('YUZ visual editor original position');
        visual.before(placeholder);
        workspace.querySelector('.yuz-work-visual').appendChild(visual);
      } else visual = null;
      sizeWorkspace();
      workspace.showModal();
      root.focus({preventScroll:true});
    }
    if (!state.rows.length && !state.busy) load();
  }
  function close() {
    if (state.busy) { status('Une opération est en cours. Attendez sa réponse avant de fermer.'); return; }
    // Closing only hides the catalog: keep unsaved forms and dirty state.
    if (!mount && workspace?.open) {
      if (visual && placeholder?.parentNode) { placeholder.replaceWith(visual); placeholder = null; visual = null; }
      workspace.close();
    }
    root.classList.add('hidden');
    if (!mount && returnFocus?.isConnected) returnFocus.focus({preventScroll:true});
  }
  el('close').addEventListener('click', close);
  root.addEventListener('cancel', e => { e.preventDefault(); close(); });
  // Prevent the visual editor's document shortcuts from saving/closing the
  // wrong editor while the catalog owns keyboard focus. Keep native Tab trap.
  root.addEventListener('keydown', e => {
    if (mount) return;
    e.stopPropagation();
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
      e.preventDefault(); status('Utilisez le bouton Enregistrer de la chaîne à sauvegarder.');
    }
    if (e.key === 'Tab' && workspace?.classList.contains('yuz-work-single')) {
      const targets = Array.from(root.querySelectorAll('button,input,select,textarea,a[href],summary,[tabindex]'))
        .filter(node => !node.disabled && node.tabIndex >= 0 && node.getClientRects().length);
      const first = targets[0], last = targets[targets.length - 1];
      if (!first) { e.preventDefault(); root.focus(); }
      else if (e.shiftKey && (document.activeElement === first || document.activeElement === root)) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && (document.activeElement === last || document.activeElement === root)) { e.preventDefault(); first.focus(); }
    }
  });
  document.addEventListener('keydown', e => {
    if (!mount && workspace?.open && e.key === 'Escape') {
      e.preventDefault(); e.stopImmediatePropagation(); close();
    }
  }, true);
  el('search').addEventListener('click', () => { if (canLeave()) { state.page=1; load(); } });
  ['domain','filter'].forEach(name => el(name).addEventListener('change', () => { if (canLeave()) {state.page=1; load();} }));
  el('lang').addEventListener('change', () => {
    if (!canLeave()) { el('lang').value=state.lang; return; }
    state.lang=el('lang').value; state.page=1; load();
  });
  el('q').addEventListener('keydown', e => { if(e.key==='Enter') el('search').click(); });
  el('prev').addEventListener('click', () => { if(state.page>1 && canLeave()) {state.page--;load();} });
  el('next').addEventListener('click', () => { if(canLeave()) {state.page++;load();} });
  root.addEventListener('input', e => { const row=e.target.closest('[data-id]'); if(row && e.target.matches('[data-form],[data-state]')) state.dirty.add(row.dataset.id); });
  root.addEventListener('click', async e => {
    const action=e.target.closest('[data-save],[data-publish],[data-approve]');
    if(!action || state.busy) return;
    const row=action.closest('[data-id]'), id=row.dataset.id;
    const forms=Array.from(row.querySelectorAll('[data-form]'), field=>field.value);
    const approval=action.hasAttribute('data-approve');
    if(approval && !window.confirm('Confirmez-vous avoir vérifié le sens, les nombres et les variables de cette traduction ? Elle pourra servir de référence.')) return;
    const currentStatus=Number(row.querySelector('[data-state]').value);
    const nextStatus=action.hasAttribute('data-publish')?4:approval?(currentStatus===4?4:3):currentStatus;
    lock(true);
    try {
      await request('save',{id,forms:JSON.stringify(forms),status:nextStatus});
      if(approval) await request('approve',{id,forms:JSON.stringify(forms)});
      state.dirty.delete(id); row.querySelector('[data-state]').value=nextStatus;
      row.querySelector('[data-row-status]').textContent=approval?'Approbation humaine enregistrée.':nextStatus===4?'Publié.':'Enregistré.';
      status('Sauvegarde effectuée.');
    } catch(err) {status(err.message);} finally {lock(false);}
  });
  el('translate').addEventListener('click', async () => {
    if(state.busy || !canLeave()) return;
    const ids=Array.from(root.querySelectorAll('[data-check]:checked'), box=>Number(box.closest('[data-id]').dataset.id));
    if(!ids.length || ids.length>5) {status('Sélectionnez entre 1 et 5 chaînes.');return;}
    lock(true); status('Traduction en cours…');
    try {
      const data=await request('translate',{ids:JSON.stringify(ids)});
      lock(false); await load();
      status(`${data.translated} chaînes traduites à relire, ${data.skipped} déjà renseignées conservées.` + data.errors.map(e=>' #'+e.id+': '+e.message).join(''));
      usage(data.usage);
    } catch(e) {lock(false);status(e.message);}
  });
  el('scan').addEventListener('click', async () => {
    if(state.busy || !canLeave()) return;
    lock(true);
    try {
      let start=1, data;
      do {
        data=await request('scan',{start}); start=0;
        status(`Scan : ${data.scanned}/${data.total} fichiers, ${data.strings} appels Gettext détectés…`);
        await new Promise(resolve=>setTimeout(resolve,30));
      } while(!data.done);
      lock(false); state.page=1; await load();
      status(`Scan terminé : ${data.total} fichiers. Les doublons du catalogue ont été éliminés.`);
    } catch(e) {lock(false);status(e.message);}
  });
  el('source-save').addEventListener('click', async () => {
    if(state.busy) return;
    const domain=el('domain').value, source=el('source').value.trim();
    if(!domain || !source) { status('Choisissez un domaine et une locale source.'); return; }
    if(!window.confirm('Les traductions existantes seront conservées et doivent être revérifiées. Continuer ?')) return;
    lock(true);
    try { await request('source_language',{domain,source}); lock(false); await load(); }
    catch(e) { status(e.message); } finally { lock(false); }
  });
  el('glossary-save').addEventListener('click', async () => {
    if(state.busy) return;
    if(!el('glossary-approved').checked) { status('Une approbation humaine explicite est requise.'); return; }
    lock(true);
    try { const data=await request('glossary',{csv:el('glossary').value,approved:1}); status(data.imported+' termes approuvés importés.'); }
    catch(e) { status(e.message); } finally { lock(false); }
  });
  document.addEventListener('click', e => {if(e.target.closest('[data-yuz-open-strings]')) {e.preventDefault();open();}});
  document.addEventListener('keydown', e => {if(e.altKey && e.key.toLowerCase()==='g') {e.preventDefault();open();}});
  window.addEventListener('beforeunload', e => {if(state.dirty.size) {e.preventDefault();e.returnValue='';}});
  const params=new URLSearchParams(window.location.search);
  if(mount || params.get('yuz-panel')==='strings' || params.get('page')==='yuz-string-translation-editor') open();
})();
