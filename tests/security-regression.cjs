'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const root = path.resolve(__dirname, '..');
const optionsBridge = fs.readFileSync(path.join(root, 'includes/class-yuz-options-bridge.php'), 'utf8');
const csvImporter = fs.readFileSync(path.join(root, 'includes/class-yuz-csv-importer.php'), 'utf8');
const ajax = fs.readFileSync(path.join(root, 'includes/class-yuz-ajax.php'), 'utf8');
const renderer = fs.readFileSync(path.join(root, 'includes/class-yuz-renderer.php'), 'utf8');
const settings = fs.readFileSync(path.join(root, 'includes/class-yuz-settings.php'), 'utf8');
const settingsHelpers = fs.readFileSync(path.join(root, 'includes/helpers/settings-helpers.php'), 'utf8');
const debugProbe = fs.readFileSync(path.join(root, 'includes/class-yuz-debug-probe.php'), 'utf8');
const frontend = fs.readFileSync(path.join(root, 'includes/class-yuz-frontend.php'), 'utf8');
const restMonitoring = fs.readFileSync(path.join(root, 'includes/class-yuz-rest-monitoring.php'), 'utf8');

test('automatic-translation credentials are never serialized to diagnostic logs', () => {
  const forbidden = [
    /POST_RAW/,
    /payload='\s*\.\s*wp_json_encode\(\$value\)/,
    /final='\s*\.\s*wp_json_encode\(\$value\)/,
    /OLD='\s*\.\s*(?:json_encode|wp_json_encode)\(\$old_at\)/,
    /AFTER_SAN='\s*\.\s*(?:json_encode|wp_json_encode)\(\$value\)/,
    /value_preview/,
    /original_preview/,
  ];

  for (const pattern of forbidden) {
    assert.doesNotMatch(optionsBridge, pattern, `sensitive diagnostic pattern remains: ${pattern}`);
  }
  assert.match(optionsBridge, /function yuz_tra_diag_shape\(/);
});

test('CSV import validates the real upload and uses local safe redirects', () => {
  for (const required of [
    /check_admin_referer\(/,
    /current_user_can\(\s*'manage_options'/,
    /UPLOAD_ERR_OK/,
    /is_uploaded_file\(/,
    /sanitize_file_name\(\s*wp_unslash\(/,
    /wp_check_filetype_and_ext\(/,
    /wp_safe_redirect\(/,
  ]) {
    assert.match(csvImporter, required, `missing CSV hardening control: ${required}`);
  }
  assert.doesNotMatch(csvImporter, /wp_redirect\(/);
});


test('debug log endpoints cannot be called anonymously and do not log provider payloads', () => {
  assert.doesNotMatch(ajax, /wp_ajax_nopriv_yuz_(?:dom_log|trace_beacon|tra_diag_chain)/);
  assert.doesNotMatch(ajax, /'raw'\s*=>\s*wp_json_encode\(\$raw_api_settings/);
  assert.doesNotMatch(ajax, /'resolved'\s*=>\s*wp_json_encode\(\$resolved_api_settings/);
  assert.doesNotMatch(ajax, /single_empty RAW=/);
  assert.match(ajax, /function yuz_dom_log\(\): void \{[\s\S]*?current_user_can\('manage_options'\)[\s\S]*?current_user_can\('yuz_translate_content'\)[\s\S]*?check_ajax_referer\('yuz_log_nonce', 'nonce'\)/);
});

test('saved provider credentials are never rendered back into HTML', () => {
  for (const key of ['openai', 'libre', 'google', 'deepl', 'custom']) {
    const field = new RegExp(`<input type="password" id="yuz_tra_${key}_key"[^>]*value=""[^>]*autocomplete="new-password"`);
    assert.match(renderer, field, `${key} credential field is not write-only`);
  }
  assert.doesNotMatch(renderer, /value="<\?php echo esc_attr\(\$(?:libre|google|deepl|custom)_key\)/);
});

test('settings and debug probes never log complete request payloads', () => {
  assert.doesNotMatch(settings, /wp_json_encode\(\$_POST\)/);
  assert.doesNotMatch(settingsHelpers, /['"]_POST['"]\s*=>\s*\$_POST/);
  assert.doesNotMatch(settingsHelpers, /['"]raw['"]\s*=>\s*\$raw/);
  assert.doesNotMatch(debugProbe, /['"]post['"]\s*=>\s*array_map\('wp_unslash',\s*\$_POST\)/);
  assert.match(debugProbe, /function handle_client_log\(\): void \{[\s\S]*?check_ajax_referer\('yuz_log_nonce', 'nonce'\)/);
});

test('anonymous AJAX exposure is an explicit read-only allowlist', () => {
  assert.match(ajax, /private const PUBLIC_AJAX_ACTIONS = \[[\s\S]*?'yuz_get_regular'[\s\S]*?'yuz_tra_js_get_regular'[\s\S]*?\];/);
  assert.match(ajax, /if \(in_array\(\$action, self::PUBLIC_AJAX_ACTIONS, true\)\) \{[\s\S]*?wp_ajax_nopriv_/);
  assert.doesNotMatch(frontend, /wp_ajax_nopriv_yuz_probe/);
  assert.match(frontend, /function ajax_probe\(\): void \{[\s\S]*?check_ajax_referer\('yuz_log_nonce', 'nonce'\)/);
  assert.match(frontend, /register_rest_route\('yuz\/v1', '\/js-error',[\s\S]*?'permission_callback' => function\(\)[\s\S]*?current_user_can\('yuz_translate_content'\)/);
});

test('front-end telemetry is private by default and never stores content previews', () => {
  assert.doesNotMatch(frontend, /preview_(?:src|dst)/);
  assert.match(frontend, /get_option\('yuz_probes_enabled', false\)/);
  assert.match(restMonitoring, /defined\('YUZ_TRA_RUM'\) && YUZ_TRA_RUM/);
  assert.match(restMonitoring, /if \(!\$enabled\) \{\s*return;/);
});
