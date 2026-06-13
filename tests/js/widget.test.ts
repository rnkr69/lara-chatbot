import { describe, expect, it, beforeEach, afterEach, vi } from 'vitest';
import { defineWidget, ChatbotWidgetElement } from '../../resources/js/widget.js';
import { saveState, clearState } from '../../resources/js/persistence.js';
import { resetModeCache } from '../../resources/js/runtime.js';

declare global {
  interface Window {
    Inertia?: unknown;
    Livewire?: unknown;
  }
}

beforeEach(() => {
  // The custom element registry is global per JSDOM document — define once.
  defineWidget();
  document.body.innerHTML = '';
  delete (window as Window).Chatbot;
  delete (window as Window).__chatbot_widget_initialized__;
  delete (window as Window).Inertia;
  delete (window as Window).Livewire;
  clearState();
  // E17 / D16: page-mode tests mirror conversationId to localStorage, and
  // the widget's rehydrate path promotes session→local. Clear that key too
  // so each test starts with a pristine cross-tab state.
  window.localStorage.clear();
  resetModeCache();
  // jsdom 24 does not expose CSS.escape on the window. The widget uses it to
  // build attribute selectors; provide a minimal polyfill so refreshAssistantNode
  // can run in the test environment.
  if (typeof (globalThis as { CSS?: { escape?: (v: string) => string } }).CSS === 'undefined'
      || typeof (globalThis as { CSS?: { escape?: (v: string) => string } }).CSS!.escape !== 'function') {
    (globalThis as { CSS?: { escape?: (v: string) => string } }).CSS = {
      escape: (value: string): string => String(value).replace(/[^a-zA-Z0-9_-]/g, (c) => `\\${c}`),
    };
  }
});

afterEach(() => {
  vi.useRealTimers();
});

function makeWidget(): ChatbotWidgetElement {
  const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
  el.setAttribute('data-endpoint', '/chatbot/stream');
  document.body.appendChild(el);
  return el;
}

describe('ChatbotWidgetElement persistence (E13)', () => {
  it('rehydrates conversationId, isOpen and draft from sessionStorage on connect', () => {
    saveState({ conversationId: 'conv-7', isOpen: true, draft: 'half-typed' });
    const el = makeWidget();
    expect(el.getAttribute('data-conversation-id')).toBe('conv-7');
    expect(el.getAttribute('data-state')).toBe('open');
    const textarea = el.shadowRoot!.querySelector('textarea')!;
    expect(textarea.value).toBe('half-typed');
  });

  it('falls back to data-default-open when no persisted state exists', () => {
    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    el.setAttribute('data-default-open', 'true');
    document.body.appendChild(el);
    expect(el.getAttribute('data-state')).toBe('open');
  });

  it('persists state changes after typing into the textarea (debounced)', () => {
    vi.useFakeTimers();
    const el = makeWidget();
    const textarea = el.shadowRoot!.querySelector('textarea')!;
    textarea.value = 'hello';
    textarea.dispatchEvent(new Event('input'));
    vi.advanceTimersByTime(300);
    const raw = window.sessionStorage.getItem('chatbot:state:v1');
    expect(raw).not.toBeNull();
    const parsed = JSON.parse(raw!) as { draft: string };
    expect(parsed.draft).toBe('hello');
  });

  it('clears the draft on submit and persists the empty draft', () => {
    vi.useFakeTimers();
    const el = makeWidget();
    const textarea = el.shadowRoot!.querySelector('textarea')!;
    const form = el.shadowRoot!.querySelector('form')!;
    textarea.value = 'send me';
    // Stub fetch so the SSE reader doesn't try to parse a real response.
    const fetchMock = vi.fn(async () => new Response('', { status: 500 }));
    Object.defineProperty(window, 'fetch', { configurable: true, value: fetchMock });
    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    expect(textarea.value).toBe('');
    vi.advanceTimersByTime(300);
    const parsed = JSON.parse(
      window.sessionStorage.getItem('chatbot:state:v1') ?? '{}',
    ) as { draft?: string };
    expect(parsed.draft ?? '').toBe('');
  });
});

describe('ChatbotWidgetElement SPA navigation (E13)', () => {
  it('aborts the current stream when inertia:navigate fires (SPA mode)', async () => {
    (window as Window).Inertia = { visit: () => undefined };
    resetModeCache();
    const el = makeWidget();
    // Inject a fake current stream we can observe.
    const aborted = vi.fn();
    // Reach into the private field via index access — keeps the test minimal.
    (el as unknown as { currentStream: { abort: () => void } | null }).currentStream = {
      abort: aborted,
    };
    window.dispatchEvent(new Event('inertia:navigate'));
    expect(aborted).toHaveBeenCalled();
  });

  it('does not register SPA listeners in MPA mode', () => {
    const el = makeWidget();
    const aborted = vi.fn();
    (el as unknown as { currentStream: { abort: () => void } | null }).currentStream = {
      abort: aborted,
    };
    window.dispatchEvent(new Event('inertia:navigate'));
    expect(aborted).not.toHaveBeenCalled();
  });
});

