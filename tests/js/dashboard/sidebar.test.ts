import { describe, expect, it, beforeEach, vi } from 'vitest';
import { mountDashboardSidebar } from '../../../resources/js/dashboard/sidebar.js';
import { DashboardApi } from '../../../resources/js/dashboard/api.js';
import type { DashboardRow } from '../../../resources/js/dashboard/types.js';

function makeApi(rows: DashboardRow[]): {
  api: DashboardApi;
  state: { rows: DashboardRow[] };
  calls: Array<{ url: string; method: string; body: string | null }>;
} {
  const state = { rows };
  const calls: Array<{ url: string; method: string; body: string | null }> = [];
  const fetcher = (async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = typeof input === 'string' ? input : (input as URL).toString();
    const method = init?.method ?? 'GET';
    const body = typeof init?.body === 'string' ? init.body : null;
    calls.push({ url, method, body });
    if (method === 'GET' && url.endsWith('/chatbot/dashboards')) {
      return new Response(JSON.stringify({ data: state.rows }), { status: 200 });
    }
    if (method === 'POST' && url.endsWith('/chatbot/dashboards')) {
      const payload = JSON.parse(body ?? '{}');
      const row: DashboardRow = {
        id: 99,
        slug: payload.name.toLowerCase().replace(/\s+/g, '-'),
        name: payload.name,
        is_default: false,
        layout_version: 1,
        metadata: null,
        widget_count: 0,
        created_at: null,
        updated_at: null,
      };
      state.rows.push(row);
      return new Response(JSON.stringify({ data: row }), { status: 201 });
    }
    if (method === 'PATCH') {
      const slug = url.split('/').pop()!;
      const payload = JSON.parse(body ?? '{}');
      state.rows = state.rows.map((r) => {
        if (r.slug !== slug) {
          // Other rows lose default when this one becomes default.
          if (payload.is_default === true) return { ...r, is_default: false };
          return r;
        }
        return { ...r, ...payload, slug: payload.name ? payload.name.toLowerCase().replace(/\s+/g, '-') : r.slug };
      });
      const updated = state.rows.find((r) => r.slug === slug || r.name === payload.name)!;
      return new Response(JSON.stringify({ data: updated }), { status: 200 });
    }
    if (method === 'DELETE') {
      const slug = url.split('/').pop()!;
      state.rows = state.rows.filter((r) => r.slug !== slug);
      return new Response(null, { status: 204 });
    }
    return new Response(null, { status: 404 });
  }) as typeof fetch;
  const api = new DashboardApi({ endpoint: '/chatbot/dashboards', fetcher });
  return { api, state, calls };
}

async function flush(): Promise<void> {
  // setTimeout(0) yields to the macrotask queue which fully drains the
  // microtask queue first — more reliable than chaining await Promise.resolve()
  // for async fetch → Response.json() chains.
  await new Promise<void>((r) => setTimeout(r, 0));
}

beforeEach(() => {
  document.body.innerHTML = '';
  window.localStorage.clear();
});

