<?php
defined('ABSPATH') || exit;

/** Shared fail-closed budget. Failed attempts also consume quota. All buckets use UTC. */
final class YUZ_Translation_Budget {
    /** Return a non-negative decimal setting without treating blank as a price. */
    private static function decimal(array $settings, string $key, float $default = 0.0): float {
        if (!array_key_exists($key, $settings) || !is_scalar($settings[$key]) || !is_numeric($settings[$key])) return $default;
        return max(0.0, (float) $settings[$key]);
    }

    /** Calculate money from measured tokens; this is an estimate, never an invoice claim. */
    public static function financial_snapshot(): array {
        $usage = self::usage();
        $settings = self::settings();
        $input_price = self::decimal($settings, 'input_cost_usd_per_million');
        $output_price = self::decimal($settings, 'output_cost_usd_per_million');
        $sale_price = self::decimal($settings, 'sale_price_usd_per_million');
        $fixed_cost = self::decimal($settings, 'fixed_monthly_cost_usd');
        $reserve = self::decimal($settings, 'minimum_balance_usd');
        $input_cost = $usage['input_tokens'] / 1000000 * $input_price;
        $output_cost = $usage['output_tokens'] / 1000000 * $output_price;
        $revenue = ($usage['input_tokens'] + $usage['output_tokens']) / 1000000 * $sale_price;
        $variable_cost = $input_cost + $output_cost;
        return [
            'input_cost_usd' => round($input_cost, 8),
            'output_cost_usd' => round($output_cost, 8),
            'variable_cost_usd' => round($variable_cost, 8),
            'estimated_revenue_usd' => round($revenue, 8),
            'estimated_margin_usd' => round($revenue - $variable_cost, 8),
            'fixed_monthly_cost_usd' => round($fixed_cost, 8),
            'estimated_monthly_margin_usd' => round($revenue - $variable_cost - $fixed_cost, 8),
            'minimum_balance_usd' => round($reserve, 8),
            'prices_source' => (string) ($settings['pricing_source'] ?? 'administrator estimate'),
            'is_profitable' => $revenue >= $variable_cost + $fixed_cost,
            'is_above_reserve' => $reserve <= 0.0 || $variable_cost < $reserve,
            'currency' => 'USD',
        ];
    }

