'use strict';

const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '..');
const core = fs.readFileSync(path.join(root, 'includes/class-yuz-core.php'), 'utf8');
const db = fs.readFileSync(path.join(root, 'includes/class-yuz-db.php'), 'utf8');

test('ensure_tables reports schema failure to its caller', () => {
  assert.match(db, /public function ensure_tables\(\): bool/);
  assert.match(db, /if \(!empty\(\$failed_tables\)\)[\s\S]{0,400}?return false;/);
});

test('the tables_ok flag mirrors the real result of schema creation', () => {
  // Le drapeau ne doit jamais etre force a true sans consulter ensure_tables().
  assert.doesNotMatch(core, /update_option\(\s*'yuz_tra_tables_ok'\s*,\s*true\s*\)/);
  assert.match(
    core,
    /\$tables_ready = \(bool\) \$db->ensure_tables\(\);[\s\S]{0,200}?update_option\('yuz_tra_tables_ok', \$tables_ready\);/
  );
});

test('a failed schema creation is surfaced and left retryable', () => {
  assert.match(core, /if \(!\$tables_ready\) \{[\s\S]{0,300}?self::flag_issue\(/);
});
