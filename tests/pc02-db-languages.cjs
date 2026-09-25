'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const root = path.resolve(__dirname, '..');
const languages = fs.readFileSync(path.join(root, 'includes/class-yuz-languages.php'), 'utf8');
const db = fs.readFileSync(path.join(root, 'includes/class-yuz-db.php'), 'utf8');

test('PC-02 has no file-wide SQL suppressions', () => {
  for (const source of [languages, db]) {
    assert.doesNotMatch(source, /phpcs:disable[^\n]*(?:PreparedSQL|UnescapedDBParameter)/);
  }
});

test('language table identifiers come only from wpdb prefix and constant suffixes', () => {
  assert.match(languages, /private const LANGUAGES_TABLE_SUFFIX\s*=\s*'yuz_tra_languages'/);
  assert.match(languages, /private const TRANSLATIONS_TABLE_SUFFIX\s*=\s*'yuz_tra_translations'/);
  assert.match(languages, /return \$wpdb->prefix \. self::LANGUAGES_TABLE_SUFFIX/);
  assert.match(languages, /return \$wpdb->prefix \. self::TRANSLATIONS_TABLE_SUFFIX/);
  assert.doesNotMatch(languages, /str_replace\(\s*'yuz_tra_languages'/);
  assert.doesNotMatch(languages, /\$wpdb->prefix\s*\.\s*'yuz_tra_/);
});

test('language SQL uses identifier placeholders and binds value placeholders', () => {
  assert.match(languages, /SELECT language_code FROM %i WHERE is_source = 1/);
  assert.match(languages, /SELECT id FROM %i WHERE language_code = %s/);
  assert.match(languages, /UPDATE %i SET is_default/);
  assert.match(languages, /UPDATE %i SET is_source/);
  assert.doesNotMatch(languages, /SHOW TABLES LIKE ['"]\{?\$table/);
  assert.doesNotMatch(languages, /(?:FROM|UPDATE|INTO)\s+\{\$(?:table|table_name|translations)\}/);
});

test('DB wrapper allowlists constant table suffixes and uses identifier placeholders', () => {
  assert.match(db, /private static function table_name\(string \$suffix\): string/);
  assert.match(db, /if \(!in_array\(\$suffix, \$allowed, true\)\)/);
  assert.match(db, /return \$wpdb->prefix \. \$suffix/);
  assert.doesNotMatch(db, /\$wpdb->prefix\s*\.\s*'yuz_tra_/);
  assert.doesNotMatch(db, /\{\$prefix\}yuz_tra_/);
  assert.match(db, /SELECT COUNT\(\*\) FROM %i/);
  assert.match(db, /SHOW COLUMNS FROM %i/);
  assert.match(db, /ALTER TABLE %i/);
  assert.match(db, /UPDATE %i SET is_translatable/);
  assert.doesNotMatch(db, /private static function create_languages_table\(string \$lang\)/);
  assert.doesNotMatch(db, /SHOW TABLES LIKE ['"]\{?\$(?:lang|trans|table)/);
});

test('dynamic IN lists are generated placeholders, never interpolated values', () => {
  for (const source of [languages, db]) {
    assert.doesNotMatch(source, /IN\s*\(\s*\{\$(?:codes|wanted|ids_to_enable|values)\}\s*\)/);
  }
  assert.match(db, /WHERE id = %d/);
  assert.match(db, /WHERE id IN \(%d, %d\)/);
  assert.match(languages, /UPDATE %i SET is_translatable = 1 WHERE language_code = %s/);
  assert.doesNotMatch(languages, /\$table,\s*\.\.\.\$(?:codes|wanted)/);
});

test('prepared SELECT and ALTER statements reach wpdb without an opaque query variable', () => {
  assert.match(db, /\$wpdb->get_var\(\$wpdb->prepare\(\s*"SELECT COUNT\(\*\)/);
  assert.match(db, /\$wpdb->query\(\$wpdb->prepare\("ALTER TABLE %i/);
  assert.doesNotMatch(db, /\$wpdb->get_var\(\$query\)/);
  assert.doesNotMatch(db, /\$wpdb->query\(\$add_column_sql\)/);
  assert.doesNotMatch(languages, /\$wpdb->get_var\(\$query\)/);
});

test('internally generated SQL has no suppression comments', () => {
  const schemaIgnores = db.match(/phpcs:ignore.*(PreparedSQL|DirectDB)/g) || [];
  assert.equal(schemaIgnores.length, 4, 'only four reviewed dynamic SQL boundaries may be ignored');
  assert.equal((db.match(/Static schema DDL; constraint names are namespaced and validated immediately above\./g) || []).length, 2);
  assert.equal((db.match(/Generic DBInterface SQL is prepared with the caller.s typed parameter list immediately here\./g) || []).length, 2);
});
