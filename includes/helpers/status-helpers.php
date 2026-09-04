<?php
/**
 * Canonical translation status helpers.
 *
 * Backfills the workflow from the CODEX prompt:
 *   1 Draft       → chaîne en saisie (manuel)
 *   2 In review   → auto/semi-auto à relire
 *   3 Reviewed    → relu/validé, prêt à publier
 *   4 Published   → injecté côté public (seule valeur servie en prod)
 *   5 Archived    → retiré de la diffusion, conservé pour audit/restauration
 *
 * Defaults by mode (prompt):
 *   - Manuel       → DRAFT
 *   - Semi-auto    → IN_REVIEW
 *   - Auto complet → PUBLISHED
 *
 * Every backend entry point should feed incoming values through
 * yuz_tra_status_sanitize() to keep the DB consistent, and use the
 * helper predicates to decide whether a review/publish step is required.
 */

if (!defined('ABSPATH')) {
    exit;
}

defined('YUZ_TRA_STATUS_DRAFT')        || define('YUZ_TRA_STATUS_DRAFT', 1);
defined('YUZ_TRA_STATUS_IN_REVIEW')    || define('YUZ_TRA_STATUS_IN_REVIEW', 2);
defined('YUZ_TRA_STATUS_REVIEWED')     || define('YUZ_TRA_STATUS_REVIEWED', 3);
defined('YUZ_TRA_STATUS_PUBLISHED')    || define('YUZ_TRA_STATUS_PUBLISHED', 4);
defined('YUZ_TRA_STATUS_ARCHIVED')     || define('YUZ_TRA_STATUS_ARCHIVED', 5);

// Legacy compatibility (aliases kept for existing callers)
defined('YUZ_TRA_STATUS_REVIEW')       || define('YUZ_TRA_STATUS_REVIEW', YUZ_TRA_STATUS_IN_REVIEW);
defined('YUZ_TRA_STATUS_MACHINE')      || define('YUZ_TRA_STATUS_MACHINE', YUZ_TRA_STATUS_IN_REVIEW);
defined('YUZ_TRA_STATUS_QUEUED')       || define('YUZ_TRA_STATUS_QUEUED', YUZ_TRA_STATUS_REVIEWED);

if (!function_exists('yuz_tra_status_catalog')) {
    /**
     * Returns the canonical status map (id => slug + label).
     */
    function yuz_tra_status_catalog(): array
    {
        return [
            YUZ_TRA_STATUS_DRAFT        => ['key' => 'draft',      'label' => __('Draft', 'yuz-tra')],
            YUZ_TRA_STATUS_IN_REVIEW    => ['key' => 'in_review',  'label' => __('In review', 'yuz-tra')],
            YUZ_TRA_STATUS_REVIEWED     => ['key' => 'reviewed',   'label' => __('Reviewed', 'yuz-tra')],
            YUZ_TRA_STATUS_PUBLISHED    => ['key' => 'published',  'label' => __('Published', 'yuz-tra')],
            YUZ_TRA_STATUS_ARCHIVED     => ['key' => 'archived',   'label' => __('Archived', 'yuz-tra')],
        ];
    }
}

if (!function_exists('yuz_tra_status_label')) {
    function yuz_tra_status_label(int $status): string
    {
        $catalog = yuz_tra_status_catalog();
        /* translators: %d: translation status ID. */
        return $catalog[$status]['label'] ?? sprintf(__('Status #%d', 'yuz-tra'), $status);
    }
}

if (!function_exists('yuz_tra_status_sanitize')) {
    /**
     * Clamp arbitrary input to the supported scale and translate legacy values.
     */
    function yuz_tra_status_sanitize($raw, ?int $default = null): int
    {
        if ($raw === '' || $raw === null) {
            return $default ?? YUZ_TRA_STATUS_DRAFT;
        }

        // Slug / string input
        if (is_string($raw) && !is_numeric($raw)) {
            $slug = strtolower(trim($raw));
            $map = [
                'draft'      => YUZ_TRA_STATUS_DRAFT,
                'manual'     => YUZ_TRA_STATUS_DRAFT,
                'machine'    => YUZ_TRA_STATUS_IN_REVIEW,
                'auto'       => YUZ_TRA_STATUS_IN_REVIEW,
                'in_review'  => YUZ_TRA_STATUS_IN_REVIEW,
                'review'     => YUZ_TRA_STATUS_IN_REVIEW,
                'queued'     => YUZ_TRA_STATUS_REVIEWED,
                'reviewed'   => YUZ_TRA_STATUS_REVIEWED,
                'ready'      => YUZ_TRA_STATUS_REVIEWED,
                'publish'    => YUZ_TRA_STATUS_PUBLISHED,
                'published'  => YUZ_TRA_STATUS_PUBLISHED,
                'archive'    => YUZ_TRA_STATUS_ARCHIVED,
                'archived'   => YUZ_TRA_STATUS_ARCHIVED,
            ];
            if (isset($map[$slug])) {
                return $map[$slug];
            }
        }

        $value = (int) $raw;

        // Legacy numeric scale (0 draft, 1 machine, 2 review, 3 queued, 4 published, 5 archived)
        if ($value <= 0) {
            return YUZ_TRA_STATUS_DRAFT;
        }
        if ($value === 1) {
            return YUZ_TRA_STATUS_IN_REVIEW;
        }
        if ($value === 2) {
            return YUZ_TRA_STATUS_IN_REVIEW;
        }
        if ($value === 3) {
            return YUZ_TRA_STATUS_REVIEWED;
        }

        if ($value > YUZ_TRA_STATUS_ARCHIVED) {
            $value = YUZ_TRA_STATUS_ARCHIVED;
        }

        return $value;
    }
}

