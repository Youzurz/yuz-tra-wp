var yuz_release_console={log(){},debug(){},info(){},warn(){},error(){},groupCollapsed(){},groupEnd(){},table(){}};


/**
 * assets/js/yuz-ai-translation.js
 *
 * Onglet IA — orchestrateur côté client (isolation stricte).
 *
 * Discipline:
 *  - ACT-12: Onglet AI isolé — lit UNIQUEMENT `window.yuzAI` (pas de globals partagés).
 *  - ACT-13: Pattern batch job → { job_id } puis polling `yuz_ai_job_status`.
 *  - ACT-14: Gestion 429 (backoff exponentiel + jitter), quotas/coûts affichés.
 *  - ACT-15: Confidentialité — filtrage client PII/secret avant envoi (opt-in par défaut).
 */

(function (window, document) {
  'use strict';

  /* --------------------------------- Guards --------------------------------- */
  const y = window.yuzAI || null;
  if (!y || !y.ajax_url) {
    return;
  }
  // Ne s’exécute que sur l’onglet AI (ou si un conteneur AI est présent dans le DOM)
  const isAITab = (y.current_tab || '').toString() === 'ai-translation' || !!document.getElementById('yuz_ai_panel');

  if (!isAITab) {
    return;
  }

  /* --------------------------------- Logger --------------------------------- */
  const LOG = {
    critical: '🟥 [CRITICAL]',
    warning:  '🟨 [WARNING]',
    success:  '🟩 [SUCCESS]',
    info:     '🟦 [INFO]',
  };
  function log(level, message, context) {
    const ts = new Date().toISOString();
    const line = `${LOG[level] || ''} ${message} @ ${ts}`;
    // eslint-disable-next-line no-console
    (level === 'critical' ? yuz_release_console.error : level === 'warning' ? yuz_release_console.warn : yuz_release_console.log)(
      context ? `${line} | ${safeStringify(context)}` : line
    );
  }
  function safeStringify(x) { try { return JSON.stringify(x); } catch { return '[unserializable]'; } }

  /* --------------------------- Net banner (offline) -------------------------- */
  function ensureNetBanner() {
    if (document.getElementById('yuz-ai-net-banner')) return;
    const bar = document.createElement('div');
    bar.id = 'yuz-ai-net-banner';
    bar.style.cssText = 'position:fixed;left:50%;transform:translateX(-50%);bottom:12px;z-index:999999;padding:8px 12px;border-radius:8px;display:none;font:12px/1.3 system-ui, -apple-system, Segoe UI, Roboto, Arial;background:#222;color:#fff;box-shadow:0 4px 12px rgba(0,0,0,.25)';
    bar.textContent = y.messages?.offline_banner || 'Vous semblez hors-ligne.';
    document.body.appendChild(bar);
  }
  function showNet(status) {
    ensureNetBanner();
    const bar = document.getElementById('yuz-ai-net-banner');
    if (!bar) return;
    if (status === 'offline') {
      bar.style.background = '#b02a37';
      bar.textContent = y.messages?.offline_banner || 'Vous semblez hors-ligne.';
      bar.style.display = 'block';
    } else {
      bar.style.background = '#198754';
      bar.textContent = y.messages?.online_banner || 'Connexion rétablie.';
      bar.style.display = 'block';
      setTimeout(() => { bar.style.display = 'none'; }, 1800);
    }
  }
  window.addEventListener('offline', () => showNet('offline'));
  window.addEventListener('online',  () => showNet('online'));

  /* ------------------------------ Cost banner -------------------------------- */
  function ensureCostBanner() {
    if (document.getElementById('yuz-ai-cost-banner')) return;
    const wrap = document.createElement('div');
    wrap.id = 'yuz-ai-cost-banner';
    wrap.style.cssText = 'position:sticky;top:12px;margin:10px 0;padding:10px 12px;border-radius:10px;background:#f8f9fa;border:1px solid #e9ecef;color:#222;display:flex;gap:16px;align-items:center;font:13px system-ui,-apple-system,Segoe UI,Roboto,Arial';
    wrap.innerHTML = `
      <strong>AI Usage</strong>
      <span id="yuz-ai-quota"></span>
      <span id="yuz-ai-cost"></span>
      <label style="margin-left:auto;display:flex;gap:6px;align-items:center;cursor:pointer;">
        <input type="checkbox" id="yuz-ai-protect-pii" checked>
        <span>Mask PII before sending</span>
      </label>
    `;
    // Best-effort placement: before main panel if present, else top of body
    const anchor = document.getElementById('yuz_ai_panel') || document.body.firstElementChild;
    (anchor?.parentNode || document.body).insertBefore(wrap, anchor || null);
  }
  function updateCostBanner({ quota_used, quota_remaining, est_cost, currency }) {
    ensureCostBanner();
    const q = document.getElementById('yuz-ai-quota');
    const c = document.getElementById('yuz-ai-cost');
    if (q) {
      const used = typeof quota_used === 'number' ? quota_used : null;
      const left = typeof quota_remaining === 'number' ? quota_remaining : null;
      q.textContent = (used !== null || left !== null) ? `Quota: ${used ?? '?'} used / ${left ?? '?'} left` : '';
    }
    if (c) {
      if (typeof est_cost === 'number') {
        const cur = currency || 'USD';
        c.textContent = `Est. cost: ${est_cost.toFixed(4)} ${cur}`;
        // Persist daily running total
        try {
          const key = `yuz_ai_cost_${new Date().toISOString().slice(0,10)}`;
          const prev = Number(localStorage.getItem(key) || '0');
          localStorage.setItem(key, (prev + Math.max(0, est_cost)).toString());
        } catch (_) {}
      }
    }
  }

  /* ------------------------------ Nonce helper ------------------------------- */
  function nonceFor(action) {
    if (!action) return '';
    const n = y.nonces || {};
    const val = n[action];
    if (!val) log('warning', `[AI] Nonce introuvable pour ${action}`);
    return val || '';
  }

  /* ------------------------------ Fetch helpers ------------------------------ */
  function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
  function jitter(base) { // +/- 20%
    const delta = base * 0.2;
    return base + (Math.random() * 2 * delta - delta);
  }

  /**
   * POST x-www-form-urlencoded avec retries/backoff + 429.
   * Retourne l’objet JSON WordPress tel quel { success, data, ... }.
   */
  async function postAjax(action, payload, { retries = 2, backoffMs = 800, timeoutMs = 12000 } = {}) {
    const body = new URLSearchParams();
    body.set('action', action);
    body.set('nonce', nonceFor(action));
    Object.entries(payload || {}).forEach(([k, v]) => {
      if (v === undefined || v === null) return;
      body.set(k, (typeof v === 'object') ? JSON.stringify(v) : String(v));
    });

    let attempt = 0;
    // eslint-disable-next-line no-constant-condition
    while (true) {
      const controller = new AbortController();
      const to = setTimeout(() => controller.abort(), timeoutMs);
      try {
        const res = await fetch(y.ajax_url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body,
          signal: controller.signal,
          credentials: 'same-origin'
        });
        clearTimeout(to);

        if (res.status === 401 || res.status === 403) {
          const txt = await res.text().catch(() => '');
          log('critical', `[AI] Permissions insuffisantes (${res.status})`, txt.slice(0, 200));
          throw new Error(`Permission denied (HTTP ${res.status})`);
        }

        if (res.status === 429) {
          // Rate-limited → backoff agressif + jitter
          const wait = Math.min(15000, jitter(backoffMs * Math.pow(2, attempt)));
          log('warning', `[AI] HTTP 429 rate-limited, retry in ~${Math.round(wait)}ms`);
          await sleep(wait);
          attempt++;
          continue;
        }

        if (!res.ok) {
          const txt = await res.text().catch(() => '');
          if (attempt < retries) {
            const wait = Math.min(8000, jitter(backoffMs * Math.pow(2, attempt)));
            log('warning', `[AI] HTTP ${res.status}, retry in ~${Math.round(wait)}ms`, { body: payload });
            await sleep(wait);
            attempt++;
            continue;
          }
          throw new Error(`HTTP ${res.status} ${res.statusText}: ${txt.slice(0, 200)}`);
        }

        const json = await res.json().catch(() => ({}));
        return json;

      } catch (err) {
        clearTimeout(to);
        if (err.name === 'AbortError') {
          showNet('offline');
        }
        if (attempt < retries) {
          const wait = Math.min(8000, jitter(backoffMs * Math.pow(2, attempt)));
          log('warning', `[AI] Réseau/timeout, retry in ~${Math.round(wait)}ms`, { error: String(err) });
          await sleep(wait);
          attempt++;
          continue;
        }
        throw err;
      }
    }
  }

  /* ----------------------------- PII / Secrets --------------------------------
     Filtre simple côté client. Remplace par des tokens neutres avant envoi.
     Conçu pour limiter les fuites accidentelles (best-effort).
  ----------------------------------------------------------------------------- */
  function sanitizePII(text) {
    if (!text || typeof text !== 'string') return { text: '', redactions: 0 };

    let redactions = 0;
    const rules = [
      // Emails
      { re: /\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/gi, token: '[EMAIL]' },
      // Téléphones (>=7 chiffres avec séparateurs usuels)
      { re: /(?:(?:\+?\d{1,3}[\s.\-()]*)?(?:\(?\d{2,4}\)?[\s.\-]*){2,}\d{2,4})/g, token: '[PHONE]' },
      // IPv4
      { re: /\b(?:(?:25[0-5]|2[0-4]\d|[01]?\d?\d)(?:\.|$)){4}\b/g, token: '[IP]' },
      // CB (13-16 chiffres, permissif)
      { re: /\b(?:\d[ -]?){13,16}\b/g, token: '[CARD]' },
      // IBAN (très simple)
      { re: /\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/g, token: '[IBAN]' },
      // N° de doc / identifiants simples (heuristique: 9+ alnum)
      { re: /\b[A-Z0-9]{9,}\b/gi, token: '[ID]' },
    ];

    let out = text;
    rules.forEach(({ re, token }) => {
      out = out.replace(re, (m) => { redactions++; return token; });
    });
    return { text: out, redactions };
  }

  function isPIIProtectionEnabled() {
    ensureCostBanner();
    const el = document.getElementById('yuz-ai-protect-pii');
    return !el || !!el.checked; // par défaut ON
  }

  /* ---------------------------- Toast util (UX) ------------------------------ */
  function toast(msg, type = 'info', ms = 2200) {
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;right:16px;bottom:16px;padding:10px 14px;border-radius:8px;color:#fff;z-index:999999;font:13px system-ui,-apple-system,Segoe UI,Roboto,Arial;box-shadow:0 6px 16px rgba(0,0,0,.2)';
    t.style.background = type === 'success' ? '#198754' : type === 'error' ? '#dc3545' : type === 'warning' ? '#ffc107' : '#0d6efd';
    if (type === 'warning') t.style.color = '#222';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity .25s'; setTimeout(() => t.remove(), 250); }, ms);
  }

  /* ----------------------------- UI references ------------------------------- */
  const UI = {
    btnTest:      () => document.getElementById('yuz_ai_test_btn'),
    btnBatch:     () => document.getElementById('yuz_ai_batch_btn'),
    txtInput:     () => document.getElementById('yuz_ai_text_input'),     // textarea (1 par ligne)
    fileGlossary: () => document.getElementById('yuz_ai_glossary_file'),  // input[type=file]
    btnGlossary:  () => document.getElementById('yuz_ai_upload_glossary_btn'),
    srcLang:      () => document.getElementById('yuz_ai_lang_source'),
    dstLang:      () => document.getElementById('yuz_ai_lang_target'),
    status:       () => document.getElementById('yuz_ai_status'),
    progress:     () => document.getElementById('yuz_ai_progress'),
  };

  function setStatus(html) {
    const el = UI.status();
    if (!el) return;
    el.innerHTML = html;
  }
  function setProgress(pct) {
    const el = UI.progress();
    if (!el) return;
    const v = Math.max(0, Math.min(100, Math.round(pct)));
    el.style.width = v + '%';
    el.ariaValueNow = String(v);
    el.textContent = v + '%';
  }

  /* ------------------------------ AI actions -------------------------------- */
  async function testConnection() {
    const action = 'yuz_ai_test_connection';
    const res = await postAjax(action, {}, { retries: 1 });
    if (res?.success) {
      toast('AI connection OK', 'success');
      log('success', '[AI] Test connection OK', res.data);
      return true;
    }
    toast(res?.data?.message || 'AI connection failed', 'error');
    log('warning', '[AI] Test connection failed', res);
    return false;
  }

  async function uploadGlossary(file) {
    if (!file) return;
    const action = 'yuz_ai_glossary_upload';
    const nonce = nonceFor(action);
    if (!nonce) { toast('Glossary upload: nonce missing', 'error'); return; }

    const fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', nonce);
    fd.append('file', file);

    const res = await fetch(y.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(r => r.json())
      .catch(e => ({ success: false, data: { message: e.message } }));

    if (res.success) {
      toast('Glossary uploaded', 'success');
      log('success', '[AI] Glossary uploaded', res.data);
    } else {
      toast(res.data?.message || 'Glossary upload failed', 'error');
      log('warning', '[AI] Glossary upload failed', res);
    }
  }

  /**
   * Lance une traduction batch et retourne { job_id, ... }.
   * @param {string[]} texts
   * @param {string} source
   * @param {string} target
   */
  async function startBatchTranslate(texts, source, target) {
    const action = 'yuz_ai_batch_translate';
    const cleanTexts = texts
      .map(t => (t || '').trim())
      .filter(Boolean)
      .slice(0, 1000); // limite prudente

    if (cleanTexts.length === 0) {
      toast('No input provided', 'warning');
      return null;
    }

    let payloadTexts = cleanTexts;
    let redactions = 0;
    if (isPIIProtectionEnabled()) {
      payloadTexts = cleanTexts.map(t => {
        const s = sanitizePII(t);
        redactions += s.redactions;
        return s.text;
      });
      if (redactions > 0) toast(`PII masked (${redactions})`, 'info', 1600);
    }

    const res = await postAjax(action, {
      source_lang: source || 'auto',
      target_lang: target || '',
      texts: payloadTexts
    }, { retries: 2, backoffMs: 800 });

    if (!res?.success) {
      toast(res?.data?.message || 'Batch start failed', 'error');
      log('critical', '[AI] Batch start failed', res);
      return null;
    }

    const d = res.data || {};
    updateCostBanner({ est_cost: d.est_cost, currency: d.currency, quota_used: d.quota_used, quota_remaining: d.quota_remaining });
    log('success', '[AI] Batch started', d);
    return { job_id: d.job_id, meta: d };
  }

  /**
   * Polling du job côté serveur.
   * @param {string} jobId
   */
  async function pollJob(jobId) {
    const action = 'yuz_ai_job_status';
    let delay = 900; // ms
    let cycles = 0;

    setStatus('Job queued…');

    // eslint-disable-next-line no-constant-condition
    while (true) {
      cycles++;
      const res = await postAjax(action, { job_id: jobId }, { retries: 0, backoffMs: delay });

      if (!res || res.success === false) {
        // Erreur applicative — on attend un peu et on retente un petit nombre de fois
        const msg = res?.data?.message || 'Job status error';
        log('warning', '[AI] Job status error', msg);
        if (cycles < 5) { await sleep(jitter(Math.min(3000, delay * 1.6))); continue; }
        throw new Error(msg);
      }

      const d = res.data || {};
      // d.status: queued | running | completed | failed
      // d.progress: 0..100
      // d.cost_inc, d.quota_used, d.quota_remaining (optionnels)
      if (typeof d.quota_used === 'number' || typeof d.quota_remaining === 'number' || typeof d.cost_inc === 'number') {
        updateCostBanner({ est_cost: d.cost_inc || 0, currency: d.currency, quota_used: d.quota_used, quota_remaining: d.quota_remaining });
      }

      if (typeof d.progress === 'number') setProgress(d.progress);
      if (d.message) setStatus(String(d.message));

      if (d.status === 'completed') {
        setProgress(100);
        setStatus('Done.');
        log('success', '[AI] Job completed', d);
        return d;
      }
      if (d.status === 'failed') {
        setStatus('Failed.');
        log('critical', '[AI] Job failed', d);
        throw new Error(d.error || 'AI job failed');
      }

      // queued / running → backoff progressif
      delay = Math.min(8000, Math.round(delay * 1.4));
      await sleep(jitter(delay));
    }
  }

  /* --------------------------------- Boot ----------------------------------- */
  document.addEventListener('DOMContentLoaded', () => {
    ensureCostBanner();
    setProgress(0);
    setStatus('Idle.');

    // Test de connexion
    const test = UI.btnTest();
    if (test && !test.dataset.yuzBound) {
      test.dataset.yuzBound = '1';
      test.addEventListener('click', async (e) => {
        e.preventDefault();
        try { await testConnection(); } catch (err) { toast(String(err.message || err), 'error'); }
      });
    }

    // Upload glossary
    const upBtn = UI.btnGlossary();
    const fileIn = UI.fileGlossary();
    if (upBtn && fileIn && !upBtn.dataset.yuzBound) {
      upBtn.dataset.yuzBound = '1';
      upBtn.addEventListener('click', async (e) => {
        e.preventDefault();
        if (!fileIn.files || !fileIn.files[0]) { toast('Choose a glossary file', 'warning'); return; }
        try { await uploadGlossary(fileIn.files[0]); } catch (err) { toast(String(err.message || err), 'error'); }
      });
    }

    // Batch translate
    const batch = UI.btnBatch();
    if (batch && !batch.dataset.yuzBound) {
      batch.dataset.yuzBound = '1';
      batch.addEventListener('click', async (e) => {
        e.preventDefault();
        try {
          const src = UI.srcLang()?.value || 'auto';
          const dst = UI.dstLang()?.value || '';
          const raw = UI.txtInput()?.value || '';
          const lines = raw.split(/\r?\n/).map(s => s.trim()).filter(Boolean);

          if (!dst) { toast('Choose a target language', 'warning'); return; }
          if (lines.length === 0) { toast('Enter some text to translate', 'warning'); return; }

          setProgress(0);
          setStatus('Starting batch…');

          const start = await startBatchTranslate(lines, src, dst);
          if (!start || !start.job_id) return;

          const out = await pollJob(start.job_id);
          // Affichage best-effort du résultat (si serveur renvoie `result`)
          if (out?.result && Array.isArray(out.result.translations)) {
            // Si un conteneur de sortie existe, on le renseigne
            const outEl = document.getElementById('yuz_ai_output');
            if (outEl) {
              outEl.value = out.result.translations.join('\n');
            }
          }
          toast('Batch translation completed', 'success');
        } catch (err) {
          log('critical', '[AI] Batch flow error', String(err?.message || err));
          toast('AI error: ' + (err?.message || 'Unknown error'), 'error', 3000);
        }
      });
    }

    log('success', '[AI] Module initialisé');
  });

})(window, document);