describe('ChatbotWidgetElement page context (E14)', () => {
  it('reads the chatbot:context meta tag on connect and seeds the API context', () => {
    document.head.innerHTML =
      '<meta name="chatbot:context" content=\'{"route":"orders.index","tenant":7}\'>';
    const el = makeWidget();
    expect(window.Chatbot!.__internal.getPageContext()).toEqual({
      route: 'orders.index',
      tenant: 7,
    });
    el.remove();
    document.head.innerHTML = '';
  });

  it('emits chatbot:context-changed once the meta tag has been ingested at boot', () => {
    document.head.innerHTML =
      '<meta name="chatbot:context" content=\'{"route":"a"}\'>';
    const events: unknown[] = [];
    const listener = (e: Event): void => { events.push((e as CustomEvent).detail); };
    window.addEventListener('chatbot:context-changed', listener);
    makeWidget();
    expect(events).toEqual([{ route: 'a' }]);
    window.removeEventListener('chatbot:context-changed', listener);
    document.head.innerHTML = '';
  });

  it('re-reads the meta tag and emits on every SPA navigation (D14 hook)', () => {
    (window as Window).Inertia = { visit: () => undefined };
    resetModeCache();
    document.head.innerHTML =
      '<meta name="chatbot:context" content=\'{"route":"a"}\'>';
    const events: unknown[] = [];
    const listener = (e: Event): void => { events.push((e as CustomEvent).detail); };
    window.addEventListener('chatbot:context-changed', listener);
    makeWidget(); // boot — first emission
    expect(events.length).toBe(1);

    // Simulate SPA navigation: host updates the meta tag, then fires inertia:navigate.
    document.head.innerHTML =
      '<meta name="chatbot:context" content=\'{"route":"b","page":3}\'>';
    window.dispatchEvent(new Event('inertia:navigate'));

    // The widget re-reads + setPageContext shallow merges → emission #2.
    expect(events).toEqual([
      { route: 'a' },
      { route: 'b', page: 3 },
    ]);
    expect(window.Chatbot!.__internal.getPageContext()).toEqual({ route: 'b', page: 3 });

    window.removeEventListener('chatbot:context-changed', listener);
    document.head.innerHTML = '';
  });

  it('does not seed the context when the meta tag is absent', () => {
    const events: unknown[] = [];
    const listener = (e: Event): void => { events.push((e as CustomEvent).detail); };
    window.addEventListener('chatbot:context-changed', listener);
    makeWidget();
    expect(events).toEqual([]);
    expect(window.Chatbot!.__internal.getPageContext()).toEqual({});
    window.removeEventListener('chatbot:context-changed', listener);
  });
});

describe('ChatbotWidgetElement render_block frontend_action interception (E15)', () => {
  type Privates = {
    currentAssistant: { id: string; role: string; text: string; blocks: Array<{ type: string; data: unknown }>; pending: boolean } | null;
    bodyEl: HTMLElement;
    handleFrame(frame: { event: string; data: Record<string, unknown> }): void;
  };

  function privates(el: ChatbotWidgetElement): Privates {
    return el as unknown as Privates;
  }

  function seedAssistant(el: ChatbotWidgetElement): void {
    const p = privates(el);
    p.currentAssistant = { id: 'a-1', role: 'assistant', text: '', blocks: [], pending: true };
    const node = document.createElement('div');
    node.className = 'msg assistant';
    node.dataset['msgId'] = 'a-1';
    p.bodyEl.appendChild(node);
  }

  it('converts a render_block frontend_action into a block on the current assistant message', () => {
    const el = makeWidget();
    seedAssistant(el);
    privates(el).handleFrame({
      event: 'frontend_action',
      data: {
        tool: 'render_block',
        args: { type: 'table', data: { rows: [{ id: 1 }, { id: 2 }] } },
        action_id: 'a',
        confirmation: 'auto',
      },
    });
    const blocks = privates(el).currentAssistant!.blocks;
    expect(blocks).toEqual([{ type: 'table', data: { rows: [{ id: 1 }, { id: 2 }] } }]);
    // And the table actually got rendered into the body.
    const renderedRows = el.shadowRoot!.querySelectorAll('.cb-table tbody tr');
    expect(renderedRows.length).toBe(2);
  });

  it('does nothing when args.type is missing or empty', () => {
    const el = makeWidget();
    seedAssistant(el);
    privates(el).handleFrame({
      event: 'frontend_action',
      data: {
        tool: 'render_block',
        args: { data: { rows: [] } },
        action_id: 'b',
        confirmation: 'auto',
      },
    });
    expect(privates(el).currentAssistant!.blocks).toEqual([]);
  });

  it('still routes non-render_block frontend_actions through the action handler', () => {
    const el = makeWidget();
    seedAssistant(el);
    const calls: unknown[] = [];
    window.Chatbot!.registerTool('custom_tool', (args) => { calls.push(args); });
    privates(el).handleFrame({
      event: 'frontend_action',
      data: {
        tool: 'custom_tool',
        args: { foo: 'bar' },
        action_id: 'c',
        confirmation: 'auto',
      },
    });
    // Block list stays empty, but the registered tool was invoked.
    expect(privates(el).currentAssistant!.blocks).toEqual([]);
    expect(calls).toEqual([{ foo: 'bar' }]);
  });
});

