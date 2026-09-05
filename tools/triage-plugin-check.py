#!/usr/bin/env python3
"""Create a deterministic, complete triage of a Plugin Check strict CSV report."""

from __future__ import annotations

import collections
import csv
import pathlib
import sys


CLASSIFICATIONS = {
    "WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare": (
        "reviewed_dynamic_placeholders",
        "Dynamic IN() placeholders; query argument counts are covered by activation and integration checks.",
    ),
    "WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber": (
        "reviewed_dynamic_placeholders",
        "Dynamic WHERE/IN() placeholders passed as a supported variadic or array argument.",
    ),
    "PluginCheck.CodeAnalysis.AIProvider.DirectIntegration": (
        "documented_compatibility",
        "Direct adapters are retained because the declared minimum is WordPress 6.5; WP AI Client starts at 7.0.",
    ),
    "WordPress.DB.DirectDatabaseQuery.DirectQuery": (
        "architectural_debt",
        "The translation catalog is plugin-owned storage and intentionally queries its own tables.",
    ),
    "WordPress.DB.DirectDatabaseQuery.NoCaching": (
        "architectural_debt",
        "Catalog/editor reads need explicit cache review; tracked as performance debt, not a release blocker.",
    ),
    "WordPress.DB.PreparedSQL.InterpolatedNotPrepared": (
        "manual_sql_review",
        "Mostly plugin-owned table identifiers and constrained fragments; retain for focused SQL refactoring.",
    ),
    "PluginCheck.Security.DirectDB.UnescapedDBParameter": (
        "manual_sql_review",
        "Overlaps dynamic plugin table identifiers/clauses; retain for focused SQL refactoring.",
    ),
    "WordPress.DB.DirectDatabaseQuery.SchemaChange": (
        "documented_schema_management",
        "Schema creation/migration is expected plugin lifecycle work and uses the plugin database service.",
    ),
    "WordPress.Security.NonceVerification.Missing": (
        "manual_request_review",
        "Remaining hits are routing/diagnostic reads or code reached behind Settings API/central AJAX verification.",
    ),
    "WordPress.Security.NonceVerification.Recommended": (
        "manual_request_review",
        "Read-only request switches; no state change is authorized by these reads alone.",
    ),
    "WordPress.Security.ValidatedSanitizedInput.MissingUnslash": (
        "input_hygiene_debt",
        "Requires incremental normalization; no Plugin Check error, but retained as hardening debt.",
    ),
    "WordPress.Security.ValidatedSanitizedInput.InputNotSanitized": (
        "input_hygiene_debt",
        "Requires incremental normalization; authenticated handlers sanitize at their boundary.",
    ),
    "WordPress.Security.ValidatedSanitizedInput.InputNotValidated": (
        "input_hygiene_debt",
        "Array-shape validation warning retained for focused hardening.",
    ),
    "WordPress.PHP.DevelopmentFunctions.error_log_error_log": (
        "diagnostic_debt",
        "Diagnostic logging remains, but regression tests prohibit credentials and complete request payloads.",
    ),
    "WordPress.PHP.DevelopmentFunctions.error_log_print_r": (
        "diagnostic_debt",
        "Debug-only structural output; replace incrementally with the plugin logger.",
    ),
    "WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace": (
        "diagnostic_debt",
        "Debug-only stack metadata; replace incrementally with bounded structured diagnostics.",
    ),
    "WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound": (
        "namespace_debt",
        "Legacy compatibility globals; migration requires a backwards-compatible deprecation cycle.",
    ),
    "WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound": (
        "namespace_debt",
        "Legacy compatibility hooks; migration requires a backwards-compatible deprecation cycle.",
    ),
    "WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound": (
        "namespace_debt",
        "Legacy global variables; migration requires a backwards-compatible deprecation cycle.",
    ),
    "WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound": (
        "namespace_debt",
        "Legacy public class names; migration requires aliases and a deprecation cycle.",
    ),
    "WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound": (
        "namespace_debt",
        "Legacy public constants; migration requires a deprecation cycle.",
    ),
    "WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound": (
        "namespace_debt",
        "Dynamic compatibility hook name; document and migrate with aliases.",
    ),
    "WordPress.DB.SlowDBQuery.slow_db_query_meta_query": (
        "performance_debt",
        "One metadata query remains for profiling and indexed-query review.",
    ),
}


def main() -> int:
    if len(sys.argv) != 3:
        raise SystemExit("usage: triage-plugin-check.py REPORT.csv OUTPUT.md")
    source = pathlib.Path(sys.argv[1])
    output = pathlib.Path(sys.argv[2])
    with source.open(newline="", errors="replace") as handle:
        rows = list(csv.DictReader(handle))

    unknown = sorted({row.get("code", "") for row in rows} - CLASSIFICATIONS.keys())
    if unknown:
        raise SystemExit("unclassified Plugin Check codes: " + ", ".join(unknown))

    type_counts = collections.Counter(row.get("type", "") for row in rows)
    code_counts = collections.Counter(row.get("code", "") for row in rows)
    class_counts = collections.Counter(CLASSIFICATIONS[row["code"]][0] for row in rows)
    file_counts = collections.Counter(row.get("file", "") for row in rows)

    lines = [
        "# Plugin Check triage — YUZ-TRA 1.5.5",
        "",
        f"Source report: `{source.name}` (retained as build evidence, not bundled in the plugin ZIP).",
        "",
        f"Coverage: **{sum(class_counts.values())}/{len(rows)} findings classified**.",
        f"Errors: **{type_counts.get('ERROR', 0)}**. Warnings: **{type_counts.get('WARNING', 0)}**.",
        "",
        "The zero-error result is the release gate. Warnings are not hidden: every row is",
        "classified below, and the remaining debt stays explicit for follow-up releases.",
        "",
        "## Classification summary",
        "",
        "| Classification | Findings |",
        "|---|---:|",
    ]
    lines.extend(f"| `{name}` | {count} |" for name, count in class_counts.most_common())
    lines.extend(["", "## Codes", "", "| Code | Count | Classification | Decision |", "|---|---:|---|---|"])
    for code, count in code_counts.most_common():
        classification, decision = CLASSIFICATIONS[code]
        lines.append(f"| `{code}` | {count} | `{classification}` | {decision} |")
    lines.extend(["", "## Most affected files", "", "| File | Findings |", "|---|---:|"])
    lines.extend(f"| `{name}` | {count} |" for name, count in file_counts.most_common(30))
    lines.extend([
        "",
        "## Release-blocking review",
        "",
        "The candidate additionally runs focused regression checks for write-only provider",
        "credentials, redacted diagnostics, authenticated log endpoints, an explicit anonymous",
        "read allowlist, and validated CSV uploads. Those controls address the exploitable",
        "issues found during manual review; the remaining warning groups above are scheduled",
        "technical debt and are not represented as resolved.",
        "",
    ])
    output.write_text("\n".join(lines), encoding="utf-8")
    print(f"classified={sum(class_counts.values())} findings={len(rows)} errors={type_counts.get('ERROR', 0)}")
    return 0 if sum(class_counts.values()) == len(rows) and type_counts.get("ERROR", 0) == 0 else 1


if __name__ == "__main__":
    raise SystemExit(main())
