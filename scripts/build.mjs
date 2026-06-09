#!/usr/bin/env node
import { build } from 'esbuild';
import { mkdir, stat } from 'node:fs/promises';
import { gzipSync } from 'node:zlib';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { checkBundleTokens } from './check-bundle-tokens.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = resolve(__dirname, '..');

const targets = [
  {
    name: 'widget',
    entry: resolve(root, 'resources/js/index.ts'),
    outfile: resolve(root, 'public-build/chatbot-widget.js'),
    capKb: 80,
    loader: {},
  },
  {
    name: 'dashboard',
    entry: resolve(root, 'resources/js/dashboard/index.ts'),
    outfile: resolve(root, 'public-build/chatbot-dashboard.js'),
    capKb: 150,
    // v2.0 / E5: the dashboard bundle pulls gridstack's CSS in as a TS string
    // (see resources/js/dashboard/styles.ts) so the whole bundle stays a
    // single .js file — the blade only needs one <script src=…>. esbuild's
    // 'text' loader transforms the CSS imports into JS string constants;
    // tree-shaking still drops unused branches.
    loader: { '.css': 'text' },
  },
];

const fmt = (n) => `${(n / 1024).toFixed(2)} KB`;
let exitCode = 0;

for (const t of targets) {
  await mkdir(dirname(t.outfile), { recursive: true });

  await build({
    entryPoints: [t.entry],
    outfile: t.outfile,
    bundle: true,
    // IIFE wraps every internal var/function in a closure so minified
    // identifiers (e.g. `postConfirm` → `$`) can never become global
    // properties of `window`. Hosts that load jQuery before the bundle
    // would otherwise see their `$` overwritten — see Bug #3 in
    // docs/chatbot_package_fixes.md. The public API (window.Chatbot, the
    // chatbot-widget custom element) is still attached explicitly inside.
    format: 'iife',
    target: 'es2020',
    platform: 'browser',
    minify: true,
    sourcemap: true,
    treeShaking: true,
    legalComments: 'none',
    logLevel: 'info',
    loader: t.loader,
  });

  const raw = readFileSync(t.outfile);
  const gz = gzipSync(raw);
  const { size } = await stat(t.outfile);
  const gzKb = gz.length / 1024;
  console.log(`\nBundle:  ${t.outfile}`);
  console.log(`  raw:   ${fmt(size)}`);
  console.log(`  gzip:  ${fmt(gz.length)}  (cap: ${t.capKb} KB)`);
  if (gzKb > t.capKb) {
    console.error(`  FAIL: ${t.name} bundle exceeds ${t.capKb} KB gzip cap by ${fmt(gz.length - t.capKb * 1024)}.`);
    exitCode = 1;
  } else {
    console.log(`  OK:    under cap by ${fmt(t.capKb * 1024 - gz.length)}.`);
  }
}

// v2.1 (E19) — verify both freshly-built bundles still contain the string
// tokens of every shipped feature. Catches the "stale / silently-stripped
// bundle" class of bug (finding #4) before it reaches a release.
console.log('\nBundle token check:');
if (!checkBundleTokens()) exitCode = 1;

if (exitCode !== 0) process.exit(exitCode);
