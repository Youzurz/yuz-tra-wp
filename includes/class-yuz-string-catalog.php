<?php
defined('ABSPATH') || exit;

/** Gettext identity, collection and published-only application. No provider calls during rendering. */
final class YUZ_String_Catalog {
    private static $pending = [];
    private static $published = [];
    private static $busy = false;

    public static function init(): void {
        if (is_admin() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) YUZ_DB::ensure_string_tables();
        add_filter('gettext', [__CLASS__, 'gettext'], 20, 3);
        add_filter('gettext_with_context', [__CLASS__, 'context'], 20, 4);
        add_filter('ngettext', [__CLASS__, 'plural'], 20, 5);
        add_filter('ngettext_with_context', [__CLASS__, 'plural_context'], 20, 6);
        add_action('shutdown', [__CLASS__, 'flush']);
    }

    public static function locale(string $code): string {
        return str_replace('-', '_', trim($code));
    }

    public static function source_language(string $domain): string {
        $map=(array)get_option('yuz_tra_domain_source_languages',[]);
        // WordPress Gettext convention, explicitly overridable for non-English source plugins.
        return self::locale((string)apply_filters('yuz_tra_gettext_source_language',$map[$domain] ?? 'en',$domain));
    }

    public static function language(): string {
        if (is_admin()) return self::locale(get_user_locale());
        $locale = get_locale();
        if (class_exists('YUZ_Front_Renderer')) $locale = YUZ_Front_Renderer::get_active_language();
        elseif (function_exists('yuz_get_current_language')) $locale = yuz_get_current_language();
        elseif (get_query_var('lang')) $locale = get_query_var('lang');
        return self::locale((string) apply_filters('yuz_tra_string_language', $locale));
    }

    public static function languages(): array {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT language_code AS code, language_name AS name FROM {$wpdb->prefix}yuz_tra_languages WHERE is_translatable = 1 OR is_source = 1 OR is_default = 1 ORDER BY language_weight,id", ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $row) $out[self::locale($row['code'])] = ['code' => self::locale($row['code']), 'name' => $row['name']];
        foreach (array_merge(['en_US', get_locale(), get_user_locale()], get_available_languages()) as $locale) {
            $code = self::locale($locale);
            if (!isset($out[$code])) $out[$code] = ['code' => $code, 'name' => $code];
        }
        return apply_filters('yuz_tra_catalog_languages', array_values($out));
    }

    public static function valid_language(string $lang): bool {
        return in_array(self::locale($lang), array_column(self::languages(), 'code'), true);
    }

    public static function identity(string $text, string $domain = 'default', string $context = '', string $plural = ''): string {
        return hash('sha256', wp_json_encode([$domain, $context, $text, $plural]));
    }

    public static function gettext($translation, $text, $domain) { return self::apply($translation, $text, $domain); }
    public static function context($translation, $text, $context, $domain) { return self::apply($translation, $text, $domain, $context); }
    public static function plural($translation, $single, $plural, $number, $domain) { return self::apply($translation, $single, $domain, '', $plural, $number); }
    public static function plural_context($translation, $single, $plural, $number, $context, $domain) { return self::apply($translation, $single, $domain, $context, $plural, $number); }

    public static function apply($fallback, $text, $domain, $context = '', $plural = '', $number = 1) {
        if (self::$busy || !is_string($text) || $text === '' || strlen($text) > 20000 || !in_array(get_option('yuz_tra_strings_schema'),['1','2'],true)) return $fallback;
        self::$busy = true;
        try {
            $key = self::identity($text, $domain, $context, $plural);
            if (count(self::$pending) < 100) self::$pending[$key] = [$text, $domain, $context, $plural];
            $lang = self::language();
            $bucket = get_current_blog_id() . '|' . $lang . '|' . $domain;
            if (!isset(self::$published[$bucket])) {
                global $wpdb;
                $sources = $wpdb->prefix . 'yuz_tra_string_sources';
                $targets = $wpdb->prefix . 'yuz_tra_string_targets';
                $rows = $wpdb->get_results($wpdb->prepare("SELECT s.identity_hash,t.forms,t.lang FROM $sources s JOIN $targets t ON t.source_id=s.id WHERE s.domain=%s AND t.status=4 AND t.lang IN (%s,%s) ORDER BY (t.lang=%s) ASC", $domain, $lang, explode('_', $lang)[0], $lang), ARRAY_A) ?: [];
                $map = [];
                foreach ($rows as $row) $map[$row['identity_hash']] = json_decode($row['forms'], true);
                self::$published[$bucket] = $map;
            }
            $forms = self::$published[$bucket][$key] ?? [];
            $index = $plural === '' ? 0 : self::plural_index($lang, (int) $number);
            return isset($forms[$index]) && $forms[$index] !== '' ? $forms[$index] : $fallback;
        } finally { self::$busy = false; }
    }

