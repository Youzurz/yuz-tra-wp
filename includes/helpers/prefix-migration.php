<?php
/**
 * Explicit, per-site migration. NOT auto-executed merely by including this file.
 * Bootstrap integration (owner task): require this file and call
 * yuztra_prefix_migrate($reviewed_manifest, true) BEFORE class loading/boot/schema.
 * The boolean attests ALL legacy writers/cron/workers are stopped, not just a UI banner.
 * Manifest keys: options, usermeta, capabilities, tables; each is old => new.
 * Tables are suffixes relative to this site's $wpdb->prefix. No guessed mappings.
 * Run separately for each multisite blog. Network options are NOT covered.
 * Failure throws: caller MUST abort boot; never catch and continue to dbDelta.
 * Old keys/capabilities remain; tables are renamed atomically, never merged/dropped.
 * Optional tables defaults to []; omit it to retain historical table names.
 * option_paths maps OPTION NAME to lists of exact path moves, for example:
 * ['yuztra_settings' => [['from' => ['sections','yuz_tra_general'],
 *                       'to' => ['sections','yuztra_general']]]].
 * Paths contain literal string/integer keys, not dotted strings or wildcards.
 * For copied options, rules address the canonical destination and are applied
 * BEFORE comparison/insertion. Other canonical options can be remapped in place.
 * option_path_stages[new_option] = [rules_phase1, rules_phase2, ...] supports
 * section moves followed by child-key moves. Do not also specify option_paths
 * for that option. All phases run in memory before one compare/write.
 * Within a phase, only identical destination paths may overlap (equal aliases);
 * different payloads fail. Later phases must not recreate earlier source paths.
 * cron_hooks is an explicit old hook => new hook map under cron timestamps only.
 * Neither feature rewrites scalar values, cron arguments, or arbitrary nested keys.
 */
defined('ABSPATH') || exit;

function yuztra_prefix_merge_caps(array $value, array $mapping): array {
    foreach ($mapping as $old => $new) {
        if (!array_key_exists($old, $value)) continue;
        if (array_key_exists($new, $value) && $value[$new] !== $value[$old]) {
            throw new RuntimeException('Prefix capability conflict');
        }
        $value[$new] = $value[$old]; // Preserve explicit false/denials too.
    }
    return $value;
}

function yuztra_prefix_validate_paths(array $rules): void {
    $paths = [];
    foreach ($rules as $rule) {
        if (!is_array($rule) || count($rule) !== 2 || !isset($rule['from'], $rule['to'])) throw new InvalidArgumentException('Invalid option_paths rule');
        foreach (['from', 'to'] as $side) {
            $path = $rule[$side];
            if (!is_array($path) || !$path || !array_is_list($path)) throw new InvalidArgumentException('Invalid option_paths path');
            foreach ($path as $key) {
                if ((!is_string($key) && !is_int($key)) || (is_string($key) && (is_numeric($key) || $key === '*'))) throw new InvalidArgumentException('Ambiguous option_paths key');
            }
            foreach ($paths as [$other, $otherSide]) {
                if ($side === 'to' && $otherSide === 'to' && $path === $other) continue;
                $length = min(count($path), count($other));
                if (array_slice($path, 0, $length) === array_slice($other, 0, $length)) throw new InvalidArgumentException('Overlapping option_paths paths');
            }
            $paths[] = [$path, $side];
        }
    }
}

function yuztra_prefix_validate_stages(array $stages): void {
    if (!array_is_list($stages)) throw new InvalidArgumentException('Invalid option_path_stages list');
    $earlierSources = [];
    foreach ($stages as $rules) {
        if (!is_array($rules) || !array_is_list($rules)) throw new InvalidArgumentException('Invalid option_path_stages phase');
        yuztra_prefix_validate_paths($rules);
        foreach ($rules as $rule) {
            foreach ($earlierSources as $source) {
                $length = min(count($source), count($rule['to']));
                if (array_slice($source, 0, $length) === array_slice($rule['to'], 0, $length)) throw new InvalidArgumentException('option_path_stages recreates an earlier source');
            }
        }
        foreach ($rules as $rule) $earlierSources[] = $rule['from'];
    }
}

