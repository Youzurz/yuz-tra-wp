<?php

if (!defined('ABSPATH')) {
    exit;
}

final class YUZ_Settings_Service {
    public const OPTION = 'yuz_tra_all_settings';

    /**
     * Returns the consolidated settings array as stored in the SSOT.
     */
    public static function get(): array {
        $option = get_option(self::OPTION, []);
        return is_array($option) ? $option : [];
    }

    /**
     * Persists the provided settings while bumping the SSOT version metadata.
     */
    public static function update(array $next): array {
        $current  = self::get();
        $meta     = is_array($current['__meta'] ?? null) ? $current['__meta'] : [];
        $version  = max(1, (int)($meta['version'] ?? 0));

        $nextMeta = is_array($next['__meta'] ?? null) ? $next['__meta'] : [];
        $nextMeta['version']      = $version + 1;
        $nextMeta['last_changed'] = time();
        $next['__meta']           = $nextMeta;

        update_option(self::OPTION, $next, false);
        if (function_exists('yuz_settings_runtime_flush')) {
            yuz_settings_runtime_flush();
        }

        return $next;
    }

    /**
     * Bumps the SSOT version without altering the actual payload.
     */
    public static function touch(): array {
        $current = self::get();
        if (empty($current)) {
            $current = ['__meta' => []];
        }
        return self::update($current);
    }

    /**
     * Builds a versioned cache key (object cache namespace).
     */
    public static function cache_key(string $key): string {
        if (function_exists('yuz_settings_cache_key')) {
            return yuz_settings_cache_key($key);
        }

        $key     = ltrim($key, ':');
        $current = self::get();
        $version = max(1, (int)($current['__meta']['version'] ?? $current['version'] ?? 1));
        return sprintf('yuztra:v%d:%s', $version, $key);
    }

    /**
     * Builds a transient key safe for the options table while keeping versioned namespace.
     */
    public static function transient_key(string $key): string {
        if (function_exists('yuz_settings_transient_key')) {
            return yuz_settings_transient_key($key);
        }

        $raw        = self::cache_key($key);
        $normalized = str_replace([':', '|'], '_', $raw);
        if (strlen($normalized) > 170) {
            $normalized = substr(sha1($normalized), 0, 32);
        }
        return $normalized;
    }
}
