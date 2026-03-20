=== YUZ Translation ===
Contributors: youzurz
Tags: translation, multilingual, localization, language switcher
Requires at least: 6.0
Tested up to: 6.7.1
Requires PHP: 8.0
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Translation management plugin with language switching and publishing review tools.

== Description ==

YUZ Translation helps manage multilingual content inside WordPress with:

* language management
* translation review workflows
* language switching on the front end
* translation publishing controls

This package is a release-oriented build intended for distribution and excludes
development artifacts, diagnostic snapshots, and internal debugging payloads.

Current connector status:

* LibreTranslate: available
* Google Translate connector: present in the codebase but not currently validated for release use
* DeepL connector: present in the codebase but not currently validated for release use

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress admin.
3. Configure languages and translation settings from the YUZ Translation menus.

== Frequently Asked Questions ==

= Does this plugin use external services? =

Depending on your configuration, the plugin can integrate with external
translation providers. Review your plugin settings before enabling those
integrations.

= What data is sent to LibreTranslate? =

When a site administrator enables LibreTranslate, text selected for translation
and the requested source and target language codes are sent to the configured
LibreTranslate endpoint. No provider connection is enabled by default.

= Which provider is currently supported in this release-oriented build? =

LibreTranslate is the currently operable connector. Google Translate and DeepL
connectors exist in the codebase, but they are not yet considered release-ready
for public distribution.

== Changelog ==

= 1.2.1 =

* Prepared a release-oriented package for distribution.