/** Internal envelope allows the same adapter methods to handle both APIs. */
function yuztra_prefix_apply_paths(array $value, array $rules): array {
    if (!array_key_exists('stages', $rules)) return yuztra_prefix_move_paths($value, $rules);
    if (count($rules) !== 1 || !is_array($rules['stages'])) throw new InvalidArgumentException('Invalid stages envelope');
    yuztra_prefix_validate_stages($rules['stages']);
    foreach ($rules['stages'] as $phase) $value = yuztra_prefix_move_paths($value, $phase);
    return $value;
}

/** Pure exact-key moves. Missing source is a no-op; unequal destination is fatal. */
function yuztra_prefix_move_paths(array $value, array $rules): array {
    yuztra_prefix_validate_paths($rules);
    foreach ($rules as $rule) {
        $source =& $value;
        $from = $rule['from']; $old = array_pop($from);
        foreach ($from as $part) {
            if (!array_key_exists($part, $source)) { unset($source); continue 2; }
            if (!is_array($source[$part])) throw new RuntimeException('Source option path is not an array');
            $source =& $source[$part];
        }
        if (!array_key_exists($old, $source)) { unset($source); continue; }
        $payload = $source[$old];
        unset($source);
        $target =& $value;
        $to = $rule['to']; $new = array_pop($to);
        foreach ($to as $part) {
            if (!array_key_exists($part, $target)) $target[$part] = [];
            if (!is_array($target[$part])) throw new RuntimeException('Destination option path is not an array');
            $target =& $target[$part];
        }
        if (array_key_exists($new, $target) && $target[$new] !== $payload) throw new RuntimeException('Prefix option path conflict');
        $target[$new] = $payload;
        unset($target);
        $source =& $value;
        foreach ($from as $part) $source =& $source[$part];
        unset($source[$old]);
        unset($source);
    }
    return $value;
}

/** Reject object/reference payloads rather than silently altering their serialization. */
function yuztra_prefix_decode_array(string $raw): array {
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Convert malformed serialized input warnings to a fail-closed exception; handler restored in finally.
    set_error_handler(static function () { throw new RuntimeException('Malformed serialized migration option'); });
    try { $value = unserialize($raw, ['allowed_classes' => false, 'max_depth' => 64]); }
    finally { restore_error_handler(); }
    if (!is_array($value) || serialize($value) !== $raw) throw new RuntimeException('Migration option must be a canonical serialized array');
    $check = static function (array $items, int $depth) use (&$check): void {
        if ($depth > 64) throw new RuntimeException('Migration option nesting limit');
        foreach ($items as $key => $item) {
            if (ReflectionReference::fromArrayElement($items, $key) !== null || is_object($item)) throw new RuntimeException('Unsupported migration option object/reference');
            if (is_array($item)) $check($item, $depth + 1);
        }
    };
    $check($value, 0);
    return $value;
}

/** Only hook keys immediately below numeric timestamps; event payload stays intact. */
function yuztra_prefix_remap_cron(array $cron, array $mapping): array {
    foreach ($cron as $timestamp => $hooks) {
        if ($timestamp === 'version') continue;
        if (!ctype_digit((string) $timestamp) || !is_array($hooks)) throw new RuntimeException('Malformed cron timestamp');
        foreach ($mapping as $old => $new) {
            if (!array_key_exists($old, $hooks)) continue;
            if (array_key_exists($new, $hooks) && $hooks[$new] !== $hooks[$old]) throw new RuntimeException('Prefix cron hook conflict');
            $hooks[$new] = $hooks[$old];
            unset($hooks[$old]);
        }
        $cron[$timestamp] = $hooks;
    }
    return $cron;
}

