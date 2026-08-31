<?php
defined('ABSPATH') || exit;
require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';

/** Bounded pending-string worker. No rescanning of posts and no implicit publication of manual drafts. */
class YUZ_Cron {
    private static $booted = false;
    public static function init() {
        if (self::$booted) return;
        self::$booted = true;
        add_action('yuz_tra_run_job', ['YUZ_Translation_Jobs', 'run']);
        add_action('yuz_tra_batch_translate', [__CLASS__, 'run_batch']);
        add_action('yuz_auto_translation_tick', [__CLASS__, 'run_batch']);
        add_filter('cron_schedules', [__CLASS__, 'add_cron_intervals']);
        add_action('update_option_yuz_tra_at_settings', [__CLASS__, 'reconcile_schedule'], 20, 0);
        self::reconcile_schedule();
    }
    public static function reconcile_schedule(): void {
        $s = YUZ_Translation_Budget::settings();
        $enabled = !empty($s['enable_auto_translate']) && in_array($s['translation_mode'] ?? 'manual', ['silent','all','auto'], true);
        if (!$enabled) {
            if (wp_next_scheduled('yuz_tra_batch_translate')) wp_clear_scheduled_hook('yuz_tra_batch_translate');
            if (wp_next_scheduled('yuz_auto_translation_tick')) wp_clear_scheduled_hook('yuz_auto_translation_tick');
            return;
        }
        $interval = $s['cron_interval'] ?? 'hourly';
        if (!isset(wp_get_schedules()[$interval])) $interval = 'hourly';
        $event = wp_get_scheduled_event('yuz_tra_batch_translate');
        if ($event && $event->schedule !== $interval) wp_clear_scheduled_hook('yuz_tra_batch_translate');
        if (!wp_next_scheduled('yuz_tra_batch_translate')) wp_schedule_event(time()+60, $interval, 'yuz_tra_batch_translate');
    }
    public static function add_cron_intervals($schedules) {
        $schedules['every_five_minutes'] = ['interval'=>300,'display'=>'Every five minutes'];
        return $schedules;
    }
    public static function run_batch() {
        $s = YUZ_Translation_Budget::settings();
        if (!wp_doing_cron() || empty($s['enable_auto_translate']) || !in_array($s['translation_mode'] ?? 'manual',['silent','all','auto'],true)) return ['processed'=>0,'reason'=>'disabled'];
        return self::process(min(10,max(1,(int)($s['worker_batch_size'] ?? 3))));
    }
    public static function process(int $limit = 3, int $status = 4): array {
        global $wpdb;
        if (!YUZ_DB::ensure_string_tables()) throw new RuntimeException('strings_storage_unavailable');
        $lock = 'yuz-tra-worker-' . substr(hash('sha256',$wpdb->prefix),0,40);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock)) !== 1) return ['processed'=>0,'reason'=>'busy'];
        $report=['processed'=>0,'failed'=>0,'errors'=>[]];
        $settings=YUZ_Translation_Budget::settings();
        $seconds=min(120,max(5,(int)($settings['worker_time_budget'] ?? 35)));
        try {
            $sources=$wpdb->prefix.'yuz_tra_string_sources'; $targets=$wpdb->prefix.'yuz_tra_string_targets';
            $langs=$wpdb->get_results("SELECT language_code AS code FROM {$wpdb->prefix}yuz_tra_languages WHERE is_translatable=1 ORDER BY language_weight,id",ARRAY_A) ?: [];
            $cursor=(int)get_option('yuz_tra_worker_language',0);
            $started=microtime(true);
            for($offset=0;$offset<count($langs) && $report['processed']+$report['failed']<min(10,max(1,$limit));$offset++) {
                $index=($cursor+$offset)%count($langs); $lang=YUZ_String_Catalog::locale($langs[$index]['code']);
                $remaining=min(10,max(1,$limit))-$report['processed']-$report['failed'];
                $ids=$wpdb->get_col($wpdb->prepare("SELECT s.id FROM $sources s LEFT JOIN $targets t ON t.source_id=s.id AND t.lang=%s WHERE t.id IS NULL OR (t.status=0 AND t.attempts<3 AND (t.retry_after IS NULL OR t.retry_after<=UTC_TIMESTAMP())) ORDER BY s.id LIMIT %d",$lang,$remaining));
                foreach($ids as $id) {
                    if(microtime(true)-$started>$seconds) break 2;
                    try {
                        YUZ_String_Catalog::translate((int)$id,$lang,$status,$started+$seconds);
                        $report['processed']++;
                    } catch(Throwable $e) {
                        $message=substr($e->getMessage(),0,150);
                        $report['failed']++; $report['errors'][]=$message;
                        if(in_array($message,['daily_character_limit','minute_request_limit','budget_busy','translation_time_budget','translation_in_progress'],true)) break 2;
                        $wpdb->query($wpdb->prepare("INSERT INTO $targets (source_id,lang,forms,status,origin,attempts,retry_after,last_error,updated_at) VALUES (%d,%s,'[]',0,'machine',1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),%s,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE attempts=IF(status=0,attempts+1,attempts),retry_after=IF(status=0,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE),retry_after),last_error=IF(status=0,VALUES(last_error),last_error)",$id,$lang,$message));
                    }
                }
            }
            if($langs) update_option('yuz_tra_worker_language',($cursor+1)%count($langs),false);
            update_option('yuz_tra_worker_last',['time'=>gmdate('c'),'result'=>$report],false);
            // Retention keeps the usage ledger bounded without touching translation content.
            $wpdb->query("DELETE FROM {$wpdb->prefix}yuz_tra_translation_usage WHERE bucket LIKE 'minute:%' AND bucket < CONCAT('minute:',DATE_FORMAT(UTC_TIMESTAMP()-INTERVAL 2 DAY,'%Y-%m-%d-%H-%i'))");
            return $report;
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
}
// On hook tout de suite l'init() pour charger le Cron
add_action( 'init', [ 'YUZ_Cron', 'init' ], 15 );
