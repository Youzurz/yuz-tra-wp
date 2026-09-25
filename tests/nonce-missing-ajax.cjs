const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '..');
const ajax = fs.readFileSync(path.join(root, 'includes/class-yuz-ajax.php'), 'utf8');

const languageStart = ajax.indexOf('public function yuz_tra_ws_get_languages()');
const languageEnd = ajax.indexOf('public function yuz_tra_ws_upd_languages()', languageStart);
const languageHandler = ajax.slice(languageStart, languageEnd);
assert.ok(languageHandler, 'website-languages handler must be found');
assert.ok(
  languageHandler.indexOf("wp_verify_nonce($nonce, 'yuz_tra_nonce')") < languageHandler.indexOf("array_keys($_POST)"),
  'request metadata must only be read after explicit nonce verification'
);

assert.doesNotMatch(
  ajax,
  /\$_POST\s*\[\s*['"]yuz_trace['"]\s*\]/,
  'guarded callbacks must consume the sanitized request passed by __handleRequest'
);

assert.match(
  ajax,
  /public function yuz_gt_save\(\)[\s\S]*?check_ajax_referer\('yuz_int_nonce', 'nonce'\)[\s\S]*?legacy_catalog_items\(self::raw_post_payload\('items'\)\)/,
  'legacy catalog payload must be read explicitly after the route nonce check'
);
const decoderStart = ajax.indexOf('private static function legacy_catalog_items(');
const decoderEnd = ajax.indexOf('public function yuz_gt_save()', decoderStart);
const decoder = ajax.slice(decoderStart, decoderEnd);
assert.ok(decoderStart >= 0 && decoderEnd > decoderStart, 'legacy decoder must be found');
assert.doesNotMatch(
  decoder,
  /\$_POST/,
  'legacy decoder must not perform an implicit post-guard request read'
);

console.log('PASS seven class-yuz-ajax nonce findings made explicit');