describe('ChatbotWidgetElement dashboard mutation side_effects (v2.2.1 PR-B)', () => {
  type Privates = {
    currentAssistant: { id: string; role: string; text: string; blocks: unknown[]; pending: boolean } | null;
    bodyEl: HTMLElement;
    handleFrame(frame: { event: string; data: Record<string, unknown> }): void;
  };
  function privates(el: ChatbotWidgetElement): Privates {
    return el as unknown as Privates;
  }
  function seedAssistant(el: ChatbotWidgetElement): void {
    const p = privates(el);
    p.currentAssistant = { id: 'a-1', role: 'assistant', text: '', blocks: [], pending: true };
    const node = document.createElement('div');
    node.className = 'msg assistant';
    node.dataset['msgId'] = 'a-1';
    p.bodyEl.appendChild(node);
  }

  it('dispatches `chatbot:dashboard-mutation` on `document` when a block frame carries meta.side_effects', () => {
    const el = makeWidget();
    seedAssistant(el);
    const events: CustomEvent[] = [];
    const listener = (e: Event): void => { events.push(e as CustomEvent); };
    document.addEventListener('chatbot:dashboard-mutation', listener);

    privates(el).handleFrame({
      event: 'block',
      data: {
        type: 'card',
        data: { title: '✅ Added' },
        meta: { side_effects: { type: 'widget_added', dashboard_slug: 'ops', widget_id: 7 } },
      },
    });

    document.removeEventListener('chatbot:dashboard-mutation', listener);
    expect(events.length).toBe(1);
    expect(events[0]!.detail).toEqual({ type: 'widget_added', dashboard_slug: 'ops', widget_id: 7 });
  });

  it('does NOT dispatch the event when the block has no meta.side_effects (v1 block back-compat)', () => {
    const el = makeWidget();
    seedAssistant(el);
    const events: CustomEvent[] = [];
    const listener = (e: Event): void => { events.push(e as CustomEvent); };
    document.addEventListener('chatbot:dashboard-mutation', listener);

    privates(el).handleFrame({
      event: 'block',
      data: { type: 'card', data: { title: 'just a card' } },
    });

    document.removeEventListener('chatbot:dashboard-mutation', listener);
    expect(events.length).toBe(0);
  });

  it('does NOT dispatch when meta.side_effects is malformed (no string type)', () => {
    const el = makeWidget();
    seedAssistant(el);
    const events: CustomEvent[] = [];
    const listener = (e: Event): void => { events.push(e as CustomEvent); };
    document.addEventListener('chatbot:dashboard-mutation', listener);

    privates(el).handleFrame({
      event: 'block',
      data: {
        type: 'card',
        data: {},
        meta: { side_effects: { dashboard_slug: 'ops' } }, // missing `type`
      },
    });

    document.removeEventListener('chatbot:dashboard-mutation', listener);
    expect(events.length).toBe(0);
  });
});

describe('ChatbotWidgetElement stream error frame (#3)', () => {
  type Privates = {
    currentAssistant: { id: string; role: string; text: string; blocks: unknown[]; pending: boolean; error?: string } | null;
    bodyEl: HTMLElement;
    handleFrame(frame: { event: string; data: Record<string, unknown> }): void;
  };

  function privates(el: ChatbotWidgetElement): Privates {
    return el as unknown as Privates;
  }

  function seedAssistant(el: ChatbotWidgetElement): void {
    const p = privates(el);
    p.currentAssistant = { id: 'a-1', role: 'assistant', text: '', blocks: [], pending: true };
    const node = document.createElement('div');
    node.className = 'msg assistant';
    node.dataset['msgId'] = 'a-1';
    p.bodyEl.appendChild(node);
  }

  it('renders an inline error block into the assistant message instead of leaving it empty', () => {
    const el = makeWidget();
    seedAssistant(el);
    // The handler logs the raw provider message — silence it for the test.
    const originalErr = console.error;
    console.error = () => undefined;
    try {
      privates(el).handleFrame({
        event: 'error',
        data: { message: 'Connection refused for URI https://provider.example/v1' },
      });
    } finally {
      console.error = originalErr;
    }

    // The model carries a localized, non-technical error message.
    expect(privates(el).currentAssistant!.error).toBe('Something went wrong. Please try again.');

    // The assistant DOM node shows it (it rendered completely empty before #3)
    // and is NOT leaking the raw technical provider string.
    const node = el.shadowRoot!.querySelector('[data-msg-id="a-1"]')!;
    const errEl = node.querySelector('.cb-block-error');
    expect(errEl).not.toBeNull();
    expect(errEl!.textContent).toBe('Something went wrong. Please try again.');
    expect(node.classList.contains('failed')).toBe(true);
    expect(node.textContent).not.toContain('Connection refused');
  });
});

describe('ChatbotWidgetElement confirm/manual banner routing (E16)', () => {
  type Privates = {
    currentAssistant: { id: string; role: string; text: string; blocks: unknown[]; pending: boolean } | null;
    bodyEl: HTMLElement;
    handleFrame(frame: { event: string; data: Record<string, unknown> }): void;
  };

  function privates(el: ChatbotWidgetElement): Privates {
    return el as unknown as Privates;
  }

  function seedAssistant(el: ChatbotWidgetElement): void {
    const p = privates(el);
    p.currentAssistant = { id: 'a-1', role: 'assistant', text: '', blocks: [], pending: true };
    const node = document.createElement('div');
    node.className = 'msg assistant';
    node.dataset['msgId'] = 'a-1';
    p.bodyEl.appendChild(node);
  }

  it('attaches a confirm banner under the current assistant for confirmation=confirm and does NOT run the primitive', () => {
    const el = makeWidget();
    seedAssistant(el);
    const toolCalls: unknown[] = [];
    window.Chatbot!.registerTool('confirm_dialog', (args) => { toolCalls.push(args); });

    privates(el).handleFrame({
      event: 'frontend_action',
      data: {
        tool: 'confirm_dialog',
        args: { message: 'Run it?' },
        action_id: 'aaaa-bbbb',
        confirmation: 'confirm',
      },
    });

    const banner = el.shadowRoot!.querySelector<HTMLElement>('.cb-confirm-banner');
    expect(banner).not.toBeNull();
    expect(banner!.dataset['actionId']).toBe('aaaa-bbbb');
    expect(banner!.dataset['confirmation']).toBe('confirm');

    // The primitive must not run until the user accepts.
    expect(toolCalls).toEqual([]);
  });

  it('uses manual labels for confirmation=manual', () => {
    const el = makeWidget();
    seedAssistant(el);

    privates(el).handleFrame({
      event: 'frontend_action',
      data: {
        tool: 'invoke_host_action',
        args: { action: 'sign' },
        action_id: 'manual-1',
        confirmation: 'manual',
      },
    });

    const banner = el.shadowRoot!.querySelector<HTMLElement>('.cb-confirm-banner');
    expect(banner).not.toBeNull();
    expect(banner!.dataset['confirmation']).toBe('manual');
    const accept = banner!.querySelector<HTMLButtonElement>('.cb-confirm-accept');
    expect(accept?.textContent).toBe('Mark as done');
  });
});

