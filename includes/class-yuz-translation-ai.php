<?php
defined('ABSPATH') || exit;
require_once YUZ_TRA_INCLUDES.'class-yuz-contracts.php';
/** Legacy class retained without a second HTTP channel or duplicate AJAX handlers. */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- public legacy class name
final class YUZ_Translation_AI implements \YUZTRA\Interfaces\AIInterface {
    public static function init(): void {}
    public static function translate(string $text,string $source,string $target): string {
        return YUZ_Services::tm()->translate_text($text,$source,$target);
    }
}
