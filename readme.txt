=== YUZ-TRA ===
Contributors: youzurz
Tags: translation, multilingual, localization, gettext, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.5.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Visual translation workspace and Gettext catalog for WordPress, plugins and themes, with review, publication and approved translation memory.

== Description ==

YUZ-TRA combines a visual editor with a searchable Gettext catalog. The two editors
share a resizable workspace, with keyboard-accessible handles and a stacked mobile
layout. Layout preferences stay in the browser, scoped to the current site and user.

Scan installed WordPress code, choose a language and domain, edit singular or plural
forms, review and publish. Only published catalog translations replace displayed
strings. Machine-generated catalog translations require review; approved memory
entries require an explicit human confirmation.

The silent worker is optional and depends on WordPress Cron. Classic providers can
publish missing translations in explicitly enabled silent mode. Ollama output
always stays pending review. Resource limits are configurable safety budgets, not
a paid feature unlock. No translation correctness or legal compliance is guaranteed.

YUZ-TRA is open-source software. Test on staging and back up before replacing an existing plugin.
Declared minimum versions have not all been tested; acceptance tests used WordPress
7.1, PHP 8.3 and WooCommerce 10.8.1.

Source, readable bundled libraries, build instructions and known limits:
https://github.com/Youzurz/yuz-tra-wp
Documentation: https://github.com/Youzurz/yuz-tra-wp/blob/main/README.md
Support: https://github.com/Youzurz/yuz-tra-wp/issues/new/choose
Privacy: https://github.com/Youzurz/yuz-tra-wp/blob/main/PRIVACY.md

== External services ==

No hosted translation subscription is included. Configure a provider deliberately
before requesting translations. The provider receives source text, source/target
language codes and its configured credentials. It can also observe the server IP.
Never translate sensitive content without checking the provider's terms and your
site's privacy obligations. See PRIVACY.md for retention and local storage details.

* LibreTranslate: administrator-selected endpoint; receives text and languages when
  translating. A language-discovery or connection test also contacts that endpoint.
  Self-hosting: https://github.com/LibreTranslate/LibreTranslate
  Self-hosted software license: https://github.com/LibreTranslate/LibreTranslate/blob/main/LICENSE
  No hosted operator is selected or contracted by this plugin. Before configuring a
  hosted endpoint, obtain that operator's service terms and privacy policy; the
  software license is not a hosted-service agreement. LibreTranslate's API portal
  privacy notice: https://portal.libretranslate.com/privacy.html
* Ollama: administrator-selected server and installed model. Receives text, languages,
  up to three approved examples and twelve relevant glossary entries for generation.
  Connection tests request the installed model list. No model is downloaded by YUZ.
  Software: https://github.com/ollama/ollama ; hosted-service terms, if you independently
  choose such a service: https://ollama.com/terms and https://ollama.com/privacy .
  A local Ollama deployment is not a request to Ollama Cloud.
* Google Cloud Translation: optional configured connector, not live-certified in this
  release. https://cloud.google.com/terms/service-terms ; https://cloud.google.com/terms/cloud-privacy-notice
* DeepL API: optional configured connector, not live-certified in this release.
  https://www.deepl.com/pro-license ; https://www.deepl.com/privacy
* OpenAI API: optional translation provider. After administrator configuration,
  explicit translations or enabled background jobs send source text, language
  instructions, selected model and API authentication to the configured endpoint.
  Connection tests and model discovery send authentication to the models endpoint;
  no translation text is sent for model discovery. Responses include translations
  and token usage, recorded locally for budget accounting. No provider request is
  needed to install or activate the plugin. Terms: https://openai.com/policies/services-agreement/
  Privacy: https://openai.com/policies/privacy-policy/
  An administrator-selected compatible API belongs to its own operator, whose
  terms and privacy policy must be checked before configuring credentials.

JavaScript dependencies are bundled locally. Opening support links visits GitHub;
no bug report or translation content is automatically transmitted to the maintainer.

== Installation ==

1. Back up the database and previous plugin. Try staging first.
2. In Plugins > Add New > Upload Plugin, select yuz-tra-1.5.6.zip and activate it.
3. Configure source and target languages in YUZ-TRA.
4. Open Strings, scan installed code and filter by domain/language.
5. Edit and publish, or select up to five strings for provider translation and review.
6. On a WordPress front page, open the visual editor, then Strings to use both panels.

== Frequently Asked Questions ==

= Does it translate a separate non-WordPress front end? =
Not by itself. A headless application needs its own translation integration.

= Does every third-party string become translatable? =
The catalog targets Gettext and WordPress-registered JavaScript translations.
Arbitrary API text, images, PDFs and unregistered dynamic JS are not automatically covered.

= What does it cost? =
This downloadable distribution has no activation fee or included paid feature gate.
Translation providers and hosting may charge separately. No paid support plan or
guaranteed response time is offered by this package. See PRICING.md.

= Are updates automatic? =
WordPress.org installations use the standard WordPress update mechanism. For a ZIP
installed directly from GitHub, read CHANGELOG.md, back up and upload the new ZIP manually.

= What happens to my data when I deactivate or delete it? =
Deactivation unschedules YUZ cron. Catalogs, memory, glossary, options and historical
logs are retained; deleting plugin files is not a data-erasure operation. Arrange a
site-specific export/erasure procedure with your administrator. See PRIVACY.md.

== Changelog ==

= 1.5.6 =
* Reject executable translations on save and when loading legacy PHP/JavaScript catalogs.
* Require administrator permission for global publication and administrator consent for credit links.
* Protect health diagnostics, sanitize settings and stop writing the debug-probe log into wp-content.
* Enqueue scripts through WordPress and scope template output buffering.
* Update Select2 to 4.1.0 and document OpenAI requests and external-service policies.
* Remove assumed root AJAX URLs and generic fallback names.

= 1.5.5 =
* Prevent provider credentials and complete automatic-translation settings from reaching diagnostic logs.
* Validate CSV uploads with WordPress file inspection and safe local redirects.
* Restrict anonymous AJAX to the two read-only routes required for front-end translation.
* Require authentication, capability and nonces for diagnostic log endpoints.
* Disable probes and RUM telemetry by default and remove source/target previews from metrics.
* Add focused security regression tests for these controls.

= 1.5.4 =
* Production-ready WordPress.org metadata and durable update guidance.
* Package author normalized to YOUZURZ (YUZ CLA GPT).
* Fresh WordPress 7.1 and Plugin Check 2.1.0 validation of the exact release ZIP.

= 1.5.3 =
* Translation domain normalized to the `yuz-tra` slug.
* Third-party update header removed from the WordPress.org package.
* Reproducible GitHub Actions build with external SHA-256 and provenance manifest.

= 1.5.1 =
* Shared top-aligned visual and string workspace, accessible resizing and local preferences.
* Draft-preserving close/reopen, mobile stacking and scoped keyboard shortcuts.
* Bundled dependencies, licensing, privacy/cost documentation and support entry points.
* Explicit independent distribution status; no claim of WordPress.org approval.

= 1.4.2 =
* Top-layer string catalog and protection against late translation responses overwriting edits.

== Upgrade Notice ==

= 1.5.6 =
Back up first. Test on staging. Review provider settings and privacy policy before enabling automatic translation.
