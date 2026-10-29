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
 * yuztra_status_sanitize() to keep the DB consistent, and use the
 * helper predicates to decide whether a review/publish step is required.
 */

if (!defined('ABSPATH')) {
    exit;
}

defined('YUZTRA_STATUS_DRAFT')        || define('YUZTRA_STATUS_DRAFT', 1);
defined('YUZTRA_STATUS_IN_REVIEW')    || define('YUZTRA_STATUS_IN_REVIEW', 2);
defined('YUZTRA_STATUS_REVIEWED')     || define('YUZTRA_STATUS_REVIEWED', 3);
defined('YUZTRA_STATUS_PUBLISHED')    || define('YUZTRA_STATUS_PUBLISHED', 4);
defined('YUZTRA_STATUS_ARCHIVED')     || define('YUZTRA_STATUS_ARCHIVED', 5);

// Additional canonical labels retain their established workflow meanings.
defined('YUZTRA_STATUS_REVIEW')      || define('YUZTRA_STATUS_REVIEW', YUZTRA_STATUS_IN_REVIEW);
defined('YUZTRA_STATUS_MACHINE')     || define('YUZTRA_STATUS_MACHINE', YUZTRA_STATUS_IN_REVIEW);
defined('YUZTRA_STATUS_QUEUED')      || define('YUZTRA_STATUS_QUEUED', YUZTRA_STATUS_REVIEWED);

if (!function_exists('yuztra_status_catalog')) {
    /**
     * Returns the canonical status map (id => slug + label).
     */
    function yuztra_status_catalog(): array
    {
        return [
            YUZTRA_STATUS_DRAFT        => ['key' => 'draft',      'label' => __('Draft', 'yuz-tra')],
            YUZTRA_STATUS_IN_REVIEW    => ['key' => 'in_review',  'label' => __('In review', 'yuz-tra')],
            YUZTRA_STATUS_REVIEWED     => ['key' => 'reviewed',   'label' => __('Reviewed', 'yuz-tra')],
            YUZTRA_STATUS_PUBLISHED    => ['key' => 'published',  'label' => __('Published', 'yuz-tra')],
            YUZTRA_STATUS_ARCHIVED     => ['key' => 'archived',   'label' => __('Archived', 'yuz-tra')],
        ];
    }
}

if (!function_exists('yuztra_status_label')) {
    function yuztra_status_label(int $status): string
    {
        $catalog = yuztra_status_catalog();
        /* translators: %d: translation status ID. */
        return $catalog[$status]['label'] ?? sprintf(__('Status #%d', 'yuz-tra'), $status);
    }
}

if (!function_exists('yuztra_status_sanitize')) {
    /**
     * Clamp arbitrary input to the supported scale and translate legacy values.
     */
    function yuztra_status_sanitize($raw, ?int $default = null): int
    {
        if ($raw === '' || $raw === null) {
            return $default ?? YUZTRA_STATUS_DRAFT;
        }

        // Slug / string input
        if (is_string($raw) && !is_numeric($raw)) {
            $slug = strtolower(trim($raw));
            $map = [
                'draft'      => YUZTRA_STATUS_DRAFT,
                'manual'     => YUZTRA_STATUS_DRAFT,
                'machine'    => YUZTRA_STATUS_IN_REVIEW,
                'auto'       => YUZTRA_STATUS_IN_REVIEW,
                'in_review'  => YUZTRA_STATUS_IN_REVIEW,
                'review'     => YUZTRA_STATUS_IN_REVIEW,
                'queued'     => YUZTRA_STATUS_REVIEWED,
                'reviewed'   => YUZTRA_STATUS_REVIEWED,
                'ready'      => YUZTRA_STATUS_REVIEWED,
                'publish'    => YUZTRA_STATUS_PUBLISHED,
                'published'  => YUZTRA_STATUS_PUBLISHED,
                'archive'    => YUZTRA_STATUS_ARCHIVED,
                'archived'   => YUZTRA_STATUS_ARCHIVED,
            ];
            if (isset($map[$slug])) {
                return $map[$slug];
            }
        }

        $value = (int) $raw;

        // Legacy numeric scale (0 draft, 1 machine, 2 review, 3 queued, 4 published, 5 archived)
        if ($value <= 0) {
            return YUZTRA_STATUS_DRAFT;
        }
        if ($value === 1) {
            return YUZTRA_STATUS_IN_REVIEW;
        }
        if ($value === 2) {
            return YUZTRA_STATUS_IN_REVIEW;
        }
        if ($value === 3) {
            return YUZTRA_STATUS_REVIEWED;
        }

        if ($value > YUZTRA_STATUS_ARCHIVED) {
            $value = YUZTRA_STATUS_ARCHIVED;
        }

        return $value;
    }
}

