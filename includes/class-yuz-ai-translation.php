<?php
defined('ABSPATH') || exit;
/** Compatibility service. AJAX registration, authorization and responses live in YUZ_Ajax. */
final class YUZ_AI {
    private static $instance;
    public static function instance(): self { return self::$instance ??= new self(); }
    public function register_hooks(): void { /* Central AJAX bootstrap owns registration. */ }
    public function translate(string $text, string $source, string $target): array {
        return ['translation'=>YUZ_Services::tm()->translate_text($text,$source,$target),'status'=>2,'cost_cents'=>null];
    }
    public function batch(array $texts,string $source,string $target): string {
        return YUZ_Translation_Jobs::create($texts,$source,$target);
    }
    public function status(string $id): array { return YUZ_Translation_Jobs::status($id); }
}
