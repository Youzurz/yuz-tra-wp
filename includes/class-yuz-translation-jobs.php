<?php
defined('ABSPATH') || exit;

/** Persistent, bounded jobs. Polling is read-only; only the worker advances progress. */
final class YUZ_Translation_Jobs {
    private static function key(string $id): string {
        if (!preg_match('/^[a-f0-9-]{36}$/D', $id)) throw new InvalidArgumentException('invalid_job_id');
        return 'yuz_tra_job_' . $id;
    }
    public static function create(array $texts, string $source, string $target): string {
        if (!$texts || count($texts) > 100 || $source === '' || $target === '' || $target === 'auto') {
            throw new InvalidArgumentException('invalid_job_request');
        }
        $bytes = 0;
        foreach ($texts as $text) {
            if (!is_string($text) || $text === '' || strlen($text) > 20000) throw new InvalidArgumentException('invalid_job_text');
            $bytes += strlen($text);
        }
        if ($bytes > 200000) throw new InvalidArgumentException('job_payload_too_large');
        if (!has_action('yuz_tra_run_job')) throw new RuntimeException('translation_worker_unavailable');
        $id = wp_generate_uuid4();
        $job = ['id'=>$id, 'owner'=>get_current_user_id(), 'status'=>'queued', 'created_at'=>time(),
            'updated_at'=>time(), 'source'=>$source, 'target'=>$target, 'texts'=>array_values($texts),
            'total'=>count($texts), 'completed'=>0, 'translations'=>[], 'error'=>null];
        if (!add_option(self::key($id), $job, '', false)) throw new RuntimeException('job_storage_failed');
        try { self::schedule($id); }
        catch (Throwable $e) { $job['status']='failed'; $job['error']=$e->getMessage(); self::save($job); throw $e; }
        return $id;
    }
    private static function schedule(string $id): void {
        if (wp_next_scheduled('yuz_tra_run_job', [$id])) return;
        $result = wp_schedule_single_event(time()+10, 'yuz_tra_run_job', [$id], true);
        if (is_wp_error($result) || !$result) throw new RuntimeException('job_schedule_failed');
    }
    private static function save(array $job): void {
        $job['updated_at']=time();
        update_option(self::key($job['id']), $job, false);
        if (get_option(self::key($job['id'])) !== $job) throw new RuntimeException('job_storage_failed');
    }
    public static function status(string $id): array {
        $job = get_option(self::key($id));
        if (!is_array($job)) throw new RuntimeException('job_not_found');
        unset($job['texts']);
        $job['progress'] = $job['total'] ? (int)floor(100*$job['completed']/$job['total']) : 0;
        $job['scheduled_at'] = wp_next_scheduled('yuz_tra_run_job', [$id]) ?: null;
        // A queued job is not a completed translation, even when cron is delayed/disabled.
        $job['cost_cents'] = null;
        return $job;
    }
    public static function run(string $id): void {
        global $wpdb;
        $key = self::key($id);
        $lock = 'yuz-job-'.substr(hash('sha256',$wpdb->prefix.$id),0,48);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock)) !== 1) return;
        try {
            $job = get_option($key);
            if (!is_array($job) || in_array($job['status'], ['completed','failed'],true)) return;
            $job['status']='processing'; self::save($job);
            // Schedule recovery before HTTP: process death cannot silently lose the job.
            self::schedule($id);
            $settings = YUZ_Translation_Budget::settings();
            $limit = min(10,max(1,(int)($settings['worker_batch_size'] ?? 3)));
            $deadline = microtime(true)+min(120,max(5,(int)($settings['worker_time_budget'] ?? 35)));
            try {
                for ($n=0; $n<$limit && $job['completed']<$job['total'] && microtime(true)<$deadline; $n++) {
                    $index = $job['completed'];
                    $translated = YUZ_Services::tm()->translate_text($job['texts'][$index],$job['source'],$job['target']);
                    if ($translated === '') throw new RuntimeException('empty_from_provider');
                    $job['translations'][$index]=$translated;
                    $job['completed']++;
                    self::save($job);
                }
                if ($job['completed'] === $job['total']) {
                    $job['status']='completed';
                    // Raw inputs no longer needed; outputs remain available to the requester.
                    $job['texts']=[];
                    wp_clear_scheduled_hook('yuz_tra_run_job',[$id]);
                } else {
                    // A concurrent cron runner may have consumed the recovery event while locked.
                    self::schedule($id);
                }
            } catch (Throwable $e) {
                // No automatic paid retry. Completed outputs remain inspectable.
                $job['status']='failed'; $job['error']=substr($e->getMessage(),0,191);
                wp_clear_scheduled_hook('yuz_tra_run_job',[$id]);
            }
            self::save($job);
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
}
