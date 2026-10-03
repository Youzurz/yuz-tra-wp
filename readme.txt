=== YUZ-TRA ===
Contributors: youzurz
Tags: translation, multilingual, localization, gettext, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.5.45
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
2. In Plugins > Add New > Upload Plugin, select the downloaded YUZ-TRA ZIP and activate it.
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

= 1.5.45 =
* Validate and sanitize REQUEST_URI directly at its read boundary.
* Consolidate portability corrections and regression checks without replacing earlier candidate files.

= 1.5.44 =
* Keep HTTPS administrative/login URLs unchanged when the public site uses HTTP.
* Recognize WordPress content-directory constants without mistaking another CDN host for the local site.

= 1.5.43 =
* Preserve query-mode REST routes, translate normal front-controller pages and distinguish CDN paths from local pages.
* Respect the configured site-directory boundary when detecting language prefixes.

= 1.5.42 =
* Resolve editor and service assets from WordPress configuration or their actual module URLs, including custom content folders and subdirectory sites.
* Optional theme integrations require explicit script URLs instead of assuming a particular child theme.
* Site URL conversion uses WordPress configuration rather than request Host headers or a localhost fallback.

= 1.5.41 =
* Settings input unslashing made explicit at the authenticated request boundary, before field validation.

= 1.5.40 =
* Reject invalid AJAX nonces before diagnostics and prevent delayed Gettext discovery writes during AJAX requests.
* Canonical plugin-specific JavaScript globals and switcher cache group; producer and consumer regression coverage.
* Expanded acceptance tests with active logging, real HTTP refusal status, SQL observation and activation callback verification.

= 1.5.39 =
* Review candidate: field-specific JSON/CSV validation, typed target languages, settings authorization and data-preserving validation.
* Canonical YUZTRA_/yuztra_ PHP symbols, hooks, AJAX actions and REST namespace. Integrations using earlier evaluation identifiers require updates.
* Explicit, journaled settings/capability/cron migration; historical translation tables and original options retained. Existing evaluation installations require a controlled maintenance migration before boot.
* Schema failure propagation and clean-activation regression coverage. No platform approval is implied by this candidate.

= 1.5.18 =
* Complete explicit AJAX nonce boundaries and remove the remaining high-confidence SQL preparation errors found in the 1.5.10 scan.
* Keep request diagnostics free of stack traces and normalize settings requests at their input boundary.

= 1.5.10 =
* Harden settings and asset request handling with capability, nonce and typed sanitization controls.
* Prepare dynamic SQL identifiers and values safely, and constrain generated table suffixes.
* Prefix helper implementations while retaining data-compatible migration aliases.
* Remove development diagnostics that could expose translated or provider response content.

= 1.5.9 =
* Enforce capability checks before effects on sensitive AJAX routes and verify denial without SQL writes.
* Return an explicit failure when no translation can be published; never report a false publication success.
* Keep provider secrets out of settings responses and restrict maintenance to the canonical translations table.

= 1.5.8 =
* Update the bundled Axios browser library to 1.20.0, with verified upstream package integrity.
* Preserve prior candidate ZIPs; this evaluation artifact is not a WordPress.org approval.

= 1.5.7 =
* Separate published public lookups from privileged translation and publication endpoints.
* Filter substituted HTML and validate bounded callback request data.
* Reduce diagnostic content and derive asset URLs from the plugin file.
* Evaluation candidate: not yet approved by WordPress.org.

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

= 1.5.18 =
Security review candidate. Back up and test on staging before replacing an installed version.

= 1.5.10 =
Security review candidate. Back up and test on staging before replacing an installed version.

= 1.5.9 =
Security and publication-correctness review candidate. Back up and test on staging before replacing an installed version.

= 1.5.8 =
Evaluation candidate; staging validation required. Includes the 1.5.7 review fixes and Axios 1.20.0.

= 1.5.7 =
Evaluation candidate. Back up and test on staging. Public dynamic lookups require a public, non-password-protected post and return published content only.

= 1.5.6 =
Back up first. Test on staging. Review provider settings and privacy policy before enabling automatic translation.