describe('ChatbotWidgetElement auto-action failure reporting (v1.1.3 #16)', () => {
  type Privates = {
    handleFrame(frame: { event: string; data: Record<string, unknown> }): void;
  };
  function privates(el: ChatbotWidgetElement): Privates {
    return el as unknown as Privates;
  }

  it('POSTs the failure to the confirm endpoint when an auto primitive returns ok:false', async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ data: { status: 'executed' } }), { status: 200 }));
    Object.defineProperty(window, 'fetch', { configurable: true, value: fetchMock });
    const el = makeWidget();
    // Trigger fill_form with a form_id that does not exist on the page.
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    privates(el).handleFrame({
      event: 'frontend_action',
      data: {
        tool: 'fill_form',
        args: { form_id: 'filtersForm', fields: [{ name: 'status', value: 'approved' }] },
        action_id: 'auto-fail-uuid',
        confirmation: 'auto',
      },
    });
    // Allow the void Promise inside reportAutoActionFailure to flush.
    await Promise.resolve();
    await Promise.resolve();

    const failureCall = (fetchMock.mock.calls as unknown as Array<[string, { body: string }]>).find(
      ([url]) => url.includes('/actions/auto-fail-uuid/confirm'),
    );
    expect(failureCall).toBeDefined();
    const body = JSON.parse(failureCall![1].body) as {
      accept: boolean;
      result: { ok: boolean; error: string };
    };
    expect(body.accept).toBe(true);
    expect(body.result.ok).toBe(false);
    expect(body.result.error).toBe('no_form_matched');
    warn.mockRestore();
  });

  it('does NOT POST when the auto primitive succeeds (happy path stays cheap)', async () => {
    const fetchMock = vi.fn(async () => new Response('{}', { status: 200 }));
    Object.defineProperty(window, 'fetch', { configurable: true, value: fetchMock });
    const el = makeWidget();

    privates(el).handleFrame({
      event: 'frontend_action',
      data: {
        tool: 'show_toast',
        args: { message: 'hi' },
        action_id: 'auto-ok-uuid',
        confirmation: 'auto',
      },
    });
    await Promise.resolve();
    await Promise.resolve();

    const confirmCalls = (fetchMock.mock.calls as unknown as Array<[string]>).filter(
      ([url]) => url.includes('/actions/auto-ok-uuid/confirm'),
    );
    expect(confirmCalls.length).toBe(0);
  });
});

