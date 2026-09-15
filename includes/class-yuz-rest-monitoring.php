<?php
// REST endpoint for lightweight RUM/error telemetry.
defined('ABSPATH') || exit;

class YUZ_Rest_Monitoring
{
    private const ROUTE_NAMESPACE = 'yuz/v1';
    private const ROUTE_PATH = '/jslog';
    private const MAX_EVENTS = 10;
    private const MAX_STRING_LEN = 512;
    private const MAX_DETAIL_KEYS = 10;
    private const RATE_LIMIT_COUNT = 30; // max events per window per client
    private const RATE_LIMIT_WINDOW = 300; // seconds

    public static function init(): void
    {
        $enabled = defined('YUZ_TRA_RUM') && YUZ_TRA_RUM;
        if (!$enabled) {
            return;
        }
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(): void
    {
        register_rest_route(
            self::ROUTE_NAMESPACE,
            self::ROUTE_PATH,
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [__CLASS__, 'handle_log'],
                'permission_callback' => '__return_true',
                'args'                => [],
            ]
        );
    }

    public static function handle_log(WP_REST_Request $request)
    {
        if (self::is_rate_limited($request)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'rate_limited'], 429);
        }

        $events = self::normalize_payload($request->get_json_params());
        if (!$events) {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid_payload'], 400);
        }

        $client = self::client_context();
        foreach ($events as $event) {
            $log = [
                'ip'      => $client['ip'],
                'ua'      => $client['ua'],
                'referer' => $client['referer'],
                'event'   => $event,
            ];
            yuz_tra_debug_log('[YUZ-RUM] ' . wp_json_encode($log, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return new WP_REST_Response(['ok' => true], 200);
    }

    private static function normalize_payload($data): array
    {
        if (!is_array($data)) {
            return [];
        }

        // Allow either a single event or an indexed array of events.
        $events = array_values(isset($data[0]) ? $data : [$data]);
        $events = array_slice($events, 0, self::MAX_EVENTS);

        $sanitized = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $sanitized_event = self::sanitize_event($event);
            if ($sanitized_event) {
                $sanitized[] = $sanitized_event;
            }
        }

        return $sanitized;
    }

    private static function sanitize_event(array $event): array
    {
        $allowed_keys = ['t', 'msg', 'src', 'ln', 'col', 'detail', 'ts'];
        $filtered = array_intersect_key($event, array_flip($allowed_keys));
        if (!$filtered) {
            return [];
        }

        $result = [];
        foreach ($filtered as $key => $value) {
            switch ($key) {
                case 'ln':
                case 'col':
                    $result[$key] = max(0, (int) $value);
                    break;
                case 'ts':
                    $result[$key] = (int) $value;
                    break;
                case 'detail':
                    $result[$key] = self::sanitize_detail($value);
                    break;
                default:
                    $result[$key] = self::truncate_string($value);
                    break;
            }
        }

        return $result;
    }

    private static function sanitize_detail($value)
    {
        if (is_array($value)) {
            $detail = [];
            foreach (array_slice($value, 0, self::MAX_DETAIL_KEYS) as $k => $v) {
                $detail[self::truncate_string($k)] = self::detail_value($v);
            }
            return $detail;
        }

        return self::truncate_string($value);
    }

    private static function detail_value($value)
    {
        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return self::truncate_string((string) $value);
        }
        if (is_array($value)) {
            $summary = [];
            foreach (array_slice($value, 0, self::MAX_DETAIL_KEYS) as $k => $v) {
                $summary[self::truncate_string($k)] = self::detail_value($v);
            }
            return $summary;
        }
        return null;
    }

    private static function truncate_string($value): string
    {
        $string = is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
        $string = wp_strip_all_tags($string);
        if (strlen($string) > self::MAX_STRING_LEN) {
            $string = substr($string, 0, self::MAX_STRING_LEN);
        }
        return $string;
    }

    private static function client_context(): array
    {
        $ip = self::detect_ip();
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? self::truncate_string(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))) : '';
        $referer = isset($_SERVER['HTTP_REFERER']) ? self::truncate_string(esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']))) : '';

        return [
            'ip'      => $ip,
            'ua'      => $ua,
            'referer' => $referer,
        ];
    }

    private static function detect_ip(): string
    {
        $candidates = [];
        // Forwarded headers are client-controlled unless a trusted proxy validates them.
        foreach (['REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $candidates = array_merge($candidates, explode(',', sanitize_text_field(wp_unslash($_SERVER[$key]))));
            }
        }
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
        return '0.0.0.0';
    }

    private static function is_rate_limited(WP_REST_Request $request): bool
    {
        $sig = self::detect_ip();
        $key = 'yuz_rum_' . md5($sig);
        $state = get_transient($key);
        if (!is_array($state)) {
            $state = ['count' => 0, 'reset' => time() + self::RATE_LIMIT_WINDOW];
        }

        if ($state['count'] >= self::RATE_LIMIT_COUNT) {
            return true;
        }

        $state['count']++;
        $ttl = max(1, $state['reset'] - time());
        set_transient($key, $state, $ttl);

        return false;
    }
}
