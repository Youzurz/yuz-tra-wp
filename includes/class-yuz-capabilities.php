<?php
/**
 * Central hub for RBAC checks and capability propagation.
 */

defined('ABSPATH') || exit;

if (!class_exists('YUZ_Capabilities')) {
    class YUZ_Capabilities {
        public const CAP_TRANSLATE_CONTENT    = 'yuz_translate_content';
        public const CAP_TRANSLATE_STRINGS    = 'yuz_translate_strings';
        public const CAP_MANAGE_SLUGS         = 'yuz_manage_slugs';
        public const CAP_PUBLISH              = 'yuz_publish_translations';
        public const CAP_MANAGE_SETTINGS      = 'yuz_manage_settings';

        /**
         * Legacy capability slugs kept for backward compatibility.
         */
        private const LEGACY_CAPS = [
            'yuz_translate',
            'yuz_translate_posts',
            'yuz_translate_publish',
            'yuz_translate_settings',
            'yuz_translate_core',
        ];

        /**
         * Fallback map so we keep recognizing legacy caps until every site is resynced.
         */
        private const LEGACY_FALLBACK = [
            self::CAP_TRANSLATE_CONTENT => ['yuz_translate'],
            self::CAP_TRANSLATE_STRINGS => ['yuz_translate_posts', 'yuz_translate'],
            self::CAP_MANAGE_SLUGS      => ['yuz_translate_posts', 'yuz_translate'],
            self::CAP_PUBLISH           => ['yuz_translate_publish', 'yuz_translate'],
            self::CAP_MANAGE_SETTINGS   => ['yuz_translate_settings', 'yuz_translate_core'],
        ];

        private const TRANSLATOR_CAPS = [
            self::CAP_TRANSLATE_CONTENT,
            self::CAP_TRANSLATE_STRINGS,
            self::CAP_MANAGE_SLUGS,
            self::CAP_PUBLISH,
        ];

        private const ADMIN_CAPS = [
            self::CAP_MANAGE_SETTINGS,
        ];

        private static bool $bootstrapped = false;

        /**
         * Hook sync helpers once.
         */
        public static function bootstrap(): void {
            if (self::$bootstrapped) {
                return;
            }
            self::$bootstrapped = true;
            if (function_exists('add_action')) {
                add_action('switch_blog', [__CLASS__, 'sync_role_matrix'], 20, 0);
            }
        }

        /**
         * Ensure allowed roles receive translator/admin capabilities.
         *
         * @param array|null $allowed_roles Optional allowed role slugs.
         */
        public static function sync_role_matrix(?array $allowed_roles = null): void {
            if (!function_exists('wp_roles')) {
                return;
            }

            $roles_api = wp_roles();
            if (!$roles_api) {
                return;
            }

            $allowed = self::normalise_allowed_roles($allowed_roles);
            $role_objects = [];
            if (isset($roles_api->role_objects) && is_array($roles_api->role_objects)) {
                $role_objects = $roles_api->role_objects;
            } elseif (isset($roles_api->roles) && is_array($roles_api->roles)) {
                foreach (array_keys($roles_api->roles) as $slug) {
                    $role = get_role($slug);
                    if ($role instanceof \WP_Role) {
                        $role_objects[$slug] = $role;
                    }
                }
            }

            foreach ($role_objects as $slug => $role) {
                if (!$role instanceof \WP_Role) {
                    continue;
                }
                $is_allowed = in_array($slug, $allowed, true);
                if ($is_allowed) {
                    self::grant_caps($role, self::TRANSLATOR_CAPS);
                } else {
                    self::revoke_caps($role, self::TRANSLATOR_CAPS);
                }

                if ($slug === 'administrator' || $role->has_cap('manage_options')) {
                    self::grant_caps($role, self::ADMIN_CAPS);
                } else {
                    self::revoke_caps($role, self::ADMIN_CAPS);
                }

                // Remove outdated slugs to avoid phantom caps when switching blogs.
                self::revoke_caps($role, self::LEGACY_CAPS);
            }
        }

        /**
         * Checks if a user can interact with translations (overlay, editor, etc.).
         */
        public static function user_is_translator(?int $user_id = null): bool {
            if (!function_exists('current_user_can')) {
                return false;
            }
            return self::user_has_cap(self::CAP_TRANSLATE_CONTENT, $user_id)
                || self::user_has_cap(self::CAP_TRANSLATE_STRINGS, $user_id)
                || self::user_has_cap(self::CAP_MANAGE_SLUGS, $user_id)
                || self::user_has_cap(self::CAP_PUBLISH, $user_id)
                || self::user_has_cap('manage_options', $user_id)
                || self::is_super_admin($user_id);
        }

        /**
         * Checks if a user may publish translations via the dock.
         */
        public static function user_can_publish(?int $user_id = null): bool {
            return self::user_has_cap(self::CAP_PUBLISH, $user_id)
                || self::user_has_cap('manage_options', $user_id)
                || self::is_super_admin($user_id);
        }

        /**
         * Checks if a user may access translation settings.
         */
        public static function user_can_access_settings(?int $user_id = null): bool {
            return self::user_has_cap(self::CAP_MANAGE_SETTINGS, $user_id)
                || self::user_has_cap('manage_options', $user_id)
                || self::is_super_admin($user_id);
        }

        /**
         * Expose a compact permission payload for JS.
         */
        public static function permissions_payload(?int $user_id = null): array {
            $caps = [
                'translate_content'    => self::user_has_cap(self::CAP_TRANSLATE_CONTENT, $user_id),
                'translate_strings'    => self::user_has_cap(self::CAP_TRANSLATE_STRINGS, $user_id),
                'manage_slugs'         => self::user_has_cap(self::CAP_MANAGE_SLUGS, $user_id),
                'publish_translations' => self::user_has_cap(self::CAP_PUBLISH, $user_id),
                'manage_settings'      => self::user_has_cap(self::CAP_MANAGE_SETTINGS, $user_id),
            ];

            return [
                'isAdmin'      => self::user_has_cap('manage_options', $user_id) || self::is_super_admin($user_id),
                'isSuperAdmin' => self::is_super_admin($user_id),
                'canTranslate' => $caps['translate_content'],
                'canPublish'   => $caps['publish_translations'],
                'canManage'    => $caps['manage_settings'],
                'canStrings'   => $caps['translate_strings'],
                'canSlugs'     => $caps['manage_slugs'],
                'caps'         => $caps,
            ];
        }

        /**
         * Normalize allowed roles list (option or provided array).
         *
         * @param array|null $roles
         * @return array
         */
        private static function normalise_allowed_roles(?array $roles): array {
            $allowed = is_array($roles) ? $roles : (array) get_option('yuz_tra_allowed_roles', []);
            $allowed = array_map('sanitize_key', $allowed);
            $allowed = array_filter($allowed);

            if (empty($allowed) && function_exists('yuz_tra_default_allowed_roles')) {
                $allowed = yuz_tra_default_allowed_roles();
            }

            $ensure = ['administrator', 'editor'];
            foreach ($ensure as $role_slug) {
                if (get_role($role_slug) && !in_array($role_slug, $allowed, true)) {
                    $allowed[] = $role_slug;
                }
            }

            return array_values(array_unique($allowed));
        }

        /**
         * Grant capabilities to a role.
         */
        private static function grant_caps(\WP_Role $role, array $caps): void {
            foreach ($caps as $cap) {
                $role->add_cap($cap);
            }
        }

        /**
         * Revoke capabilities from a role.
         */
        private static function revoke_caps(\WP_Role $role, array $caps): void {
            foreach ($caps as $cap) {
                $role->remove_cap($cap);
            }
        }

        /**
         * Wrapper for capability lookups.
         */
        private static function user_has_cap(string $cap, ?int $user_id = null): bool {
            if ($user_id !== null && function_exists('user_can')) {
                if (user_can($user_id, $cap)) {
                    return true;
                }
            } elseif (function_exists('current_user_can') && current_user_can($cap)) {
                return true;
            }

            foreach (self::LEGACY_FALLBACK[$cap] ?? [] as $legacy) {
                if ($user_id !== null && function_exists('user_can')) {
                    if (user_can($user_id, $legacy)) {
                        return true;
                    }
                } elseif (function_exists('current_user_can') && current_user_can($legacy)) {
                    return true;
                }
            }

            return false;
        }

        private static function is_super_admin(?int $user_id = null): bool {
            if (!function_exists('is_super_admin')) {
                return false;
            }
            return $user_id === null ? is_super_admin() : is_super_admin($user_id);
        }
    }

    YUZ_Capabilities::bootstrap();
}
