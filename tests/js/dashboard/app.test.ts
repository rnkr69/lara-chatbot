import { describe, expect, it, beforeEach, afterEach, vi } from 'vitest';
import { startDashboardApp } from '../../../resources/js/dashboard/app.js';
import type { DashboardDetail, DashboardRow, DashboardWidget } from '../../../resources/js/dashboard/types.js';
import type { GridStackNode } from 'gridstack';

/**
 * Tiny GridStack stub that exposes the same surface mountGrid relies on
 * (on/off/addWidget/removeWidget/destroy) so the app boots without the real
 * library. Exposed `trigger` lets a test simulate a layout change.
 */
function fakeGridStackFactory(): {
  factory: NonNullable<Parameters<typeof startDashboardApp>[0]['gridFactory']>;
  triggerChange(items: GridStackNode[]): void;
  removeWidgetSpy: ReturnType<typeof vi.fn>;
  addWidgetSpy: ReturnType<typeof vi.fn>;
} {
  let changeHandler: ((event: unknown, items: GridStackNode[]) => void) | null = null;
  const addWidgetSpy = vi.fn();
  const removeWidgetSpy = vi.fn();
  const factory: NonNullable<Parameters<typeof startDashboardApp>[0]['gridFactory']> = () => ({
    on: ((event: string, handler: (event: unknown, items: GridStackNode[]) => void) => {
      if (event === 'change') changeHandler = handler;
    }) as never,
    off: vi.fn() as never,
    addWidget: addWidgetSpy as never,
    removeWidget: removeWidgetSpy as never,
    destroy: vi.fn() as never,
  });
  return {
    factory,
    triggerChange(items) { changeHandler?.(null, items); },
    removeWidgetSpy,
    addWidgetSpy,
  };
}

interface FetchRecord {
  url: string;
  method: string;
  body: string | null;
}

function buildFetcher(handlers: Array<(call: FetchRecord) => { status?: number; body?: unknown; stream?: string[] } | null>): {
  fetcher: typeof fetch;
  calls: FetchRecord[];
} {
  const calls: FetchRecord[] = [];
  const fetcher = (async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = typeof input === 'string' ? input : (input as URL).toString();
    const method = init?.method ?? 'GET';
    const body = typeof init?.body === 'string' ? init.body : null;
    const record: FetchRecord = { url, method, body };
    calls.push(record);
    for (const h of handlers) {
      const result = h(record);
      if (result !== null) {
        if (result.stream) {
          const encoder = new TextEncoder();
          const stream = new ReadableStream<Uint8Array>({
            start(controller) {
              for (const chunk of result.stream!) controller.enqueue(encoder.encode(chunk));
              controller.close();
            },
          });
          return new Response(stream, { status: result.status ?? 200 });
        }
        const status = result.status ?? 200;
        // 204/205/304 are null-body statuses — the Response constructor throws
        // if handed even an empty-string body for them.
        const nullBody = status === 204 || status === 205 || status === 304;
        const responseBody = nullBody
          ? null
          : (result.body === undefined ? '' : JSON.stringify(result.body));
        return new Response(responseBody, { status });
      }
    }
    return new Response('not handled', { status: 500 });
  }) as typeof fetch;
  return { fetcher, calls };
}

