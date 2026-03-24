
(function (w, $) {
  'use strict';
  const LC = w.yuzLC || {};
  const { ajax_url, nonces = {}, license = {}, capabilities = {} } = LC;

  function getStatus() {
    return $.post(ajax_url, { action: 'yuz_lic_get_status', _ajax_nonce: nonces.yuz_lic_get_status });
  }
  function ping() {
    return $.post(ajax_url, { action: 'yuz_lic_ping', _ajax_nonce: nonces.yuz_lic_ping });
  }
  function activate(key) {
    return $.post(ajax_url, { action: 'yuz_lic_activate', key, _ajax_nonce: nonces.yuz_lic_activate });
  }
  function deactivate() {
    return $.post(ajax_url, { action: 'yuz_lic_deactivate', _ajax_nonce: nonces.yuz_lic_deactivate });
  }
  function updateKey(key) {
    return $.post(ajax_url, { action: 'yuz_lic_update_key', key, _ajax_nonce: nonces.yuz_lic_update_key });
  }
  function deleteKey() {
    return $.post(ajax_url, { action: 'yuz_lic_delete', _ajax_nonce: nonces.yuz_lic_delete });
  }

  // === Bind UI (exemples)
  $(document).on('click', '.yuz-lic-ping', function (e) {
    e.preventDefault();
    ping().done(/* update UI */);
  });

  $(document).on('submit', '#yuz-lic-form', function (e) {
    e.preventDefault();
    const key = $.trim($(this).find('input[name="license_key"]').val() || '');
    activate(key).done(() => location.reload());
  });

  $(document).on('click', '.yuz-lic-deactivate', function (e) {
    e.preventDefault();
    deactivate().done(() => location.reload());
  });

  $(document).on('click', '.yuz-lic-update-key', function (e) {
    e.preventDefault();
    const key = $.trim($('input[name="license_key"]').val() || '');
    updateKey(key).done(() => location.reload());
  });

  $(document).on('click', '.yuz-lic-delete', function (e) {
    e.preventDefault();
    if (!confirm('Delete license key?')) return;
    deleteKey().done(() => location.reload());
  });

})(window, jQuery);

