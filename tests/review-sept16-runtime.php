<?php
// Real WordPress/SQL fixture only, never run against production.
if (!defined('WP_CLI') || !WP_CLI) exit(1);
if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
function yuztra_review_assert($ok, $label) {
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    WP_CLI::log('PASS '.$label);
}
class Yuztra_Sept16_Exit extends RuntimeException {}
add_filter('wp_die_ajax_handler', static function () {
    return static function () { throw new Yuztra_Sept16_Exit(); };
});
require_once WP_PLUGIN_DIR.'/yuz-tra/includes/class-yuz-ajax.php';
$handler=(new ReflectionClass('YUZ_Ajax'))->newInstanceWithoutConstructor();
$sanitizer=new ReflectionMethod('YUZ_Ajax','sanitize_callback_request');
$sanitizer->setAccessible(true);
$input=['translated_text'=>'<strong>Bonjour %1$s — 日本語</strong><script>alert(1)</script>',
    'originals'=>wp_json_encode(['Une "citation" et \\ chemin %s']), 'post_id'=>'12'];
$clean=wp_unslash($sanitizer->invoke(null,wp_slash($input)));
yuztra_review_assert($clean['translated_text']==='<strong>Bonjour %1$s — 日本語</strong>alert(1)','wrapper removes executable tags without stripping legitimate markup');
yuztra_review_assert(json_decode($clean['originals'],true)===['Une "citation" et \\ chemin %s'],'wrapper preserves JSON escaping and placeholders');
foreach ([['post_id'=> '12 OR 1=1'], ['originals'=>'[broken'], ['data'=>array_fill(0,501,'x')]] as $bad) {
    $rejected=false;
    try { $sanitizer->invoke(null,wp_slash($bad)); } catch (InvalidArgumentException $e) { $rejected=true; }
    yuztra_review_assert($rejected,'wrapper rejects invalid typed payload');
}
function yuztra_review_call($handler, $method, $data) {
    $statuses=[];
    $status_observer=static function($header, $code) use (&$statuses) {
        $statuses[]=(int)$code;
        return $header;
    };
    add_filter('status_header',$status_observer,10,2);
    $_POST=$_REQUEST=wp_slash($data);
    ob_start();
    try { $handler->$method(); } catch (Yuztra_Sept16_Exit $exit) {}
    $decoded=json_decode(ob_get_clean(),true);
    remove_filter('status_header',$status_observer,10);
    if (!is_array($decoded)) $decoded=[];
    $decoded['_observed_statuses']=$statuses;
    return $decoded;
}
global $wpdb;
$table=$wpdb->prefix.'yuz_tra_translations';
$target=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}yuz_tra_languages WHERE language_code=%s",'fr_FR'));
$source=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}yuz_tra_languages WHERE language_code=%s",'en_US'));
yuztra_review_assert($source>0 && $target>0,'fixture languages exist');
$post=wp_insert_post(['post_title'=>'Public fixture','post_status'=>'publish']);
$private=wp_insert_post(['post_title'=>'Private fixture','post_status'=>'private']);
$protected=wp_insert_post(['post_title'=>'Protected fixture','post_status'=>'publish','post_password'=>'fixture-only']);
foreach ([['Bonjour %s — 日本語',4],['Draft',1],['<img src=x onerror="alert(1)"><strong>Bonjour</strong><script>alert(1)</script>',4]] as $i=>$fixture) {
    $ok=$wpdb->insert($table,['post_id'=>$post,'context'=>'content','block_id'=>'review-'.$i,
        'original_text'=>'Source '.$i,'translated_text'=>$fixture[0],'source_lang_id'=>$source,
        'target_lang_id'=>$target,'language_code'=>'fr_FR','status'=>$fixture[1]]);
    yuztra_review_assert($ok!==false,'stored fixture '.$i);
}
wp_set_current_user(0);
$request=['nonce'=>wp_create_nonce('yuz_tra_nonce'),'post_id'=>(string)$post,'language'=>'fr_FR',
    'originals'=>wp_json_encode(['Source 0','Source 1','Source 2','Missing'])];
