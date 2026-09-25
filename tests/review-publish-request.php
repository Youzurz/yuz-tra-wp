<?php
// A separate process exercises real wp_send_json termination, not an exception double.
if (!defined('WP_CLI') || !WP_CLI || !in_array(get_option('blogname'),['YUZ-Proof','YUZ-CI'],true)) exit(1);
define('DOING_AJAX',true);
require_once WP_PLUGIN_DIR.'/yuz-tra/includes/class-yuz-ajax.php';
$mode=$args[0]??'invalid';
$admin=get_user_by('login','admin');wp_set_current_user($admin->ID);
global $wpdb;
$post=wp_insert_post(['post_title'=>'Publish '.$mode,'post_status'=>'publish']);
$target=(int)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}yuz_tra_languages WHERE language_code='fr_FR'");
$source=(int)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}yuz_tra_languages WHERE language_code='en_US'");
$wpdb->insert($wpdb->prefix.'yuz_tra_translations',['post_id'=>$post,'context'=>'content','block_id'=>'nonce-'.$mode,
    'original_text'=>'Original','translated_text'=>'Traduction','source_lang_id'=>$source,'target_lang_id'=>$target,'language_code'=>'fr_FR','status'=>1]);
$id=(int)$wpdb->insert_id;
update_option('yuz_tra_review_publish_'.$mode,$id,false);
$_POST=$_REQUEST=wp_slash(['action'=>'yuz_tra_te_upd_publish','nonce'=>$mode==='valid'?wp_create_nonce('yuz_int_nonce'):'invalid',
    'page_url'=>get_permalink($post),'target_lang'=>'fr_FR','entries'=>wp_json_encode([['id'=>$id,'translated_text'=>'Publié %s — 日本語']])]);
$handler=(new ReflectionClass('YUZ_Ajax'))->newInstanceWithoutConstructor();
$db=new ReflectionProperty('YUZ_Ajax','db');$db->setAccessible(true);$db->setValue($handler,YUZ_Services::db());
$handler->yuz_tra_te_upd_publish();
throw new RuntimeException('Handler did not terminate');
