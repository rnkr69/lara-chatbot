#!/usr/bin/env node
//
// Post-build guard that catches two classes of release-time bugs:
//
//   1. Stale or tree-shaken bundle (originally the pre-0.4 finding #4: the pin
//      button never mounted because the SSE-metadata code path got dropped).
//      Encoded as REQUIRED — per-bundle string literals that must survive
//      esbuild's minification (identifiers get mangled; string literals do not).
//
//   2. Cross-bundle protocol drift (originally the pre-0.4 PR-C v2.2.2→v2.2.3
//      same-day cascade: the theme fix shipped in the widget bundle but the
//      equivalent change in the dashboard bundle was forgotten and had to be
//      hot-patched). Encoded as SHARED — tokens that the cross-bundle contract
//      requires to appear in BOTH bundles (public API names, cross-bundle
//      events, declarative attributes both bundles consume).
//
// Each entry below is a STRING LITERAL or CSS class name. When a feature lands
// on the cross-bundle rail, add its token to SHARED. When a feature exists in
// one bundle only but has multiple plausible implementations that could be
// silently broken, add the implementation-distinctive token to REQUIRED for
// that bundle.
//
// Run standalone: `node scripts/check-bundle-tokens.mjs` (or `npm run build:tokens`).
// Also invoked at the end of `scripts/build.mjs` so `npm run build` enforces it.

import { readFileSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const buildDir = resolve(__dirname, '..', 'public-build');

const REQUIRED = {
  'chatbot-widget.js': [
    'pinnable',             // readV2BlockMetadata reads raw['pinnable']
    'block_ordinal',        // replay descriptor (pre-0.4 finding #27)
    'cb-pin-button',        // pin button overlay CSS class
    'cb-pin-wrapper',
    'cb-pin-modal',         // pin-from-chat modal
    'cb-block-error',       // inline stream-error block
    'data-theme-effective', // widget theme resolver attribute (pre-0.4 PR-C)
  ],
  'chatbot-dashboard.js': [
    'cb-dashboard-root',
    'cb-dashboard-card',
    'cb-table',             // shared block-styles module
    'table-striped',        // Bootstrap host-native renderer classes
    'widget_refreshed',     // bulk-refresh SSE frame name
    'cb-theme-dark',        // dashboard theme class (pre-0.4 PR-C v2.2.3)
    'cb-theme-light',       // dashboard theme class (pre-0.4 PR-C v2.2.3)
  ],
};

// Tokens that MUST appear in BOTH bundles — cross-bundle protocol surface.
// A token here that goes missing from either bundle means the public contract
// between widget and dashboard has drifted. Add a token here when a feature
// crosses the bundle boundary (cross-bundle event, shared declarative
// attribute, public API both bundles install).
const SHARED = [
  'setPageContext',             // public API installed by both bundles
  'chatbot:ready',              // boot-handshake event
  'chatbot:dashboard-mutation', // cross-bundle refresh signal (widget emits, dashboard listens)
  'data-i18n',                  // PHP→JS i18n bridge attribute, drained by both
  'registerBlockRenderer',      // public block-renderer registration API
  'kpi',                        // KPI block built-in in both bundles
];

function loadBundle(file) {
  const path = resolve(buildDir, file);
  if (!existsSync(path)) {
    return { path, content: null };
  }
  return { path, content: readFileSync(path, 'utf8') };
}

export function checkBundleTokens() {
  let ok = true;

  // Per-bundle REQUIRED — bundle is stale or feature dropped.
  for (const [file, tokens] of Object.entries(REQUIRED)) {
    const { path, content } = loadBundle(file);
    if (content === null) {
      console.error(`  FAIL: bundle missing at ${path}. Run "npm run build" first.`);
      ok = false;
      continue;
    }
    const missing = tokens.filter((t) => !content.includes(t));
    if (missing.length > 0) {
      console.error(`  FAIL [REQUIRED]: ${file} is missing token(s): ${missing.join(', ')}`);
      console.error('        The bundle is stale or a feature code path was dropped — rebuild.');
      ok = false;
    } else {
      console.log(`  OK [REQUIRED]: ${file} — all ${tokens.length} per-bundle tokens present.`);
    }
  }

  // SHARED — both bundles must carry the cross-bundle contract.
  const bundleFiles = Object.keys(REQUIRED);
  const loaded = bundleFiles.map((f) => ({ file: f, ...loadBundle(f) }));
  if (loaded.every((b) => b.content !== null)) {
    const driftByToken = SHARED.map((tok) => {
      const present = loaded.filter((b) => b.content.includes(tok)).map((b) => b.file);
      const missing = loaded.filter((b) => !b.content.includes(tok)).map((b) => b.file);
      return { tok, present, missing };
    });
    const drifted = driftByToken.filter((d) => d.missing.length > 0);
    if (drifted.length > 0) {
      for (const d of drifted) {
        console.error(`  FAIL [SHARED]: token "${d.tok}" is present in [${d.present.join(', ') || '—'}] but missing in [${d.missing.join(', ')}].`);
        console.error('        Cross-bundle contract drift — a feature was changed in one bundle without the matching change in the other.');
      }
      ok = false;
    } else {
      console.log(`  OK [SHARED]: all ${SHARED.length} cross-bundle tokens present in both bundles.`);
    }
  }

  return ok;
}

// Run standalone when invoked directly.
if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
  console.log('Bundle token check:');
  process.exit(checkBundleTokens() ? 0 : 1);
}
