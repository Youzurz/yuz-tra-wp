'use strict';

const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const files = [
  'includes/helpers/lang-helpers.php',
  'includes/helpers/status-helpers.php',
  'includes/hooks/yuz-ajax-guard.php',
];

const legacyFunctions = new Set([
  'yuz_norm_locale',
  'yuz_normalize_language_code',
  'yuz_root',
  'yuz_canon_lang',
  'yuz_allowed_locales',
  'yuz_map_to_lt',
  'yuz_default_locales',
  'yuz_map_from_lt',
  'yuz_resolve_target_locale',
  'yuz_strip_css_js_noise',
  'yuz_human_label_from_locale',
  'yuz_generate_block_id',
  'yuz_tra_status_catalog',
  'yuz_tra_status_label',
  'yuz_tra_status_sanitize',
  'yuz_tra_status_requires_review',
  'yuz_tra_status_is_publishable',
  'yuz_tra_status_transition',
  'yuz_tra_status_for_origin',
  'yuz_rate_limit_or_die',
]);

const legacyConstants = new Set([
  'YUZ_TRA_STATUS_DRAFT',
  'YUZ_TRA_STATUS_IN_REVIEW',
  'YUZ_TRA_STATUS_REVIEWED',
  'YUZ_TRA_STATUS_PUBLISHED',
  'YUZ_TRA_STATUS_ARCHIVED',
  'YUZ_TRA_STATUS_REVIEW',
  'YUZ_TRA_STATUS_MACHINE',
  'YUZ_TRA_STATUS_QUEUED',
]);

const sources = new Map(files.map((file) => [file, fs.readFileSync(path.join(root, file), 'utf8')]));

for (const [file, source] of sources) {
  const functions = [...source.matchAll(/\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/g)].map((match) => match[1]);
  for (const name of functions) {
    assert.ok(
      name.startsWith('yuztra_') || legacyFunctions.has(name),
      `${file}: global function ${name} is neither canonical nor an approved compatibility wrapper`
    );
  }

  const constants = [...source.matchAll(/\bdefine\(\s*['"]([A-Z_][A-Z0-9_]*)['"]/g)].map((match) => match[1]);
  for (const name of constants) {
    assert.ok(
      name.startsWith('YUZTRA_') || legacyConstants.has(name),
      `${file}: global constant ${name} is neither canonical nor an approved compatibility alias`
    );
  }
}

const combined = [...sources.values()].join('\n');
for (const legacy of legacyFunctions) {
  const canonical = legacy.startsWith('yuz_tra_')
    ? `yuztra_${legacy.slice('yuz_tra_'.length)}`
    : `yuztra_${legacy.slice('yuz_'.length)}`;
  assert.match(combined, new RegExp(`function\\s+${canonical}\\s*\\(`), `missing canonical helper ${canonical}`);
  assert.match(combined, new RegExp(`function\\s+${legacy}\\s*\\([^}]*\\b${canonical}\\s*\\(`), `legacy helper ${legacy} must delegate to ${canonical}`);
}

for (const legacy of legacyConstants) {
  const canonical = legacy.replace(/^YUZ_TRA_/, 'YUZTRA_');
  if (['YUZ_TRA_STATUS_REVIEW', 'YUZ_TRA_STATUS_MACHINE', 'YUZ_TRA_STATUS_QUEUED'].includes(legacy)) {
    continue;
  }
  assert.match(combined, new RegExp(`define\\(\\s*['"]${legacy}['"]\\s*,\\s*${canonical}\\s*\\)`), `${legacy} must alias ${canonical}`);
}

assert.match(
  sources.get('includes/helpers/lang-helpers.php'),
  /get_option\(\s*['"]yuz_tra_general['"]\s*,/,
  'persisted option name yuz_tra_general must remain unchanged'
);

const phpRuntime = String.raw`
define('ABSPATH', __DIR__);
function __($text, $domain = null) { return $text; }
function get_option($name, $default = false) {
    return $name === 'yuz_tra_general'
        ? ['yuz_tra_translatable_languages' => ['fr_FR', 'en_US']]
        : $default;
}
function get_current_user_id() { return 1; }
function sanitize_text_field($value) { return $value; }
function wp_unslash($value) { return $value; }
function get_transient($key) { return 0; }
function set_transient($key, $value, $expiration) { return true; }
function wp_send_json_error($data, $status = null) { throw new RuntimeException('unexpected rate limit'); }
function wp_strip_all_tags($text, $remove_breaks = false) { return strip_tags($text); }

require ${JSON.stringify(path.join(root, 'includes/helpers/lang-helpers.php'))};
require ${JSON.stringify(path.join(root, 'includes/helpers/status-helpers.php'))};
require ${JSON.stringify(path.join(root, 'includes/hooks/yuz-ajax-guard.php'))};

$checks = [
    yuz_norm_locale('fr_FR') === yuztra_norm_locale('fr_FR'),
    yuz_normalize_language_code('fr-fr') === yuztra_normalize_language_code('fr-fr'),
    yuz_canon_lang('en_GB') === yuztra_canon_lang('en_GB'),
    yuz_allowed_locales() === yuztra_allowed_locales(),
    yuz_map_from_lt('fr') === yuztra_map_from_lt('fr'),
    yuz_resolve_target_locale('fr_CA') === yuztra_resolve_target_locale('fr_CA'),
    yuz_tra_status_catalog() === yuztra_status_catalog(),
    yuz_tra_status_sanitize('published') === yuztra_status_sanitize('published'),
    yuz_tra_status_transition('publish') === yuztra_status_transition('publish'),
    YUZ_TRA_STATUS_PUBLISHED === YUZTRA_STATUS_PUBLISHED,
    function_exists('yuz_rate_limit_or_die') && function_exists('yuztra_rate_limit_or_die'),
];

if (in_array(false, $checks, true)) {
    fwrite(STDERR, "compatibility check failed\n");
    exit(1);
}
echo "runtime compatibility PASS\n";
`;

const runtimeOutput = execFileSync('php', ['-r', phpRuntime], { encoding: 'utf8' });
assert.match(runtimeOutput, /runtime compatibility PASS/);

console.log(`PC-04 PASS: ${files.length} files expose yuztra_* implementations with declared legacy compatibility.`);
