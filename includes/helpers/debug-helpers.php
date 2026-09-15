<?php
defined('ABSPATH') || exit;

/** Private, opt-in sink for legacy diagnostic messages. Never writes into a web directory. */
function yuz_tra_debug_log($message, $message_type = 0, $destination = null, $additional_headers = null): bool {
    if (!((defined('YUZ_TRA_DEBUG') && YUZ_TRA_DEBUG) || (defined('WP_DEBUG') && WP_DEBUG) || (defined('YUZ_TRA_TRACE_AUTO') && YUZ_TRA_TRACE_AUTO))) return false;
    static $busy = false;
    if ($busy || !function_exists('update_option')) return false;
    $busy = true;
    try {
        $text = is_scalar($message) ? (string) $message : '[non-scalar diagnostic omitted]';
        $text = preg_replace('/\bsk-[A-Za-z0-9_-]{8,}\b/', '[REDACTED]', $text);
        $text = preg_replace('/\bBearer\s+[^\s,"\x27}\]]+/i', 'Bearer [REDACTED]', $text);
        $text = preg_replace('/((?:api[_-]?key|authorization|password|secret|nonce|token)[\s"\]\x27]*[:=]+\s*[>\s"\x27]*)([^\s,"\x27}\]]+)/i', '$1[REDACTED]', $text);
        $records = (array) get_option('yuz_tra_legacy_diagnostics', []);
        $records[] = ['time'=>gmdate('c'),'message'=>substr($text,0,4000)];
        $records = array_slice($records,-100);
        update_option('yuz_tra_legacy_diagnostics', $records, false);
        return get_option('yuz_tra_legacy_diagnostics', []) === $records;
    } finally { $busy = false; }
}
