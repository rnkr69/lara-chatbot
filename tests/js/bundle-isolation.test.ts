import { describe, expect, it, beforeEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Bug #3 regression — the production bundle must not pollute global scope.
 *
 * Before v1.1, the bundle was emitted as `format: 'esm'` but loaded as a
 * classic <script>. esbuild's minifier renamed `postConfirm` (and other
 * short-named internals) to `$`, which then became a property of `window`
 * and overwrote jQuery's `$`. After v1.1 the bundle is IIFE-wrapped so all
 * minified internals stay local; the only globals it sets are the ones it
 * sets explicitly (window.Chatbot + the chatbot-widget custom element).
 */

const BUNDLE_PATH = resolve(__dirname, '../../public-build/chatbot-widget.js');

declare global {
  interface Window {
    $?: unknown;
    jQuery?: unknown;
  }
}

function loadBundle(): void {
  const code = readFileSync(BUNDLE_PATH, 'utf8');
  // Execute in the global jsdom context. The IIFE runs immediately;
  // any internal `function $(...)` declarations stay scoped inside it.
  // eslint-disable-next-line @typescript-eslint/no-implied-eval
  new Function(code)();
}

describe('production bundle isolation', () => {
  beforeEach(() => {
    delete (window as Window).$;
    delete (window as Window).jQuery;
    delete (window as Window & { Chatbot?: unknown }).Chatbot;
  });

  it('does not overwrite window.$ when jQuery is loaded first', () => {
    const fakeJquery = (selector: string) => ({ selector, length: 0 });
    (window as Window).jQuery = fakeJquery;
    (window as Window).$ = fakeJquery;

    loadBundle();

    expect((window as Window).$).toBe(fakeJquery);
    expect((window as Window).$).toBe((window as Window).jQuery);
  });

  it('does not introduce a top-level $ when no jQuery is present', () => {
    expect((window as Window).$).toBeUndefined();

    loadBundle();

    expect((window as Window).$).toBeUndefined();
  });

  it('still attaches the public window.Chatbot API', () => {
    loadBundle();

    expect((window as Window & { Chatbot?: unknown }).Chatbot).toBeDefined();
  });
});