describe('ChatbotWidgetElement page mode (E17)', () => {
  function makePageWidget(opts: { conversationsEndpoint?: string | null } = {}): ChatbotWidgetElement {
    // Mock fetch so the sidebar's initial GET resolves with an empty list.
    Object.defineProperty(window, 'fetch', {
      configurable: true,
      value: vi.fn(async () => new Response(JSON.stringify({ data: [] }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })),
    });
    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    el.setAttribute('mode', 'page');
    if (opts.conversationsEndpoint !== null) {
      el.setAttribute(
        'data-conversations-endpoint',
        opts.conversationsEndpoint ?? '/chatbot/conversations',
      );
    }
    document.body.appendChild(el);
    return el;
  }

  it('mirrors the mode attribute to data-mode and hides the launcher', () => {
    const el = makePageWidget();
    expect(el.getAttribute('data-mode')).toBe('page');
    const launcher = el.shadowRoot!.querySelector<HTMLElement>('.launcher');
    // CSS hides via :host([data-mode="page"]) .launcher — we verify the element
    // is still in the DOM (no need to render CSS), but the host attribute is set.
    expect(launcher).not.toBeNull();
  });

  it('forces the state to open in page mode (the page is always visible)', () => {
    const el = makePageWidget();
    expect(el.getAttribute('data-state')).toBe('open');
  });

  it('mounts a sidebar inside the panel when data-conversations-endpoint is provided', () => {
    const el = makePageWidget();
    const sidebar = el.shadowRoot!.querySelector<HTMLElement>('.cb-sidebar');
    expect(sidebar).not.toBeNull();
    expect(sidebar!.querySelector('.cb-sidebar-search-input')).not.toBeNull();
  });

  it('still mounts the sidebar when data-conversations-endpoint is missing but data-endpoint ends in /stream (v2.2.1 PR-A fallback)', () => {
    // v2.2.1 — the canonical mount snippet (getting-started.md) only declares
    // `data-endpoint`. Before the fix, page mode here rendered a sidebar-less
    // layout AND the MPA history rehydrate silently no-op'd. Now the widget
    // derives `/chatbot/stream` → `/chatbot/conversations` so both behave.
    const el = makePageWidget({ conversationsEndpoint: null });
    const sidebar = el.shadowRoot!.querySelector<HTMLElement>('.cb-sidebar');
    expect(sidebar).not.toBeNull();
    const panel = el.shadowRoot!.querySelector<HTMLElement>('.panel');
    expect(panel?.classList.contains('cb-page-layout-no-sidebar')).toBe(false);
  });

  it('falls back to sidebar-less page layout when neither attribute is resolvable (data-endpoint not /stream)', () => {
    // No `data-conversations-endpoint`, and `data-endpoint` does NOT end in
    // `/stream` — the deriveConversationsEndpoint() helper returns null, so
    // page mode degrades to a single-column layout instead of mounting a
    // sidebar with a broken endpoint.
    Object.defineProperty(window, 'fetch', {
      configurable: true,
      value: vi.fn(async () => new Response(JSON.stringify({ data: [] }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })),
    });
    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/api/v1/llm/turn'); // non-canonical
    el.setAttribute('mode', 'page');
    document.body.appendChild(el);
    const sidebar = el.shadowRoot!.querySelector<HTMLElement>('.cb-sidebar');
    expect(sidebar).toBeNull();
    const panel = el.shadowRoot!.querySelector<HTMLElement>('.panel');
    expect(panel?.classList.contains('cb-page-layout-no-sidebar')).toBe(true);
  });

  it('mirrors data-conversation-id changes to localStorage (D16 cross-tab)', () => {
    const el = makePageWidget();
    el.setAttribute('data-conversation-id', 'conv-xyz');
    const stored = window.localStorage.getItem('chatbot:active-conversation:v1');
    expect(stored).toBe('"conv-xyz"');
  });

  it('rehydrates conversationId from localStorage with priority over sessionStorage (D16)', () => {
    saveState({ conversationId: 'session-id', isOpen: false, draft: '' });
    window.localStorage.setItem(
      'chatbot:active-conversation:v1',
      JSON.stringify('cross-tab-id'),
    );
    const el = makePageWidget();
    expect(el.getAttribute('data-conversation-id')).toBe('cross-tab-id');
  });

  it('promotes a sessionStorage conversationId to localStorage on first boot (D16)', () => {
    saveState({ conversationId: 'session-only', isOpen: false, draft: '' });
    expect(window.localStorage.getItem('chatbot:active-conversation:v1')).toBeNull();
    makePageWidget();
    expect(window.localStorage.getItem('chatbot:active-conversation:v1'))
      .toBe('"session-only"');
  });

  it('clears the cross-tab key when data-conversation-id is removed', () => {
    const el = makePageWidget();
    el.setAttribute('data-conversation-id', 'conv-1');
    expect(window.localStorage.getItem('chatbot:active-conversation:v1')).toBe('"conv-1"');
    el.removeAttribute('data-conversation-id');
    expect(window.localStorage.getItem('chatbot:active-conversation:v1')).toBeNull();
  });

  it('v2.1.3 #36: renders messages of an existing conversation hydrated from `messages.data` (sibling of `data`)', async () => {
    // Server shape (matches ConversationControllerTest::show):
    //   { data: <conversation>, messages: { data: [<msg>, …] } }
    // The pre-2.1.3 client read payload.data.messages.data → undefined → empty.
    // Note: do NOT use `makePageWidget()` here — its helper overwrites
    // `window.fetch` with the empty-list mock, which would clobber the
    // routed mock below. Mount the element by hand instead.
    window.localStorage.setItem(
      'chatbot:active-conversation:v1',
      JSON.stringify('90'),
    );
    Object.defineProperty(window, 'fetch', {
      configurable: true,
      value: vi.fn(async (input: RequestInfo | URL) => {
        const url = String(input);
        if (/\/conversations\/\d+(?:\?|$)/.test(url)) {
          return new Response(JSON.stringify({
            data: { id: 90, title: 'Existing thread' },
            messages: {
              data: [
                // Server returns id desc; the client reverses to chronological.
                { id: 2, role: 'assistant', content: [{ type: 'text', text: 'Sure — picking that up now.' }] },
                { id: 1, role: 'user', content: [{ type: 'text', text: 'What is the fleet status?' }] },
              ],
              links: {},
              meta: { per_page: 50, next_cursor: null, prev_cursor: null },
            },
          }), { status: 200, headers: { 'Content-Type': 'application/json' } });
        }
        // Sidebar's initial GET /conversations — empty list.
        return new Response(JSON.stringify({ data: [] }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        });
      }),
    });
    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    el.setAttribute('mode', 'page');
    el.setAttribute('data-conversations-endpoint', '/chatbot/conversations');
    document.body.appendChild(el);

    // fetchAndRenderConversation is fire-and-forget — await several microtask
    // turns so its promise chain (fetch → json → adapt → append) settles in
    // jsdom. Four turns covers fetch resolution + Response.json() + loops.
    for (let i = 0; i < 8; i++) await Promise.resolve();

    const body = el.shadowRoot!.querySelector<HTMLElement>('.body');
    expect(body).not.toBeNull();
    expect(body!.textContent).toContain('What is the fleet status?');
    expect(body!.textContent).toContain('Sure — picking that up now.');
  });
});

