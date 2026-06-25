/**
 * v2.0 / E7 — Vitest for the wiring in `index.ts`:
 *
 *   - `installChatbotShim()` installs a minimal `window.Chatbot` with
 *     `registerBlockRenderer` + `__internal.getBlockRenderer` when there is no
 *     previous widget bundle.
 *   - `configureChartRenderer()` (v0.4.4) no longer registers a chart renderer
 *     — Chart.js is the CORE built-in (blocks.ts → chart-default.ts). It only
 *     installs the shim so host overrides keep working and #35 migration holds.
 */

import { describe, expect, it, vi, beforeEach } from 'vitest';

// Mock chart.js/auto so that `chart-default.ts` (imported by index.ts) does
// not try to load the real lib during the test setup.
const mocks = vi.hoisted(() => ({
  ChartStub: vi.fn().mockImplementation((canvas: HTMLCanvasElement) => ({
    canvas,
    destroy: vi.fn(),
  })),
}));
vi.mock('chart.js/auto', () => ({ default: mocks.ChartStub }));

// Importing `index.ts` triggers its auto-install (`queueMicrotask(start)`),
// which logs a warn because there's no `#chatbot-dashboard-root` in the test
// DOM. Mute that one-shot warn to keep the test output clean.
const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => undefined);

import { configureChartRenderer, installChatbotShim } from '../../../resources/js/dashboard/index.js';
import type { ChatbotApi } from '../../../resources/js/types.js';

beforeEach(() => {
  document.body.innerHTML = '';
  warnSpy.mockClear();
  // `delete window.Chatbot` works because the property is configurable.
  delete (window as { Chatbot?: unknown }).Chatbot;
});

describe('installChatbotShim', () => {
  it('creates a minimal Chatbot global with registerBlockRenderer + __internal.getBlockRenderer', () => {
    installChatbotShim();
    expect(typeof window.Chatbot).toBe('object');
    expect(typeof window.Chatbot?.registerBlockRenderer).toBe('function');
    expect(typeof window.Chatbot?.__internal.getBlockRenderer).toBe('function');
    expect(window.Chatbot?.__internal.getBlockRenderer('chart')).toBeUndefined();
  });

  it('round-trips a registered renderer', () => {
    installChatbotShim();
    const fn = vi.fn();
    window.Chatbot?.registerBlockRenderer('table', fn);
    expect(window.Chatbot?.__internal.getBlockRenderer('table')).toBe(fn);
  });

  it('is idempotent — does not clobber an existing window.Chatbot', () => {
    const existing = { sentinel: true } as unknown as ChatbotApi;
    (window as { Chatbot?: ChatbotApi }).Chatbot = existing;
    installChatbotShim();
    expect(window.Chatbot).toBe(existing);
  });

  it('rejects non-function renderer silently', () => {
    installChatbotShim();
    window.Chatbot?.registerBlockRenderer('chart', null as unknown as () => HTMLElement);
    expect(window.Chatbot?.__internal.getBlockRenderer('chart')).toBeUndefined();
  });

  it('rejects empty type silently', () => {
    installChatbotShim();
    const fn = vi.fn();
    window.Chatbot?.registerBlockRenderer('', fn);
    expect(window.Chatbot?.__internal.getBlockRenderer('')).toBeUndefined();
  });
});

describe('configureChartRenderer (v0.4.4 — shim only, no chart registration)', () => {
  it('installs the window.Chatbot shim but does NOT register a host chart renderer', () => {
    // Charts render via the core built-in now, so the dashboard registers
    // nothing on the host map — getBlockRenderer('chart') stays undefined while
    // the shim itself exists (for host overrides + #35 migration).
    configureChartRenderer();
    expect(typeof window.Chatbot).toBe('object');
    expect(typeof window.Chatbot?.registerBlockRenderer).toBe('function');
    expect(window.Chatbot?.__internal.getBlockRenderer('chart')).toBeUndefined();
  });

  it('does not clobber an existing host chart renderer', () => {
    // Host loaded the widget bundle (with its own chart override) BEFORE the
    // dashboard bundle. installChatbotShim respects an existing window.Chatbot.
    const hostRenderer = vi.fn();
    const existing = {
      registerBlockRenderer: vi.fn(),
      __internal: {
        getBlockRenderer: vi.fn((type: string) => (type === 'chart' ? hostRenderer : undefined)),
      },
    } as unknown as ChatbotApi;
    (window as { Chatbot?: ChatbotApi }).Chatbot = existing;

    configureChartRenderer();

    expect(window.Chatbot).toBe(existing);
    expect(window.Chatbot?.__internal.getBlockRenderer('chart')).toBe(hostRenderer);
    expect(window.Chatbot?.registerBlockRenderer).not.toHaveBeenCalled();
  });
});
