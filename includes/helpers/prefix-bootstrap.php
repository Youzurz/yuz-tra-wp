<?php
/** Gate before defaults, schema creation or worker registration. */
defined('ABSPATH') || exit;
require_once __DIR__ . '/prefix-manifest.php';
require_once __DIR__ . '/prefix-migration.php';

function yuztra_prefix_bootstrap_notice(): void {
    if (!current_user_can('manage_options')) return;
    echo '<div class="notice notice-error"><p>' . esc_html__(
        'YUZ-TRA is not running: its settings migration requires controlled maintenance or conflict resolution. Back up the site, stop all legacy writers and workers, then set YUZTRA_MIGRATION_QUIESCENT to true only for the migration. See the upgrade procedure before resuming traffic.',
        'yuz-tra'
    ) . '</p></div>';
}

function yuztra_prefix_bootstrap(): bool {
    global $wpdb;
    try {
        $store = new YUZTRA_Prefix_Migration_Store($wpdb);
        $state = $store->state();
        if (($state['done'] ?? false) === true && is_string($state['id'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $state['id'])) return true;

        // Discover names only; no credentials or customer values enter diagnostics.
        $names = $wpdb->get_col($wpdb->prepare(
            'SELECT option_name FROM %i WHERE option_name LIKE %s',
            $wpdb->options, $wpdb->esc_like('yuz_') . '%'
        ));
        if ($wpdb->last_error || !is_array($names)) throw new RuntimeException('Migration inventory unavailable');
        $jobs = array_values(array_filter($names, static fn($name): bool =>
            is_string($name) && (bool) preg_match('/^yuz_tra_job_[a-f0-9-]{36}$/D', $name)));
        $manifest = yuztra_prefix_manifest($jobs);
        $legacy = (bool) array_intersect($names, array_keys($manifest['options']));
        $cron = get_option('cron', []);
        foreach (is_array($cron) ? $cron : [] as $hooks) {
            if (is_array($hooks) && array_intersect(array_keys($hooks), array_keys($manifest['cron_hooks']))) {
                $legacy = true;
                break;
            }
        }
        if (!$legacy) {
            $table = $wpdb->get_var($wpdb->prepare(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE %s LIMIT 1',
                $wpdb->esc_like($wpdb->prefix . 'yuz_tra_') . '%'
            ));
            if ($wpdb->last_error) throw new RuntimeException('Migration table inventory unavailable');
            $legacy = $table !== null;
        }
        if (!$legacy) {
            $roles = get_option($wpdb->prefix . 'user_roles', []);
            foreach (is_array($roles) ? $roles : [] as $role) {
                if (is_array($role) && is_array($role['capabilities'] ?? null)
                    && array_intersect(array_keys($role['capabilities']), array_keys($manifest['capabilities']))) {
                    $legacy = true;
                    break;
                }
            }
            // Per-user capabilities can exist without a corresponding role grant.
            $user = $wpdb->get_var($wpdb->prepare(
                'SELECT umeta_id FROM %i WHERE meta_key=%s AND meta_value LIKE %s LIMIT 1',
                $wpdb->usermeta, $wpdb->prefix . 'capabilities', '%' . $wpdb->esc_like('"yuz_') . '%'
            ));
            if ($wpdb->last_error) throw new RuntimeException('Migration capability inventory unavailable');
            $legacy = $legacy || $user !== null;
        }
        if ($legacy && !(defined('YUZTRA_MIGRATION_QUIESCENT') && YUZTRA_MIGRATION_QUIESCENT === true)) {
            throw new RuntimeException('Maintenance required');
        }
        // The constant asserts stopped writers; this function does not stop them.
        yuztra_prefix_migrate($manifest, true);
        return true;
    } catch (Throwable $failure) {
        // Do not expose SQL, setting values or exception payloads in the frontend.
        add_action('admin_notices', 'yuztra_prefix_bootstrap_notice');
        return false;
    }
}
