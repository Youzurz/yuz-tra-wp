

(()=>{
  const CFG = window.yuzStrings || {};
  const ajaxurl = CFG.ajax_url || window.ajaxurl || '/wp-admin/admin-ajax.php';
  const nonce = CFG.nonce || '';
  if (!nonce) {
    return;
  }

  const request = (action, payload = {}) => {
    const body = new URLSearchParams(Object.assign({ action, nonce }, payload));
    return fetch(ajaxurl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      credentials: 'same-origin',
      body: body.toString()
    }).then((res) => res.json());
  };

  const createDock = () => {
    let dock = document.getElementById('yuz-dock');
    if (dock) return dock;
    const tpl = document.createElement('div');
    tpl.innerHTML = `
      <aside id="yuz-dock" class="hidden" aria-label="YUZ Strings">
        <header class="yuz-dock-hd">
          <nav class="yuz-tabs" role="tablist">
            <button type="button" role="tab" data-tab="gettext" class="is-active">Gettext</button>
            <button type="button" role="tab" data-tab="slugs">Slugs</button>
            <button type="button" role="tab" data-tab="emails">Emails</button>
          </nav>
          <button type="button" class="yuz-close" aria-label="Close">×</button>
        </header>
        <section class="yuz-dock-body">
          <div class="yuz-tab" data-panel="gettext"></div>
          <div class="yuz-tab hidden" data-panel="slugs"></div>
          <div class="yuz-tab hidden" data-panel="emails"></div>
        </section>
        <footer class="yuz-dock-ft">
          <div class="yuz-status" aria-live="polite"></div>
          <div class="yuz-actions">
            <button type="button" data-action="save">Save</button>
            <button type="button" data-action="publish">Publish</button>
          </div>
        </footer>
      </aside>`;
    document.body.appendChild(tpl.firstElementChild);
    return document.getElementById('yuz-dock');
  };

  const dock = createDock();
  if (!dock) return;

  const state = {
    active: 'gettext',
    mods: { gettext: new Map(), slugs: new Map(), emails: new Map() },
    page: { gettext: 1, slugs: 1, emails: 1 },
    perPage: 30,
    lang: CFG.defaultLang || CFG.sourceLang || ''
  };

  const statusEl = dock.querySelector('.yuz-status');

  const setStatus = (msg) => {
    if (statusEl) statusEl.textContent = msg || '';
  };

  const switchTab = (tab) => {
    if (!tab || tab === state.active) return;
    state.active = tab;
    dock.querySelectorAll('.yuz-tabs [data-tab]').forEach((btn) => {
      btn.classList.toggle('is-active', btn.dataset.tab === tab);
    });
    dock.querySelectorAll('.yuz-tab').forEach((panel) => {
      panel.classList.toggle('hidden', panel.dataset.panel !== tab);
    });
    loadActive();
  };

  const renderGettext = (rows) => {
    if (!rows.length) return '<p>No gettext entry for this filter.</p>';
    return `<table class="yuz-tbl" role="grid">
      <thead><tr><th>Domain</th><th>Original</th><th>Translation</th></tr></thead>
      <tbody>
        ${rows.map((row) => `
          <tr data-id="${row.id}" data-lang="${row.lang}" data-domain="${row.domain}" data-context="${row.context}" data-status="${row.status}" data-origin="${row.origin}">
            <td>${escapeHtml(row.domain)}</td>
            <td class="yuz-mono">${escapeHtml(row.original)}</td>
            <td><textarea data-edit rows="2">${escapeHtml(row.translated || '')}</textarea></td>
          </tr>`).join('')}
      </tbody>
    </table>`;
  };

  const renderSlugs = (rows) => {
    if (!rows.length) return '<p>No slugs available.</p>';
    return `<table class="yuz-tbl" role="grid">
      <thead><tr><th>Object</th><th>Type</th><th>Slug</th></tr></thead>
      <tbody>
        ${rows.map((row) => `
          <tr data-key="${row.object_type}:${row.object_id}:${row.lang}" data-post-type="${row.post_type}" data-status="${row.status}">
            <td>#${row.object_id}</td>
            <td>${escapeHtml(row.post_type || row.object_type)}</td>
            <td><input type="text" data-edit value="${escapeAttribute(row.slug || '')}" /></td>
          </tr>`).join('')}
      </tbody>
    </table>`;
  };

  const renderEmails = (rows) => {
    if (!rows.length) return '<p>No email templates found.</p>';
    return `<table class="yuz-tbl" role="grid">
      <thead><tr><th>Template</th><th>Translation</th></tr></thead>
      <tbody>
        ${rows.map((row) => `
          <tr data-key="${row.ekey}:${row.lang}" data-source="${escapeAttribute(row.source || '')}" data-status="${row.status}">
            <td class="yuz-mono">${escapeHtml(row.ekey)}</td>
            <td><textarea data-edit rows="3">${escapeHtml(row.translated || '')}</textarea></td>
          </tr>`).join('')}
      </tbody>
    </table>`;
  };

  const loadActive = () => {
    const panel = dock.querySelector(`.yuz-tab[data-panel="${state.active}"]`);
    if (!panel) return;
    panel.innerHTML = '<p>Loading…</p>';
    const page = state.page[state.active] || 1;
    const payload = { lang: state.lang, page, per_page: state.perPage };
    const action = state.active === 'gettext'
      ? 'yuz_gt_search'
      : state.active === 'slugs'
        ? 'yuz_slugs_search'
        : 'yuz_eml_search';
    request(action, payload).then((res) => {
      if (!res || !res.success && !res.ok) {
        panel.innerHTML = '<p>Error loading data.</p>';
        return;
      }
      const data = res.data || res;
      const rows = data.rows || [];
      panel.dataset.total = data.total || rows.length || 0;
      panel.innerHTML = state.active === 'gettext'
        ? renderGettext(rows)
        : state.active === 'slugs'
          ? renderSlugs(rows)
          : renderEmails(rows);
      renderPager(panel, parseInt(panel.dataset.total, 10) || rows.length || 0);
      setStatus(`Loaded ${rows.length} / ${(panel.dataset.total || 0)} rows`);
    }).catch(() => {
      panel.innerHTML = '<p>Error loading data.</p>';
    });
  };

  const renderPager = (panel, total) => {
    const pages = Math.max(1, Math.ceil(total / state.perPage));
    const current = state.page[state.active] || 1;
    const existing = panel.querySelector('.yuz-pager');
    if (existing) existing.remove();
    if (pages <= 1) return;
    const nav = document.createElement('div');
    nav.className = 'yuz-pager';
    nav.innerHTML = `
      <button type="button" data-nav="prev" ${current <= 1 ? 'disabled' : ''}>Prev</button>
      <span>Page ${current} of ${pages}</span>
      <button type="button" data-nav="next" ${current >= pages ? 'disabled' : ''}>Next</button>`;
    panel.appendChild(nav);
    panel.dataset.pages = pages;
  };

  const openDock = (tab = 'gettext') => {
    dock.classList.remove('hidden');
    dock.setAttribute('data-open', '1');
    switchTab(tab);
  };

  const closeDock = () => {
    dock.classList.add('hidden');
    dock.removeAttribute('data-open');
  };

  dock.querySelector('.yuz-close').addEventListener('click', closeDock);
  dock.querySelectorAll('.yuz-tabs [data-tab]').forEach((btn) => {
    btn.addEventListener('click', () => switchTab(btn.dataset.tab));
  });

  dock.addEventListener('input', (event) => {
    const target = event.target;
    if (!target.matches('[data-edit]')) return;
    const tr = target.closest('tr');
    if (!tr) return;
    if (state.active === 'gettext') {
      const id = tr.dataset.id;
      const payload = {
        id: parseInt(id, 10),
        domain: tr.dataset.domain || '',
        context: tr.dataset.context || '',
        original: (tr.querySelector('td:nth-child(2)') || {}).textContent || '',
        lang: tr.dataset.lang || state.lang,
        translated: target.value,
        status: parseInt(tr.dataset.status || '0', 10) || 2,
        origin: parseInt(tr.dataset.origin || '0', 10) || 0,
      };
      state.mods.gettext.set(id, payload);
    } else if (state.active === 'slugs') {
      const [type, objectId, lang] = (tr.dataset.key || '').split(':');
      state.mods.slugs.set(tr.dataset.key, {
        object_type: type || 'post',
        object_id: parseInt(objectId || '0', 10),
        lang: lang || state.lang,
        slug: target.value,
        post_type: tr.dataset.postType || '',
        status: parseInt(tr.dataset.status || '0', 10) || 2,
      });
    } else {
      const [ekey, lang] = (tr.dataset.key || '').split(':');
      state.mods.emails.set(tr.dataset.key, {
        ekey: ekey || '',
        lang: lang || state.lang,
        translated: target.value,
        status: parseInt(tr.dataset.status || '0', 10) || 2,
        source: tr.dataset.source || '',
      });
    }
  });

  const flushMods = (panel) => {
    if (state.mods[panel]) state.mods[panel].clear();
  };

  dock.querySelector('[data-action="save"]').addEventListener('click', () => submitActive(false));
  dock.querySelector('[data-action="publish"]').addEventListener('click', () => submitActive(true));

  const submitActive = (publish) => {
    const panel = state.active;
    const items = Array.from(state.mods[panel].values()).map((item) => Object.assign({}, item, { status: publish ? 3 : (item.status || 2) }));
    if (!items.length) {
      setStatus('Nothing to save.');
      return;
    }
    const action = panel === 'gettext' ? 'yuz_gt_save' : panel === 'slugs' ? 'yuz_slugs_save' : 'yuz_eml_save';
    request(action, { items: JSON.stringify(items) }).then((res) => {
      if (res && (res.success || res.ok)) {
        flushMods(panel);
        setStatus('Saved.');
        loadActive();
      } else {
        setStatus('Save failed.');
      }
    }).catch(() => setStatus('Save failed.'));
  };

  document.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-yuz-open-strings]');
    if (btn) {
      event.preventDefault();
      openDock(btn.dataset.tab || 'gettext');
    }
  });

  dock.addEventListener('click', (event) => {
    const nav = event.target.closest('[data-nav]');
    if (!nav) return;
    event.preventDefault();
    const panel = dock.querySelector(`.yuz-tab[data-panel="${state.active}"]`);
    const totalPages = panel ? parseInt(panel.dataset.pages || '1', 10) : 1;
    if (nav.dataset.nav === 'prev') {
      state.page[state.active] = Math.max(1, (state.page[state.active] || 1) - 1);
    } else {
      state.page[state.active] = Math.min(totalPages || 1, (state.page[state.active] || 1) + 1);
    }
    loadActive();
  });

  document.addEventListener('keydown', (event) => {
    if (event.altKey && event.key.toLowerCase() === 'g') {
      event.preventDefault();
      if (dock.classList.contains('hidden')) {
        openDock('gettext');
      } else {
        closeDock();
      }
    }
  });

  const urlPanel = new URLSearchParams(window.location.search).get('yuz-panel');
  if (urlPanel === 'strings') {
    openDock('gettext');
  }

  function escapeHtml(str){
    return (str == null ? '' : String(str)).replace(/[&<>\"]/g, (m) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));
  }
  function escapeAttribute(str){
    return (str == null ? '' : String(str)).replace(/["'<>&]/g, (m) => ({'"':'&quot;',"'":'&#39;','<':'&lt;','>':'&gt;','&':'&amp;'}[m]));
  }
})();