function makeWidget(overrides: Partial<DashboardWidget> = {}): DashboardWidget {
  return {
    id: 1,
    block_type: 'table',
    title: 'W1',
    position: { x: 0, y: 0, w: 4, h: 2 },
    snapshot: { data: { rows: [] }, captured_at: null },
    source: { tool: 'list_x', args: {} },
    source_signature: 'abc',
    refresh_policy: 'on_open',
    last_refresh_status: 'fresh',
    last_refresh_error: null,
    last_refreshed_at: '2026-05-13T10:00:00.000Z',
    order_index: 0,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

async function flush(): Promise<void> {
  // Bumped from 5 to 20 hops because Node 20's `Response.json()` requires
  // more microtask cycles than Node 22/24 to drain the body-stream chain;
  // 5 was enough locally on newer Node but failed in CI on Node 20. Can't
  // use `setImmediate`-based flushing because some sibling tests in this
  // file enable `vi.useFakeTimers()`.
  for (let i = 0; i < 20; i++) await Promise.resolve();
}

beforeEach(() => {
  document.body.innerHTML = '';
  window.localStorage.clear();
});

afterEach(() => {
  vi.useRealTimers();
});

describe('DashboardApp bootstrap', () => {
  it('loads list + active dashboard from data-default-slug and mounts widgets', async () => {
    const row: DashboardRow = {
      id: 1, slug: 'mi-panel', name: 'Mi Panel', is_default: true,
      layout_version: 1, metadata: null, widget_count: 2,
      created_at: null, updated_at: null,
    };
    const detail: DashboardDetail = { ...row, widgets: [makeWidget({ id: 11 }), makeWidget({ id: 12 })] };
    const { fetcher, calls } = buildFetcher([
      (c) => c.url === '/chatbot/dashboards' && c.method === 'GET' ? { body: { data: [row] } } : null,
      (c) => c.url === '/chatbot/dashboards/mi-panel' && c.method === 'GET' ? { body: { data: detail } } : null,
      (c) => c.url === '/chatbot/dashboards/mi-panel/refresh' && c.method === 'POST'
        ? { stream: [
            'event: widget_refreshed\ndata: {"widget_id":11,"status":"fresh","snapshot":{"data":{"rows":[{"id":1}]},"captured_at":null},"error":null,"last_refreshed_at":"2026-05-13T10:01:00.000Z"}\n\n',
            'event: widget_refreshed\ndata: {"widget_id":12,"status":"fresh","snapshot":null,"error":null,"last_refreshed_at":"2026-05-13T10:01:00.000Z"}\n\n',
            'event: done\ndata: {"widget_count":2}\n\n',
          ] }
        : null,
    ]);

    const root = document.createElement('div');
    root.id = 'chatbot-dashboard-root';
    root.dataset['dashboardsEndpoint'] = '/chatbot/dashboards';
    root.dataset['defaultSlug'] = 'mi-panel';
    root.dataset['theme'] = 'light';
    document.body.appendChild(root);

    const grid = fakeGridStackFactory();
    startDashboardApp({ root, fetcher, gridFactory: grid.factory });
    await flush();
    await flush();

    // Theme applied.
    expect(root.classList.contains('cb-theme-light')).toBe(true);
    // Title rendered.
    expect(root.querySelector('.cb-dashboard-title')?.textContent).toBe('Mi Panel');
    // Both widgets added to grid.
    expect(grid.addWidgetSpy).toHaveBeenCalledTimes(2);
    // Bulk SSE was fired (on_open policy).
    expect(calls.some((c) => c.url.endsWith('/refresh') && c.method === 'POST')).toBe(true);
  });

  it('renders the empty main pane when the list is empty', async () => {
    const { fetcher } = buildFetcher([
      (c) => c.url === '/chatbot/dashboards' && c.method === 'GET' ? { body: { data: [] } } : null,
    ]);
    const root = document.createElement('div');
    root.id = 'chatbot-dashboard-root';
    root.dataset['dashboardsEndpoint'] = '/chatbot/dashboards';
    document.body.appendChild(root);

    const grid = fakeGridStackFactory();
    startDashboardApp({ root, fetcher, gridFactory: grid.factory, layoutDebounceMs: 1 });
    // Give the queueMicrotask + setTimeout(50) fallback time to fire and recognise empty.
    await new Promise((r) => setTimeout(r, 80));
    expect(root.querySelector<HTMLElement>('.cb-dashboard-main-empty')?.hidden).toBe(false);
  });

  it('layout changes debounce into a PATCH per widget', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const row: DashboardRow = {
      id: 1, slug: 'a', name: 'A', is_default: true,
      layout_version: 1, metadata: null, widget_count: 1,
      created_at: null, updated_at: null,
    };
    const detail: DashboardDetail = {
      ...row,
      widgets: [makeWidget({ id: 11, refresh_policy: 'never' })],
    };
    const { fetcher, calls } = buildFetcher([
      (c) => c.url === '/chatbot/dashboards' && c.method === 'GET' ? { body: { data: [row] } } : null,
      (c) => c.url === '/chatbot/dashboards/a' && c.method === 'GET' ? { body: { data: detail } } : null,
      (c) => c.url === '/chatbot/dashboards/a/widgets/11' && c.method === 'PATCH' ? { body: {} } : null,
    ]);

    const root = document.createElement('div');
    root.id = 'chatbot-dashboard-root';
    root.dataset['dashboardsEndpoint'] = '/chatbot/dashboards';
    root.dataset['defaultSlug'] = 'a';
    document.body.appendChild(root);

    const grid = fakeGridStackFactory();
    startDashboardApp({ root, fetcher, gridFactory: grid.factory, layoutDebounceMs: 100 });
    await flush();
    await flush();

    // Simulate gridstack emitting two consecutive changes for widget 11.
    const el = document.createElement('div');
    el.dataset['widgetId'] = '11';
    grid.triggerChange([{ x: 0, y: 0, w: 1, h: 1, el } as GridStackNode]);
    grid.triggerChange([{ x: 2, y: 3, w: 4, h: 2, el } as GridStackNode]);

    // Debounce fires once.
    await vi.advanceTimersByTimeAsync(150);
    await flush();

    const patches = calls.filter((c) => c.method === 'PATCH');
    expect(patches).toHaveLength(1);
    expect(JSON.parse(patches[0]!.body!)).toEqual({ position: { x: 2, y: 3, w: 4, h: 2 } });
  });
});

describe('DashboardApp i18n (E9)', () => {
  it('applies dashboard.header labels to the refresh-all button and empty pane', async () => {
    const { fetcher } = buildFetcher([
      (c) => c.url === '/chatbot/dashboards' && c.method === 'GET' ? { body: { data: [] } } : null,
    ]);
    const root = document.createElement('div');
    root.id = 'chatbot-dashboard-root';
    root.dataset['dashboardsEndpoint'] = '/chatbot/dashboards';
    document.body.appendChild(root);

    const grid = fakeGridStackFactory();
    startDashboardApp({
      root, fetcher, gridFactory: grid.factory, layoutDebounceMs: 1,
      i18n: {
        header: {
          refresh_all: 'Refrescar todo',
          empty_main: 'Sin dashboard seleccionado',
          empty_main_hint: 'Crea uno desde la barra lateral.',
        },
      },
    });
    await new Promise((r) => setTimeout(r, 80));

    const refreshBtn = root.querySelector<HTMLButtonElement>('.cb-dashboard-refresh-all');
    expect(refreshBtn?.title).toBe('Refrescar todo');
    expect(refreshBtn?.getAttribute('aria-label')).toBe('Refrescar todo');

    const empty = root.querySelector<HTMLElement>('.cb-dashboard-main-empty');
    expect(empty?.querySelector('h2')?.textContent).toBe('Sin dashboard seleccionado');
    expect(empty?.querySelector('p')?.textContent).toBe('Crea uno desde la barra lateral.');
  });

  it('falls back to English defaults when i18n option is omitted', async () => {
    const { fetcher } = buildFetcher([
      (c) => c.url === '/chatbot/dashboards' && c.method === 'GET' ? { body: { data: [] } } : null,
    ]);
    const root = document.createElement('div');
    root.id = 'chatbot-dashboard-root';
    root.dataset['dashboardsEndpoint'] = '/chatbot/dashboards';
    document.body.appendChild(root);

    const grid = fakeGridStackFactory();
    startDashboardApp({ root, fetcher, gridFactory: grid.factory, layoutDebounceMs: 1 });
    await new Promise((r) => setTimeout(r, 80));

    const refreshBtn = root.querySelector<HTMLButtonElement>('.cb-dashboard-refresh-all');
    expect(refreshBtn?.title).toBe('Refresh all');

    const empty = root.querySelector<HTMLElement>('.cb-dashboard-main-empty');
    expect(empty?.querySelector('h2')?.textContent).toBe('No dashboard selected');
  });

  it('maps snake_case sidebar labels to the mounter (camelCase interface)', async () => {
    const row: DashboardRow = {
      id: 1, slug: 'a', name: 'A', is_default: true,
      layout_version: 1, metadata: null, widget_count: 0,
      created_at: null, updated_at: null,
    };
    const detail: DashboardDetail = { ...row, widgets: [] };
    const { fetcher } = buildFetcher([
      (c) => c.url === '/chatbot/dashboards' && c.method === 'GET' ? { body: { data: [row] } } : null,
      (c) => c.url === '/chatbot/dashboards/a' && c.method === 'GET' ? { body: { data: detail } } : null,
    ]);

    const root = document.createElement('div');
    root.id = 'chatbot-dashboard-root';
    root.dataset['dashboardsEndpoint'] = '/chatbot/dashboards';
    root.dataset['defaultSlug'] = 'a';
    document.body.appendChild(root);

    const grid = fakeGridStackFactory();
    startDashboardApp({
      root, fetcher, gridFactory: grid.factory, layoutDebounceMs: 1,
      i18n: {
        sidebar: {
          new_placeholder: 'Nombre…',
          create: 'Crear',
          default_badge: 'por defecto',
        },
      },
    });
    await flush();
    await flush();

    const newInput = root.querySelector<HTMLInputElement>('.cb-dashboard-sidebar-new-input');
    expect(newInput?.placeholder).toBe('Nombre…');
    const createBtn = root.querySelector<HTMLButtonElement>('.cb-dashboard-sidebar-new-btn');
    expect(createBtn?.textContent).toBe('Crear');
    // Default badge appears only on default rows once the list renders.
    await new Promise((r) => setTimeout(r, 50));
    const badge = root.querySelector<HTMLElement>('.cb-dashboard-sidebar-item-default');
    expect(badge?.textContent).toBe('por defecto');
  });
});

describe('DashboardApp dashboard resolution (#13/#14)', () => {
  function mkRow(over: Partial<DashboardRow> & { slug: string; name: string }): DashboardRow {
    return {
      id: 1, is_default: false, layout_version: 1, metadata: null,
      widget_count: 0, created_at: null, updated_at: null, ...over,
    };
  }

  it('auto-selects the user default dashboard when there is no stored/attr slug (#14)', async () => {
    const analytics = mkRow({ id: 1, slug: 'analytics', name: 'Analytics', is_default: false });
    const ops = mkRow({ id: 2, slug: 'ops', name: 'Ops', is_default: true });
    const detail: DashboardDetail = { ...ops, widgets: [] };
    const { fetcher, calls } = buildFetcher([
      (c) => c.url === '/chatbot/dashboards' && c.method === 'GET' ? { body: { data: [analytics, ops] } } : null,
      (c) => c.url === '/chatbot/dashboards/ops' && c.method === 'GET' ? { body: { data: detail } } : null,
    ]);

    const root = document.createElement('div');
    root.id = 'chatbot-dashboard-root';
    root.dataset['dashboardsEndpoint'] = '/chatbot/dashboards';
    // No data-default-slug, no localStorage — the app must resolve one itself.
    document.body.appendChild(root);

    const grid = fakeGridStackFactory();
    startDashboardApp({ root, fetcher, gridFactory: grid.factory, layoutDebounceMs: 1 });
    await flush();
    await flush();
    await flush();

    // The is_default row ('ops') was auto-selected — not the first row.
    expect(calls.some((c) => c.url === '/chatbot/dashboards/ops' && c.method === 'GET')).toBe(true);
    expect(root.querySelector('.cb-dashboard-title')?.textContent).toBe('Ops');
  });

  it('falls back to the default dashboard when a stale localStorage slug 404s (#13)', async () => {
    window.localStorage.setItem('chatbot:active-dashboard:v1', 'deleted-panel');
    const ops = mkRow({ id: 1, slug: 'ops', name: 'Ops', is_default: true });
    const detail: DashboardDetail = { ...ops, widgets: [] };
    const { fetcher } = buildFetcher([
      (c) => c.url === '/chatbot/dashboards' && c.method === 'GET' ? { body: { data: [ops] } } : null,
      (c) => c.url === '/chatbot/dashboards/deleted-panel' && c.method === 'GET' ? { status: 404, body: { message: 'Not found' } } : null,
      (c) => c.url === '/chatbot/dashboards/ops' && c.method === 'GET' ? { body: { data: detail } } : null,
    ]);

    const root = document.createElement('div');
    root.id = 'chatbot-dashboard-root';
    root.dataset['dashboardsEndpoint'] = '/chatbot/dashboards';
    document.body.appendChild(root);

    const grid = fakeGridStackFactory();
    startDashboardApp({ root, fetcher, gridFactory: grid.factory, layoutDebounceMs: 1 });
    await flush();
    await flush();
    await flush();

    // Stale key replaced with the fallback, default loaded, NO raw HTTP error
    // in <main>. (persistence.ts JSON-encodes the value, so assert by content.)
    const storedSlug = window.localStorage.getItem('chatbot:active-dashboard:v1') ?? '';
    expect(storedSlug).toContain('ops');
    expect(storedSlug).not.toContain('deleted-panel');
    expect(root.querySelector('.cb-dashboard-title')?.textContent).toBe('Ops');
    expect(root.querySelector('.cb-dashboard-fatal')).toBeNull();
  });

  it('keeps the header <h1> in sync when the active dashboard is renamed (#9)', async () => {
    const ops = mkRow({ id: 1, slug: 'ops', name: 'Ops', is_default: true });
    const detail: DashboardDetail = { ...ops, widgets: [] };
    const renamed: DashboardRow = { ...ops, slug: 'ops-qa', name: 'Ops QA' };
    const { fetcher } = buildFetcher([
      (c) => c.url === '/chatbot/dashboards' && c.method === 'GET' ? { body: { data: [ops] } } : null,
      (c) => c.url === '/chatbot/dashboards/ops' && c.method === 'GET' ? { body: { data: detail } } : null,
      (c) => c.url === '/chatbot/dashboards/ops' && c.method === 'PATCH' ? { body: { data: renamed } } : null,
    ]);

    const root = document.createElement('div');
    root.id = 'chatbot-dashboard-root';
    root.dataset['dashboardsEndpoint'] = '/chatbot/dashboards';
    root.dataset['defaultSlug'] = 'ops';
    document.body.appendChild(root);

    const grid = fakeGridStackFactory();
    startDashboardApp({ root, fetcher, gridFactory: grid.factory, layoutDebounceMs: 1 });
    await flush();
    await flush();
    // Let the sidebar finish its own list load + render its rows.
    await new Promise((r) => setTimeout(r, 50));

    expect(root.querySelector('.cb-dashboard-title')?.textContent).toBe('Ops');

    // Drive the sidebar's inline rename: click ✎, type a new name, commit.
    root.querySelector<HTMLButtonElement>('.cb-dashboard-sidebar-item-rename')!.click();
    const input = root.querySelector<HTMLInputElement>('.cb-dashboard-sidebar-rename-input')!;
    input.value = 'Ops QA';
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    await flush();
    await flush();

    // #9 — the header <h1> reflects the rename (it used to stay "Ops").
    expect(root.querySelector('.cb-dashboard-title')?.textContent).toBe('Ops QA');
  });

  it('auto-selects a sibling dashboard after the active one is deleted (#22)', async () => {
    const ops = mkRow({ id: 1, slug: 'ops', name: 'Ops', is_default: true });
    const analytics = mkRow({ id: 2, slug: 'analytics', name: 'Analytics', is_default: false });
    const opsDetail: DashboardDetail = { ...ops, widgets: [] };
    const analyticsDetail: DashboardDetail = { ...analytics, widgets: [] };
    const { fetcher } = buildFetcher([
      (c) => c.url === '/chatbot/dashboards' && c.method === 'GET' ? { body: { data: [ops, analytics] } } : null,
      (c) => c.url === '/chatbot/dashboards/ops' && c.method === 'GET' ? { body: { data: opsDetail } } : null,
      (c) => c.url === '/chatbot/dashboards/ops' && c.method === 'DELETE' ? { status: 204 } : null,
      (c) => c.url === '/chatbot/dashboards/analytics' && c.method === 'GET' ? { body: { data: analyticsDetail } } : null,
    ]);

    const root = document.createElement('div');
    root.id = 'chatbot-dashboard-root';
    root.dataset['dashboardsEndpoint'] = '/chatbot/dashboards';
    root.dataset['defaultSlug'] = 'ops';
    document.body.appendChild(root);

    const grid = fakeGridStackFactory();
    startDashboardApp({
      root, fetcher, gridFactory: grid.factory, layoutDebounceMs: 1,
      confirmer: () => true,
    });
    await flush();
    await flush();
    await new Promise((r) => setTimeout(r, 50));

    expect(root.querySelector('.cb-dashboard-title')?.textContent).toBe('Ops');

    // Delete the active dashboard from the sidebar.
    const opsItem = Array.from(root.querySelectorAll<HTMLElement>('.cb-dashboard-sidebar-item'))
      .find((el) => el.dataset['slug'] === 'ops')!;
    opsItem.querySelector<HTMLButtonElement>('.cb-dashboard-sidebar-item-delete')!.click();
    await flush();
    await flush();
    await new Promise((r) => setTimeout(r, 50));

    // #22 — the sibling ('analytics') is auto-selected in memory instead of
    // leaving "No dashboard selected" up until an F5.
    expect(root.querySelector('.cb-dashboard-title')?.textContent).toBe('Analytics');
    expect(root.querySelector<HTMLElement>('.cb-dashboard-main-empty')?.hidden).toBe(true);
  });
});

describe('DashboardApp theme resolution (v2.2.2 PR-C)', () => {
  // Same matchMedia mock shape as the widget theme test — let tests flip the
  // OS preference, then trigger the registered listener manually.
  interface MockMql {
    media: string;
    matches: boolean;
    __listeners: Array<(e: { matches: boolean }) => void>;
    __fire(matches: boolean): void;
    addEventListener(type: string, fn: (e: { matches: boolean }) => void): void;
    removeEventListener(type: string, fn: (e: { matches: boolean }) => void): void;
    addListener(): void;
    removeListener(): void;
    dispatchEvent(): boolean;
    onchange: null;
  }
  type MqlWindow = Window & {
    __prefersDark__?: boolean;
    __mqls__?: MockMql[];
  };

  function installMatchMediaMock(): void {
    const w = window as MqlWindow;
    w.__prefersDark__ = false;
    w.__mqls__ = [];
    const matchMedia = (query: string): MockMql => {
      const mql: MockMql = {
        media: query,
        get matches(): boolean {
          return query.includes('dark') ? Boolean(w.__prefersDark__) : false;
        },
        onchange: null,
        __listeners: [],
        addEventListener(_type, fn): void { mql.__listeners.push(fn); },
        removeEventListener(_type, fn): void {
          mql.__listeners = mql.__listeners.filter((l) => l !== fn);
        },
        addListener(): void { /* legacy noop */ },
        removeListener(): void { /* legacy noop */ },
        dispatchEvent(): boolean { return true; },
        __fire(matches): void { mql.__listeners.forEach((l) => l({ matches })); },
      };
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

  function bootRootWithTheme(theme: string): { root: HTMLElement; handle: { destroy(): void } } {
    const { fetcher } = buildFetcher([
      (c) => c.url === '/chatbot/dashboards' && c.method === 'GET' ? { body: { data: [] } } : null,
    ]);
    const root = document.createElement('div');
    root.id = 'chatbot-dashboard-root';
    root.dataset['dashboardsEndpoint'] = '/chatbot/dashboards';
    root.dataset['theme'] = theme;
    document.body.appendChild(root);
    const grid = fakeGridStackFactory();
    const handle = startDashboardApp({ root, fetcher, gridFactory: grid.factory });
    return { root, handle };
  }

  it('data-theme="light" applies cb-theme-light regardless of OS preference', () => {
    (window as MqlWindow).__prefersDark__ = true;
    const { root } = bootRootWithTheme('light');
    expect(root.classList.contains('cb-theme-light')).toBe(true);
    expect(root.classList.contains('cb-theme-dark')).toBe(false);
  });

  it('data-theme="dark" applies cb-theme-dark regardless of OS preference', () => {
    (window as MqlWindow).__prefersDark__ = false;
    const { root } = bootRootWithTheme('dark');
    expect(root.classList.contains('cb-theme-dark')).toBe(true);
    expect(root.classList.contains('cb-theme-light')).toBe(false);
  });

  it('data-theme="auto" follows <html data-bs-theme> when the host declares it', () => {
    (window as MqlWindow).__prefersDark__ = true;
    document.documentElement.setAttribute('data-bs-theme', 'light');
    const { root } = bootRootWithTheme('auto');
    expect(root.classList.contains('cb-theme-light')).toBe(true);
  });

  it('data-theme="auto" falls back to prefers-color-scheme when host has no data-bs-theme', () => {
    (window as MqlWindow).__prefersDark__ = true;
    const { root } = bootRootWithTheme('auto');
    expect(root.classList.contains('cb-theme-dark')).toBe(true);
  });

  it('auto mode reacts to runtime <html data-bs-theme> mutations (host toggle)', async () => {
    document.documentElement.setAttribute('data-bs-theme', 'light');
    const { root } = bootRootWithTheme('auto');
    expect(root.classList.contains('cb-theme-light')).toBe(true);

    document.documentElement.setAttribute('data-bs-theme', 'dark');
    await Promise.resolve();
    await Promise.resolve();
    expect(root.classList.contains('cb-theme-dark')).toBe(true);
    expect(root.classList.contains('cb-theme-light')).toBe(false);
  });

  it('auto mode reacts to prefers-color-scheme changes when host has no data-bs-theme', () => {
    (window as MqlWindow).__prefersDark__ = false;
    const { root } = bootRootWithTheme('auto');
    expect(root.classList.contains('cb-theme-light')).toBe(true);

    (window as MqlWindow).__prefersDark__ = true;
    (window as MqlWindow).__mqls__!.forEach((m) => m.__fire(true));
    expect(root.classList.contains('cb-theme-dark')).toBe(true);
  });

  it('explicit data-theme does NOT subscribe to host signals (no observer)', async () => {
    document.documentElement.setAttribute('data-bs-theme', 'light');
    const { root } = bootRootWithTheme('dark');
    expect(root.classList.contains('cb-theme-dark')).toBe(true);

    document.documentElement.setAttribute('data-bs-theme', 'dark');
    await Promise.resolve();
    await Promise.resolve();
    document.documentElement.setAttribute('data-bs-theme', 'light');
    await Promise.resolve();
    await Promise.resolve();
    // Stayed locked to the explicit declared mode.
    expect(root.classList.contains('cb-theme-dark')).toBe(true);
  });

  it('destroy() tears down the matchMedia listener', () => {
    const { handle } = bootRootWithTheme('auto');
    const totalListeners = (): number =>
      (window as MqlWindow).__mqls__!.reduce((n, m) => n + m.__listeners.length, 0);
    expect(totalListeners()).toBeGreaterThanOrEqual(1);
    handle.destroy();
    expect(totalListeners()).toBe(0);
  });
});