    public static function flush(): void {
        if (!self::$pending || !in_array(get_option('yuz_tra_strings_schema'),['1','2'],true)) return;
        $pending = self::$pending; self::$pending = [];
        self::$busy = true;
        try {
            global $wpdb;
            $values=[];
            foreach ($pending as [$text,$domain,$context,$plural]) {
                $values[]=$wpdb->prepare('(%s,%s,%s,%s,%s,%s,%s)', self::identity($text,$domain,$context,$plural),$domain,$context,$text,$plural,self::source_language($domain),gmdate('Y-m-d H:i:s'));
            }
            $wpdb->query("INSERT IGNORE INTO {$wpdb->prefix}yuz_tra_string_sources (identity_hash,domain,context,original,plural_original,source_lang,created_at) VALUES ".implode(',',$values));
        }
        finally { self::$busy = false; }
    }

    public static function collect(string $text, string $domain = 'default', string $context = '', string $plural = ''): int {
        global $wpdb;
        if ($text === '' || strlen($text) > 20000 || strlen($plural) > 20000) return 0;
        $table = $wpdb->prefix . 'yuz_tra_string_sources';
        $hash = self::identity($text, $domain, $context, $plural);
        $source = self::source_language($domain);
        $ok = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table (identity_hash,domain,context,original,plural_original,source_lang,created_at) VALUES (%s,%s,%s,%s,%s,%s,%s)", $hash, $domain, $context, $text, $plural, $source, gmdate('Y-m-d H:i:s')));
        if ($ok === false) throw new RuntimeException('catalog_write_failed');
        return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE identity_hash=%s", $hash));
    }

    /** Inventory writes are batched, avoiding two database round trips per string. */
    public static function collect_many(array $rows): int {
        global $wpdb;
        $count=0;
        foreach(array_chunk($rows,100) as $chunk) {
            $values=[];
            foreach($chunk as [$text,$domain,$context,$plural]) {
                if($text==='' || strlen($text)>20000 || strlen($plural)>20000 || strlen($domain)>191 || !preg_match('//u',$text.$domain.$context.$plural))continue;
                $values[]=$wpdb->prepare('(%s,%s,%s,%s,%s,%s,%s)',self::identity($text,$domain,$context,$plural),$domain,$context,$text,$plural,self::source_language($domain),gmdate('Y-m-d H:i:s'));
                $count++;
            }
            if($values && $wpdb->query("INSERT IGNORE INTO {$wpdb->prefix}yuz_tra_string_sources (identity_hash,domain,context,original,plural_original,source_lang,created_at) VALUES ".implode(',',$values))===false)throw new RuntimeException('catalog_write_failed');
        }
        return $count;
    }

    /** Standard gettext plural rules; evaluated by WordPress's safe POMO parser, never eval(). */
    public static function plural_rule(string $lang): array {
        static $rules;
        if ($rules === null) $rules=json_decode(file_get_contents(__DIR__.'/data/plural-rules.json'),true);
        $code=self::locale($lang);
        $rule=$rules[$code] ?? $rules[str_replace('_','-',$code)] ?? $rules[strtolower(explode('_',$code)[0])] ?? [2,'n != 1'];
        return apply_filters('yuz_tra_plural_rule',$rule,$lang);
    }

    public static function plural_index(string $lang, int $number): int {
        require_once ABSPATH . WPINC . '/pomo/plural-forms.php';
        [$count, $expression] = self::plural_rule($lang);
        static $parsers = [];
        if (!isset($parsers[$expression])) $parsers[$expression] = new Plural_Forms($expression);
        return min($count - 1, max(0, (int) $parsers[$expression]->get(abs($number))));
    }

    public static function tokens(string $text): array {
        preg_match_all('/%(?:\d+\$)?[-+0 #]*(?:\d+|\*)?(?:\.(?:\d+|\*))?[bcdeEfFgGosuxX]|\{\{?[^{}]+\}?\}|<\/?[a-zA-Z][^>]*>/', str_replace('%%', '', $text), $m);
        $tokens = $m[0]; sort($tokens); return $tokens;
    }

