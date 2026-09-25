<?php
if (!defined('WP_CLI') || !WP_CLI || !in_array(get_option('blogname'),['YUZ-Proof','YUZ-CI'],true)) exit(1);
global $wpdb;
foreach (['invalid'=>1,'valid'=>4] as $mode=>$expected) {
    $id=(int)get_option('yuz_tra_review_publish_'.$mode);
    $row=$wpdb->get_row($wpdb->prepare("SELECT status, translated_text FROM {$wpdb->prefix}yuz_tra_translations WHERE id=%d",$id),ARRAY_A);
    if (!$row || (int)$row['status']!==$expected) throw new RuntimeException('FAIL publication '.$mode);
    if ($mode==='valid' && $row['translated_text']!=='Publié %s — 日本語') throw new RuntimeException('FAIL publication text');
    if ($mode==='invalid' && $row['translated_text']!=='Traduction') throw new RuntimeException('FAIL invalid nonce mutated text');
    WP_CLI::log('PASS administrator publication '.$mode.' nonce, SQL state '.$expected);
}
