import { describe, expect, it, beforeEach } from 'vitest';
import { emitDashboardContext } from '../../resources/js/dashboard/index.js';
import { installApi, markReady } from '../../resources/js/api.js';

/**
 * v2.2 — dashboard page_context auto-inject. The dashboard bundle reads
 * `data-dashboard-context` from the root and emits it via `Chatbot.setPageContext`,
 * but respects the shim's timing (the call waits for the widget bundle's
 * `chatbot:ready` if the real API has not been published yet).
 */

declare global {
  interface Window {
    Chatbot?: import('../../resources/js/types.js').ChatbotApi;
    __chatbot_widget_initialized__?: true;
    __chatbot_widget_ready__?: true;
  }
}

function buildRoot(contextValue: string | null): HTMLElement {
  const root = document.createElement('div');
  root.id = 'chatbot-dashboard-root';
  if (contextValue !== null) {
    root.setAttribute('data-dashboard-context', contextValue);
  }
  document.body.appendChild(root);
  return root;
}

beforeEach(() => {
  delete (window as Window).Chatbot;
  delete (window as Window).__chatbot_widget_initialized__;
  delete (window as Window).__chatbot_widget_ready__;
  document.body.innerHTML = '';
});

describe('emitDashboardContext', () => {
  it('emits setPageContext immediately when the widget bundle is already ready', () => {
    installApi();
    markReady();
    const root = buildRoot(JSON.stringify({
      slug: 'qa', name: 'QA', is_default: true,
      widgets: [{ id: 7, title: 'KPI', block_type: 'kpi' }],
    }));

    emitDashboardContext(root);

    const ctx = window.Chatbot!.__internal.getPageContext();
    expect(ctx).toEqual({
      dashboard: {
        slug: 'qa', name: 'QA', is_default: true,
        widgets: [{ id: 7, title: 'KPI', block_type: 'kpi' }],
      },
    });
  });

  it('defers until the chatbot:ready event when the API is not yet ready', () => {
    installApi(); // creates window.Chatbot but does NOT mark ready
    const root = buildRoot(JSON.stringify({ slug: 'a', name: 'A', is_default: false, widgets: [] }));

    emitDashboardContext(root);

    // setPageContext not called yet — the listener is queued.
    expect(window.Chatbot!.__internal.getPageContext()).toEqual({});

    markReady();

    // After the ready event fires the listener runs and the page_context
    // lands.
    expect(window.Chatbot!.__internal.getPageContext()).toEqual({
      dashboard: { slug: 'a', name: 'A', is_default: false, widgets: [] },
    });
  });

  it('does nothing when data-dashboard-context is the empty JSON object', () => {
    installApi();
    markReady();
    const root = buildRoot('[]'); // controller emits `[]` when there is no dashboard

    emitDashboardContext(root);

    expect(window.Chatbot!.__internal.getPageContext()).toEqual({});
  });

  it('does nothing when data-dashboard-context is absent', () => {
    installApi();
    markReady();
    const root = buildRoot(null);

    emitDashboardContext(root);

    expect(window.Chatbot!.__internal.getPageContext()).toEqual({});
  });

  it('logs a warning and bails out when the attribute is not parseable JSON', () => {
    installApi();
    markReady();
    const root = buildRoot('not-json');
    const originalWarn = console.warn;
    let warned = false;
    console.warn = () => { warned = true; };

    try {
      emitDashboardContext(root);
    } finally {
      console.warn = originalWarn;
    }

    expect(warned).toBe(true);
    expect(window.Chatbot!.__internal.getPageContext()).toEqual({});
  });
});