$writes=[];$provider_calls=0;
$query_observer=static function($sql) use (&$writes) {
    if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i',$sql)) $writes[]=$sql;
    return $sql;
};
$http_observer=static function($pre) use (&$provider_calls) { $provider_calls++; return new WP_Error('fixture_network_forbidden'); };
add_filter('query',$query_observer);
add_filter('pre_http_request',$http_observer);
$result=yuztra_review_call($handler,'yuz_tra_public_lookup',$request);
yuztra_review_assert(($result['success']??false)===true,'anonymous published lookup succeeds');
$rows=$result['data'];
yuztra_review_assert($rows[0]['translationsArray']['fr_FR']['translated']==='Bonjour %s — 日本語','Unicode and placeholder preserved');
yuztra_review_assert($rows[1]['translation_id']===0 && $rows[1]['translationsArray']['fr_FR']['translated']==='','draft not disclosed');
$html=$rows[2]['translationsArray']['fr_FR']['translated'];
yuztra_review_assert(!str_contains($html,'onerror') && !str_contains($html,'<script') && str_contains($html,'<strong>Bonjour</strong>'),'stored XSS removed; legitimate HTML retained');
yuztra_review_assert($rows[3]['translation_id']===0,'missing translation does not invoke provider');
foreach ([['nonce'=>'bad'],['post_id'=>(string)$private],['post_id'=>(string)$protected],['originals'=>'[["nested"]]'],['language'=>['fr_FR']],['originals'=>wp_json_encode(array_fill(0,101,'x'))]] as $invalid) {
    $denied=yuztra_review_call($handler,'yuz_tra_public_lookup',array_replace($request,$invalid));
    yuztra_review_assert(($denied['success']??null)===false,'public malformed or unauthorized request refused');
}
yuztra_review_assert($writes===[],'public success and refusal perform zero SQL writes');
yuztra_review_assert($provider_calls===0,'public lookup performs zero HTTP/provider calls');
remove_filter('query',$query_observer);
remove_filter('pre_http_request',$http_observer);

foreach (['subscriber','author'] as $role) {
    $id=wp_insert_user(['user_login'=>'review16-'.$role,'user_pass'=>wp_generate_password(),'role'=>$role]);
    yuztra_review_assert(!is_wp_error($id),'fixture role '.$role);
    wp_set_current_user($id);
    $guarded=[
        'yuz_get_regular'=>'yuz_tra_nonce','yuz_tra_js_get_regular'=>'yuz_int_nonce',
        'yuz_tra_te_cre_tstart'=>'yuz_int_nonce','yuz_tra_te_cre_translation'=>'yuz_int_nonce',
        'yuz_tra_te_upd_manual'=>'yuz_int_nonce','yuz_tra_te_upd_publish'=>'yuz_int_nonce',
        'yuz_publish_translations'=>'yuz_con_nonce','yuz_tra_at_get_api_settings'=>'yuz_tra_nonce',
        'yuz_tra_at_del_api_settings'=>'yuz_con_nonce','yuz_tra_tm_cre_page'=>'yuz_int_nonce',
        'yuz_tra_tm_get_translations'=>'yuz_tra_nonce','yuz_tra_tm_search'=>'yuz_tra_nonce',
        'yuz_tra_ts_get_settings'=>'yuz_tra_nonce','yuz_tra_ts_upd_settings'=>'yuz_con_nonce',
        'yuz_tra_ws_get_languages'=>'yuz_tra_nonce','yuz_tra_sw_switch_language'=>'yuz_tra_nonce',
        'yuz_save_translation'=>'yuz_tra_nonce',
    ];
    foreach ($guarded as $method=>$nonce_action) {
        $nonce=wp_create_nonce($nonce_action);
        $writes=[];add_filter('query',$query_observer);
        $denied=yuztra_review_call($handler,$method,['nonce'=>$nonce]);
        remove_filter('query',$query_observer);
        $refused=(($denied['success']??null)===false) || in_array(403,$denied['_observed_statuses']??[],true);
        yuztra_review_assert($refused && $writes===[], $role.' valid nonce denied without writes: '.$method);
    }
    $writes=[];add_filter('query',$query_observer);
    $denied=yuztra_review_call($handler,'ajax_preflight',['nonce'=>wp_create_nonce('yuz_hvy_nonce')]);
    remove_filter('query',$query_observer);
    $refused=(($denied['success']??null)===false) || in_array(403,$denied['_observed_statuses']??[],true);
    yuztra_review_assert($refused && $writes===[], $role.' preflight denied without writes');
}
