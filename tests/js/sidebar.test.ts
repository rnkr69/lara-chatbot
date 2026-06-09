import { describe, expect, it, beforeEach, afterEach, vi } from 'vitest';
import { mountSidebar, type SidebarHandle } from '../../resources/js/sidebar.js';

beforeEach(() => {
  document.body.innerHTML = '';
  document.head.innerHTML = '';
});

afterEach(() => {
  vi.useRealTimers();
});

interface FetchCall {
  url: string;
  init: RequestInit | undefined;
}

function makeFetcher(rows: Array<{ id: string | number; title?: string | null; updated_at?: string | null }>): {
  fetcher: typeof fetch;
  calls: FetchCall[];
} {
  const calls: FetchCall[] = [];
  const fetcher = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = typeof input === 'string' ? input : (input as URL).toString();
    calls.push({ url, init });
    if (init?.method === 'DELETE') {
      return new Response(null, { status: 204 });
    }
    return new Response(JSON.stringify({ data: rows }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    });
  }) as unknown as typeof fetch;
  return { fetcher, calls };
}

async function flushMicroAndTimers(ms = 0): Promise<void> {
  // Yield to microtasks (the fetch + Response.json chain) and advance fake
  // timers if any. Node 20's `Response.json()` requires more microtask hops
  // than 22/24 — a low fixed count was fragile against Node version, so we
  // bump to 20. We can't use `setImmediate` here because some tests in this
  // file enable `vi.useFakeTimers()` which fakes setImmediate too and would
  // hang waiting for a macrotask that never fires.
  for (let i = 0; i < 20; i++) await Promise.resolve();
  if (ms > 0) vi.advanceTimersByTime(ms);
  for (let i = 0; i < 20; i++) await Promise.resolve();
}

describe('mountSidebar — initial load and rendering', () => {
  it('renders rows returned by the conversations endpoint', async () => {
    const { fetcher, calls } = makeFetcher([
      { id: 1, title: 'First', updated_at: '2026-05-09T10:00:00.000Z' },
      { id: 2, title: 'Second', updated_at: '2026-05-08T10:00:00.000Z' },
    ]);
    const host = document.createElement('div');
    document.body.appendChild(host);
    mountSidebar(host, {
      endpoint: '/chatbot/conversations',
      onSelect: () => undefined,
      fetcher,
    });
    await flushMicroAndTimers();
    const items = host.querySelectorAll('.cb-sidebar-item');
    expect(items.length).toBe(2);
    const titles = Array.from(items).map((el) => el.querySelector('.cb-sidebar-item-title')?.textContent);
    expect(titles).toEqual(['First', 'Second']);
    expect(calls[0]?.url).toBe('/chatbot/conversations');
  });

  it('renders an empty-state message when there are no conversations', async () => {
    const { fetcher } = makeFetcher([]);
    const host = document.createElement('div');
    mountSidebar(host, {
      endpoint: '/chatbot/conversations',
      onSelect: () => undefined,
      fetcher,
    });
    await flushMicroAndTimers();
    const empty = host.querySelector<HTMLElement>('.cb-sidebar-empty');
    expect(empty).not.toBeNull();
    expect(empty!.hidden).toBe(false);
    expect(host.querySelectorAll('.cb-sidebar-item').length).toBe(0);
  });

  it('shows an error banner when the request fails', async () => {
    const fetcher = vi.fn(async () => new Response('boom', { status: 500 })) as unknown as typeof fetch;
    const host = document.createElement('div');
    mountSidebar(host, {
      endpoint: '/chatbot/conversations',
      onSelect: () => undefined,
      fetcher,
    });
    await flushMicroAndTimers();
    const error = host.querySelector<HTMLElement>('.cb-sidebar-error');
    expect(error).not.toBeNull();
    expect(error!.hidden).toBe(false);
  });

  it('falls back to a default title when title is null', async () => {
    const { fetcher } = makeFetcher([{ id: 42, title: null }]);
    const host = document.createElement('div');
    mountSidebar(host, {
      endpoint: '/chatbot/conversations',
      onSelect: () => undefined,
      fetcher,
    });
    await flushMicroAndTimers();
    const title = host.querySelector('.cb-sidebar-item-title')?.textContent;
    expect(title).toBe('Conversation 42');
  });

  it('marks the activeId row with the active class', async () => {
    const { fetcher } = makeFetcher([
      { id: 1, title: 'A' },
      { id: 2, title: 'B' },
    ]);
    const host = document.createElement('div');
    mountSidebar(host, {
      endpoint: '/chatbot/conversations',
      onSelect: () => undefined,
      activeId: 2,
      fetcher,
    });
    await flushMicroAndTimers();
    const items = host.querySelectorAll<HTMLElement>('.cb-sidebar-item');
    expect(items[0]!.classList.contains('cb-sidebar-item-active')).toBe(false);
    expect(items[1]!.classList.contains('cb-sidebar-item-active')).toBe(true);
  });
});

