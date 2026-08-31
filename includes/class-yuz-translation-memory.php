<?php
defined('ABSPATH') || exit;

/** Approved records only. Per-site tables and exact locale/domain/context boundaries. */
final class YUZ_Translation_Memory {
    public static function approve(int $id, string $lang, array $expected): void {
        global $wpdb;
        $user=get_current_user_id();
        if (!$user) throw new RuntimeException('human_approval_required');
        $s=YUZ_String_Catalog::source($id);
        $t=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}yuz_tra_string_targets WHERE source_id=%d AND lang=%s",$id,$lang),ARRAY_A);
        if (!$s || !$t || !in_array((int)$t['status'],[3,4],true) || json_decode($t['forms'],true)!==$expected) {
            throw new RuntimeException('save_reviewed_translation_before_approval');
        }
        $table=$wpdb->prefix.'yuz_tra_approved_memory';
        $data=['source_id'=>$id,'lang'=>$lang,'source_lang'=>$s['source_lang'],'forms'=>$t['forms'],
            'approved_by'=>$user,'approved_at'=>gmdate('Y-m-d H:i:s')];
        if ($wpdb->replace($table,$data)===false) throw new RuntimeException('memory_write_failed');
    }
    /** Editing, archiving or changing source language invalidates an approval automatically. */
    public static function records(string $source, string $target, string $domain, string $context, string $original = ''): array {
        global $wpdb;
        if (!YUZ_DB::ensure_string_tables()) throw new RuntimeException('memory_storage_unavailable');
        return $wpdb->get_results($wpdb->prepare("SELECT s.id,s.original,s.plural_original,m.forms,m.approved_by,m.approved_at
            FROM {$wpdb->prefix}yuz_tra_approved_memory m
            JOIN {$wpdb->prefix}yuz_tra_string_sources s ON s.id=m.source_id
            JOIN {$wpdb->prefix}yuz_tra_string_targets t ON t.source_id=m.source_id AND t.lang=m.lang
            WHERE m.source_lang=%s AND s.source_lang=m.source_lang AND m.lang=%s
            AND s.domain=%s AND BINARY s.context=BINARY %s AND BINARY m.forms=BINARY t.forms
            AND t.status IN (3,4) AND m.approved_by>0 ORDER BY (BINARY s.original=BINARY %s) DESC,m.approved_at DESC LIMIT 200",
            $source,$target,$domain,$context,$original),ARRAY_A) ?: [];
    }
    public static function retrieve(string $text, string $source, string $target, array $context): array {
        $domain=(string)($context['domain'] ?? 'default');
        $ctx=(string)($context['context'] ?? '');
        $original=(string)($context['original'] ?? $text);
        $result=['exact'=>null,'examples'=>[],'terms'=>[]];
        $words=array_unique(preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower($original),-1,PREG_SPLIT_NO_EMPTY) ?: []);
        foreach (self::records($source,$target,$domain,$ctx,$original) as $row) {
            // Plural conversions require separate grammatical review; never guess a plural form.
            if ($row['plural_original']!=='') continue;
            $forms=json_decode($row['forms'],true);
            if (!is_array($forms) || !isset($forms[0])) continue;
            if ($row['original']===$original) {
                $result['exact']=(string)$forms[0];
                break;
            }
            $score=count(array_intersect($words,preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower($row['original']),-1,PREG_SPLIT_NO_EMPTY) ?: []));
            if ($score>0) $result['examples'][]=['id'=>(int)$row['id'],'source'=>$row['original'],'target'=>$forms[0],'score'=>$score];
        }
        usort($result['examples'],static fn($a,$b)=>$b['score']<=>$a['score']);
        $result['examples']=array_slice($result['examples'],0,3);
        global $wpdb;
        $terms=$wpdb->get_results($wpdb->prepare("SELECT id,source_text,target_text FROM {$wpdb->prefix}yuz_tra_glossary
            WHERE source_lang=%s AND target_lang=%s AND domain=%s AND BINARY context=BINARY %s
            AND approved_by>0 ORDER BY id DESC LIMIT 1000",$source,$target,$domain,$ctx),ARRAY_A) ?: [];
        foreach ($terms as $term) {
            if (mb_stripos($original,$term['source_text'])!==false) $result['terms'][]=$term;
            if (count($result['terms'])>=12) break;
        }
        return $result;
    }
    /** CSV header: source_lang,target_lang,domain,context,source,target. Atomic validated import. */
    public static function import_csv(string $csv): int {
        global $wpdb;
        if (strlen($csv)>200000 || !get_current_user_id()) throw new InvalidArgumentException('invalid_glossary_import');
        $stream=fopen('php://temp','r+');
        fwrite($stream,$csv); rewind($stream);
        try {
            $header=fgetcsv($stream,0,',','"','');
            if ($header!==['source_lang','target_lang','domain','context','source','target']) throw new InvalidArgumentException('invalid_glossary_header');
            $rows=[];
            while (($row=fgetcsv($stream,0,',','"',''))!==false) {
                if ($row===[null]) continue;
                if (count($row)!==6 || count($rows)>=500) throw new InvalidArgumentException('invalid_glossary_rows');
                [$src,$tgt,$domain,$ctx,$text,$translation]=$row;
                foreach ([$src,$tgt] as $lang) if (!preg_match('/^[a-zA-Z]{2,3}(?:[_-][a-zA-Z0-9]+)*$/D',$lang)) throw new InvalidArgumentException('invalid_glossary_locale');
                if (!preg_match('/^[a-zA-Z0-9_.-]{1,191}$/D',$domain) || $text==='' || $translation==='' || strlen($text)>1000 || strlen($translation)>2000 || strlen($ctx)>1000) throw new InvalidArgumentException('invalid_glossary_term');
                if (YUZ_String_Catalog::tokens($text)!==YUZ_String_Catalog::tokens($translation)) throw new InvalidArgumentException('glossary_placeholder_mismatch');
                $src=str_replace('-','_',$src); $tgt=str_replace('-','_',$tgt);
                $rows[]=['identity_hash'=>hash('sha256',wp_json_encode([$src,$tgt,$domain,$ctx,$text])),
                    'source_lang'=>$src,'target_lang'=>$tgt,'domain'=>$domain,'context'=>$ctx,
                    'source_text'=>$text,'target_text'=>$translation,'approved_by'=>get_current_user_id(),'approved_at'=>gmdate('Y-m-d H:i:s')];
            }
        } finally { fclose($stream); }
        if (!$rows) throw new InvalidArgumentException('empty_glossary');
        if (!YUZ_DB::ensure_string_tables()) throw new RuntimeException('memory_storage_unavailable');
        $wpdb->query('START TRANSACTION');
        try {
            foreach ($rows as $row) if ($wpdb->replace($wpdb->prefix.'yuz_tra_glossary',$row)===false) throw new RuntimeException('glossary_write_failed');
            if ($wpdb->query('COMMIT')===false) throw new RuntimeException('glossary_commit_failed');
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
        return count($rows);
    }
}