    /** Fail closed before a paid request when the configured reserve would be crossed. */
    public static function assert_affordable(int $input_tokens, int $output_tokens = 0): void {
        $settings = self::settings();
        $reserve = self::decimal($settings, 'minimum_balance_usd');
        $available = self::decimal($settings, 'available_balance_usd');
        $input_price = self::decimal($settings, 'input_cost_usd_per_million');
        $output_price = self::decimal($settings, 'output_cost_usd_per_million');
        $estimate = ($input_tokens / 1000000 * $input_price) + ($output_tokens / 1000000 * $output_price);
        if ($available > 0 && $available - $estimate < $reserve) throw new RuntimeException('financial_reserve_would_be_crossed');
    }
    public static function record_metrics(int $input, int $output, int $milliseconds): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->prefix}yuz_tra_translation_usage (bucket) VALUES (%s)",'day:'.gmdate('Y-m-d')));
        $ok=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}yuz_tra_translation_usage SET input_tokens=input_tokens+%d,output_tokens=output_tokens+%d,duration_ms=duration_ms+%d,measured_requests=measured_requests+1 WHERE bucket=%s",$input,$output,$milliseconds,'day:'.gmdate('Y-m-d')));
        if ($ok!==1) throw new RuntimeException('metrics_storage_failed');
    }
    public static function settings(): array {
        $s = get_option('yuz_tra_at_settings', []);
        return is_array($s) && $s ? $s : (array) get_option('yuz_tra_api_settings', []);
    }
    public static function usage(): array {
        global $wpdb;
        $s = self::settings(); $t = $wpdb->prefix . 'yuz_tra_translation_usage';
        $day = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE bucket=%s", 'day:' . gmdate('Y-m-d')), ARRAY_A) ?: [];
        $minute = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE bucket=%s", 'minute:' . gmdate('Y-m-d-H-i')), ARRAY_A) ?: [];
        return ['input_tokens'=>(int)($day['input_tokens'] ?? 0),'output_tokens'=>(int)($day['output_tokens'] ?? 0),'duration_ms'=>(int)($day['duration_ms'] ?? 0),'measured_requests'=>(int)($day['measured_requests'] ?? 0),'characters' => (int)($day['characters'] ?? 0), 'attempts' => (int)($day['requests'] ?? 0), 'successes' => (int)($day['successes'] ?? 0), 'failures' => (int)($day['failures'] ?? 0), 'cache_hits' => (int)($day['cache_hits'] ?? 0), 'minute_requests' => (int)($minute['requests'] ?? 0), 'char_limit' => max(1,(int)($s['char_limit'] ?? 50000)), 'requests_limit' => max(1,(int)($s['requests_limit'] ?? 100)), 'timezone' => 'UTC'];
    }
    public static function run(string $text, string $fingerprint, callable $translate): string {
        global $wpdb;
        $lock='yuz-tra-text-'.substr(hash('sha256',$wpdb->prefix.'|'.$fingerprint.'|'.$text),0,40);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1) throw new RuntimeException('translation_in_progress');
        try { return self::run_locked($text,$fingerprint,$translate); }
        finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    private static function run_locked(string $text, string $fingerprint, callable $translate): string {
        global $wpdb;
        if (!YUZ_DB::ensure_string_tables()) throw new RuntimeException('translation_storage_unavailable');
        $cache_key = 'yuz_tra_text_' . hash('sha256', $fingerprint . '|' . $text);
        $cached = get_transient($cache_key);
        $table = $wpdb->prefix . 'yuz_tra_translation_usage';
        $day = 'day:' . gmdate('Y-m-d'); $minute = 'minute:' . gmdate('Y-m-d-H-i');
        foreach ([$day,$minute] as $bucket) {
            if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table (bucket) VALUES (%s)", $bucket)) === false) throw new RuntimeException('budget_storage_unavailable');
        }
        if (is_string($cached) && $cached !== '') {
            $wpdb->query($wpdb->prepare("UPDATE $table SET cache_hits=cache_hits+1 WHERE bucket=%s", $day));
            return $cached;
        }
        $lock = 'yuz-tra-budget-' . substr(hash('sha256', $table),0,40);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,1)', $lock)) !== 1) throw new RuntimeException('budget_busy');
        try {
            // A request may cross a minute/day boundary while waiting for the lock.
            $day='day:'.gmdate('Y-m-d'); $minute='minute:'.gmdate('Y-m-d-H-i');
            foreach([$day,$minute] as $bucket) if($wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table (bucket) VALUES (%s)",$bucket))===false)throw new RuntimeException('budget_storage_unavailable');
            $usage = self::usage();
            $characters = function_exists('mb_strlen') ? mb_strlen($text,'UTF-8') : strlen($text);
            if ($usage['characters'] + $characters > $usage['char_limit']) throw new RuntimeException('daily_character_limit');
            if ($usage['minute_requests'] >= $usage['requests_limit']) throw new RuntimeException('minute_request_limit');
            $ok = $wpdb->query($wpdb->prepare("UPDATE $table SET characters=characters+%d,requests=requests+1 WHERE bucket IN (%s,%s)", $characters,$day,$minute));
            if ($ok !== 2) throw new RuntimeException('budget_reservation_failed');
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
        try {
            $result = $translate();
            if (!is_string($result) || trim($result) === '') throw new RuntimeException('empty_provider_translation');
            $wpdb->query($wpdb->prepare("UPDATE $table SET successes=successes+1 WHERE bucket=%s", $day));
            set_transient($cache_key, $result, DAY_IN_SECONDS);
            return $result;
        } catch (Throwable $e) {
            $wpdb->query($wpdb->prepare("UPDATE $table SET failures=failures+1 WHERE bucket=%s", $day));
            throw $e;
        }
    }
}
