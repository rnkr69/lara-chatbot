import { afterEach, describe, expect, it } from 'vitest';
import { injectStyles } from '../../../resources/js/dashboard/styles.js';

/**
 * v2.1 (E14 / #19) — `injectStyles(useBootstrap)` gates whether the package's
 * own block-primitive CSS is injected. In layout mode with the host's
 * Bootstrap present (`useBootstrap = true`) the bundle skips `BLOCK_STYLES`
 * + `BLOCK_DASHBOARD_CSS` so the host's `.table` / `.card` / `.list-group`
 * own the look. The dashboard shell CSS is injected either way.
 */
const STYLE_ID = 'chatbot-dashboard-styles';

function styleText(): string {
  return document.getElementById(STYLE_ID)?.textContent ?? '';
}

afterEach(() => {
  document.getElementById(STYLE_ID)?.remove();
});

describe('injectStyles', () => {
  it('injects the block-primitive CSS when useBootstrap is false (default)', () => {
    injectStyles();
    const css = styleText();
    // shared block base + dashboard block polish are present
    expect(css).toContain('.cb-table');
    expect(css).toContain('.cb-card-body');
    expect(css).toContain('.cb-dashboard-card-body .cb-table tbody tr:hover');
    // shell CSS is always there
    expect(css).toContain('.cb-dashboard-root');
  });

  it('skips the block-primitive CSS when useBootstrap is true', () => {
    injectStyles(true);
    const css = styleText();
    // the package block CSS is NOT injected — the host's Bootstrap owns it
    expect(css).not.toContain('.cb-table tbody tr:hover');
    expect(css).not.toContain('.cb-card-body { padding');
    // but the dashboard shell CSS is still injected
    expect(css).toContain('.cb-dashboard-root');
    expect(css).toContain('.cb-dashboard-sidebar');
  });

  it('is idempotent — a second call does not append a second <style>', () => {
    injectStyles();
    injectStyles(true);
    expect(document.querySelectorAll(`#${STYLE_ID}`).length).toBe(1);
  });
});
