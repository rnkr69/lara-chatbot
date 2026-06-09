/**
 * v2.0 / E7 — Vitest para el cableado en `index.ts`:
 *
 *   - `installChatbotShim()` instala un mini `window.Chatbot` con
 *     `registerBlockRenderer` + `__internal.getBlockRenderer` cuando no hay
 *     widget bundle previo.
 *   - `configureChartRenderer(root)` respeta `data-chart-renderer`:
 *       * `'chartjs'` (default): registra `renderChartBlockChartjs`.
 *       * `'none'`: no registra nada.
 *   - Si el host registró su propio renderer ANTES, no clobeamos.
 */

import { describe, expect, it, vi, beforeEach } from 'vitest';

// Mock chart.js/auto para que `chart-default.ts` (importado por index.ts) no
// intente cargar la lib real durante el setup del test.
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

function makeRoot(attrs: Record<string, string> = {}): HTMLElement {
  const el = document.createElement('div');
  for (const [k, v] of Object.entries(attrs)) el.setAttribute(k, v);
  document.body.appendChild(el);
  return el;
}

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

describe('configureChartRenderer', () => {
  it('registers the chart renderer when data-chart-renderer=chartjs', () => {
    const root = makeRoot({ 'data-chart-renderer': 'chartjs' });
    configureChartRenderer(root);
    const renderer = window.Chatbot?.__internal.getBlockRenderer('chart');
    expect(typeof renderer).toBe('function');
  });

  it('registers when data-chart-renderer is missing (defaults to chartjs)', () => {
    const root = makeRoot();
    configureChartRenderer(root);
    expect(typeof window.Chatbot?.__internal.getBlockRenderer('chart')).toBe('function');
  });

  it('does NOT register when data-chart-renderer=none', () => {
    const root = makeRoot({ 'data-chart-renderer': 'none' });
    configureChartRenderer(root);
    // The shim is not even installed when 'none' — the function only fires for chartjs.
    expect(window.Chatbot?.__internal.getBlockRenderer('chart')).toBeUndefined();
  });

  it('does not clobber an existing host chart renderer', () => {
    // Simulate the host having loaded the widget bundle BEFORE the dashboard
    // bundle, with its own chart renderer registered.
    const hostRenderer = vi.fn();
    (window as { Chatbot?: ChatbotApi }).Chatbot = {
      registerBlockRenderer: vi.fn(),
      __internal: {
        getBlockRenderer: vi.fn((type: string) => (type === 'chart' ? hostRenderer : undefined)),
      },
    } as unknown as ChatbotApi;

    const root = makeRoot({ 'data-chart-renderer': 'chartjs' });
    configureChartRenderer(root);

    expect(window.Chatbot?.__internal.getBlockRenderer('chart')).toBe(hostRenderer);
    expect(window.Chatbot?.registerBlockRenderer).not.toHaveBeenCalled();
  });

  it('case-insensitive on data-chart-renderer (NONE / Chartjs)', () => {
    const root1 = makeRoot({ 'data-chart-renderer': 'NONE' });
    configureChartRenderer(root1);
    expect(window.Chatbot?.__internal.getBlockRenderer('chart')).toBeUndefined();

    document.body.innerHTML = '';
    delete (window as { Chatbot?: unknown }).Chatbot;
    const root2 = makeRoot({ 'data-chart-renderer': 'Chartjs' });
    configureChartRenderer(root2);
    expect(typeof window.Chatbot?.__internal.getBlockRenderer('chart')).toBe('function');
  });
});
