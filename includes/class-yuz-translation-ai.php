<?php
defined('ABSPATH') || exit;
require_once YUZ_TRA_INCLUDES.'class-yuz-contracts.php';
/** Legacy class retained without a second HTTP channel or duplicate AJAX handlers. */
final class YUZ_Translation_AI implements \YUZTRA\Interfaces\AIInterface {
    public static function init(): void {}
    public static function translate(string $text,string $source,string $target): string {
        return YUZ_Services::tm()->translate_text($text,$source,$target);
    }
}
