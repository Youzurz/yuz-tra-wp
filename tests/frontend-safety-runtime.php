<?php
// WordPress integration for the real filter; renderer output is an explicit test double.
// Run with --skip-plugins --skip-themes, in the disposable SQL fixture only.
if (!defined('WP_CLI') || !WP_CLI) exit(1);
define('YUZ_TRA_INCLUDES',WP_PLUGIN_DIR.'/yuz-tra/includes/');
require_once YUZ_TRA_INCLUDES.'class-yuz-contracts.php';
require_once YUZ_TRA_INCLUDES.'helpers/debug-helpers.php';
class YUZ_Front_Renderer {
    public static string $fixture='';
    public static function translate_post_field(string $text,int $id,string $context): string { return self::$fixture; }
    public static function get_active_language(): string { return 'fr_FR'; }
    public static function get_default_language(): string { return 'en_US'; }
}
require_once YUZ_TRA_INCLUDES.'class-yuz-frontend.php';
$id=wp_insert_post(['post_title'=>'Filter fixture','post_status'=>'publish']);
global $wp_query;
$wp_query=new WP_Query(['p'=>$id]);
$filter=new YUZ_Frontend();
$active=new ReflectionProperty($filter,'active_language_is_source');
$active->setAccessible(true);$active->setValue($filter,false);
foreach (['filter_content','filter_excerpt'] as $method) {
    $source='<p>Original %s — 日本語</p>';
    YUZ_Front_Renderer::$fixture='<p><strong>Bonjour %s — 日本語</strong><img src="x" onerror="alert(1)"><script>alert(1)</script></p>';
    $html=$filter->$method($source);
    if (str_contains($html,'<script') || str_contains($html,'onerror') || !str_contains($html,'<strong>Bonjour %s — 日本語</strong>')) throw new RuntimeException('FAIL '.$method.' XSS or legitimate markup');
    WP_CLI::log('PASS '.$method.' real WordPress KSES removes executable HTML, retains markup/Unicode/placeholders (renderer fixture)');
    $original='<p>Unchanged</p><iframe src="https://example.invalid/embed"></iframe>';
    YUZ_Front_Renderer::$fixture=$original;
    if ($filter->$method($original)!==$original) throw new RuntimeException('FAIL source fallback mutated');
    WP_CLI::log('PASS '.$method.' unchanged source preserved');
}
