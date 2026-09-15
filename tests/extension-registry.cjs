'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const registry = require('../ops/extensions.json');

test('registry does not certify all YUZ plugins from one pipeline', () => {
  assert.deepEqual(registry.extensions.map(p => p.slug), ['yuz-tra']);
  assert.equal(new Set(registry.extensions.map(p => p.slug)).size, registry.extensions.length);
});
test('failed security gate remains an explicit publication blocker', () => {
  for (const plugin of registry.extensions) {
    assert.match(plugin.evidence.commit, /^[a-f0-9]{40}$/);
    assert.match(plugin.evidence.github_run, /^https:\/\/github\.com\/Youzurz\/yuz-tra-wp\/actions\/runs\/\d+$/);
    if (plugin.evidence.security !== 'success') {
      assert.equal(plugin.status, 'ci-active-release-blocked');
      assert.equal(plugin.evidence.release_gate, 'failure');
      assert.equal(plugin.evidence.publication, 'skipped');
      assert.ok(plugin.remaining_gates.length > 0);
    }
  }
});