describe('ChatbotWidgetElement cross-user gating (v1.1.3 #21)', () => {
  it('purges the cross-tab active conversation when data-user-id changes between boots', () => {
    // Pretend user "1" was active last and left conversation 99 behind.
    window.localStorage.setItem('chatbot:active-user:v1', '1');
    window.localStorage.setItem('chatbot:active-conversation:v1', '"99"');

    // A new boot under user "2" must drop user 1's conversation.
    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    el.setAttribute('data-user-id', '2');
    document.body.appendChild(el);

    expect(window.localStorage.getItem('chatbot:active-conversation:v1')).toBeNull();
    expect(window.localStorage.getItem('chatbot:active-user:v1')).toBe('2');
    expect(el.getAttribute('data-conversation-id')).toBeNull();
  });

  it('keeps the cross-tab active conversation when data-user-id matches', () => {
    window.localStorage.setItem('chatbot:active-user:v1', '7');
    window.localStorage.setItem('chatbot:active-conversation:v1', '"abc"');

    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    el.setAttribute('data-user-id', '7');
    document.body.appendChild(el);

    expect(window.localStorage.getItem('chatbot:active-conversation:v1')).toBe('"abc"');
    expect(el.getAttribute('data-conversation-id')).toBe('abc');
  });

  it('persists the current user id on first boot when none was set yet', () => {
    expect(window.localStorage.getItem('chatbot:active-user:v1')).toBeNull();

    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    el.setAttribute('data-user-id', '42');
    document.body.appendChild(el);

    expect(window.localStorage.getItem('chatbot:active-user:v1')).toBe('42');
  });

  it('purges the active conversation when data-user-id changes at runtime', () => {
    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    el.setAttribute('data-user-id', '1');
    document.body.appendChild(el);
    el.setAttribute('data-conversation-id', 'conv-1');
    expect(window.localStorage.getItem('chatbot:active-conversation:v1')).toBe('"conv-1"');

    // Host swaps the user attribute (SPA login flow).
    el.setAttribute('data-user-id', '2');

    expect(window.localStorage.getItem('chatbot:active-conversation:v1')).toBeNull();
    expect(window.localStorage.getItem('chatbot:active-user:v1')).toBe('2');
  });

  it('leaves storage untouched when data-user-id is missing (guest visit)', () => {
    window.localStorage.setItem('chatbot:active-user:v1', '7');
    window.localStorage.setItem('chatbot:active-conversation:v1', '"abc"');

    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    // No data-user-id attribute.
    document.body.appendChild(el);

    expect(window.localStorage.getItem('chatbot:active-conversation:v1')).toBe('"abc"');
    expect(window.localStorage.getItem('chatbot:active-user:v1')).toBe('7');
  });

  it('purges the per-tab sessionStorage conversationId when the user changes (#24)', () => {
    // User "3" left a conv id in BOTH localStorage (cross-tab) AND
    // sessionStorage (per-tab state). On a same-tab logout/login as user
    // "2", clearing only the cross-tab key would leave sessionStorage
    // poisoned and rehydrate() would resurrect user 3's conv id.
    window.localStorage.setItem('chatbot:active-user:v1', '3');
    window.localStorage.setItem('chatbot:active-conversation:v1', '"30"');
    window.sessionStorage.setItem(
      'chatbot:state:v1',
      JSON.stringify({ conversationId: '30', isOpen: true, draft: 'borrador WIP' }),
    );

    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    el.setAttribute('data-user-id', '2');
    document.body.appendChild(el);

    expect(window.localStorage.getItem('chatbot:active-conversation:v1')).toBeNull();
    expect(window.localStorage.getItem('chatbot:active-user:v1')).toBe('2');

    const tabState = JSON.parse(window.sessionStorage.getItem('chatbot:state:v1') ?? '{}');
    expect(tabState.conversationId).toBeNull();
    // Per-tab UI state (draft/isOpen) must survive — gating only nukes
    // the conv id, not the user's in-flight draft.
    expect(tabState.isOpen).toBe(true);
    expect(tabState.draft).toBe('borrador WIP');

    expect(el.getAttribute('data-conversation-id')).toBeNull();
  });

  it('clears the inline data-conversation-id attribute when the user changes (#30)', () => {
    // User 5 left BOTH storages pointing at conv 77, and the server is now
    // rendering the widget with a stale `data-conversation-id` still in the
    // HTML (e.g. emitted by a misconfigured layout or surviving a same-tab
    // SPA navigation). 1.1.4's gating cleared storage but the in-memory
    // `this.conversationId`/attribute survived — a later persist() would
    // re-introduce the previous user's id.
    window.localStorage.setItem('chatbot:active-user:v1', '5');
    window.localStorage.setItem('chatbot:active-conversation:v1', '"77"');
    window.sessionStorage.setItem(
      'chatbot:state:v1',
      JSON.stringify({ conversationId: '77', isOpen: false, draft: '' }),
    );

    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    el.setAttribute('data-conversation-id', '77');
    el.setAttribute('data-user-id', '8');
    document.body.appendChild(el);

    // Gating must (a) clear the inline attribute, (b) null the internal
    // conversationId, (c) leave storage empty.
    expect(el.getAttribute('data-conversation-id')).toBeNull();
    expect(window.localStorage.getItem('chatbot:active-conversation:v1')).toBeNull();
    const tabState = JSON.parse(window.sessionStorage.getItem('chatbot:state:v1') ?? '{}');
    expect(tabState.conversationId ?? null).toBeNull();
    expect(window.localStorage.getItem('chatbot:active-user:v1')).toBe('8');
  });

  it('re-runs gating on every connect, not only the first bootstrap (#30)', () => {
    // Boot once as user 4 — establishes the active-user key.
    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    el.setAttribute('data-user-id', '4');
    document.body.appendChild(el);
    el.setAttribute('data-conversation-id', 'conv-x');
    expect(window.localStorage.getItem('chatbot:active-conversation:v1')).toBe('"conv-x"');

    // Disconnect (SPA-style DOM move) without recreating the element.
    document.body.removeChild(el);

    // While disconnected, simulate a backend logout/login that swaps the
    // user attribute. (Custom-element attributeChangedCallback still fires.)
    el.setAttribute('data-user-id', '9');

    // Reconnect — bootstrap() is skipped because bootstrapped=true. With the
    // 1.1.4.1 fix, connectedCallback re-runs gating before rehydrate(), so
    // user 4's conv-x must be purged.
    document.body.appendChild(el);

    expect(window.localStorage.getItem('chatbot:active-conversation:v1')).toBeNull();
    expect(window.localStorage.getItem('chatbot:active-user:v1')).toBe('9');
    expect(el.getAttribute('data-conversation-id')).toBeNull();
  });
});