    public static function save(int $id, string $lang, array $forms, int $status, string $origin = 'manual', $expected = false): void {
        global $wpdb;
        if (!self::valid_language($lang) || !in_array($status, [1,2,3,4,5], true)) throw new InvalidArgumentException('invalid_language_or_status');
        $s = self::source($id);
        if (!$s) throw new InvalidArgumentException('unknown_string');
        $count = $s['plural_original'] === '' ? 1 : self::plural_rule($lang)[0];
        if (count($forms) !== $count) throw new InvalidArgumentException('incomplete_plural_forms');
        $singular_index = $s['plural_original'] === '' ? 0 : self::plural_index($lang, 1);
        foreach ($forms as $i => $text) {
            if (!is_string($text) || strlen($text) > 40000) throw new InvalidArgumentException('invalid_translation');
            $original = $i === $singular_index ? $s['original'] : $s['plural_original'];
            if ($status >= 2 && $status <= 4 && (trim($text) === '' || self::tokens($text) !== self::tokens($original))) throw new InvalidArgumentException('placeholders_or_empty_translation');
        }
        $t = $wpdb->prefix . 'yuz_tra_string_targets';
        if ($expected !== false) {
            $record=['forms'=>wp_json_encode(array_values($forms)),'status'=>$status,'origin'=>$origin,'attempts'=>0,'retry_after'=>null,'last_error'=>'','updated_at'=>gmdate('Y-m-d H:i:s')];
            if ($expected === null) {
                $ok=$wpdb->insert($t,array_merge(['source_id'=>$id,'lang'=>$lang],$record));
                if ($ok === false) throw new RuntimeException('concurrent_edit_preserved');
            } else {
                $where=['id'=>$expected['id'],'forms'=>$expected['forms'],'status'=>$expected['status'],'updated_at'=>$expected['updated_at']];
                $ok=$wpdb->update($t,$record,$where);
                if (!$ok) throw new RuntimeException('concurrent_edit_preserved');
            }
            self::$published=[];
            return;
        }
        $sql = $wpdb->prepare("INSERT INTO $t (source_id,lang,forms,status,origin,updated_at) VALUES (%d,%s,%s,%d,%s,%s) ON DUPLICATE KEY UPDATE forms=VALUES(forms),status=VALUES(status),origin=VALUES(origin),attempts=0,retry_after=NULL,last_error='',updated_at=VALUES(updated_at)", $id, $lang, wp_json_encode(array_values($forms)), $status, $origin, gmdate('Y-m-d H:i:s'));
        if ($wpdb->query($sql) === false) throw new RuntimeException('translation_write_failed');
        self::$published = [];
    }

