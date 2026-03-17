(function (w, $) {
  'use strict';
  const AO = w.yuzAO || {};
  const { ajax_url, nonces = {}, addons = [], capabilities = {} } = AO;

  function refreshList() {
    return $.post(ajax_url, { action: 'yuz_add_list', _ajax_nonce: nonces.yuz_add_list });
  }

  function toggleAddon(slug, enable) {
    return $.post(ajax_url, {
      action: 'yuz_add_toggle',
      addon: slug,
      enable: enable ? 1 : 0,
      _ajax_nonce: nonces.yuz_add_toggle
    });
  }

  function installAddon(slug) {
    return $.post(ajax_url, { action: 'yuz_add_install', addon: slug, _ajax_nonce: nonces.yuz_add_install });
  }

  function updateAddon(slug) {
    return $.post(ajax_url, { action: 'yuz_add_update', addon: slug, _ajax_nonce: nonces.yuz_add_update });
  }

  function deleteAddon(slug) {
    return $.post(ajax_url, { action: 'yuz_add_delete', addon: slug, _ajax_nonce: nonces.yuz_add_delete });
  }

  // === Bind UI
  $(document).on('click', '.yuz-addon-refresh', function (e) {
    e.preventDefault();
    refreshList().done(() => location.reload());
  });

  $(document).on('click', '.yuz-addon-toggle', function (e) {
    e.preventDefault();
    const $btn = $(this);
    const slug = $btn.data('addon');
    const enable = !$btn.data('active');
    $btn.prop('disabled', true);
    toggleAddon(slug, enable)
      .done((r) => { if (r && r.success) location.reload(); else $btn.prop('disabled', false); })
      .fail(() => $btn.prop('disabled', false));
  });

  $(document).on('click', '.yuz-addon-install', function (e) {
    e.preventDefault();
    const slug = $(this).data('addon');
    installAddon(slug).done(() => location.reload());
  });

  $(document).on('click', '.yuz-addon-update', function (e) {
    e.preventDefault();
    const slug = $(this).data('addon');
    updateAddon(slug).done(() => location.reload());
  });

  $(document).on('click', '.yuz-addon-delete', function (e) {
    e.preventDefault();
    const slug = $(this).data('addon');
    if (!confirm('Delete this add-on?')) return;
    deleteAddon(slug).done(() => location.reload());
  });

})(window, jQuery);

