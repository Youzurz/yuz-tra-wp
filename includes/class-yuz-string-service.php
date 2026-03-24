<?php
defined('ABSPATH') || exit;

/**
 * Data helpers for auxiliary translation entities (gettext, slugs, emails).
 */
class YUZ_String_Service {

    private static function md5bin(string $text): string {
        return function_exists('hash') ? pack('H*', md5($text)) : md5($text, true);
    }

    public static function save_gettext(array $row): int {
        global $wpdb;
        $table = $wpdb->prefix . 'yuz_tra_gettext';
        $defaults = [
            'domain'     => '',
            'context'    => '',
            'original'   => '',
            'lang'       => '',
            'translated' => null,
            'status'     => 0,
            'origin'     => 0,
        ];
        $data = array_merge($defaults, $row);
        $record = [
            'domain'      => $data['domain'],
            'context'     => $data['context'] ?: '',
            'original'    => $data['original'],
            'original_md5'=> self::md5bin($data['original']),
            'lang'        => $data['lang'],
            'translated'  => $data['translated'],
            'status'      => (int) $data['status'],
            'origin'      => (int) $data['origin'],
        ];

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE domain=%s AND context=%s AND original_md5=%s AND lang=%s",
            $record['domain'],
            $record['context'],
            $record['original_md5'],
            $record['lang']
        ));

        if ($existing) {
            $wpdb->update(
                $table,
                [
                    'translated' => $record['translated'],
                    'status'     => $record['status'],
                    'origin'     => $record['origin'],
                ],
                ['id' => (int) $existing]
            );
            return (int) $existing;
        }

        $wpdb->insert($table, $record);
        return (int) $wpdb->insert_id;
    }

    public static function save_slug(array $row): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'yuz_tra_slugs';
        $record = [
            'object_id'   => (int) ($row['object_id'] ?? 0),
            'object_type' => (string) ($row['object_type'] ?? 'post'),
            'post_type'   => (string) ($row['post_type'] ?? ''),
            'lang'        => (string) ($row['lang'] ?? ''),
            'slug'        => (string) ($row['slug'] ?? ''),
            'status'      => (int) ($row['status'] ?? 0),
        ];
        $result = $wpdb->replace($table, $record);
        return $result !== false;
    }

    public static function save_email(array $row): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'yuz_tra_emails';
        $record = [
            'ekey'       => (string) ($row['ekey'] ?? ''),
            'source'     => (string) ($row['source'] ?? ''),
            'lang'       => (string) ($row['lang'] ?? ''),
            'translated' => isset($row['translated']) ? (string) $row['translated'] : null,
            'status'     => (int) ($row['status'] ?? 0),
        ];
        $result = $wpdb->replace($table, $record);
        return $result !== false;
    }
}