if (!function_exists('yuztra_status_requires_review')) {
    /**
     * True when the string must pass through the review dock/panel.
     */
    function yuztra_status_requires_review(int $status): bool
    {
        return in_array($status, [
            YUZTRA_STATUS_DRAFT,
            YUZTRA_STATUS_IN_REVIEW,
            YUZTRA_STATUS_REVIEWED,
        ], true);
    }
}

if (!function_exists('yuztra_status_is_publishable')) {
    /**
     * Publishable statuses get rendered on the front-end.
     */
    function yuztra_status_is_publishable(int $status): bool
    {
        return $status === YUZTRA_STATUS_PUBLISHED;
    }
}

if (!function_exists('yuztra_status_transition')) {
    /**
     * Computes the status applied after a given action.
     *
     * @param string $action publish|review|archive|draft|queue
     */
    function yuztra_status_transition(string $action, ?int $current = null): int
    {
        switch ($action) {
            case 'publish':
                return YUZTRA_STATUS_PUBLISHED;
            case 'queue': // legacy → reviewed
                return YUZTRA_STATUS_REVIEWED;
            case 'review':
            case 'needs_review':
                return YUZTRA_STATUS_IN_REVIEW;
            case 'ready':
            case 'validate':
                return YUZTRA_STATUS_REVIEWED;
            case 'archive':
                return YUZTRA_STATUS_ARCHIVED;
            case 'reset':
            case 'draft':
                return YUZTRA_STATUS_DRAFT;
            default:
                return $current ?? YUZTRA_STATUS_DRAFT;
        }
    }
}

if (!function_exists('yuztra_status_for_origin')) {
    /**
     * Normalizes status according to the origin/batch flags.
     *
     * @param string      $origin e.g. manual|machine|dom|string-editor…
     * @param int         $status candidate status (already sanitized if possible)
     * @param bool        $is_batch true for batch/cron
     * @param string|null $mode optional mode hint (manual|semi|semi_auto|auto)
     */
    function yuztra_status_for_origin(string $origin, int $status, bool $is_batch = false, ?string $mode = null): int
    {
        $origin = strtolower($origin);
        $mode   = $mode ? strtolower($mode) : null;
        if ($mode !== 'manual' && ($mode === 'auto' || $is_batch || in_array($origin,['machine','dom'],true))
            && class_exists('YUZTRA_Services') && YUZTRA_Services::tm()->requires_review()) {
            return YUZTRA_STATUS_IN_REVIEW;
        }

        // Mode has priority over origin, to respect the prompt defaults.
        if ($mode === 'manual') {
            return $status > 0 ? max($status, YUZTRA_STATUS_DRAFT) : YUZTRA_STATUS_DRAFT;
        }
        if ($mode === 'semi' || $mode === 'semi_auto') {
            return $status >= YUZTRA_STATUS_IN_REVIEW ? $status : YUZTRA_STATUS_IN_REVIEW;
        }
        if ($mode === 'auto') {
            return YUZTRA_STATUS_PUBLISHED;
        }

        if (in_array($origin, ['machine', 'dom'], true) || $is_batch) {
            return $status >= YUZTRA_STATUS_REVIEWED ? $status : YUZTRA_STATUS_IN_REVIEW;
        }

        if ($origin === 'manual') {
            return $status > 0 ? $status : YUZTRA_STATUS_DRAFT;
        }

        if (in_array($origin, ['dock', 'gettext'], true) && !$is_batch) {
            return YUZTRA_STATUS_PUBLISHED;
        }

        return $status ?: YUZTRA_STATUS_DRAFT;
    }
}

/* Backward-compatible function aliases. New code must call yuztra_* helpers. */
