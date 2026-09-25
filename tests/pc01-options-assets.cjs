#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const optionsPath = path.join(root, 'includes/class-yuz-options-bridge.php');
const assetsPath = path.join(root, 'includes/class-yuz-assets.php');
const options = fs.readFileSync(optionsPath, 'utf8');
const assets = fs.readFileSync(assetsPath, 'utf8');

let failures = 0;
function check(condition, message) {
  if (condition) {
    console.log(`PASS ${message}`);
  } else {
    failures += 1;
    console.error(`FAIL ${message}`);
  }
}

check(
  /function yuztra_authorize_settings_request[\s\S]*current_user_can\('manage_options'\)[\s\S]*wp_verify_nonce/.test(options),
  'Settings writes require both manage_options and a verified group nonce'
);
check(
  /if \(!yuztra_authorize_settings_request\(\$group\)\) \{\s*return \$old;\s*\}\s*\$raw = yuztra_request_array\(\$yuztra_opt\)/.test(options),
  'general option payload is read only after authorization'
);
check(
  /function yuztra_request_text[\s\S]*sanitize_text_field[\s\S]*wp_unslash[\s\S]*sanitize_text_field/.test(options),
  'scalar POST values are unslashed and sanitized'
);
check(
  /function yuztra_request_array[\s\S]*sanitize_text_field[\s\S]*map_deep\(wp_unslash\(\$value\), 'sanitize_text_field'\)/.test(options),
  'array POST values are recursively unslashed and sanitized'
);
check(
  !/phpcs:ignore WordPress\.Security\.(?:NonceVerification\.Missing|ValidatedSanitizedInput\.(?:InputNotSanitized|MissingUnslash))/.test(options + assets),
  'no PC-01 security sniff derogation was added'
);
check(
  /function yuz_tra_assets_query_text[\s\S]*wp_unslash[\s\S]*sanitize_text_field/.test(assets),
  'asset query selectors are normalized at one read-only boundary'
);
check(
  /function yuz_tra_assets_server_text[\s\S]*wp_unslash[\s\S]*sanitize_text_field/.test(assets),
  'server metadata is normalized at one boundary'
);

const directQueryUses = [...assets.matchAll(/\$_GET\s*\[/g)].length;
check(directQueryUses === 3, 'direct GET access is confined to the query helper guard and normalized return');

const forbiddenRawRouting = [
  /\$_GET\['page'\]/,
  /\$_GET\['tab'\]/,
  /\$_GET\['lang'\]/,
  /\$_GET\['yuz-edit-translation'\]/,
  /\$_REQUEST\['yuz_trace'\]/,
];
check(
  forbiddenRawRouting.every((pattern) => !pattern.test(assets)),
  'asset routing no longer consumes raw request fields'
);

if (failures > 0) {
  process.exit(1);
}
console.log('PC-01 options/assets checks passed.');