/** Small injectable storage contract makes restart/conflict behavior testable. */
function yuztra_prefix_migration_engine(array $manifest, object $store, bool $quiescent): void {
    if (!$quiescent) throw new RuntimeException('Prefix migration requires stopped legacy writers');
    if (array_diff(array_keys($manifest), ['options', 'usermeta', 'capabilities', 'tables', 'option_paths', 'option_path_stages', 'cron_hooks'])) {
        throw new InvalidArgumentException('Unknown prefix manifest section');
    }
    $manifest += ['tables' => [], 'option_paths' => [], 'cron_hooks' => []];
    if (!is_array($manifest['option_paths'])) throw new InvalidArgumentException('Invalid option_paths section');
    foreach ($manifest['option_paths'] as $option => $rules) {
        if (!is_string($option) || !preg_match('/^yuztra_[a-zA-Z0-9_]+$/D', $option) || !is_array($rules) || !array_is_list($rules)) throw new InvalidArgumentException('Invalid option_paths option');
        yuztra_prefix_validate_paths($rules);
    }
    ksort($manifest['option_paths']);
    if (isset($manifest['option_path_stages'])) {
        if (!is_array($manifest['option_path_stages'])) throw new InvalidArgumentException('Invalid option_path_stages section');
        foreach ($manifest['option_path_stages'] as $option => $stages) {
            if (!is_string($option) || !preg_match('/^yuztra_[a-zA-Z0-9_]+$/D', $option) || !is_array($stages)) throw new InvalidArgumentException('Invalid option_path_stages option');
            if (array_key_exists($option, $manifest['option_paths'])) throw new InvalidArgumentException('Use option_paths OR option_path_stages per option');
            yuztra_prefix_validate_stages($stages);
        }
        ksort($manifest['option_path_stages']);
    } elseif (array_key_exists('option_path_stages', $manifest)) {
        throw new InvalidArgumentException('Invalid option_path_stages section');
    }
    $optionRules = $manifest['option_paths'];
    foreach ($manifest['option_path_stages'] ?? [] as $option => $stages) $optionRules[$option] = ['stages' => $stages];
    foreach (['options', 'usermeta', 'capabilities', 'tables', 'cron_hooks'] as $section) {
        if (!isset($manifest[$section]) || !is_array($manifest[$section])) throw new InvalidArgumentException('Missing manifest section');
        $targets = [];
        foreach ($manifest[$section] as $old => $new) {
            $jobOption = $section === 'options' && is_string($old) && preg_match('/^yuz_tra_job_[a-f0-9-]{36}$/D', $old);
            if (!is_string($old) || !is_string($new) || (!preg_match('/^yuz_(?:tra_)?[a-zA-Z0-9_]+$/D', $old) && !$jobOption)
                || $new !== preg_replace('/^yuz_(?:tra_)?/', 'yuztra_', $old)) {
                throw new InvalidArgumentException('Invalid mapping');
            }
            if (isset($targets[$new])) throw new InvalidArgumentException('Many-to-one mapping requires reconciliation');
            $targets[$new] = true;
        }
        ksort($manifest[$section]);
    }
    ksort($manifest);
    $id = hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR));
    $store->lock();
    try {
        $state = $store->state();
        if ($state && ($state['id'] ?? '') !== $id) throw new RuntimeException('Prefix migration manifest changed');
        if (($state['done'] ?? false) === true) return;
        $state = $state ?: ['id' => $id, 'tables' => [], 'done' => false];
        $store->save($state);
        foreach ($manifest['options'] as $old => $new) {
            $rules = $optionRules[$new] ?? [];
            if ($rules) $store->copyOption($old, $new, $rules);
            else $store->copyOption($old, $new);
        }
        foreach ($optionRules as $option => $rules) {
            if ($rules) $store->remapOption($option, $rules);
        }
        foreach ($manifest['usermeta'] as $old => $new) $store->copyMeta($old, $new);
        $store->capabilities($manifest['capabilities']);
        foreach ($manifest['tables'] as $old => $new) {
            $a = $store->tableExists($old); $b = $store->tableExists($new);
            if ($a && $b) throw new RuntimeException('Both prefix tables exist; manual reconciliation required: ' . $new);
            if (!$a && $b && !isset($state['tables'][$old])) throw new RuntimeException('Unproven canonical table: ' . $new);
            if (!$a && !$b && isset($state['tables'][$old])) throw new RuntimeException('Journaled table disappeared: ' . $old);
            if ($a) {
                $state['tables'][$old] = 'intent'; $store->save($state);
                $store->renameTable($old, $new);
                if ($store->tableExists($old) || !$store->tableExists($new)) throw new RuntimeException('Table rename verification failed');
            }
            if ($a || $b) { $state['tables'][$old] = 'done'; $store->save($state); }
        }
        if ($manifest['cron_hooks']) $store->cronHooks($manifest['cron_hooks']);
        $state['done'] = true; $store->save($state);
    } finally { $store->unlock(); }
}

