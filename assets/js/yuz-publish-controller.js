/**
 * ===========================================================
 * YUZ Translation — Publish Controller v9.3+ (hardened)
 * ===========================================================
 * Flux de publication automatique, manuelle et dockée
 * Compatible avec : éditeur, inspecteur DOM, gettext
 * ===========================================================
 */
console.log("🗞️ yuz-publish-controller.js chargé avec succès ✊", new Date());

(function () {
    if (typeof window === 'undefined') {
        return;
    }

    // Si déjà initialisé, on ne rebinde rien (évite les collisions)
    if (window.YUZ_PUBLISH && window.YUZ_PUBLISH.__v93hardened) {
        try {
            console.info('[YUZ_PUBLISH] Déjà initialisé, on saute le bootstrap dupliqué.');
        } catch (_) { }
        return;
    }

    const settings = window.yuzTraSettings || {};
    const ajaxUrl = settings.ajax_url;
    const nonceStore = window.yuzNonce || {};

    if (typeof window.__yuzInitPipelineProbe !== 'function') {
        window.__yuzInitPipelineProbe = function initPipelineProbe(originLabel = 'pc') {
            const search = window.location.search || '';
            const forcedOff = /\byuzprobe=0\b/.test(search);
            const forcedOn = /\byuzprobe=1\b/.test(search);
            const telemetryFlag = window.yuzTraSettings?.telemetry?.pipeline_probe;
            const enabled = !forcedOff && (forcedOn || telemetryFlag === true || window.YUZ_DEBUG === true);
            const endpoint = window.yuzTraSettings?.ajax_url || window.ajaxurl || '/wp-admin/admin-ajax.php';

            if (!enabled || !endpoint) {
                const noop = () => { };
                noop.enabled = false;
                return noop;
            }

            const limitArray = (input, max = 20) => {
                if (!Array.isArray(input)) return input;
                if (input.length <= max) return input;
                const subset = input.slice(0, max);
                subset.push(`+${input.length - max}`);
                return subset;
            };

            const sanitizeValue = (value, depth = 0) => {
                if (value == null) return value;
                if (Array.isArray(value)) {
                    return limitArray(value.map(entry => sanitizeValue(entry, depth + 1)));
                }
                if (typeof value === 'object') {
                    if (depth >= 2) return '[Object]';
                    const out = {};
                    Object.entries(value).slice(0, 8).forEach(([key, val]) => {
                        out[key] = sanitizeValue(val, depth + 1);
                    });
                    return out;
                }
                if (typeof value === 'string' && value.length > 400) {
                    return value.slice(0, 400) + '…';
                }
                return value;
            };

            const alias = window.YUZ_PARAMS?.alias || window.yuzTraSettings?.current_user || '';

            const send = (event, detail = {}) => {
                try {
                    const context = sanitizeValue(detail) || {};
                    context.agent = originLabel;
                    context.alias = context.alias || alias || null;
                    context.url = context.url || window.location.href.split('#')[0];
                    context.ts = new Date().toISOString();
                    if (context.ids) {
                        const ids = Array.isArray(context.ids) ? context.ids : [context.ids];
                        context.ids = limitArray(ids);
                    }
                    const payload = new URLSearchParams({
                        action: 'yuz_dom_log',
                        event: `pipeline:${event}`,
                        context: JSON.stringify(context)
                    });
                    const data = payload.toString();
                    if (navigator.sendBeacon) {
                        navigator.sendBeacon(endpoint, new Blob([data], { type: 'application/x-www-form-urlencoded' }));
                        return;
                    }
                    fetch(endpoint, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: data,
                        credentials: 'include'
                    }).catch(() => { });
                } catch (probeErr) {
                    try { console.debug('[YUZ][PIPELINE][probe_failed]', probeErr); } catch (_) { }
                }
            };
            send.enabled = true;
            return send;
        };
    }

    const pipelineProbe = (() => {
        if (typeof window.YUZ_PIPE_PROBE === 'function') {
            return window.YUZ_PIPE_PROBE;
        }
        const factory = window.__yuzInitPipelineProbe;
        if (typeof factory === 'function') {
            const fn = factory('publish-controller');
            window.YUZ_PIPE_PROBE = fn;
            return fn;
        }
        const noop = () => { };
        noop.enabled = false;
        return noop;
    })();

    const fromBucket = (bucket) => {
        if (typeof window.yuzGetNonce === 'function') {
            return window.yuzGetNonce(bucket) || '';
        }
        return nonceStore[bucket] || '';
    };

    const defaultNonce = fromBucket('yuz_tra_nonce');

    const ACTION_NONCES = {
        yuz_publish_translations: fromBucket('yuz_con_nonce'),
        yuz_mass_publish: fromBucket('yuz_con_nonce'),
        yuz_get_pending_translations: fromBucket('yuz_int_nonce'),
        yuz_get_publish_review: fromBucket('yuz_int_nonce')
    };

    const NONCE_KEYS = ['nonce', '_ajax_nonce', 'security'];
    // Augmenté pour permettre la publication de gros lots (114 détectés)
    const MAX_PUBLICATION_IDS = 10000;
    const FLOOD_WINDOW_MS = 0;

    const getActionNonce = action => ACTION_NONCES[action] || defaultNonce;

    const applyNonce = (payload, value) => {
        if (!value) {
            return;
        }
        NONCE_KEYS.forEach(k => payload.set(k, value));
    };

    const restRootSource = typeof settings.rest_root === 'string' && settings.rest_root
        ? settings.rest_root
        : (window?.wpApiSettings?.root || '');
    const restRoot = restRootSource ? restRootSource.replace(/\/$/, '') : '';
    const restNonce = typeof settings.rest_nonce === 'string' && settings.rest_nonce
        ? settings.rest_nonce
        : (window?.wpApiSettings?.nonce || '');
    const AUTH_ERROR_MESSAGE = 'Connexion requise. Merci de vous reconnecter.';

    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const normalizeIds = input => {
        if (!input) {
            return [];
        }
        const arr = Array.isArray(input) ? input : [input];
        const normalized = [];
        const seen = new Set();
        arr.forEach(value => {
            const num = Number(value);
            if (!Number.isInteger(num) || num <= 0 || seen.has(num)) {
                return;
            }
            seen.add(num);
            normalized.push(num);
        });
        return normalized;
    };

    const normalizeItems = (items = [], ids = []) => {
        const coerceId = (value) => {
            const num = Number(value);
            return Number.isInteger(num) && num > 0 ? num : null;
        };

        if (!Array.isArray(items) || !items.length) {
            // Fallback : on reconstruit sur base des IDs
            return ids.map(id => ({
                id,
                original: `#${id}`,
                translated: ''
            }));
        }

        return items
            .map(item => {
                const id = coerceId(item.id ?? item.ID ?? item.translation_id ?? item.translationId);
                return {
                    id,
                    original: item.original ?? item.original_text ?? '',
                    translated: item.translated ?? item.translated_text ?? ''
                };
            })
            .filter(entry => entry.id);
    };

    // -------------------------------------------------------
    // 🔐 Authentification REST (ne doit jamais bloquer le reste)
    // -------------------------------------------------------

    const authState = {
        hydrated: false,
        ok: false,
        inflight: null
    };

    const restMeEndpoint = restRoot ? `${restRoot}/wp/v2/users/me` : '';

    async function verifyAuthentication() {
        // Si WP fournit apiRequest, on l'utilise
        if (typeof window?.wp?.apiRequest === 'function') {
            await window.wp.apiRequest({ path: '/wp/v2/users/me' });
            return true;
        }

        // Sinon, si pas de root REST, on fait confiance (fallback)
        if (!restMeEndpoint) {
            return true;
        }

        const headers = {};
        if (restNonce) {
            headers['X-WP-Nonce'] = restNonce;
        }

        const response = await fetch(restMeEndpoint, {
            credentials: 'include',
            headers
        });

        if (!response.ok) {
            const error = new Error('not_logged_in');
            error.code = 'not_logged_in';
            error.status = response.status;
            throw error;
        }
        return true;
    }

    async function ensureAuthenticated() {
        if (authState.hydrated && authState.ok) {
            return true;
        }
        if (authState.inflight) {
            return authState.inflight;
        }

        authState.inflight = (async () => {
            await verifyAuthentication();
            authState.ok = true;
            authState.hydrated = true;
            return true;
        })().catch(error => {
            authState.ok = false;
            authState.hydrated = false;
            throw error;
        }).finally(() => {
            authState.inflight = null;
        });

        return authState.inflight;
    }

    // =======================================================
    // Contrôleur principal YUZ_PUBLISH
    // =======================================================

    window.YUZ_PUBLISH = (() => {
        if (!ajaxUrl) {
            return {
                state: { mode: 'manual', pending: [] },
                toast: () => { },
                publishBatch: async () => ({ success: false }),
                showDockReview: () => { },
                collapsePanel: () => { },
                __v93hardened: true
            };
        }

        const SELECTORS = {
            reviewPanel: '.yuz-review-dock',
            publishBtn: '#yuzPublishBtn',
            toast: '.yuz-toast'
        };

        const DOCK_HOSTS = ['#yuzInspectorDock', '#yuz-editor-container', '#yuz-te-advanced'];

        // -------------------------------------------------------
        // 🧠 État
        // -------------------------------------------------------

        const STATE = {
            mode: 'manual',
            pending: [],
            active: false
        };

        let lastPublishAt = 0;
        let publishInFlight = false;

        // -------------------------------------------------------
        // 🔔 Toast (ARIA-friendly)
        // -------------------------------------------------------

        function toast(message, isError = false) {
            let el = document.querySelector(SELECTORS.toast);
            if (!el) {
                el = document.createElement('div');
                el.className = 'yuz-toast';
                document.body.appendChild(el);
            }
            el.textContent = message;
            el.classList.toggle('error', isError);
            el.classList.add('show');
            setTimeout(() => el.classList.remove('show'), 3200);
        }

        // -------------------------------------------------------
        // 📡 AJAX Helper (nonce multi-compat)
        // -------------------------------------------------------

        async function ajaxCall(action, data = {}) {
            await ensureAuthenticated();
            const payload = new URLSearchParams({ action });
            applyNonce(payload, getActionNonce(action));
            Object.entries(data).forEach(([k, v]) => payload.set(k, v));
            const res = await fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: payload
            });
            return res.json();
        }

        // -------------------------------------------------------
        // 🚀 Publication (massive / unitaire) + anti-flood sain
        // -------------------------------------------------------

        async function publishBatch(ids = [], options = {}) {
            const normalized = normalizeIds(ids);
            console.debug('[YUZ_PUBLISH] publishBatch called', {
                ids: normalized,
                options,
                now: Date.now(),
                lastPublishAt
            });
            pipelineProbe('publish_attempt', {
                count: normalized.length,
                ids: normalized,
                action: options.action || 'yuz_publish_translations'
            });

            if (!normalized.length) {
                toast('Aucune traduction valide à publier.', true);
                pipelineProbe('publish_skip', { reason: 'invalid_ids', rawCount: Array.isArray(ids) ? ids.length : 0 });
                return { success: false, code: 'invalid_ids' };
            }

            if (normalized.length > MAX_PUBLICATION_IDS) {
                toast('Trop de traductions en une seule fois. Merci de réduire la sélection.', true);
                pipelineProbe('publish_skip', { reason: 'too_many_ids', count: normalized.length });
                return { success: false, code: 'too_many_ids' };
            }

            // Si une publication est déjà en cours, on ne lance pas un second batch
            if (publishInFlight) {
                toast('Publication déjà en cours. Merci de patienter…', true);
                pipelineProbe('publish_skip', { reason: 'in_flight', count: normalized.length });
                return { success: false, code: 'in_flight' };
            }

            try {
                await ensureAuthenticated();
            } catch (authError) {
                toast(AUTH_ERROR_MESSAGE, true);
                pipelineProbe('publish_skip', { reason: 'not_logged_in', message: authError?.message || '' });
                try {
                    console.warn('[YUZ_PUBLISH] Authentication check failed', {
                        message: authError?.message,
                        code: authError?.code,
                        status: authError?.status
                    });
                } catch (_) { }
                return { success: false, error: authError, code: 'not_logged_in' };
            }

            const nowTs = Date.now();
            if (publishInFlight) {
                pipelineProbe('publish_skip', {
                    reason: 'in_flight',
                    since: nowTs - lastPublishAt,
                    count: normalized.length
                });
                return { success: false, code: 'in_flight' };
            }

            publishInFlight = true;
            lastPublishAt = nowTs;

            try {
                const action = options.action || 'yuz_publish_translations';
                const payload = new URLSearchParams({ action });
                applyNonce(payload, getActionNonce(action));

                if (action === 'yuz_mass_publish') {
                    payload.set('ids', normalized.join(','));
                } else {
                    payload.set('ids', JSON.stringify(normalized));
                }
                pipelineProbe('publish_dispatch', { action, count: normalized.length });

                const response = await fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: payload
                });

                const json = await response.json();

                if (json.success) {
                    const count = normalized.length;
                    toast(`✅ ${count} traduction${count > 1 ? 's' : ''} publiée${count > 1 ? 's' : ''}`);
                    pipelineProbe('publish_success', { action, count, ids: normalized });
                    document.dispatchEvent(new CustomEvent('yuz:afterMassPublish', {
                        detail: { ids: normalized }
                    }));
                } else {
                    toast('Erreur : ' + (json.data?.message || 'échec AJAX'), true);
                    pipelineProbe('publish_error', {
                        action,
                        message: json.data?.message || 'ajax_failed',
                        code: json.data?.code || ''
                    });
                }

                return json;
            } catch (error) {
                lastPublishAt = 0;
                toast('Erreur réseau : ' + error.message, true);
                pipelineProbe('publish_error', {
                    action: options.action || 'yuz_publish_translations',
                    message: error?.message || 'network_error'
                });
                return { success: false, error };
            } finally {
                publishInFlight = false;
            }
        }

        // -------------------------------------------------------
        // 🧱 Construction des lignes
        // -------------------------------------------------------

        function buildRowsMarkup(items = []) {
            return items.map(item => `
        <label class="yuz-review-row">
          <input type="checkbox" data-id="${escapeHtml(item.id)}" checked>
          <strong>${escapeHtml(item.original)}</strong> → ${item.translated ? escapeHtml(item.translated) : '<em>(vide)</em>'}
        </label>
      `).join('');
        }

        // -------------------------------------------------------
        // 🪟 Dock de review (avec toolbar guidance)
        // -------------------------------------------------------

        function pickDockHost() {
            for (const selector of DOCK_HOSTS) {
                const node = document.querySelector(selector);
                if (node) {
                    return node;
                }
            }
            return document.body;
        }

        function showDockReview(ids = [], items = []) {
            const normalizedIds = normalizeIds(ids);
            STATE.pending = normalizedIds;

            if (!normalizedIds.length) {
                pipelineProbe('dock_skip', { reason: 'empty_ids' });
                return;
            }

            try {
                console.info('[YUZ_PUBLISH] showDockReview called', {
                    idsCount: normalizedIds.length,
                    sample: normalizedIds.slice(0, 5)
                });
            } catch (_) { }

            const normalizedItems = normalizeItems(items, normalizedIds);
            const oldPanel = document.querySelector(SELECTORS.reviewPanel);
            if (oldPanel) {
                oldPanel.remove();
            }

            const panel = document.createElement('div');
            panel.className = 'yuz-review-dock';
            const total = normalizedIds.length;

            panel.innerHTML = `
        <h3>Validation automatique</h3>
        <p>${total} chaîne${total > 1 ? 's' : ''} détectée${total > 1 ? 's' : ''}</p>
        <div class="yuz-review-list">
          ${buildRowsMarkup(normalizedItems)}
        </div>
        <div class="yuz-review-actions">
          <button class="yuz-btn yuz-btn-blue" data-action="review-all">Publier tout</button>
          <button class="yuz-btn yuz-btn-green" data-action="review-selected">Publier la sélection</button>
          <button class="yuz-btn yuz-btn-red" data-action="review-close">Fermer</button>
        </div>
      `;

            const dock = pickDockHost();
            dock.appendChild(panel);

            try {
                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } catch (_) { }

            panel.querySelector('[data-action="review-all"]').addEventListener('click', () => {
                publishBatch(normalizedIds);
            });

            panel.querySelector('[data-action="review-selected"]').addEventListener('click', () => {
                const selected = [...panel.querySelectorAll('input:checked')].map(input => input.dataset.id);
                publishBatch(selected);
            });

            panel.querySelector('[data-action="review-close"]').addEventListener('click', collapsePanel);

            STATE.active = true;
            pipelineProbe('dock_render', {
                count: normalizedIds.length,
                ids: normalizedIds,
                sample: normalizedItems.slice(0, 5).map(item => ({
                    id: item.id,
                    preview: typeof item.translated === 'string' ? item.translated.slice(0, 80) : ''
                }))
            });
        }

        // -------------------------------------------------------
        // 📉 Repli & mini-bar
        // -------------------------------------------------------

        function collapsePanel() {
            const panel = document.querySelector(SELECTORS.reviewPanel);
            if (!panel) return;

            panel.classList.add('collapsed');
            const mini = document.createElement('div');
            mini.className = 'yuz-review-mini';
            mini.innerHTML = `<span>✓ Traductions publiées</span><button title="Réouvrir">↺</button>`;
            document.body.appendChild(mini);

            mini.querySelector('button').addEventListener('click', () => {
                mini.remove();
                panel.classList.remove('collapsed');
                try {
                    panel.scrollIntoView({ behavior: 'smooth' });
                } catch (_) { }
            });

            setTimeout(() => mini.remove(), 10000);
            pipelineProbe('dock_collapse', { pending: STATE.pending.length, active: STATE.active });
        }

        // -------------------------------------------------------
        // 🎛️ Événements de contexte (ne touchent pas aux saves du TE)
        // -------------------------------------------------------

        document.addEventListener('yuz:afterMassPublish', collapsePanel);

        // Butée Auto-Translate → Review Dock
        document.addEventListener('yuz:autoTranslateDone', e => {
            STATE.mode = 'auto';
            const detail = (e && e.detail) || {};
            const preparedItems = normalizeItems(detail.items || [], []);
            const preferredIds = Array.isArray(detail.ids) && detail.ids.length
                ? detail.ids
                : preparedItems.map(item => item.id);
            const ids = normalizeIds(preferredIds);
            const items = preparedItems.length ? preparedItems : normalizeItems([], ids);

            STATE.pending = ids;
            pipelineProbe('auto_event_received', {
                ids,
                count: ids.length,
                origin: detail.origin || 'unknown',
                isBatch: detail.isBatch === true
            });

            if (!ids.length) {
                // On ne bloque rien, on signale juste à l'utilisateur
                toast('Aucune traduction automatique classique à publier.', true);
                pipelineProbe('auto_event_empty', { reason: 'no_ids' });
                return;
            }

            showDockReview(ids, items);
        });

        // Edition manuelle (unitaire)
        document.addEventListener('yuz:manualEdit', e => {
            STATE.mode = 'manual';
            const id = e && e.detail && e.detail.id;
            if (id) {
                pipelineProbe('manual_publish', { id });
                publishBatch([id]);
            }
        });

        // Inspection DOM (dock)
        document.addEventListener('yuz:domInspect', e => {
            STATE.mode = 'dock';
            const ids = normalizeIds(e && e.detail && e.detail.ids);
            if (ids.length) {
                pipelineProbe('dom_inspect', { ids, count: ids.length });
                showDockReview(ids, normalizeItems(e.detail?.items || [], ids));
            }
        });

        // Mode gettext (publication directe)
        document.addEventListener('yuz:gettextMode', e => {
            STATE.mode = 'gettext';
            const ids = normalizeIds(e && e.detail && e.detail.ids);
            if (ids.length) {
                pipelineProbe('gettext_publish', { ids, count: ids.length });
                publishBatch(ids);
            }
        });

        const api = {
            state: STATE,
            toast,
            publishBatch,
            showDockReview,
            collapsePanel,
            __v93hardened: true
        };

        return api;
    })();

    // =======================================================
    // Fallback modal controller (legacy UI conservée)
    // =======================================================
    (() => {
        if (!ajaxUrl) return;

        const toast = (msg, err = false) => {
            if (window.YUZ_PUBLISH) {
                window.YUZ_PUBLISH.toast(msg, err);
            } else {
                alert(msg);
            }
        };

        const ajax = async (action, data = {}) => {
            try {
                await ensureAuthenticated();
            } catch (error) {
                toast(AUTH_ERROR_MESSAGE, true);
                throw error;
            }
            const payload = new URLSearchParams({ action });
            applyNonce(payload, getActionNonce(action));
            Object.entries(data).forEach(([k, v]) => payload.set(k, v));
            return fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: payload
            }).then(r => r.json());
        };

        // -------------------------------------------------------
        // 🧰 Fallback Modal (hérité, conservé et homogénéisé)
        // -------------------------------------------------------

        const buildList = rows => {
            const list = document.createElement('div');
            list.className = 'yuz-review-list';
            rows.forEach(r => {
                const row = document.createElement('label');
                row.className = 'yuz-review-row';
                row.innerHTML = `<input type="checkbox" data-id="${escapeHtml(r.id)}" checked> <strong>${escapeHtml(r.original_text)}</strong> → ${escapeHtml(r.translated_text || '')}`;
                list.appendChild(row);
            });
            return list;
        };

        const showReviewModal = rows => {
            const modal = document.createElement('div');
            modal.className = 'yuz-review-modal';
            modal.innerHTML = `
        <div class="yuz-review-box">
          <h2>Validation automatique</h2>
          <p>${rows.length} chaînes détectées</p>
        </div>`;
            const box = modal.querySelector('.yuz-review-box');
            const list = buildList(rows);
            box.appendChild(list);
            const actions = document.createElement('div');
            actions.className = 'yuz-review-actions';
            actions.innerHTML = `
        <button id="yuzReviewAll">Publier tout</button>
        <button id="yuzReviewSel">Publier la sélection</button>
        <button id="yuzReviewClose">Fermer</button>`;
            actions.querySelector('#yuzReviewAll').onclick = async () => {
                await window.YUZ_PUBLISH.publishBatch(rows.map(r => r.id));
                modal.remove();
            };
            actions.querySelector('#yuzReviewSel').onclick = async () => {
                const ids = [...list.querySelectorAll('input:checked')].map(i => i.dataset.id);
                await window.YUZ_PUBLISH.publishBatch(ids);
                modal.remove();
            };
            actions.querySelector('#yuzReviewClose').onclick = () => modal.remove();
            box.appendChild(actions);
            document.body.appendChild(modal);
        };

        const reviewPanel = async ids => {
            const normalized = normalizeIds(ids);
            if (!normalized.length) {
                return toast('Aucune chaîne à réviser.', true);
            }
            const res = await ajax('yuz_get_pending_translations', { ids: normalized.join(',') });
            if (!res.success || !Array.isArray(res.data) || !res.data.length) {
                return toast('Aucune chaîne à réviser.', true);
            }
            showReviewModal(res.data);
        };

        window.YUZ_CONTEXT = window.YUZ_CONTEXT || {};

        // Ici, on ne fait QUE mémoriser la dernière série d’IDs,
        // on ne touche pas au workflow de sauvegarde du TE.
        document.addEventListener('yuz:autoTranslateDone', e => {
            const detail = (e && e.detail) || {};
            window.YUZ_CONTEXT.mode = 'auto_translate';
            window.YUZ_CONTEXT.lastAutoSet = normalizeIds(detail.ids || []);
        });

        document.addEventListener('click', async e => {
            const btn = e.target.closest('#yuzPublishBtn');
            if (!btn) return;

            const pending = (window.YUZ_CONTEXT.lastAutoSet && window.YUZ_CONTEXT.lastAutoSet.length)
                ? window.YUZ_CONTEXT.lastAutoSet
                : (window.YUZ_PUBLISH && Array.isArray(window.YUZ_PUBLISH.state.pending)
                    ? window.YUZ_PUBLISH.state.pending
                    : []);

            if (pending.length) {
                await reviewPanel(pending);
            } else {
                toast('Aucune traduction automatique à publier.', true);
            }
        });
    })();
})();
