
/**
 * YUZ-TRA – Gutenberg: panneau "Translate Page" + "Save & Translate"
 * Robuste: gère permalink indispo, brouillon non-enregistré, modules optionnels.
 */
(function (wp) {
  // Déps WordPress (toutes optionnelles => on sort si manquantes)
  const plugins = (wp && wp.plugins) || {};
  const editPost = (wp && wp.editPost) || {};
  const components = (wp && wp.components) || {};
  const data = (wp && wp.data) || {};
  const i18n = (wp && wp.i18n) || {};
  const url = (wp && wp.url) || {};
  const element = (wp && wp.element) || {};

  const registerPlugin = plugins.registerPlugin;
  const PluginDocumentSettingPanel = editPost.PluginDocumentSettingPanel;
  const { Button, Notice, Spinner } = components;
  const { select, dispatch, subscribe } = data;
  const { __ } = i18n;
  const { addQueryArgs, removeQueryArgs } = url;
  const el = element.createElement;

  if (!registerPlugin || !PluginDocumentSettingPanel || !Button || !el) return;

  // Helpers
  const getPermalink = () => {
    try {
      const s = select && select('core/editor');
      if (s && typeof s.getPermalink === 'function') {
        return s.getPermalink() || '';
      }
    } catch (e) {}
    return '';
  };

  const isDirty = () => {
    try {
      const s = select && select('core/editor');
      return !!(s && typeof s.isEditedPostDirty === 'function' && s.isEditedPostDirty());
    } catch (e) { return false; }
  };

  const isSaving = () => {
    try {
      const s = select && select('core/editor');
      // Compat anciennes versions
      const a = (s && typeof s.isSavingPost === 'function' && s.isSavingPost());
      const b = (s && typeof s.isAutosavingPost === 'function' && s.isAutosavingPost && s.isAutosavingPost());
      return !!(a || b);
    } catch (e) { return false; }
  };

  const buildEditorHref = (baseUrl) => {
    if (!baseUrl) return '';
    try {
      // retire les params langue résiduels puis ajoute notre flag
      const cleaned = removeQueryArgs
        ? removeQueryArgs(baseUrl, ['yuz-target-lang', 'target_lang'])
        : baseUrl.replace(/([?&])(yuz-target-lang|target_lang)=[^&#]*&?/g, '$1').replace(/[?&]$/, '');
      return addQueryArgs
        ? addQueryArgs(cleaned, { 'yuz-edit-translation': 1 })
        : cleaned + (cleaned.indexOf('?') === -1 ? '?' : '&') + 'yuz-edit-translation=1';
    } catch (e) {
      return baseUrl + (baseUrl.indexOf('?') === -1 ? '?' : '&') + 'yuz-edit-translation=1';
    }
  };

  const openInNewTab = (href) => {
    try { window.open(href, '_blank', 'noopener'); } catch (e) { window.location.href = href; }
  };

  // Composant
  const Panel = () => {
    // Source d’URL (ordre de priorité)
    const permalink = getPermalink();
    const fallback = (window.yuzGB && window.yuzGB.url_to_load) || window.location.href;
    const base = permalink || fallback;
    const href = buildEditorHref(base);

    // États dynamiques (calculés à l’instant T)
    const dirty = isDirty();
    const saving = isSaving();

    // Actions
    const onTranslate = (e) => {
      // lien "Translate Page" : ouvre dans un nouvel onglet, pas de perte de saisie
      if (!href) return;
      e && e.preventDefault && e.preventDefault();
      openInNewTab(href);
    };

    const onSaveAndTranslate = async (e) => {
      e && e.preventDefault && e.preventDefault();
      if (!href) return;

      // Si rien à sauver, on y va direct
      if (!dirty && !saving) {
        openInNewTab(href);
        return;
      }

      // Déclenche sauvegarde
      try {
        const d = dispatch && dispatch('core/editor');
        if (d && typeof d.savePost === 'function') d.savePost();
      } catch (e) {}

      // Attend la fin de sauvegarde puis ouvre
      let unsub = null;
      let opened = false;
      const checkAndOpen = () => {
        if (!opened && !isSaving() && !isDirty()) {
          opened = true;
          unsub && unsub();
          openInNewTab(href);
        }
      };
      try {
        if (subscribe) {
          unsub = subscribe(checkAndOpen);
        }
      } catch (e) {}
      // filet de sécurité si subscribe absent
      setTimeout(checkAndOpen, 1500);
      setTimeout(checkAndOpen, 3000);
    };

    // UI
    const children = [];

    // Notice: permalink pas encore dispo
    if (!permalink) {
      children.push(
        el(Notice, { status: 'warning', isDismissible: false, key: 'permalink-missing' },
          __('Permalink not available yet. Save the draft to enable Translate Page.', 'yuz_tra')
        )
      );
    }

    // Notice: modifications non enregistrées
    if (dirty) {
      children.push(
        el(Notice, { status: 'info', isDismissible: false, key: 'dirty-info' },
          __('You have unsaved changes. Use "Save & Translate" to avoid losing them.', 'yuz_tra')
        )
      );
    }

    // Boutons d’action
    children.push(
      el('div', { key: 'buttons', style: { display: 'flex', gap: '8px', alignItems: 'center' } },
        // Save & Translate (prioritaire si brouillon modifié)
        el(Button, {
          isPrimary: true,
          onClick: onSaveAndTranslate,
          disabled: !href,
        }, dirty ? __('Save & Translate', 'yuz_tra') : __('Translate Page', 'yuz_tra')),
        // Translate direct (toujours dispo, nouvel onglet)
        el(Button, {
          isSecondary: true,
          href: href || undefined,
          target: '_blank',
          rel: 'noopener',
          onClick: href ? onTranslate : undefined,
          disabled: !href
        }, __('Translate Page (new tab)', 'yuz_tra')),
        // Spinner si WP sauvegarde
        saving && el(Spinner, { key: 'saving' })
      )
    );

    return el(PluginDocumentSettingPanel, { name: 'yuz-translate', title: 'YUZ-TRA' }, ...children);
  };

  // Enregistrement du plugin
  registerPlugin('yuz-translate-panel', { render: Panel });
})(window.wp || {});