describe('mountSidebar — search', () => {
  it('debounces the search input and fires the request with ?q=', async () => {
    vi.useFakeTimers();
    const { fetcher, calls } = makeFetcher([]);
    const host = document.createElement('div');
    mountSidebar(host, {
      endpoint: '/chatbot/conversations',
      onSelect: () => undefined,
      fetcher,
      searchDebounceMs: 100,
    });
    // Wait for the initial load.
    await flushMicroAndTimers();
    const input = host.querySelector<HTMLInputElement>('.cb-sidebar-search-input')!;
    input.value = 'invo';
    input.dispatchEvent(new Event('input'));
    input.value = 'invoice';
    input.dispatchEvent(new Event('input'));
    expect(calls.length).toBe(1); // still only the initial load
    await flushMicroAndTimers(150);
    expect(calls.length).toBe(2);
    expect(calls[1]!.url).toBe('/chatbot/conversations?q=invoice');
  });

  it('appends ?q= without overwriting an existing query string', async () => {
    vi.useFakeTimers();
    const { fetcher, calls } = makeFetcher([]);
    const host = document.createElement('div');
    mountSidebar(host, {
      endpoint: '/chatbot/conversations?per_page=10',
      onSelect: () => undefined,
      fetcher,
      searchDebounceMs: 50,
    });
    await flushMicroAndTimers();
    const input = host.querySelector<HTMLInputElement>('.cb-sidebar-search-input')!;
    input.value = 'foo';
    input.dispatchEvent(new Event('input'));
    await flushMicroAndTimers(100);
    expect(calls[1]!.url).toBe('/chatbot/conversations?per_page=10&q=foo');
  });
});

