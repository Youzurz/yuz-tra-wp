const fs = require('node:fs');
const cp = require('node:child_process');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
// This historical one-time migration must not re-wrap already sanitized inputs.
if (JSON.parse(fs.readFileSync(path.join(root, 'version.json'), 'utf8')).version !== '1.5.5') {
  throw new Error('This migration only accepts the original 1.5.5 source; use the committed 1.5.6 changes for subsequent builds.');
}
function edit(rel, fn) {
  const file = path.join(root, rel), old = fs.readFileSync(file, 'utf8'), next = fn(old);
  if (old === next) return;
  const patch = `*** Begin Patch\n*** Update File: ${file}\n@@\n` + old.trimEnd().split('\n').map(l=>'-'+l).join('\n') + '\n' + next.trimEnd().split('\n').map(l=>'+'+l).join('\n') + '\n*** End Patch';
  cp.execFileSync('apply_patch', [], {input: patch, stdio:'pipe', maxBuffer: 1024 * 1024});
  console.log(rel);
}
for (const rel of ['version.json','yuz-tra.php','readme.txt','README.md','GETTING-STARTED.md','RELEASE-NOTES.md','PRIVACY.md']) {
  edit(rel, s => s.replaceAll('1.5.5', '1.5.6'));
}
function walk(dir) { return fs.readdirSync(path.join(root,dir),{withFileTypes:true}).flatMap(d=>d.isDirectory()?walk(dir+'/'+d.name):[dir+'/'+d.name]); }
for (const rel of walk('includes').filter(f=>f.endsWith('.php'))) {
  edit(rel, s=>s.replace(/\bNullEnvironment\b/g,'YUZTRA_NullEnvironment').replace(/\bNullCsvImporter\b/g,'YUZTRA_NullCsvImporter').replace(/(['"])tables_ok\1/g,"'yuz_tra_tables_ok'"));
}
// Move the four static renderer fragments onto WordPress' footer script queue.
edit('includes/class-yuz-renderer.php', s=>s.replace(/<script type="text\/javascript">\s*([\s\S]*?)\s*<\/script>/g, (_, js)=> {
  if (js.includes('<?')) throw new Error('Dynamic renderer fragment requires review');
  return "<?php\nwp_enqueue_script('jquery');\nwp_add_inline_script('jquery', <<<'YUZTRA_JS'\njQuery(function ($) {\n"+js+"\n});\nYUZTRA_JS\n, 'after');\n?>";
}));
// Debug interception of browser APIs is unnecessary in the distributed plugin.
edit('includes/class-yuz-debug-probe.php', s=>s.replace(/        public static function inject_client_hooks\(\): void \{[\s\S]*?\n        \/\*\*\n         \* Receives client logs/, "        public static function inject_client_hooks(): void {\n            // Server-side, opt-in diagnostics are available without intercepting browser APIs.\n        }\n\n        /**\n         * Receives client logs"));
edit('includes/class-yuz-renderer.php', s=>s.replaceAll("wp_enqueue_script('jquery');\nwp_add_inline_script('jquery',", "wp_register_script('yuz-tra-renderer-fields', false, ['jquery'], YUZ_TRA_VERSION, true);\nwp_enqueue_script('yuz-tra-renderer-fields');\nwp_add_inline_script('yuz-tra-renderer-fields',"));
edit('includes/class-yuz-ajax.php', s=>s.replaceAll("stripslashes($_POST['items'] ?? '[]')", "wp_unslash($_POST['items'] ?? '[]')"));
edit('includes/class-yuz-advanced.php', s=>s.replaceAll("wp_verify_nonce($_POST['nonce'],'yuz_con_nonce')", "wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])),'yuz_con_nonce')"));
edit('assets/js/widgets/yuz-translation-editor.esm.js', s=>s.replaceAll("'/wp-admin/admin-ajax.php'", 'Y.ajax_url'));
for (const rel of walk('includes').filter(f=>f.endsWith('.php'))) {
  edit(rel, s=>s
    .replace(/\$_SERVER\['(REQUEST_URI|HTTP_HOST|HTTP_USER_AGENT|HTTP_X_YUZ_LANG|REMOTE_ADDR)'\] \?\? ('[^']*')/g, "sanitize_text_field(wp_unslash(\$_SERVER['$1'] ?? $2))")
    .replace(/\(string\)\s*\$_SERVER\['(REQUEST_URI|HTTP_HOST|HTTP_USER_AGENT|HTTP_X_YUZ_LANG|REMOTE_ADDR|SERVER_NAME|HTTP_X_FORWARDED_PROTO)'\]/g, "sanitize_text_field(wp_unslash(\$_SERVER['$1']))")
    .replace(/\(string\)\s*\$_SERVER\['SERVER_PORT'\]/g, "(string) absint(\$_SERVER['SERVER_PORT'])")
    .replace(/\(string\)\s*\$_POST\['option_page'\]/g, "sanitize_key(wp_unslash(\$_POST['option_page']))")
    .replace(/'post_keys'\s*=>\s*array_keys\(\$_POST\)/g, "'post_keys' => array_map('sanitize_key', array_keys(\$_POST))")
  );
}
edit('includes/class-yuz-translation-manager.php', s=>s.replace("home_url(add_query_arg([], wp_unslash($_SERVER['REQUEST_URI'])))", "home_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])))"));
