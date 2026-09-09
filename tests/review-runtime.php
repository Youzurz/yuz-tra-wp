<?php
// Run only in the disposable WordPress acceptance environment.
if (!defined('WP_CLI') || !WP_CLI) exit(1);
function yuztra_assert($condition, string $label): void {
    if (!$condition) throw new RuntimeException('FAIL ' . $label);
    WP_CLI::log('PASS ' . $label);
}
YUZ_DB::ensure_string_tables();
$logger=new YUZ_Logger();
$logger->log('error','YUZTRA_PRIVATE_LOG_ACCEPTANCE');
yuztra_assert(str_contains(implode('', (array)get_option('yuz_tra_private_log',[])), 'YUZTRA_PRIVATE_LOG_ACCEPTANCE'), 'default diagnostic log is persisted privately');
yuztra_assert(!is_file(wp_upload_dir()['basedir'].'/yuz-log.log'), 'no public default diagnostic log created');
$public_log=wp_upload_dir()['basedir'].'/yuz-review-public.log';
(new YUZ_Logger($public_log))->log('error','PUBLIC_PATH_REJECTED');
yuztra_assert(!is_file($public_log), 'custom diagnostic file inside uploads rejected');
$id = YUZ_String_Catalog::collect('Order received.', 'yuztra-review-test');
yuztra_assert($id > 0, 'catalog source persisted');
foreach (['<script>alert(1)</script>', '<img src=x onerror=alert(1)>', '<?php die(); ?>', '<a href="javascript:alert(1)">click</a>'] as $bad) {
    $rejected = false;
    try { YUZ_String_Catalog::save($id, 'en_US', [$bad], 4); }
    catch (InvalidArgumentException $e) { $rejected = $e->getMessage() === 'unsafe_translation_markup'; }
    yuztra_assert($rejected, 'executable translation rejected');
}
YUZ_String_Catalog::save($id, 'en_US', ['Order confirmed.'], 4);
$rows = YUZ_String_Catalog::search('en_US', 'Order received.', 'yuztra-review-test');
yuztra_assert($rows['rows'][0]['forms'][0] === 'Order confirmed.', 'safe translation saved and reloaded');
global $wpdb;
$malicious_id=YUZ_String_Catalog::collect('Legacy unsafe translation.', 'yuztra-review-test');
$wpdb->replace($wpdb->prefix.'yuz_tra_string_targets', [
    'source_id'=>$malicious_id,'lang'=>'en_US','forms'=>wp_json_encode(['<img src=x onerror=alert(1)>']),'status'=>4,
]);
yuztra_assert(YUZ_String_Catalog::apply('Original fallback.', 'Legacy unsafe translation.', 'yuztra-review-test') === 'Original fallback.', 'unsafe legacy gettext ignored');
$jed=json_decode(YUZ_String_Catalog::script_translations('',false,'review-test','yuztra-review-test'),true);
yuztra_assert(!isset($jed['locale_data']['messages']['Legacy unsafe translation.']), 'unsafe legacy JavaScript catalog ignored');
yuztra_assert(($jed['locale_data']['messages']['Order received.'][0] ?? '') === 'Order confirmed.', 'safe JavaScript catalog retained');
do_action('rest_api_init');
wp_set_current_user(0);
$anon = rest_do_request(new WP_REST_Request('GET', '/yuz/v1/health'));
yuztra_assert(in_array($anon->get_status(), [401,403], true), 'anonymous health access denied');
$admin = get_user_by('login', 'admin');
wp_set_current_user($admin->ID);
$health = rest_do_request(new WP_REST_Request('GET', '/yuz/v1/health'));
yuztra_assert($health->get_status() === 200, 'administrator health access allowed');
do_action('admin_init');
foreach (['yuz_tra_ws_settings','yuz_tra_ls_settings','yuz_tra_sw_settings'] as $option) {
    $registered = get_registered_settings();
    yuztra_assert(isset($registered[$option]['sanitize_callback']) && is_callable($registered[$option]['sanitize_callback']), 'sanitizer registered: ' . $option);
}
$before = ob_get_level();
YUZ_Front_Buffer::maybe_start();
yuztra_assert(ob_get_level() === $before, 'buffer bootstrap does not open an unmanaged output buffer');
update_option('yuz_tra_sw_settings', ['show_poweredby'=>false]);
// The historical block renderer is distributed but not registered by the current bootstrap.
require_once WP_PLUGIN_DIR . '/yuz-tra/includes/class-yuz-switcher.php';
require_once WP_PLUGIN_DIR . '/yuz-tra/includes/class-yuz-blocks.php';
$block=YUZ_Blocks::render_language_switcher_block(['showPoweredBy'=>true]);
yuztra_assert($block !== '' && !str_contains($block,'yuz-powered-by'), 'block attribute cannot bypass administrator credit consent');
yuztra_assert(ob_get_level() === $before, 'block rendering closes its output buffer');
$request_before=$_POST;
$_POST=['action'=>'update','option_page'=>'yuz_tra_general_settings_group',
    '_wpnonce'=>wp_create_nonce('yuz_tra_general_settings_group-options'),
    'yuz_tra_sw_settings'=>['floating_theme'=>'<script>alert(1)</script>light']];
$clean=apply_filters('pre_update_option_yuz_tra_sw_settings', [], ['floating_theme'=>'light']);
yuztra_assert(!str_contains(wp_json_encode($clean),'<script'), 'legacy settings merge cannot bypass sanitizer');
$_POST['_wpnonce']='invalid';
$old=['floating_theme'=>'dark'];
yuztra_assert(apply_filters('pre_update_option_yuz_tra_sw_settings', [], $old)===$old, 'legacy settings merge requires valid settings nonce');
$_POST=$request_before;
WP_CLI::success('Reviewer runtime acceptance passed.');
