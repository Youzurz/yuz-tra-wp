# Plugin Check triage — YUZ-TRA 1.5.5

Source report: `plugin-check-ci-exact.csv` (retained as build evidence, not bundled in the plugin ZIP).

Coverage: **1938/1938 findings classified**.
Errors: **0**. Warnings: **1938**.

The zero-error result is the release gate. Warnings are not hidden: every row is
classified below, and the remaining debt stays explicit for follow-up releases.

## Classification summary

| Classification | Findings |
|---|---:|
| `architectural_debt` | 697 |
| `manual_sql_review` | 400 |
| `input_hygiene_debt` | 319 |
| `namespace_debt` | 249 |
| `manual_request_review` | 130 |
| `diagnostic_debt` | 121 |
| `reviewed_dynamic_placeholders` | 10 |
| `documented_compatibility` | 7 |
| `documented_schema_management` | 4 |
| `performance_debt` | 1 |

## Codes

| Code | Count | Classification | Decision |
|---|---:|---|---|
| `WordPress.DB.DirectDatabaseQuery.DirectQuery` | 361 | `architectural_debt` | The translation catalog is plugin-owned storage and intentionally queries its own tables. |
| `WordPress.DB.DirectDatabaseQuery.NoCaching` | 336 | `architectural_debt` | Catalog/editor reads need explicit cache review; tracked as performance debt, not a release blocker. |
| `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` | 227 | `manual_sql_review` | Mostly plugin-owned table identifiers and constrained fragments; retain for focused SQL refactoring. |
| `PluginCheck.Security.DirectDB.UnescapedDBParameter` | 173 | `manual_sql_review` | Overlaps dynamic plugin table identifiers/clauses; retain for focused SQL refactoring. |
| `WordPress.Security.ValidatedSanitizedInput.MissingUnslash` | 171 | `input_hygiene_debt` | Requires incremental normalization; no Plugin Check error, but retained as hardening debt. |
| `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` | 148 | `input_hygiene_debt` | Requires incremental normalization; authenticated handlers sanitize at their boundary. |
| `WordPress.PHP.DevelopmentFunctions.error_log_error_log` | 108 | `diagnostic_debt` | Diagnostic logging remains, but regression tests prohibit credentials and complete request payloads. |
| `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound` | 82 | `namespace_debt` | Legacy compatibility globals; migration requires a backwards-compatible deprecation cycle. |
| `WordPress.Security.NonceVerification.Recommended` | 80 | `manual_request_review` | Read-only request switches; no state change is authorized by these reads alone. |
| `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound` | 77 | `namespace_debt` | Legacy compatibility hooks; migration requires a backwards-compatible deprecation cycle. |
| `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound` | 63 | `namespace_debt` | Legacy global variables; migration requires a backwards-compatible deprecation cycle. |
| `WordPress.Security.NonceVerification.Missing` | 50 | `manual_request_review` | Remaining hits are routing/diagnostic reads or code reached behind Settings API/central AJAX verification. |
| `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound` | 23 | `namespace_debt` | Legacy public class names; migration requires aliases and a deprecation cycle. |
| `WordPress.PHP.DevelopmentFunctions.error_log_print_r` | 11 | `diagnostic_debt` | Debug-only structural output; replace incrementally with the plugin logger. |
| `PluginCheck.CodeAnalysis.AIProvider.DirectIntegration` | 7 | `documented_compatibility` | Direct adapters are retained because the declared minimum is WordPress 6.5; WP AI Client starts at 7.0. |
| `WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare` | 5 | `reviewed_dynamic_placeholders` | Dynamic IN() placeholders; query argument counts are covered by activation and integration checks. |
| `WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber` | 5 | `reviewed_dynamic_placeholders` | Dynamic WHERE/IN() placeholders passed as a supported variadic or array argument. |
| `WordPress.DB.DirectDatabaseQuery.SchemaChange` | 4 | `documented_schema_management` | Schema creation/migration is expected plugin lifecycle work and uses the plugin database service. |
| `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound` | 3 | `namespace_debt` | Legacy public constants; migration requires a deprecation cycle. |
| `WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace` | 2 | `diagnostic_debt` | Debug-only stack metadata; replace incrementally with bounded structured diagnostics. |
| `WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound` | 1 | `namespace_debt` | Dynamic compatibility hook name; document and migrate with aliases. |
| `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` | 1 | `performance_debt` | One metadata query remains for profiling and indexed-query review. |

## Most affected files

| File | Findings |
|---|---:|
| `includes/class-yuz-ajax.php` | 724 |
| `includes/class-yuz-db.php` | 143 |
| `includes/class-yuz-languages.php` | 120 |
| `includes/class-yuz-options-bridge.php` | 103 |
| `includes/class-yuz-assets.php` | 99 |
| `includes/class-yuz-plugin.php` | 63 |
| `partials/yuz-language-switcher.php` | 54 |
| `includes/class-yuz-cli-commands.php` | 46 |
| `includes/class-yuz-string-catalog.php` | 46 |
| `includes/class-yuz-translation-manager.php` | 44 |
| `includes/class-yuz-translation-budget.php` | 44 |
| `includes/class-yuz-url-converter.php` | 36 |
| `includes/class-yuz-lang-sync.php` | 34 |
| `includes/class-yuz-rewrite.php` | 30 |
| `includes/class-yuz-editor.php` | 25 |
| `includes/helpers/settings-helpers.php` | 23 |
| `includes/class-yuz-settings.php` | 19 |
| `includes/class-yuz-cron.php` | 17 |
| `includes/class-yuz-google-translate-adapter.php` | 16 |
| `includes/class-yuz-translation-memory.php` | 16 |
| `includes/class-yuz-advanced.php` | 14 |
| `includes/hooks/yuz-request-trace.php` | 14 |
| `includes/class-yuz-deepl-translate-adapter.php` | 13 |
| `includes/class-yuz-api-manager.php` | 12 |
| `includes/helpers/lang-helpers.php` | 12 |
| `includes/class-yuz-string-service.php` | 11 |
| `includes/class-yuz-query.php` | 10 |
| `includes/class-yuz-rest-monitoring.php` | 9 |
| `includes/class-yuz-renderer.php` | 9 |
| `includes/class-yuz-translate-site.php` | 9 |

## Release-blocking review

The candidate additionally runs focused regression checks for write-only provider
credentials, redacted diagnostics, authenticated log endpoints, an explicit anonymous
read allowlist, and validated CSV uploads. Those controls address the exploitable
issues found during manual review; the remaining warning groups above are scheduled
technical debt and are not represented as resolved.
