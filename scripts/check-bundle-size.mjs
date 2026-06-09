#!/usr/bin/env node
import { readFileSync, statSync, existsSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const file = resolve(__dirname, '..', 'public-build/chatbot-widget.js');
const BUDGET_KB = 80;

if (!existsSync(file)) {
  console.error(`Bundle missing at ${file}. Run "npm run build" first.`);
  process.exit(1);
}

const raw = readFileSync(file);
const gz = gzipSync(raw);
const rawSize = statSync(file).size;
const gzKB = gz.length / 1024;
const fmt = (n) => `${n.toFixed(2)} KB`;

console.log(`Bundle:  ${file}`);
console.log(`  raw:   ${fmt(rawSize / 1024)}`);
console.log(`  gzip:  ${fmt(gzKB)}  (budget: ${BUDGET_KB} KB)`);

if (gzKB > BUDGET_KB) {
  console.error(`\nFAIL: bundle exceeds ${BUDGET_KB} KB gzip budget by ${fmt(gzKB - BUDGET_KB)}.`);
  process.exit(1);
}
console.log(`OK: under budget by ${fmt(BUDGET_KB - gzKB)}.`);
