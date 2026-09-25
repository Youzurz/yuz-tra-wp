'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const root = path.resolve(__dirname, '..');
const manager = fs.readFileSync(path.join(root, 'includes/class-yuz-translation-manager.php'), 'utf8');

test('frontend settings use the WordPress script API and hex-safe JSON', () => {
  assert.match(manager, /wp_add_inline_script\s*\(/);
  for (const flag of ['JSON_HEX_TAG', 'JSON_HEX_AMP', 'JSON_HEX_APOS', 'JSON_HEX_QUOT']) {
    assert.match(manager, new RegExp(flag));
  }
  assert.doesNotMatch(manager, /echo\s+['"]\s*window\.yuzTraSettings/);
});
