# Third-party data

YUZ-TRA is GPL-2.0-or-later, as already declared in the project's public 1.2.1
readme. The complete GPLv2 text is in LICENSE; the "or later" option is retained.

## Bundled browser dependencies

The release bundles the exact versions historically requested by its asset registrar,
now served locally rather than from a CDN. Readable distributions are included next
to minified files. Package sources/build instructions and original license notices:

- Vue 2.7.16 (MIT): https://github.com/vuejs/vue/tree/v2.7.16
- Vue Router 3.5.3 (MIT): https://github.com/vuejs/vue-router/tree/v3.5.3
- Axios 1.20.0 (MIT): https://github.com/axios/axios/tree/v1.20.0
- he 1.2.0 (MIT): https://github.com/mathiasbynens/he/tree/v1.2.0
- Select2 4.1.0 (MIT): https://github.com/select2/select2/tree/4.1.0
- SVG flags identified as flag-icons (MIT, Panayiotis Lipiridis): https://github.com/lipis/flag-icons

Notices are in assets/vendor/licenses. Vue 2 is a legacy dependency; bundling it
does not assert continued upstream maintenance. Dependency modernization and a full
security review remain required before claiming directory readiness.

WordPress-provided libraries such as jQuery are not duplicated in this package.

## Optional AI and translation providers

The provider adapters are deliberate, administrator-selected integrations, not
silent telemetry and not a bundled cloud service. OpenAI, DeepL, Google,
LibreTranslate and Ollama are called only after the administrator selects a
provider and supplies its endpoint and credentials where required. The direct
OpenAI adapter is retained for the declared WordPress 6.5 minimum; the
WordPress AI Client API referenced by Plugin Check is introduced in WordPress
7.0 and cannot be the sole implementation without raising the plugin minimum.
Every remote result is checked for transport status, HTTP status and valid
payload before it can be used. Ollama output remains pending human review.
The provider warning is therefore an explicit compatibility and privacy
boundary, not a suppressed finding or an assertion that a remote service is
available.

The provider boundary is also explicit in code: `yuztra_allowed_remote_providers()`
defines the selectable provider identifiers. The Plugin Check AI direct-integration
annotations are limited to the seven exact configuration/default/UI lines reported
by the scanner; they do not suppress transport, response validation, capability,
nonce, or budget controls, and they do not enable a provider automatically.

`includes/data/plural-rules.json` contains WordPress/Gettext locale plural metadata
extracted from the GlotPress locale registry on 2026-08-31 (346 locale aliases).
Source: https://github.com/GlotPress/GlotPress/blob/develop/locales/locales.php
GlotPress is distributed under GPL-2.0-or-later. No GlotPress application code is bundled.
Expressions are interpreted by WordPress POMO's safe `Plural_Forms` parser, not PHP eval.

JavaScript catalog integration uses the WordPress `load_script_translations` filter:
https://developer.wordpress.org/reference/hooks/load_script_translations/
