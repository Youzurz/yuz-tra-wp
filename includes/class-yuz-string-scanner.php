<?php
defined('ABSPATH') || exit;

/** Bounded inventory of literal Gettext calls; never executes the scanned PHP. */
final class YUZ_String_Scanner {
    public static function start(): array {
        $roots = [ABSPATH . 'wp-admin', ABSPATH . WPINC, get_template_directory(), get_stylesheet_directory()];
        $plugins = array_unique(array_merge((array)get_option('active_plugins', []), array_keys((array)get_site_option('active_sitewide_plugins', []))));
        foreach ($plugins as $plugin) {
            $path = WP_PLUGIN_DIR . '/' . $plugin;
            $roots[] = strpos($plugin,'/') === false ? $path : dirname($path);
        }
        $files = [];
        foreach (array_unique($roots) as $root) {
            if (is_file($root)) { $files[$root] = true; continue; }
            if (!is_dir($root)) continue;
            $filter = new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), static function ($entry) {
                return !$entry->isLink() && !in_array($entry->getFilename(), ['node_modules','vendor','.git','tests','reports','full-content','patches'], true);
            });
            foreach (new RecursiveIteratorIterator($filter) as $file) {
                if (count($files) >= 30000) throw new RuntimeException('scan_file_limit_select_fewer_plugins');
                if ($file->isFile() && in_array(strtolower($file->getExtension()), ['php','js'], true) && $file->getSize() < 2000000) $files[$file->getPathname()] = true;
            }
        }
        $state = ['files' => array_keys($files), 'offset' => 0, 'strings' => 0, 'started' => time()];
        update_option('yuz_tra_string_scan', $state, false);
        return self::step();
    }

    public static function step(): array {
        $state = get_option('yuz_tra_string_scan', []);
        if (empty($state['files'])) return ['done' => true, 'scanned' => 0, 'total' => 0, 'strings' => 0];
        $started = microtime(true); $count = 0;
        while ($state['offset'] < count($state['files']) && $count < 100 && microtime(true)-$started < 4) {
            $path = $state['files'][$state['offset']++]; $count++;
            if (!is_readable($path)) continue;
            $code = file_get_contents($path);
            $state['strings']+=YUZ_String_Catalog::collect_many(self::extract($code, pathinfo($path, PATHINFO_EXTENSION)));
        }
        $done = $state['offset'] >= count($state['files']);
        $result = ['done' => $done, 'scanned' => $state['offset'], 'total' => count($state['files']), 'strings' => $state['strings']];
        update_option('yuz_tra_string_scan', $done ? [] : $state, false);
        update_option('yuz_tra_string_scan_last', $result, false);
        return $result;
    }

    public static function extract(string $code, string $extension = 'php'): array {
        $out = [];
        $signatures = ['__'=>[0,null,null,1], '_e'=>[0,null,null,1], 'esc_html__'=>[0,null,null,1], 'esc_attr__'=>[0,null,null,1], 'esc_html_e'=>[0,null,null,1], 'esc_attr_e'=>[0,null,null,1], '_x'=>[0,null,1,2], '_ex'=>[0,null,1,2], 'esc_html_x'=>[0,null,1,2], 'esc_attr_x'=>[0,null,1,2], '_n'=>[0,1,null,3], '_nx'=>[0,1,3,4], '_n_noop'=>[0,1,null,2], '_nx_noop'=>[0,1,2,3]];
        if ($extension === 'js') {
            // JS parser only accepts literal arguments; variables are left to runtime/catalogs.
            preg_match_all('/(?<![\w$])(__|_x|_n|_nx)\s*\)?\s*\(((?:[^()"\x27]|"(?:\\\\.|[^"\\\\])*"|\x27(?:\\\\.|[^\x27\\\\])*\x27)*)\)/s', $code, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                preg_match_all('/(?:"(?:\\\\.|[^"\\\\])*"|\x27(?:\\\\.|[^\x27\\\\])*\x27|[^,])+/', $m[2], $parts);
                $args = array_map([__CLASS__,'js_literal'],$parts[0]);
                self::append($out,$args,$signatures[$m[1]]);
            }
            return $out;
        }
        $tokens = token_get_all($code);
        for ($i=0,$len=count($tokens); $i<$len; $i++) {
            if (!is_array($tokens[$i]) || !in_array($tokens[$i][0],[T_STRING,T_NAME_FULLY_QUALIFIED],true)) continue;
            $name=ltrim($tokens[$i][1],'\\');
            if (!isset($signatures[$name])) continue;
            $j=$i+1; while ($j<$len && is_array($tokens[$j]) && $tokens[$j][0]===T_WHITESPACE) $j++;
            if (($tokens[$j]??null)!=='(') continue;
            $args=[]; $current=[]; $depth=1;
            for ($j++;$j<$len;$j++) {
                $tok=$tokens[$j];
                if ($tok==='(' || $tok==='[') $depth++;
                if ($tok===')' || $tok===']') $depth--;
                if ($depth===0 || ($tok===',' && $depth===1)) { $args[]=self::literal($current); $current=[]; if ($depth===0) break; }
                else $current[]=$tok;
            }
            self::append($out,$args,$signatures[$name]);
        }
        return $out;
    }
    private static function js_literal(string $value): ?string {
        $value=trim($value);
        if(!preg_match('/^(["\x27])(?:\\\\.|(?!\1).)*\1$/s',$value))return null;
        return preg_replace_callback('/\\\\(u[0-9a-fA-F]{4}(?:\\\\u[0-9a-fA-F]{4})?|x[0-9a-fA-F]{2}|.)/s',static function($m){
            $escaped=$m[1];
            if($escaped[0]==='u')return json_decode('"\\'.$escaped.'"',true) ?? '';
            if($escaped[0]==='x')return json_decode('"\\u00'.substr($escaped,1).'"',true) ?? '';
            return ['n'=>"\n",'r'=>"\r",'t'=>"\t",'b'=>"\x08",'f'=>"\x0c",'v'=>"\x0b",'0'=>"\0","\n"=>''][$escaped] ?? $escaped;
        },substr($value,1,-1));
    }
    private static function literal(array $tokens): ?string {
        $value=''; $seen=false;
        foreach ($tokens as $t) {
            if ($t==='.' || (is_array($t) && in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))) continue;
            if (!is_array($t) || $t[0]!==T_CONSTANT_ENCAPSED_STRING) return null;
            $s=$t[1]; $raw=substr($s,1,-1);
            $value .= $s[0]==="'" ? str_replace(["\\'","\\\\"],["'","\\"],$raw) : stripcslashes($raw);
            $seen=true;
        }
        return $seen ? $value : null;
    }
    private static function append(array &$out,array $args,array $signature): void {
        [$text,$plural,$context,$domain]=$signature;
        if (!isset($args[$text]) || ($plural!==null && !isset($args[$plural])) || ($context!==null && !isset($args[$context]))) return;
        if (array_key_exists($domain,$args) && $args[$domain]===null) return;
        if(isset($args[$domain]) && !preg_match('/^[a-zA-Z0-9_.-]{1,191}$/',$args[$domain]))return;
        $out[]=[$args[$text],$args[$domain]??'default',$context===null?'':$args[$context],$plural===null?'':$args[$plural]];
    }
}