describe('ChatbotWidgetElement i18n bridge (E9)', () => {
  it('applies title and open_full_page from data-i18n to the header link', () => {
    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    el.setAttribute('data-i18n', JSON.stringify({
      title: 'Asistente',
      open_full_page: 'Open full page',
    }));
    document.body.appendChild(el);
    const link = el.shadowRoot!.querySelector<HTMLAnchorElement>('.cb-header-title-link');
    expect(link).not.toBeNull();
    expect(link!.textContent).toBe('Asistente');
    expect(link!.title).toBe('Open full page');
    expect(link!.getAttribute('aria-label')).toBe('Open full page');
  });

  it('applies new_conversation to the ✎ header button', () => {
    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    el.setAttribute('data-i18n', JSON.stringify({ new_conversation: 'New conversation' }));
    document.body.appendChild(el);
    const btn = el.shadowRoot!.querySelector<HTMLButtonElement>('.cb-header-new');
    expect(btn).not.toBeNull();
    expect(btn!.title).toBe('New conversation');
    expect(btn!.getAttribute('aria-label')).toBe('New conversation');
  });

  it('falls back to inline English defaults when data-i18n is absent', () => {
    const el = makeWidget();
    const link = el.shadowRoot!.querySelector<HTMLAnchorElement>('.cb-header-title-link');
    expect(link!.textContent).toBe('Chatbot');
    expect(link!.title).toBe('Open full chat page');
  });

  it('falls back to inline defaults when data-i18n is malformed', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    el.setAttribute('data-i18n', '{broken json');
    document.body.appendChild(el);
    const link = el.shadowRoot!.querySelector<HTMLAnchorElement>('.cb-header-title-link');
    expect(link!.textContent).toBe('Chatbot');
    expect(warn).toHaveBeenCalled();
    warn.mockRestore();
  });

  it('routes dashboard.kpi.no_value into setKpiLabels at bootstrap', async () => {
    const { resetKpiLabels, renderKpiBlock } = await import('../../resources/js/kpi.js');
    resetKpiLabels();
    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    el.setAttribute('data-i18n', JSON.stringify({
      dashboard: { kpi: { no_value: 'sin datos' } },
    }));
    document.body.appendChild(el);
    // The widget called setKpiLabels at bootstrap; the renderer now uses it.
    const node = renderKpiBlock({ label: 'Latency' }, { send: () => undefined });
    expect(node.querySelector('.cb-kpi-value')?.textContent).toBe('sin datos');
    resetKpiLabels();
  });
});

describe('ChatbotWidgetElement MPA conversations endpoint fallback (v2.2.1 PR-A)', () => {
  it('derives conversations endpoint from data-endpoint when data-conversations-endpoint is omitted, so MPA rehydrate fetches history', async () => {
    // Simulate a fresh MPA page navigation: localStorage has a conversation
    // from a prior page, but the host snippet declares only `data-endpoint`
    // (the canonical getting-started.md snippet). Without the v2.2.1 fix,
    // `fetchAndRenderConversation` early-returned silently because the
    // explicit attribute was missing — the widget remounted with an empty
    // body and the user lost their history.
    window.localStorage.setItem(
      'chatbot:active-conversation:v1',
      JSON.stringify('conv-mpa-42'),
    );
    const fetchMock = vi.fn(async () => new Response(
      JSON.stringify({ data: {}, messages: { data: [] } }),
      { status: 200 },
    ));
    Object.defineProperty(window, 'fetch', { configurable: true, value: fetchMock });

    // Only `data-endpoint` declared — same as the docs canonical snippet.
    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    document.body.appendChild(el);

    // The fire-and-forget fetch runs as soon as the widget connects; flush
    // microtasks so the call lands in the spy.
    await Promise.resolve();
    await Promise.resolve();

    const urls = (fetchMock.mock.calls as unknown as Array<[string, unknown]>).map(([u]) => u);
    expect(urls.some((u) => u === '/chatbot/conversations/conv-mpa-42')).toBe(true);
    // And the conversationId still rehydrated to the dataset attribute.
    expect(el.getAttribute('data-conversation-id')).toBe('conv-mpa-42');
  });
});

