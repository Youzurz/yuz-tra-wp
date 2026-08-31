<?php
defined('ABSPATH') || exit;

if (defined('WP_CLI') && WP_CLI) {
    /**
     * wp yuz front:doctor [--id=<page_id>] [--lang=<slug>]
     *
     * Exemples:
     *   wp yuz front:doctor
     *   wp yuz front:doctor --id=3155
     *   wp yuz front:doctor --lang=en
     */
    $yuz_front_doctor = function( $args, $assoc_args ) {

        $page_id = isset($assoc_args['id']) ? (int)$assoc_args['id'] : (int)get_option('page_on_front');
        $lang    = isset($assoc_args['lang']) ? sanitize_text_field($assoc_args['lang']) : get_locale();

        // --- Options de lecture (accueil)
        $show_on_front = get_option('show_on_front', 'posts');
        $page_on_front = (int) get_option('page_on_front');
        $page_for_posts= (int) get_option('page_for_posts');
        $front_slug    = $page_on_front ? get_post_field('post_name', $page_on_front) : '';
        $slug_asked    = $page_id ? get_post_field('post_name', $page_id) : '';

        // --- Permaliens
        $permas = get_option('permalink_structure') ?: '(vide)';

        WP_CLI::line('=== Front settings ===');
        WP_CLI::line('show_on_front     : ' . $show_on_front);
        WP_CLI::line('page_on_front     : ' . ($page_on_front ?: '(none)'));
        WP_CLI::line('front_slug(from option): ' . ($front_slug ?: '(empty)'));
        WP_CLI::line('page_for_posts    : ' . ($page_for_posts ?: '(none)'));
        WP_CLI::line('permalink_structure: ' . $permas);
        if ($page_id) {
            WP_CLI::line('slug(#'.$page_id.')       : ' . ($slug_asked ?: '(not found)'));
        }

        // --- Récupération des règles
        global $wp_rewrite;
        $rules = $wp_rewrite->wp_rewrite_rules();
        if (!$rules) {
            $rules = get_option('rewrite_rules', []);
        }

        WP_CLI::line("\n=== Rewrite rules matching \"/{$lang}/\" ===");
        $found = 0;
        foreach ((array)$rules as $regex => $query) {
            // On liste ce qui commence par ^en/  ou ^en$  (tolérant sur /?)
            if (preg_match('#^\^?' . preg_quote($lang, '#') . '(?:/|\$)#i', ltrim($regex, '^'))) {
                WP_CLI::line(sprintf('  %-40s => %s', $regex, $query));
                $found++;
            }
        }
        if (!$found) {
            WP_CLI::warning('Aucune règle commençant par "^'.$lang.'"');
        }

        // --- Simulation naïve : quelle règle matche "en/" ?
        $simulate = rtrim($lang, '/') . '/';
        $match = null;
        foreach ((array)$rules as $regex => $query) {
            // Les patterns sont sans délimiteur ; on ajoute #...#i
            if (@preg_match('#' . $regex . '#i', $simulate, $m)) {
                if ($m) { $match = ['regex' => $regex, 'query' => $query]; break; }
            }
        }

        WP_CLI::line("\n=== First rule that matches \"{$simulate}\" ===");
        if ($match) {
            WP_CLI::line('regex : ' . $match['regex']);
            WP_CLI::line('query : ' . $match['query']);
        } else {
            WP_CLI::warning('Aucune règle ne matche "'.$simulate.'"');
        }

        // --- (optionnel) YUZ settings utiles
        $yuz_settings = get_option('yuz_tra_settings');
        $yuz_general  = get_option('yuz_tra_general');
        $use_subdir   = is_array($yuz_settings) && !empty($yuz_settings['use_subdirectory']);
        $force_lang   = is_array($yuz_settings) && !empty($yuz_settings['force_lang_in_links']);

        WP_CLI::line("\n=== YUZ settings (résumé) ===");
        WP_CLI::line('use_subdirectory     : ' . ($use_subdir ? '1' : '0'));
        WP_CLI::line('force_lang_in_links  : ' . ($force_lang ? '1' : '0'));
        if (is_array($yuz_general)) {
            WP_CLI::line('default_language     : ' . ($yuz_general['yuz_tra_default_language'] ?? '(unset)'));
            WP_CLI::line('source_language      : ' . ($yuz_general['yuz_tra_source_language'] ?? '(unset)'));
        }

        WP_CLI::success('front:doctor done.');
    };

    // On tolère deux syntaxes: `wp yuz front:doctor` et `wp yuz front-doctor`
    WP_CLI::add_command('yuz front:doctor', $yuz_front_doctor);
    WP_CLI::add_command('yuz front-doctor', $yuz_front_doctor);
}