    public static function source(int $id): ?array {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}yuz_tra_string_sources WHERE id=%d", $id), ARRAY_A);
    }

    public static function search(string $lang, string $q = '', string $domain = '', int $status = -1, int $page = 1): array {
        global $wpdb;
        if (!self::valid_language($lang)) throw new InvalidArgumentException('invalid_language');
        $s = $wpdb->prefix . 'yuz_tra_string_sources'; $t = $wpdb->prefix . 'yuz_tra_string_targets';
        $where = ['1=1']; $args = [$lang];
        if ($domain !== '') { $where[] = 's.domain=%s'; $args[] = $domain; }
        if ($q !== '') { $where[] = '(s.original LIKE %s OR s.context LIKE %s OR t.forms LIKE %s)'; $like = '%' . $wpdb->esc_like($q) . '%'; array_push($args, $like, $like, $like); }
        if ($status >= 0) { $where[] = 'COALESCE(t.status,0)=%d'; $args[] = $status; }
        $from = "FROM $s s LEFT JOIN $t t ON t.source_id=s.id AND t.lang=%s WHERE " . implode(' AND ', $where);
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) $from", $args));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT s.*,t.forms,COALESCE(t.status,0) AS status,t.origin,t.last_error $from ORDER BY s.id LIMIT 30 OFFSET %d", array_merge($args, [(max(1, $page)-1)*30])), ARRAY_A) ?: [];
        foreach ($rows as &$row) {
            $count = $row['plural_original'] === '' ? 1 : self::plural_rule($lang)[0];
            $row['forms'] = json_decode($row['forms'] ?? '', true) ?: array_fill(0, $count, '');
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => 30, 'domains' => $wpdb->get_col("SELECT DISTINCT domain FROM $s ORDER BY domain"), 'usage' => YUZ_Translation_Budget::usage()];
    }

    public static function translate(int $id, string $lang, int $status = 2, float $deadline = 0): void {
        global $wpdb;
        $table=$wpdb->prefix.'yuz_tra_string_targets';
        $before=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE source_id=%d AND lang=%s",$id,$lang),ARRAY_A);
        $s = self::source($id);
        if (!$s || !self::valid_language($lang)) throw new InvalidArgumentException('invalid_string_or_language');
        $count = $s['plural_original'] === '' ? 1 : self::plural_rule($lang)[0];
        $singular = $s['plural_original'] === '' ? 0 : self::plural_index($lang, 1);
        $native=self::native_forms($s,$lang,$count);
        if ($native !== null) {
            self::save($id,$lang,$native,$status,'catalog',$before);
            return;
        }
        $forms = [];
        for ($i=0; $i<$count; $i++) {
            if ($deadline && microtime(true)>=$deadline) throw new RuntimeException('translation_time_budget');
            $original = $i === $singular ? $s['original'] : $s['plural_original'];
            $placeholders=[];
            $protected=preg_replace_callback('/%(?:\d+\$)?[-+0 #]*(?:\d+|\*)?(?:\.(?:\d+|\*))?[bcdeEfFgGosuxX]|\{\{?[^{}]+\}?\}|<\/?[a-zA-Z][^>]*>/', static function($m) use (&$placeholders) {
                $key='YUZKEEP'.count($placeholders).'TOKEN'; $placeholders[$key]=$m[0]; return $key;
            },$original);
            $translated=YUZ_Services::tm()->translate_text($protected,$s['source_lang'],$lang,[
                'domain'=>$s['domain'],'context'=>$s['context'],'original'=>$original,
                'placeholders'=>$placeholders,'deadline'=>$deadline]);
            foreach($placeholders as $key=>$value) if(substr_count($translated,$key)!==1) throw new RuntimeException('provider_changed_placeholder');
            $forms[]=strtr($translated,$placeholders);
        }
        $after=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE source_id=%d AND lang=%s",$id,$lang),ARRAY_A);
        if ($before !== $after) throw new RuntimeException('concurrent_edit_preserved');
        self::save($id, $lang, $forms, YUZ_Services::tm()->requires_review() ? 2 : $status, 'machine', $before);
    }

    private static function native_forms(array $source,string $lang,int $count): ?array {
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/',$source['domain']) || !preg_match('/^[a-zA-Z0-9_@-]+$/',$lang)) return null;
        require_once ABSPATH.WPINC.'/pomo/mo.php';
        $domain=$source['domain'];
        $paths=[WP_LANG_DIR.'/plugins/'.$domain.'-'.$lang.'.mo',WP_LANG_DIR.'/themes/'.$domain.'-'.$lang.'.mo'];
        if($domain==='default') array_unshift($paths,WP_LANG_DIR.'/'.$lang.'.mo');
        global $wp_textdomain_registry;
        if (isset($wp_textdomain_registry) && method_exists($wp_textdomain_registry,'get')) {
            $dir=$wp_textdomain_registry->get($domain,$lang);
            if($dir) $paths[]=rtrim($dir,'/').'/'.$domain.'-'.$lang.'.mo';
        }
        static $catalogs=[];
        foreach(array_unique($paths) as $path) {
            if(!is_readable($path) || filesize($path)>20000000) continue;
            if(!isset($catalogs[$path])) { $mo=new MO(); $mo->import_from_file($path); $catalogs[$path]=$mo; }
            $entry=new Translation_Entry(['singular'=>$source['original'],'plural'=>$source['plural_original'],'context'=>$source['context']]);
            $found=$catalogs[$path]->translate_entry($entry);
            if($found && count($found->translations)===$count && count(array_filter($found->translations,'strlen'))===$count) return array_values($found->translations);
        }
        return null;
    }

    /** WordPress/Jed locale data for registered JavaScript translation domains. */
    public static function missing_script_translations($json, $file, $handle, $domain) {
        // WordPress reaches this fallback only after trying its native JSON catalogs.
        if ($json !== null || $file !== false) return $json;
        $merged=self::script_translations('', $file, $handle, $domain);
        return $merged !== '' ? $merged : null;
    }

    public static function script_translations($json, $file, $handle, $domain) {
        if (!in_array(get_option('yuz_tra_strings_schema'),['1','2'],true)) return $json;
        global $wpdb;
        $lang=self::language(); $short=explode('_',$lang)[0];
        $rows=$wpdb->get_results($wpdb->prepare("SELECT s.original,s.context,s.plural_original,t.forms FROM {$wpdb->prefix}yuz_tra_string_sources s JOIN {$wpdb->prefix}yuz_tra_string_targets t ON t.source_id=s.id WHERE s.domain=%s AND t.status=4 AND t.lang IN (%s,%s) ORDER BY (t.lang=%s) ASC",$domain,$lang,$short,$lang),ARRAY_A);
        if (!$rows) return $json;
        $data=json_decode($json ?: '{}',true) ?: [];
        $key=isset($data['locale_data'][$domain])?$domain:'messages';
        [$count,$expression]=self::plural_rule($lang);
        $data['locale_data'][$key]['']=['domain'=>$domain,'lang'=>$lang,'plural-forms'=>"nplurals=$count; plural=$expression;"];
        foreach ($rows as $row) {
            $original=$row['context']!==''?$row['context']."\x04".$row['original']:$row['original'];
            $data['locale_data'][$key][$original]=json_decode($row['forms'],true);
        }
        return wp_json_encode($data);
    }
}