describe('ChatbotWidgetElement theme resolution (v2.2.2 PR-C)', () => {
  // jsdom 24 does not implement matchMedia. Provide a thin mock whose
  // `matches` value follows `__prefersDark__` so tests can flip the OS
  // preference, and whose `change` listener can be triggered manually.
  type MockMql = MediaQueryList & {
    __listeners: Array<(e: { matches: boolean }) => void>;
    __fire(matches: boolean): void;
  };
  type MqlWindow = Window & {
    __prefersDark__?: boolean;
    __mqls__?: MockMql[];
  };

  function installMatchMediaMock(): void {
    const w = window as MqlWindow;
    w.__prefersDark__ = false;
    w.__mqls__ = [];
    const matchMedia = (query: string): MockMql => {
      const mql = {
        media: query,
        get matches(): boolean {
          return query.includes('dark') ? Boolean(w.__prefersDark__) : false;
        },
        onchange: null,
        __listeners: [] as Array<(e: { matches: boolean }) => void>,
        addEventListener(_type: string, fn: (e: { matches: boolean }) => void): void {
          mql.__listeners.push(fn);
        },
        removeEventListener(_type: string, fn: (e: { matches: boolean }) => void): void {
          mql.__listeners = mql.__listeners.filter((l) => l !== fn);
        },
        addListener(): void { /* legacy noop */ },
        removeListener(): void { /* legacy noop */ },
        dispatchEvent(): boolean { return true; },
        __fire(matches: boolean): void {
          mql.__listeners.forEach((l) => l({ matches }));
        },
      } as unknown as MockMql;
      w.__mqls__!.push(mql);
      return mql;
    };
    Object.defineProperty(window, 'matchMedia', {
      configurable: true, writable: true, value: matchMedia,
    });
  }

  beforeEach(() => {
    installMatchMediaMock();
    document.documentElement.removeAttribute('data-bs-theme');
    document.documentElement.removeAttribute('data-theme');
  });

  afterEach(() => {
    document.documentElement.removeAttribute('data-bs-theme');
    document.documentElement.removeAttribute('data-theme');
  });

  it('data-theme="light" projects data-theme-effective="light" ignoring OS preference', () => {
    (window as MqlWindow).__prefersDark__ = true;
    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    el.setAttribute('data-theme', 'light');
    document.body.appendChild(el);
    expect(el.getAttribute('data-theme-effective')).toBe('light');
  });

  it('data-theme="dark" projects data-theme-effective="dark" even with OS in light', () => {
    (window as MqlWindow).__prefersDark__ = false;
    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    el.setAttribute('data-theme', 'dark');
    document.body.appendChild(el);
    expect(el.getAttribute('data-theme-effective')).toBe('dark');
  });

  it('data-theme="auto" follows <html data-bs-theme> when the host declares it', () => {
    // OS says dark, but the host toggle says light — the host wins in auto.
    (window as MqlWindow).__prefersDark__ = true;
    document.documentElement.setAttribute('data-bs-theme', 'light');
    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    el.setAttribute('data-theme', 'auto');
    document.body.appendChild(el);
    expect(el.getAttribute('data-theme-effective')).toBe('light');
  });

  it('data-theme absent defaults to auto: falls back to prefers-color-scheme when no host signal', () => {
    (window as MqlWindow).__prefersDark__ = true;
    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    document.body.appendChild(el);
    expect(el.getAttribute('data-theme-effective')).toBe('dark');
  });

  it('auto mode reacts in runtime to <html data-bs-theme> mutations (host toggle)', async () => {
    document.documentElement.setAttribute('data-bs-theme', 'light');
    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    el.setAttribute('data-theme', 'auto');
    document.body.appendChild(el);
    expect(el.getAttribute('data-theme-effective')).toBe('light');

    document.documentElement.setAttribute('data-bs-theme', 'dark');
    // MutationObserver delivers on the microtask queue; flush a couple ticks.
    await Promise.resolve();
    await Promise.resolve();
    expect(el.getAttribute('data-theme-effective')).toBe('dark');
  });

  it('auto mode reacts to prefers-color-scheme changes when host has no data-bs-theme', () => {
    (window as MqlWindow).__prefersDark__ = false;
    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    el.setAttribute('data-theme', 'auto');
    document.body.appendChild(el);
    expect(el.getAttribute('data-theme-effective')).toBe('light');

    // OS switches to dark — the MQL listener re-applies. Fire on every MQL
    // (the widget creates a couple — one for the synchronous read in
    // applyTheme() and one for the persistent observer); only the latter
    // has a listener.
    (window as MqlWindow).__prefersDark__ = true;
    (window as MqlWindow).__mqls__!.forEach((m) => m.__fire(true));
    expect(el.getAttribute('data-theme-effective')).toBe('dark');
  });

  it('switching data-theme from light to auto re-derives + wires the observer', async () => {
    document.documentElement.setAttribute('data-bs-theme', 'dark');
    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    el.setAttribute('data-theme', 'light');
    document.body.appendChild(el);
    expect(el.getAttribute('data-theme-effective')).toBe('light');

    el.setAttribute('data-theme', 'auto');
    expect(el.getAttribute('data-theme-effective')).toBe('dark');

    // And the observer is live: another host toggle propagates.
    document.documentElement.setAttribute('data-bs-theme', 'light');
    await Promise.resolve();
    await Promise.resolve();
    expect(el.getAttribute('data-theme-effective')).toBe('light');
  });

  it('explicit data-theme does NOT subscribe to host toggles', async () => {
    document.documentElement.setAttribute('data-bs-theme', 'light');
    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    el.setAttribute('data-theme', 'dark');
    document.body.appendChild(el);
    expect(el.getAttribute('data-theme-effective')).toBe('dark');

    document.documentElement.setAttribute('data-bs-theme', 'dark');
    await Promise.resolve();
    await Promise.resolve();
    // Still dark — but also would have stayed dark on a light flip. The
    // contract for explicit is "ignore the host signal entirely".
    document.documentElement.setAttribute('data-bs-theme', 'light');
    await Promise.resolve();
    await Promise.resolve();
    expect(el.getAttribute('data-theme-effective')).toBe('dark');
  });

  it('teardown removes the matchMedia listener on disconnect', () => {
    const el = document.createElement('chatbot-widget') as ChatbotWidgetElement;
    el.setAttribute('data-endpoint', '/chatbot/stream');
    el.setAttribute('data-theme', 'auto');
    document.body.appendChild(el);
    // The widget calls matchMedia twice: a one-shot read inside
    // applyTheme() (no listener attached) and a persistent subscription
    // inside setupThemeObserver(). Sum across MQLs so the assertion does
    // not depend on internal call order.
    const totalListeners = (): number =>
      (window as MqlWindow).__mqls__!.reduce((n, m) => n + m.__listeners.length, 0);
    expect(totalListeners()).toBe(1);
    el.remove();
    expect(totalListeners()).toBe(0);
  });
});
