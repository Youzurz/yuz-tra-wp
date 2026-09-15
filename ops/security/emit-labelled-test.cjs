'use strict';
// Explicit negative acceptance event: measured empty bytes versus reviewed ZIP.
// Never represents a production deployment or a real corrupted installation.
const fs=require('node:fs'),crypto=require('node:crypto');
const {observe}=require('../../tools/release-observation.cjs');
const trace=JSON.parse(fs.readFileSync(process.argv[2]));
const event={...observe(trace,Buffer.alloc(0)),test:'true',
  test_id:crypto.randomUUID(),operation:'labelled-negative-acceptance'};
fs.appendFileSync('/var/log/yuz-release/events.jsonl',JSON.stringify(event)+'\n');
console.log(JSON.stringify({test_id:event.test_id,result:event.result}));
