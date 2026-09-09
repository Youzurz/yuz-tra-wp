<?php
/**
 * YUZ Request/Redirect Trace (hook pack)
 * Logs routing steps to wp-content/redirection.log
 */

if (!defined('ABSPATH')) { exit; }

// Enable tracing now; you can also toggle with ?yuztrace=1 in URL
$__yuz_trace_enabled = false;
if (defined('YUZ_TRACE') && YUZ_TRACE) { $__yuz_trace_enabled = true; }
if (isset($_GET['yuztrace'])) { $__yuz_trace_enabled = true; }

function yuz_trace_log($stage, $data = []){
    global $__yuz_trace_enabled;
    if (!$__yuz_trace_enabled) { return; }
    $row  = [
        't'      => gmdate('c'),
        'stage'  => $stage,
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'uri'    => sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')),
        'host'   => sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? '')),
        'ip'     => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
    ];
    if (!empty($data)) { $row['data'] = $data; }
    error_log('[YUZ request trace] '.wp_json_encode($row, JSON_UNESCAPED_SLASHES));
}

add_action('parse_request', function($wp){
    if (!is_object($wp)) return;
    yuz_trace_log('parse_request', [
        'query_vars' => $wp->query_vars,
        'request'    => $wp->request,
    ]);
});

add_action('pre_get_posts', function($q){
    if (!($q instanceof WP_Query) || !$q->is_main_query()) return;
    $front_id   = (int) get_option('page_on_front');
    $front_slug = $front_id ? get_post_field('post_name', $front_id) : '';
    yuz_trace_log('pre_get_posts', [
        'is_admin'   => is_admin(),
        'is_home'    => $q->is_home(),
        'is_front'   => $q->is_front_page(),
        'pagename'   => $q->get('pagename'),
        'page_id'    => $q->get('page_id'),
        'lang'       => $q->get('lang'),
        'front_id'   => $front_id,
        'front_slug' => $front_slug,
        'show_on_front' => get_option('show_on_front'),
    ]);
}, 99);

add_filter('wp_redirect', function($location, $status){
    yuz_trace_log('wp_redirect', [ 'to' => $location, 'status' => $status ]);
    return $location;
}, 10, 2);

add_filter('template_include', function($template){
    $front_id = (int) get_option('page_on_front');
    $data = [
        'template' => $template,
        'is_home'  => is_home(),
        'is_front' => is_front_page(),
        'queried_id' => get_queried_object_id(),
        'front_id' => $front_id,
    ];
    if ($front_id) { $data['front_slug'] = get_post_field('post_name', $front_id); }
    yuz_trace_log('template_include', $data);
    return $template;
});

add_action('shutdown', function(){
    $summary = [
        'final_is_home'  => is_home(),
        'final_is_front' => is_front_page(),
        'final_id'       => get_queried_object_id(),
        'lang_qv'        => get_query_var('lang'),
        'pagename_qv'    => get_query_var('pagename'),
    ];
    yuz_trace_log('shutdown', $summary);
});
