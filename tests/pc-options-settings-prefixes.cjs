#!/usr/bin/env node

const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const targets = [
  'includes/class-yuz-options-bridge.php',
  'includes/helpers/settings-helpers.php',
];

const sources = Object.fromEntries(
  targets.map((file) => [file, fs.readFileSync(path.join(root, file), 'utf8')]),
);

for (const [file, source] of Object.entries(sources)) {
  const functions = [...source.matchAll(/^\s*function\s+([A-Za-z_][A-Za-z0-9_]*)/gm)]
    .map((match) => match[1]);

  const invalid = functions.filter((name) => !name.startsWith('yuztra_'));
  if (invalid.length) {
    throw new Error(`${file}: global functions without yuztra_ prefix: ${invalid.join(', ')}`);
  }

  if (/['"]yuz\/(?:canonical_write|settings)\//.test(source)) {
    throw new Error(`${file}: legacy non-prefixed hook remains`);
  }
}

const combined = Object.values(sources).join('\n');
const persistentKeys = [
  'yuz_tra_all_settings',
  'yuz_tra_at_settings',
  'yuz_tra_general',
  'yuz_tra_ws_settings',
  'yuz_tra_ls_settings',
  'yuz_tra_sw_settings',
  'yuz_tra_ts_settings',
];

for (const key of persistentKeys) {
  if (!combined.includes(`'${key}'`) && !combined.includes(`"${key}"`)) {
    throw new Error(`persisted option key disappeared: ${key}`);
  }
}

if (/['"]yuztra_tra_[^'"]+['"]/.test(combined)) {
  throw new Error('a persisted yuz_tra_* key was accidentally renamed');
}

console.log('PASS: option/settings globals use yuztra_ and persisted option keys are unchanged');