/** WordPress 6.5+ adapter. SQL preserves serialized bytes, duplicates and autoload. */
final class YUZTRA_Prefix_Migration_Store {
    private object $db;
    private string $lock;
    public function __construct(object $db) {
        $this->db = $db;
        $this->lock = 'yuztra_prefix_' . substr(hash('sha256', $db->options), 0, 40);
    }
    private function query(string $sql) {
        $result = $this->db->query($sql);
        if ($result === false) throw new RuntimeException('Prefix migration database write failed');
        return $result;
    }
    private function rows(string $sql): array {
        $rows = $this->db->get_results($sql, ARRAY_A);
        if ($this->db->last_error || !is_array($rows)) throw new RuntimeException('Prefix migration database read failed');
        return $rows;
    }
    public function lock(): void {
        if ((string) $this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s, 0)', $this->lock)) !== '1') {
            throw new RuntimeException('Prefix migration database lock unavailable');
        }
    }
    public function unlock(): void {
        if ((string) $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)', $this->lock)) !== '1') {
            throw new RuntimeException('Prefix migration lock release failed');
        }
    }
    public function state(): array {
        $rows = $this->rows($this->db->prepare('SELECT option_value FROM %i WHERE option_name=%s', $this->db->options, 'yuztra_prefix_migration_v1'));
        if (!$rows) return [];
        $value = json_decode($rows[0]['option_value'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new RuntimeException('Invalid migration journal');
        return $value;
    }
    public function save(array $state): void {
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        $this->query($this->db->prepare('INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)', $this->db->options, 'yuztra_prefix_migration_v1', $json, 'no'));
        if ($this->state() !== $state) throw new RuntimeException('Migration journal verification failed');
        wp_cache_delete('yuztra_prefix_migration_v1', 'options');
    }
    public function copyOption(string $old, string $new, array $rules = []): void {
        $a = $this->rows($this->db->prepare('SELECT option_value,autoload FROM %i WHERE option_name=%s', $this->db->options, $old));
        if (!$a) return;
        if ($rules) {
            $value = yuztra_prefix_decode_array($a[0]['option_value']);
            $next = yuztra_prefix_apply_paths($value, $rules);
            if ($next !== $value) $a[0]['option_value'] = serialize($next);
        }
        $b = $this->rows($this->db->prepare('SELECT option_value,autoload FROM %i WHERE option_name=%s', $this->db->options, $new));
        // A previous copy may still contain historical keys. Compare both final
        // forms before writing; this is not permission to overwrite other values.
        if ($b && $rules) {
            $value = yuztra_prefix_decode_array($b[0]['option_value']);
            $next = yuztra_prefix_apply_paths($value, $rules);
            if ($next !== $value) $b[0]['option_value'] = serialize($next);
        }
        if ($b && $a !== $b) throw new RuntimeException('Prefix option conflict');
        if ($b && $rules) $this->remapOption($new, $rules);
        if (!$b) $this->query($this->db->prepare('INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s)', $this->db->options, $new, $a[0]['option_value'], $a[0]['autoload']));
        if ($this->rows($this->db->prepare('SELECT option_value,autoload FROM %i WHERE option_name=%s', $this->db->options, $new)) !== $a) throw new RuntimeException('Option copy verification failed');
        wp_cache_delete($new, 'options'); wp_cache_delete('alloptions', 'options'); wp_cache_delete('notoptions', 'options');
    }
    private function transformOption(string $name, callable $transform): void {
        $rows = $this->rows($this->db->prepare('SELECT option_value,autoload FROM %i WHERE option_name=%s', $this->db->options, $name));
        if (!$rows) return;
        $before = $rows[0]['option_value'];
        $value = yuztra_prefix_decode_array($before);
        $next = $transform($value);
        if ($next === $value) return;
        $after = serialize($next);
        if ($this->query($this->db->prepare('UPDATE %i SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s', $this->db->options, $after, $name, $before)) !== 1) throw new RuntimeException('Prefix option concurrent write');
        $rows = $this->rows($this->db->prepare('SELECT option_value,autoload FROM %i WHERE option_name=%s', $this->db->options, $name));
        if (!$rows || $rows[0]['option_value'] !== $after) throw new RuntimeException('Prefix option verification failed');
        wp_cache_delete($name, 'options'); wp_cache_delete('alloptions', 'options'); wp_cache_delete('notoptions', 'options');
    }
    public function remapOption(string $name, array $rules): void {
        $this->transformOption($name, static fn(array $v): array => yuztra_prefix_apply_paths($v, $rules));
    }
    public function cronHooks(array $mapping): void {
        $this->transformOption('cron', static fn(array $v): array => yuztra_prefix_remap_cron($v, $mapping));
    }
    public function copyMeta(string $old, string $new): void {
        $a = $this->rows($this->db->prepare('SELECT user_id,meta_value FROM %i WHERE meta_key=%s ORDER BY umeta_id', $this->db->usermeta, $old));
        $b = $this->rows($this->db->prepare('SELECT user_id,meta_value FROM %i WHERE meta_key=%s ORDER BY umeta_id', $this->db->usermeta, $new));
        // Multiset counts preserve duplicate values; interrupted copies can resume.
        $counts = [];
        foreach ($b as $row) { $key = serialize($row); $counts[$key] = ($counts[$key] ?? 0) + 1; }
        $needed = [];
        foreach ($a as $row) { $key = serialize($row); $needed[$key] = ($needed[$key] ?? 0) + 1; }
        foreach ($counts as $key => $n) if ($n > ($needed[$key] ?? 0)) throw new RuntimeException('Prefix usermeta conflict');
        foreach ($a as $row) {
            $key = serialize($row);
            if (($counts[$key] ?? 0) > 0) { --$counts[$key]; continue; }
            $this->query($this->db->prepare('INSERT INTO %i (user_id,meta_key,meta_value) VALUES (%d,%s,%s)', $this->db->usermeta, $row['user_id'], $new, $row['meta_value']));
            wp_cache_delete((int) $row['user_id'], 'user_meta');
        }
    }
    public function capabilities(array $mapping): void {
        if (!$mapping) return;
        $key = $this->db->prefix . 'capabilities';
        foreach ($this->rows($this->db->prepare('SELECT umeta_id,user_id,meta_value FROM %i WHERE meta_key=%s', $this->db->usermeta, $key)) as $row) {
            $value = unserialize($row['meta_value'], ['allowed_classes' => false]);
            if (!is_array($value)) throw new RuntimeException('Malformed user capabilities');
            $next = yuztra_prefix_merge_caps($value, $mapping);
            if ($next !== $value) {
                if ($this->query($this->db->prepare('UPDATE %i SET meta_value=%s WHERE umeta_id=%d AND BINARY meta_value=BINARY %s', $this->db->usermeta, serialize($next), $row['umeta_id'], $row['meta_value'])) !== 1) throw new RuntimeException('User capabilities concurrent write');
                wp_cache_delete((int) $row['user_id'], 'user_meta');
            }
        }
        $key = $this->db->prefix . 'user_roles';
        $rows = $this->rows($this->db->prepare('SELECT option_value FROM %i WHERE option_name=%s', $this->db->options, $key));
        if ($rows) {
            $roles = unserialize($rows[0]['option_value'], ['allowed_classes' => false]);
            if (!is_array($roles)) throw new RuntimeException('Malformed roles');
            foreach ($roles as &$role) {
                if (!isset($role['capabilities']) || !is_array($role['capabilities'])) throw new RuntimeException('Malformed role capabilities');
                $role['capabilities'] = yuztra_prefix_merge_caps($role['capabilities'], $mapping);
            }
            unset($role);
            $next = serialize($roles);
            if ($next !== $rows[0]['option_value'] && $this->query($this->db->prepare('UPDATE %i SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s', $this->db->options, $next, $key, $rows[0]['option_value'])) !== 1) throw new RuntimeException('Role capabilities concurrent write');
            wp_cache_delete($key, 'options'); wp_cache_delete('alloptions', 'options');
            // wp_roles may already have been initialized by WordPress before plugins.
            global $wp_roles;
            if (isset($wp_roles) && is_object($wp_roles)) $wp_roles->for_site();
        }
    }
    public function tableExists(string $suffix): bool {
        $rows = $this->rows($this->db->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND BINARY TABLE_NAME=BINARY %s', $this->db->prefix . $suffix));
        return count($rows) === 1;
    }
    public function renameTable(string $old, string $new): void {
        $this->query($this->db->prepare('RENAME TABLE %i TO %i', $this->db->prefix . $old, $this->db->prefix . $new));
    }
}

function yuztra_prefix_migrate(array $manifest, bool $quiescent = false): void {
    global $wpdb;
    yuztra_prefix_migration_engine($manifest, new YUZTRA_Prefix_Migration_Store($wpdb), $quiescent);
}