describe('mountSidebar — selection and delete', () => {
  it('calls onSelect when an item is clicked and updates the active highlight', async () => {
    const { fetcher } = makeFetcher([
      { id: 1, title: 'A' },
      { id: 2, title: 'B' },
    ]);
    const host = document.createElement('div');
    const onSelect = vi.fn();
    mountSidebar(host, {
      endpoint: '/chatbot/conversations',
      onSelect,
      fetcher,
    });
    await flushMicroAndTimers();
    const second = host.querySelectorAll<HTMLElement>('.cb-sidebar-item-button')[1]!;
    second.click();
    expect(onSelect).toHaveBeenCalledWith(2);
    const items = host.querySelectorAll<HTMLElement>('.cb-sidebar-item');
    expect(items[1]!.classList.contains('cb-sidebar-item-active')).toBe(true);
  });

  it('asks for confirmation before deleting a conversation', async () => {
    const { fetcher, calls } = makeFetcher([{ id: 7, title: 'Doomed' }]);
    const host = document.createElement('div');
    const confirmer = vi.fn(() => false);
    mountSidebar(host, {
      endpoint: '/chatbot/conversations',
      onSelect: () => undefined,
      fetcher,
      confirmer,
    });
    await flushMicroAndTimers();
    const del = host.querySelector<HTMLButtonElement>('.cb-sidebar-item-delete')!;
    del.click();
    await flushMicroAndTimers();
    expect(confirmer).toHaveBeenCalled();
    // No DELETE call (rejected by confirm).
    expect(calls.some((c) => c.init?.method === 'DELETE')).toBe(false);
    expect(host.querySelectorAll('.cb-sidebar-item').length).toBe(1);
  });

  it('removes the row from the DOM after a successful DELETE', async () => {
    const { fetcher, calls } = makeFetcher([
      { id: 7, title: 'Doomed' },
      { id: 8, title: 'Survivor' },
    ]);
    const host = document.createElement('div');
    mountSidebar(host, {
      endpoint: '/chatbot/conversations',
      onSelect: () => undefined,
      fetcher,
      confirmer: () => true,
    });
    await flushMicroAndTimers();
    const del = host.querySelector<HTMLButtonElement>('.cb-sidebar-item-delete')!;
    del.click();
    await flushMicroAndTimers();
    expect(calls.some((c) => c.init?.method === 'DELETE' && c.url === '/chatbot/conversations/7')).toBe(true);
    const remaining = host.querySelectorAll('.cb-sidebar-item');
    expect(remaining.length).toBe(1);
    expect(remaining[0]!.querySelector('.cb-sidebar-item-title')?.textContent).toBe('Survivor');
  });

  it('calls onDeleteActive when the deleted row was the active one', async () => {
    const { fetcher } = makeFetcher([{ id: 7, title: 'Doomed' }]);
    const host = document.createElement('div');
    const onDeleteActive = vi.fn();
    mountSidebar(host, {
      endpoint: '/chatbot/conversations',
      activeId: 7,
      onSelect: () => undefined,
      onDeleteActive,
      fetcher,
      confirmer: () => true,
    });
    await flushMicroAndTimers();
    const del = host.querySelector<HTMLButtonElement>('.cb-sidebar-item-delete')!;
    del.click();
    await flushMicroAndTimers();
    expect(onDeleteActive).toHaveBeenCalled();
  });

  it('forwards the X-CSRF-TOKEN header on DELETE when the meta is present', async () => {
    const { fetcher, calls } = makeFetcher([{ id: 9, title: 'X' }]);
    document.head.innerHTML = '<meta name="csrf-token" content="abc123">';
    const host = document.createElement('div');
    mountSidebar(host, {
      endpoint: '/chatbot/conversations',
      onSelect: () => undefined,
      fetcher,
      confirmer: () => true,
    });
    await flushMicroAndTimers();
    host.querySelector<HTMLButtonElement>('.cb-sidebar-item-delete')!.click();
    await flushMicroAndTimers();
    const del = calls.find((c) => c.init?.method === 'DELETE')!;
    const headers = del.init?.headers as Record<string, string>;
    expect(headers['X-CSRF-TOKEN']).toBe('abc123');
  });
});

describe('mountSidebar — handle API', () => {
  it('refresh() re-fetches the list', async () => {
    const { fetcher, calls } = makeFetcher([]);
    const host = document.createElement('div');
    const handle: SidebarHandle = mountSidebar(host, {
      endpoint: '/chatbot/conversations',
      onSelect: () => undefined,
      fetcher,
    });
    await flushMicroAndTimers();
    expect(calls.length).toBe(1);
    await handle.refresh();
    expect(calls.length).toBe(2);
  });

  it('setActive moves the active class without firing onSelect', async () => {
    const { fetcher } = makeFetcher([
      { id: 1, title: 'A' },
      { id: 2, title: 'B' },
    ]);
    const host = document.createElement('div');
    const onSelect = vi.fn();
    const handle = mountSidebar(host, {
      endpoint: '/chatbot/conversations',
      onSelect,
      fetcher,
    });
    await flushMicroAndTimers();
    handle.setActive(2);
    const items = host.querySelectorAll<HTMLElement>('.cb-sidebar-item');
    expect(items[1]!.classList.contains('cb-sidebar-item-active')).toBe(true);
    expect(onSelect).not.toHaveBeenCalled();
  });

  it('destroy() removes the sidebar root from the DOM', async () => {
    const { fetcher } = makeFetcher([{ id: 1, title: 'A' }]);
    const host = document.createElement('div');
    document.body.appendChild(host);
    const handle = mountSidebar(host, {
      endpoint: '/chatbot/conversations',
      onSelect: () => undefined,
      fetcher,
    });
    await flushMicroAndTimers();
    expect(host.querySelector('.cb-sidebar')).not.toBeNull();
    handle.destroy();
    expect(host.querySelector('.cb-sidebar')).toBeNull();
  });
});