if (!function_exists('yuz_tra_status_requires_review')) {
    /**
     * True when the string must pass through the review dock/panel.
     */
    function yuz_tra_status_requires_review(int $status): bool
    {
        return in_array($status, [
            YUZ_TRA_STATUS_DRAFT,
            YUZ_TRA_STATUS_IN_REVIEW,
            YUZ_TRA_STATUS_REVIEWED,
        ], true);
    }
}

if (!function_exists('yuz_tra_status_is_publishable')) {
    /**
     * Publishable statuses get rendered on the front-end.
     */
    function yuz_tra_status_is_publishable(int $status): bool
    {
        return $status === YUZ_TRA_STATUS_PUBLISHED;
    }
}

if (!function_exists('yuz_tra_status_transition')) {
    /**
     * Computes the status applied after a given action.
     *
     * @param string $action publish|review|archive|draft|queue
     */
    function yuz_tra_status_transition(string $action, ?int $current = null): int
    {
        switch ($action) {
            case 'publish':
                return YUZ_TRA_STATUS_PUBLISHED;
            case 'queue': // legacy → reviewed
                return YUZ_TRA_STATUS_REVIEWED;
            case 'review':
            case 'needs_review':
                return YUZ_TRA_STATUS_IN_REVIEW;
            case 'ready':
            case 'validate':
                return YUZ_TRA_STATUS_REVIEWED;
            case 'archive':
                return YUZ_TRA_STATUS_ARCHIVED;
            case 'reset':
            case 'draft':
                return YUZ_TRA_STATUS_DRAFT;
            default:
                return $current ?? YUZ_TRA_STATUS_DRAFT;
        }
    }
}

if (!function_exists('yuz_tra_status_for_origin')) {
    /**
     * Normalizes status according to the origin/batch flags.
     *
     * @param string      $origin e.g. manual|machine|dom|string-editor…
     * @param int         $status candidate status (already sanitized if possible)
     * @param bool        $is_batch true for batch/cron
     * @param string|null $mode optional mode hint (manual|semi|semi_auto|auto)
     */
    function yuz_tra_status_for_origin(string $origin, int $status, bool $is_batch = false, ?string $mode = null): int
    {
        $origin = strtolower($origin);
        $mode   = $mode ? strtolower($mode) : null;
        if ($mode !== 'manual' && ($mode === 'auto' || $is_batch || in_array($origin,['machine','dom'],true))
            && class_exists('YUZ_Services') && YUZ_Services::tm()->requires_review()) {
            return YUZ_TRA_STATUS_IN_REVIEW;
        }

        // Mode has priority over origin, to respect the prompt defaults.
        if ($mode === 'manual') {
            return $status > 0 ? max($status, YUZ_TRA_STATUS_DRAFT) : YUZ_TRA_STATUS_DRAFT;
        }
        if ($mode === 'semi' || $mode === 'semi_auto') {
            return $status >= YUZ_TRA_STATUS_IN_REVIEW ? $status : YUZ_TRA_STATUS_IN_REVIEW;
        }
        if ($mode === 'auto') {
            return YUZ_TRA_STATUS_PUBLISHED;
        }

        if (in_array($origin, ['machine', 'dom'], true) || $is_batch) {
            return $status >= YUZ_TRA_STATUS_REVIEWED ? $status : YUZ_TRA_STATUS_IN_REVIEW;
        }

        if ($origin === 'manual') {
            return $status > 0 ? $status : YUZ_TRA_STATUS_DRAFT;
        }

        if (in_array($origin, ['dock', 'gettext'], true) && !$is_batch) {
            return YUZ_TRA_STATUS_PUBLISHED;
        }

        return $status ?: YUZ_TRA_STATUS_DRAFT;
    }
}
