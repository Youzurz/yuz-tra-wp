
(function($){
  function hasEnv() {
    var okUrl = (typeof yuzAdv !== 'undefined' && yuzAdv && yuzAdv.ajaxurl) || (typeof ajaxurl !== 'undefined' && ajaxurl);
    var okNonce = (typeof yuzAdv !== 'undefined' && yuzAdv && yuzAdv.nonce) || ($('#yuz_tra_maintenance_nonce').val() || '').length > 0;
    return !!(okUrl && okNonce);
  }
  function t(key, fallback) {
    return (yuzAdv && yuzAdv.i18n && yuzAdv.i18n[key]) || fallback;
  }

  $(document).on('click', '#yuz-run-diagnostics', function(e){
    e.preventDefault();
    if (!hasEnv()) return alert('Missing AJAX config');
    var $btn = $(this);
    var $pre = $('#yuz-diagnostic-report');
    var oldText = $btn.text();

    $btn.prop('disabled', true).text(t('running','Running...'));
    $pre.text(t('running','Running...'));

    $.post((yuzAdv && yuzAdv.ajaxurl) || ajaxurl, { action: 'yuz_run_diagnostics', nonce: yuzAdv.nonce })
      .done(function(res){
        if (res && res.success && res.report) {
          $pre.text(res.report);
        } else if (res && res.report) {
          $pre.text(res.report);
        } else {
          $pre.text(t('failed','Failed'));
        }
      })
      .fail(function(){
        $pre.text(t('failed','Failed'));
      })
      .always(function(){
        $btn.prop('disabled', false).text(oldText);
      });
  });

  $(document).on('click', '#yuz-clear-cache', function(e){
    e.preventDefault();
    if (!hasEnv()) return alert('Missing AJAX config');
    var $btn = $(this);
    var $span = $('#yuz-clear-cache-result');
    var oldText = $btn.text();

    $btn.prop('disabled', true).text(t('running','Running...'));
    $span.text(t('running','Running...'));

    $.post((yuzAdv && yuzAdv.ajaxurl) || ajaxurl, { action: 'yuz_clear_cache', nonce: yuzAdv.nonce })
      .done(function(res){
        if (res && res.success && res.message) {
          $span.text(res.message);
        } else if (res && res.message) {
          $span.text(res.message);
        } else {
          $span.text(t('failed','Failed'));
        }
      })
      .fail(function(){
        $span.text(t('failed','Failed'));
      })
      .always(function(){
        $btn.prop('disabled', false).text(oldText);
      });
  });

  // ---- Maintenance (translations table) ----
  function maintNonce() {
    var v = $('#yuz_tra_maintenance_nonce').val();
    return v || (hasEnv() ? yuzAdv.nonce : '');
  }
  function renderResult(obj) {
    var $pre = $('#yuz-maintenance-result');
    if (!$pre.length) return;
    try {
      $pre.text(JSON.stringify(obj, null, 2));
    } catch(_) {
      $pre.text(String(obj));
    }
  }
  function runMaint(task) {
    if (!hasEnv()) return alert('Missing AJAX config');
    var nonce = maintNonce();
    var $pre = $('#yuz-maintenance-result');
    $pre.text(t('running','Running...'));
    return $.post((yuzAdv && yuzAdv.ajaxurl) || ajaxurl, { action: 'yuz_tra_maintenance', task: task, nonce: nonce })
      .done(function(res){ renderResult(res); })
      .fail(function(xhr){ renderResult({success:false, status:xhr.status, text:xhr.responseText}); });
  }
  $(document).on('click', '#yuz-maint-metrics', function(e){ e.preventDefault(); runMaint('metrics'); });
  $(document).on('click', '#yuz-maint-backup', function(e){ e.preventDefault(); runMaint('backup'); });
  $(document).on('click', '#yuz-maint-cleanup', function(e){ e.preventDefault(); if (confirm('Run: delete empties, normalize locales, dedupe (backup created). Continue?')) runMaint('cleanup_all'); });
  $(document).on('click', '#yuz-maint-delete-empties', function(e){ e.preventDefault(); runMaint('delete_empties'); });
  $(document).on('click', '#yuz-maint-normalize-locales', function(e){ e.preventDefault(); runMaint('normalize_locales'); });
  $(document).on('click', '#yuz-maint-dedupe', function(e){ e.preventDefault(); runMaint('dedupe'); });
})(jQuery);
