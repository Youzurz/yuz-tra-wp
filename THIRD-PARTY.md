# Third-party data

YUZ-TRA is GPL-2.0-or-later, as already declared in the project's public 1.2.1
readme. The complete GPLv2 text is in LICENSE; the "or later" option is retained.

## Bundled browser dependencies

The release bundles the exact versions historically requested by its asset registrar,
now served locally rather than from a CDN. Readable distributions are included next
to minified files. Package sources/build instructions and original license notices:

- Vue 2.7.16 (MIT): https://github.com/vuejs/vue/tree/v2.7.16
- Vue Router 3.5.3 (MIT): https://github.com/vuejs/vue-router/tree/v3.5.3
- Axios 1.11.0 (MIT): https://github.com/axios/axios/tree/v1.11.0
- he 1.2.0 (MIT): https://github.com/mathiasbynens/he/tree/v1.2.0
- Select2 4.0.13 (MIT): https://github.com/select2/select2/tree/4.0.13
- SVG flags identified as flag-icons (MIT, Panayiotis Lipiridis): https://github.com/lipis/flag-icons

Notices are in assets/vendor/licenses. Vue 2 is a legacy dependency; bundling it
does not assert continued upstream maintenance. Dependency modernization and a full
security review remain required before claiming directory readiness.

WordPress-provided libraries such as jQuery are not duplicated in this package.

`includes/data/plural-rules.json` contains WordPress/Gettext locale plural metadata
extracted from the GlotPress locale registry on 2026-08-31 (346 locale aliases).
Source: https://github.com/GlotPress/GlotPress/blob/develop/locales/locales.php
GlotPress is distributed under GPL-2.0-or-later. No GlotPress application code is bundled.
Expressions are interpreted by WordPress POMO's safe `Plural_Forms` parser, not PHP eval.

JavaScript catalog integration uses the WordPress `load_script_translations` filter:
https://developer.wordpress.org/reference/hooks/load_script_translations/
