
/*! YUZ String Translation Editor — UMD (zero‑deps)
 *  Works in old browsers (loaded with nomodule). Uses optional jQuery if present.
 */
(function (root, factory) {
  if (typeof define === 'function' && define.amd) {
    define([], factory);
  } else if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.YUZStringEditor = factory();
  }
}(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

var log=function(){};
  var Y = (typeof window !== 'undefined' && window.yuzTraSettings) ? window.yuzTraSettings : {
    ajax_url: (typeof ajaxurl !== 'undefined' ? ajaxurl : ''),
    nonces: {},
  };

  function ensureRoot() {
    var wrap = document.querySelector('#wpbody-content .wrap') || document.body;
    var root = document.getElementById('yuz-string-editor-root');
    if (!root) {
      root = document.createElement('div');
      root.id = 'yuz-string-editor-root';
      root.style.marginTop = '12px';
      wrap.appendChild(root);
    }
    return root;
  }

  function styleOnce() {
    if (document.getElementById('yuz-string-editor-style')) return;
    var css = ''
      + '#yuz-string-editor-root .yuz-card{background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;box-shadow:0 1px 1px rgba(0,0,0,.04)}'
      + '#yuz-string-editor-root .yuz-title{font-size:16px;margin:0 0 12px;font-weight:600}'
      + '#yuz-string-editor-root .yuz-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px}'
      + '#yuz-string-editor-root input[type="text"]{min-width:260px}'
      + '#yuz-string-editor-root .yuz-badge{display:inline-block;background:#f0f6fc;border:1px solid #d0e3f0;color:#1d2327;border-radius:999px;padding:2px 8px;font-size:12px}'
      + '#yuz-string-editor-root .yuz-log{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;background:#f6f7f7;border:1px solid #ddd;border-radius:6px;padding:8px;max-height:220px;overflow:auto}'
      + '#yuz-string-editor-root button.button{height:auto;line-height:24px;padding:4px 10px}'
    ;
    var tag = document.createElement('style');
    tag.id = 'yuz-string-editor-style';
    tag.textContent = css;
    document.head.appendChild(tag);
  }

  function render() {
    var root = ensureRoot();
    styleOnce();
    root.innerHTML = ''
      + '<div class="yuz-card">'
      + '  <p class="yuz-title">String Translation — Editor (UMD)</p>'
      + '  <div class="yuz-row">'
      + '    <span class="yuz-badge">Mode: UMD</span>'
      + '    <span class="yuz-badge">AJAX: ' + (Y.ajax_url ? 'ready' : 'not set') + '</span>'
      + '  </div>'
      + '  <div class="yuz-row">'
      + '    <label for="yuz-ed-url"><strong>URL to scan</strong></label>'
      + '    <input id="yuz-ed-url" type="text" class="regular-text" placeholder="https://example.com/page" value="' + (Y.url_to_load||'') + '"/>'
      + '    <button id="yuz-ed-scan" class="button button-primary">Scan page strings</button>'
      + '    <button id="yuz-ed-ping" class="button">Ping AJAX</button>'
      + '  </div>'
      + '  <div id="yuz-ed-output" class="yuz-log" aria-live="polite"></div>'
      + '</div>'
    ;

    var out = root.querySelector('#yuz-ed-output');
    var $ = function (sel) { return root.querySelector(sel); };

    $('#yuz-ed-ping').addEventListener('click', function () {
      if (!Y.ajax_url) { out.textContent = 'No AJAX URL available.'; return; }
      var body = new URLSearchParams();
      body.set('action','yuz_ai_test_connection');
      if (Y.nonces && (Y.nonces.yuz_api_nonce || Y.nonce)) {
        body.set('_ajax_nonce', Y.nonces.yuz_api_nonce || Y.nonce);
      }
      fetch(Y.ajax_url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body })
        .then(function(res){ return res.text().then(function(txt){ return {res:res, txt:txt}; }); })
        .then(function(payload){
          out.textContent = 'AJAX response (' + payload.res.status + '):\n' + payload.txt.slice(0,2000);
        })
        .catch(function(e){ out.textContent = 'AJAX failed: ' + (e && e.message ? e.message : String(e)); });
    });

    $('#yuz-ed-scan').addEventListener('click', function () {
      var url = $('#yuz-ed-url').value.trim();
      if (!url) { out.textContent = 'Please enter a URL to scan.'; return; }
      out.textContent = 'Scanning… (demo)';
      setTimeout(function(){ out.textContent = 'Demo complete. URL captured: ' + url; }, 300);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', render);
  } else {
    render();
  }

  log('Boot complete.');

  return { version: '0.1.0' };
}));