describe('mountDashboardSidebar — list + CRUD', () => {
  it('lists rows on initial load and marks the active one', async () => {
    const { api } = makeApi([
      { id: 1, slug: 'a', name: 'A', is_default: true, layout_version: 1, metadata: null, widget_count: 0, created_at: null, updated_at: null },
      { id: 2, slug: 'b', name: 'B', is_default: false, layout_version: 1, metadata: null, widget_count: 3, created_at: null, updated_at: null },
    ]);
    const host = document.createElement('div');
    document.body.appendChild(host);
    mountDashboardSidebar(host, { api, activeSlug: 'b', onSelect: () => undefined });
    await flush();
    const items = host.querySelectorAll('.cb-dashboard-sidebar-item');
    expect(items.length).toBe(2);
    expect(items[1]?.classList.contains('cb-dashboard-sidebar-item-active')).toBe(true);
    const counts = Array.from(host.querySelectorAll('.cb-dashboard-sidebar-item-count')).map((el) => el.textContent);
    expect(counts).toEqual(['0', '3']);
  });

  it('renders the empty CTA when no rows are returned', async () => {
    const { api } = makeApi([]);
    const host = document.createElement('div');
    mountDashboardSidebar(host, { api, onSelect: () => undefined });
    await flush();
    const empty = host.querySelector<HTMLElement>('.cb-dashboard-sidebar-empty');
    expect(empty?.hidden).toBe(false);
    // The create form is always visible (doubles as empty-state CTA).
    expect(host.querySelector('.cb-dashboard-sidebar-new-input')).not.toBeNull();
  });

  it('creates a dashboard from the inline form and selects it', async () => {
    const { api, state } = makeApi([]);
    const host = document.createElement('div');
    const onSelect = vi.fn();
    mountDashboardSidebar(host, { api, onSelect });
    await flush();
    const input = host.querySelector<HTMLInputElement>('.cb-dashboard-sidebar-new-input')!;
    input.value = 'Mi Panel';
    host.querySelector<HTMLFormElement>('.cb-dashboard-sidebar-new')!.dispatchEvent(new Event('submit', { cancelable: true }));
    await flush();
    expect(state.rows).toHaveLength(1);
    expect(onSelect).toHaveBeenCalledWith('mi-panel');
  });

  it('deletes a dashboard after confirmer accepts', async () => {
    const { api, state } = makeApi([
      { id: 1, slug: 'a', name: 'A', is_default: true, layout_version: 1, metadata: null, widget_count: 0, created_at: null, updated_at: null },
    ]);
    const host = document.createElement('div');
    const onActiveDeleted = vi.fn();
    mountDashboardSidebar(host, {
      api,
      activeSlug: 'a',
      onSelect: () => undefined,
      onActiveDeleted,
      confirmer: () => true,
    });
    await flush();
    host.querySelector<HTMLButtonElement>('.cb-dashboard-sidebar-item-delete')!.click();
    await flush();
    expect(state.rows).toHaveLength(0);
    expect(onActiveDeleted).toHaveBeenCalled();
  });

  it('skips delete when confirmer rejects', async () => {
    const { api, state } = makeApi([
      { id: 1, slug: 'a', name: 'A', is_default: true, layout_version: 1, metadata: null, widget_count: 0, created_at: null, updated_at: null },
    ]);
    const host = document.createElement('div');
    mountDashboardSidebar(host, {
      api,
      activeSlug: 'a',
      onSelect: () => undefined,
      confirmer: () => false,
    });
    await flush();
    host.querySelector<HTMLButtonElement>('.cb-dashboard-sidebar-item-delete')!.click();
    await flush();
    expect(state.rows).toHaveLength(1);
  });

  it('collapses a trailing double period in the delete confirmation message (#23)', async () => {
    const { api } = makeApi([
      { id: 1, slug: 'a', name: 'A', is_default: true, layout_version: 1, metadata: null, widget_count: 0, created_at: null, updated_at: null },
    ]);
    const host = document.createElement('div');
    let seen = '';
    mountDashboardSidebar(host, {
      api,
      activeSlug: 'a',
      onSelect: () => undefined,
      // A malformed i18n override on the host — a stray second period.
      labels: { confirmDelete: 'Delete this dashboard? Widgets will be removed..' },
      confirmer: (msg) => { seen = msg; return false; },
    });
    await flush();
    host.querySelector<HTMLButtonElement>('.cb-dashboard-sidebar-item-delete')!.click();
    await flush();
    expect(seen).toBe('Delete this dashboard? Widgets will be removed.');
  });

  it('gives the create and rename inputs a name attribute (#24)', async () => {
    const { api } = makeApi([
      { id: 1, slug: 'a', name: 'A', is_default: false, layout_version: 1, metadata: null, widget_count: 0, created_at: null, updated_at: null },
    ]);
    const host = document.createElement('div');
    mountDashboardSidebar(host, { api, onSelect: () => undefined });
    await flush();

    // Create input — always present.
    const createInput = host.querySelector<HTMLInputElement>('.cb-dashboard-sidebar-new-input')!;
    expect(createInput.name).not.toBe('');

    // Rename input — created on demand when the ✎ button is clicked.
    host.querySelector<HTMLButtonElement>('.cb-dashboard-sidebar-item-rename')!.click();
    const renameInput = host.querySelector<HTMLInputElement>('.cb-dashboard-sidebar-rename-input')!;
    expect(renameInput.name).not.toBe('');
  });

  it('promotes default when star button clicked', async () => {
    const { api, state } = makeApi([
      { id: 1, slug: 'a', name: 'A', is_default: true, layout_version: 1, metadata: null, widget_count: 0, created_at: null, updated_at: null },
      { id: 2, slug: 'b', name: 'B', is_default: false, layout_version: 1, metadata: null, widget_count: 0, created_at: null, updated_at: null },
    ]);
    const host = document.createElement('div');
    mountDashboardSidebar(host, { api, onSelect: () => undefined });
    await flush();
    // The "make default" button only appears on non-default rows; row B is at idx 1.
    const star = host.querySelectorAll<HTMLButtonElement>('.cb-dashboard-sidebar-item-default-action')[0];
    star.click();
    await flush();
    const updated = state.rows.find((r) => r.slug === 'b');
    expect(updated?.is_default).toBe(true);
    const a = state.rows.find((r) => r.slug === 'a');
    expect(a?.is_default).toBe(false);
  });
});
