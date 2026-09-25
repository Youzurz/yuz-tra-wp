#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const sourcePath = path.join(root, 'includes/class-yuz-ajax.php');
const reportPath = '/tmp/yuz-proof-wordpress.2qi8Bq/plugin-check-summary.json';
const source = fs.readFileSync(sourcePath, 'utf8');
const report = JSON.parse(fs.readFileSync(reportPath, 'utf8'));
const targetCodes = /(?:PreparedSQL|UnescapedDBParameter|InputNotSanitized|MissingUnslash)/;
const exactFindings = report.findings.filter(
  (finding) => finding.file === 'includes/class-yuz-ajax.php' && targetCodes.test(finding.code)
);

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

assert(exactFindings.length >= 100, 'Le rapport exact attendu ne contient pas le corpus AJAX de référence.');
assert(!source.includes('SELECT COUNT(*) FROM `{$table}`'), 'La métrique total interpole encore un identifiant SQL.');
assert(!source.includes('CREATE TABLE `{$backup}` LIKE `{$table}`'), 'La sauvegarde interpole encore deux identifiants SQL.');
assert(!source.includes('FROM {$table} {$where}'), 'Une requête paginée interpole encore table et clause WHERE.');
assert(!source.includes("SHOW TABLES LIKE '$table_name'"), 'Une vérification de table interpole encore table_name.');
assert(!source.includes("SHOW TABLES LIKE '$trans_table'"), 'Une vérification de table interpole encore trans_table.');
assert(!source.includes("$nonce = $_REQUEST['nonce'] ?? ''"), 'Le nonce legacy est encore lu sans déslashage/assainissement.');
assert(!source.includes("$original = $_REQUEST['original_text']"), 'Le texte original est encore lu brut.');
assert(source.includes("absint(wp_unslash($_REQUEST['page'] ?? 1))"), 'La pagination doit être déslashée et bornée.');
assert(source.includes("$wpdb->prepare('CREATE TABLE %i LIKE %i', $backup, $table)"), 'La sauvegarde doit préparer ses identifiants.');

console.log(`PASS pc02-ajax-report: ${exactFindings.length} constats de référence couverts par des gardes ciblées.`);
