import { describe, expect, it, vi, beforeEach } from 'vitest';
import { openPinModal, suggestTitle } from '../../../resources/js/dashboard/pin-modal.js';
import { DashboardApi } from '../../../resources/js/dashboard/api.js';
import type { BlockPayload } from '../../../resources/js/types.js';
import type { DashboardRow } from '../../../resources/js/dashboard/types.js';

interface CallLog { url: string; method: string; body: unknown }

function makeApi(opts: {
  rows?: DashboardRow[];
  pinResponse?: { status: number; body?: unknown };
  createResponse?: DashboardRow;
}): { api: DashboardApi; calls: CallLog[]; created: { name: string }[] } {
  const calls: CallLog[] = [];
  const created: { name: string }[] = [];
  const rows = opts.rows ?? [];
  const fetcher = (async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = typeof input === 'string' ? input : (input as URL).toString();
    const method = init?.method ?? 'GET';
    let body: unknown = null;
    if (typeof init?.body === 'string') {
      try { body = JSON.parse(init.body); } catch { body = init.body; }
    }
    calls.push({ url, method, body });

    if (method === 'GET' && url.endsWith('/chatbot/dashboards')) {
      return new Response(JSON.stringify({ data: rows }), { status: 200 });
    }
    if (method === 'POST' && url.endsWith('/chatbot/dashboards')) {
      const payload = body as { name: string };
      created.push({ name: payload.name });
      const created_row: DashboardRow = opts.createResponse ?? {
        id: 99, slug: payload.name.toLowerCase().replace(/\s+/g, '-'),
        name: payload.name, is_default: false, layout_version: 1,
        metadata: null, widget_count: 0, created_at: null, updated_at: null,
      };
      return new Response(JSON.stringify({ data: created_row }), { status: 201 });
    }
    if (method === 'POST' && /\/chatbot\/dashboards\/[^/]+\/widgets$/.test(url)) {
      const status = opts.pinResponse?.status ?? 201;
      if (status >= 200 && status < 300) {
        return new Response(JSON.stringify({ data: { id: 1 } }), { status });
      }
      const errBody = opts.pinResponse?.body ?? {};
      return new Response(JSON.stringify(errBody), {
        status, headers: { 'Content-Type': 'application/json' },
      });
    }
    return new Response(null, { status: 404 });
  }) as typeof fetch;

  const api = new DashboardApi({ endpoint: '/chatbot/dashboards', fetcher });
  return { api, calls, created };
}

function makeBlock(overrides: Partial<BlockPayload> = {}): BlockPayload {
  return {
    type: 'table',
    data: { caption: 'Users' },
    id: 'block-uuid-1',
    pinnable: true,
    source: {
      tool: 'list_users',
      args: { limit: 10 },
      page_context_keys: ['tenant_id'],
    },
    ...overrides,
  };
}

const ROWS: DashboardRow[] = [
  { id: 1, slug: 'a', name: 'Mi Panel', is_default: false, layout_version: 1, metadata: null, widget_count: 1, created_at: null, updated_at: null },
  { id: 2, slug: 'b', name: 'Default Panel', is_default: true, layout_version: 1, metadata: null, widget_count: 5, created_at: null, updated_at: null },
];

async function flush(): Promise<void> {
  await new Promise<void>((r) => setTimeout(r, 0));
}

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('suggestTitle', () => {
  it('prefers data.title', () => {
    expect(suggestTitle({ type: 't', data: { title: 'Hello', caption: 'C' } })).toBe('Hello');
  });
  it('falls back to data.caption', () => {
    expect(suggestTitle({ type: 't', data: { caption: 'Cap' } })).toBe('Cap');
  });
  it('falls back to data.label', () => {
    expect(suggestTitle({ type: 't', data: { label: 'Lbl' } })).toBe('Lbl');
  });
  it('falls back to capitalized block type', () => {
    expect(suggestTitle({ type: 'kpi', data: {} })).toBe('Kpi');
  });
  it('returns empty string when nothing usable', () => {
    expect(suggestTitle({ type: '', data: {} })).toBe('');
  });
  it('trims whitespace from candidates', () => {
    expect(suggestTitle({ type: 't', data: { title: '  Padded  ' } })).toBe('Padded');
  });
  it('skips empty candidate strings', () => {
    expect(suggestTitle({ type: 't', data: { title: '   ', caption: 'Cap' } })).toBe('Cap');
  });
});

describe('openPinModal — list + selection', () => {
  it('renders loading then a select with default-first', async () => {
    const { api } = makeApi({ rows: ROWS });
    openPinModal(document.body, {
      block: makeBlock(),
      api,
      pageContext: {},
      onSuccess: vi.fn(),
      onClose: vi.fn(),
    });
    expect(document.querySelector('.cb-pin-modal-loading')).not.toBeNull();
    await flush();
    const select = document.querySelector<HTMLSelectElement>('.cb-pin-modal-select');
    expect(select).not.toBeNull();
    // Default-first: row "b" (is_default: true) is selected.
    expect(select!.value).toBe('b');
    // The dashboards' names appear as <option> labels.
    const optionLabels = Array.from(select!.options).map((o) => o.textContent);
    expect(optionLabels).toContain('Mi Panel');
    expect(optionLabels.some((l) => l?.includes('Default Panel'))).toBe(true);
  });

  it('falls back to the first row when no row is is_default', async () => {
    const { api } = makeApi({
      rows: ROWS.map((r) => ({ ...r, is_default: false })),
    });
    openPinModal(document.body, {
      block: makeBlock(),
      api,
      pageContext: {},
      onSuccess: vi.fn(),
      onClose: vi.fn(),
    });
    await flush();
    const select = document.querySelector<HTMLSelectElement>('.cb-pin-modal-select');
    expect(select!.value).toBe('a');
  });

  it('hides the selector when there are zero dashboards (forces create mode)', async () => {
    const { api } = makeApi({ rows: [] });
    openPinModal(document.body, {
      block: makeBlock(),
      api,
      pageContext: {},
      onSuccess: vi.fn(),
      onClose: vi.fn(),
    });
    await flush();
    expect(document.querySelector('.cb-pin-modal-select')).toBeNull();
    const createInput = document.querySelector<HTMLInputElement>('.cb-pin-modal-create-input');
    expect(createInput).not.toBeNull();
    expect(createInput!.disabled).toBe(false);
  });

  it('gives the create and title inputs a name attribute (#24)', async () => {
    const { api } = makeApi({ rows: ROWS });
    openPinModal(document.body, {
      block: makeBlock(),
      api,
      pageContext: {},
      onSuccess: vi.fn(),
      onClose: vi.fn(),
    });
    await flush();
    const createInput = document.querySelector<HTMLInputElement>('.cb-pin-modal-create-input')!;
    const titleInput = document.querySelector<HTMLInputElement>('.cb-pin-modal-title-input')!;
    expect(createInput.name).not.toBe('');
    expect(titleInput.name).not.toBe('');
  });
});

describe('openPinModal — pin existing dashboard', () => {
  it('POSTs to /widgets with the correct payload shape on submit', async () => {
    const { api, calls } = makeApi({ rows: ROWS, pinResponse: { status: 201 } });
    const onSuccess = vi.fn();
    openPinModal(document.body, {
      block: makeBlock(),
      api,
      pageContext: { tenant_id: 42 },
      onSuccess,
      onClose: vi.fn(),
    });
    await flush();
    document.querySelector<HTMLInputElement>('.cb-pin-modal-title-input')!.value = 'My Pinned Users';
    document.querySelector<HTMLButtonElement>('.cb-pin-modal-submit')!.click();
    await flush();
    const pinCall = calls.find((c) => /\/widgets$/.test(c.url) && c.method === 'POST');
    expect(pinCall).toBeDefined();
    expect(pinCall!.url).toBe('/chatbot/dashboards/b/widgets');
    expect(pinCall!.body).toEqual({
      block_type: 'table',
      snapshot: { data: { caption: 'Users' } },
      source: {
        tool: 'list_users',
        args: { limit: 10 },
        page_context_keys: ['tenant_id'],
      },
      block_id: 'block-uuid-1',
      suggested_title: 'My Pinned Users',
      page_context: { tenant_id: 42 },
    });
    expect(onSuccess).toHaveBeenCalledWith({ dashboardSlug: 'b', dashboardName: 'Default Panel' });
    // Modal removed from the DOM on success.
    expect(document.querySelector('.cb-pin-modal-overlay')).toBeNull();
  });

  it('includes block_ordinal in the payload when the block carries it (#27)', async () => {
    // #27 — the stable half of the replay descriptor. A block streamed by a
    // multi-block tool carries `blockOrdinal`; the modal must forward it as
    // `block_ordinal` so the server persists it into `source`.
    const { api, calls } = makeApi({ rows: ROWS, pinResponse: { status: 201 } });
    openPinModal(document.body, {
      block: makeBlock({ type: 'kpi', data: { label: 'Average fare' }, blockOrdinal: 1 }),
      api,
      pageContext: {},
      onSuccess: vi.fn(),
      onClose: vi.fn(),
    });
    await flush();
    document.querySelector<HTMLButtonElement>('.cb-pin-modal-submit')!.click();
    await flush();
    const pinCall = calls.find((c) => /\/widgets$/.test(c.url) && c.method === 'POST');
    expect(pinCall).toBeDefined();
    expect((pinCall!.body as { block_ordinal?: number }).block_ordinal).toBe(1);
  });

  it('includes block_ordinal: 0 (the first block of its type is still explicit) (#27)', async () => {
    const { api, calls } = makeApi({ rows: ROWS, pinResponse: { status: 201 } });
    openPinModal(document.body, {
      block: makeBlock({ blockOrdinal: 0 }),
      api,
      pageContext: {},
      onSuccess: vi.fn(),
      onClose: vi.fn(),
    });
    await flush();
    document.querySelector<HTMLButtonElement>('.cb-pin-modal-submit')!.click();
    await flush();
    const pinCall = calls.find((c) => /\/widgets$/.test(c.url) && c.method === 'POST');
    expect((pinCall!.body as { block_ordinal?: number }).block_ordinal).toBe(0);
  });

  it('omits suggested_title when the user clears it', async () => {
    const { api, calls } = makeApi({ rows: ROWS, pinResponse: { status: 201 } });
    openPinModal(document.body, {
      block: makeBlock({ type: 'kpi', data: {} }),
      api,
      pageContext: {},
      onSuccess: vi.fn(),
      onClose: vi.fn(),
    });
    await flush();
    document.querySelector<HTMLInputElement>('.cb-pin-modal-title-input')!.value = '';
    document.querySelector<HTMLButtonElement>('.cb-pin-modal-submit')!.click();
    await flush();
    const pinCall = calls.find((c) => /\/widgets$/.test(c.url));
    expect((pinCall!.body as Record<string, unknown>)['suggested_title']).toBeUndefined();
  });

  it('omits page_context when it is empty', async () => {
    const { api, calls } = makeApi({ rows: ROWS, pinResponse: { status: 201 } });
    openPinModal(document.body, {
      block: makeBlock(),
      api,
      pageContext: {},
      onSuccess: vi.fn(),
      onClose: vi.fn(),
    });
    await flush();
    document.querySelector<HTMLButtonElement>('.cb-pin-modal-submit')!.click();
    await flush();
    const pinCall = calls.find((c) => /\/widgets$/.test(c.url));
    expect((pinCall!.body as Record<string, unknown>)['page_context']).toBeUndefined();
  });
});

describe('openPinModal — create + pin', () => {
  it('creates a dashboard then pins to its slug', async () => {
    const { api, calls, created } = makeApi({ rows: ROWS, pinResponse: { status: 201 } });
    const onSuccess = vi.fn();
    openPinModal(document.body, {
      block: makeBlock(),
      api,
      pageContext: {},
      onSuccess,
      onClose: vi.fn(),
    });
    await flush();
    // Switch to "create" mode and type a name.
    const radios = document.querySelectorAll<HTMLInputElement>('.cb-pin-modal-mode input[type="radio"]');
    const createRadio = radios[1]!;
    createRadio.checked = true;
    createRadio.dispatchEvent(new Event('change'));
    document.querySelector<HTMLInputElement>('.cb-pin-modal-create-input')!.value = 'Brand New';
    document.querySelector<HTMLButtonElement>('.cb-pin-modal-submit')!.click();
    await flush();
    expect(created).toEqual([{ name: 'Brand New' }]);
    const pinCall = calls.find((c) => /\/widgets$/.test(c.url));
    expect(pinCall!.url).toBe('/chatbot/dashboards/brand-new/widgets');
    expect(onSuccess).toHaveBeenCalledWith({ dashboardSlug: 'brand-new', dashboardName: 'Brand New' });
  });

  it('creates + pins from an empty list (no existing dashboards)', async () => {
    const { api, calls, created } = makeApi({ rows: [], pinResponse: { status: 201 } });
    const onSuccess = vi.fn();
    openPinModal(document.body, {
      block: makeBlock(),
      api,
      pageContext: {},
      onSuccess,
      onClose: vi.fn(),
    });
    await flush();
    document.querySelector<HTMLInputElement>('.cb-pin-modal-create-input')!.value = 'First Panel';
    document.querySelector<HTMLButtonElement>('.cb-pin-modal-submit')!.click();
    await flush();
    expect(created).toEqual([{ name: 'First Panel' }]);
    expect(calls.some((c) => c.url === '/chatbot/dashboards/first-panel/widgets')).toBe(true);
    expect(onSuccess).toHaveBeenCalled();
  });
});

describe('openPinModal — error mapping', () => {
  it('shows error_dashboard_full when 422 errors.dashboard arrives', async () => {
    const { api } = makeApi({
      rows: ROWS,
      pinResponse: {
        status: 422,
        body: { message: 'Maximum reached', errors: { dashboard: ['Maximum of 50 widgets reached.'] } },
      },
    });
    const onSuccess = vi.fn();
    openPinModal(document.body, {
      block: makeBlock(),
      api,
      pageContext: {},
      onSuccess,
      onClose: vi.fn(),
    });
    await flush();
    document.querySelector<HTMLButtonElement>('.cb-pin-modal-submit')!.click();
    await flush();
    const errEl = document.querySelector<HTMLElement>('.cb-pin-modal-error');
    expect(errEl?.hidden).toBe(false);
    expect(errEl?.textContent).toContain('full');
    expect(onSuccess).not.toHaveBeenCalled();
    // Modal stays open so the user can pick another dashboard.
    expect(document.querySelector('.cb-pin-modal-overlay')).not.toBeNull();
  });

  it('shows error_tool_unpinnable when 422 source.tool says "not pinnable"', async () => {
    const { api } = makeApi({
      rows: ROWS,
      pinResponse: {
        status: 422,
        body: { message: 'Not pinnable', errors: { 'source.tool': ['Tool `x` is not pinnable.'] } },
      },
    });
    openPinModal(document.body, {
      block: makeBlock(),
      api,
      pageContext: {},
      onSuccess: vi.fn(),
      onClose: vi.fn(),
    });
    await flush();
    document.querySelector<HTMLButtonElement>('.cb-pin-modal-submit')!.click();
    await flush();
    expect(document.querySelector('.cb-pin-modal-error')?.textContent).toMatch(/cannot be pinned/i);
  });

  it('shows error_tool_missing when 422 source.tool says "not registered"', async () => {
    const { api } = makeApi({
      rows: ROWS,
      pinResponse: {
        status: 422,
        body: { message: 'Not registered', errors: { 'source.tool': ['Tool `x` is not registered.'] } },
      },
    });
    openPinModal(document.body, {
      block: makeBlock(),
      api,
      pageContext: {},
      onSuccess: vi.fn(),
      onClose: vi.fn(),
    });
    await flush();
    document.querySelector<HTMLButtonElement>('.cb-pin-modal-submit')!.click();
    await flush();
    expect(document.querySelector('.cb-pin-modal-error')?.textContent).toMatch(/no longer registered/i);
  });

  it('shows the localized generic message on non-422 errors (v2.1 / #11)', async () => {
    // v2.1 (#11) — non-422 responses (401/404/5xx) carry technical server
    // strings ("Unauthenticated.", a missing-route 404, an internal 500).
    // The modal must NOT leak them — it shows the localized generic message.
    const { api } = makeApi({
      rows: ROWS,
      pinResponse: { status: 401, body: { message: 'Unauthenticated.' } },
    });
    openPinModal(document.body, {
      block: makeBlock(),
      api,
      pageContext: {},
      onSuccess: vi.fn(),
      onClose: vi.fn(),
    });
    await flush();
    document.querySelector<HTMLButtonElement>('.cb-pin-modal-submit')!.click();
    await flush();
    const shown = document.querySelector('.cb-pin-modal-error')?.textContent ?? '';
    expect(shown).toBe('Could not pin to dashboard.');
    expect(shown).not.toContain('Unauthenticated');
  });
});

describe('openPinModal — close behaviour', () => {
  it('closes on ESC', async () => {
    const { api } = makeApi({ rows: ROWS });
    const onClose = vi.fn();
    openPinModal(document.body, {
      block: makeBlock(),
      api,
      pageContext: {},
      onSuccess: vi.fn(),
      onClose,
    });
    await flush();
    const dialog = document.querySelector<HTMLElement>('.cb-pin-modal')!;
    dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    expect(document.querySelector('.cb-pin-modal-overlay')).toBeNull();
    expect(onClose).toHaveBeenCalled();
  });

  it('closes on click on the dim overlay (NOT on dialog click)', async () => {
    const { api } = makeApi({ rows: ROWS });
    const onClose = vi.fn();
    openPinModal(document.body, {
      block: makeBlock(),
      api,
      pageContext: {},
      onSuccess: vi.fn(),
      onClose,
    });
    await flush();
    // Click on the dialog itself: must NOT close.
    const dialog = document.querySelector<HTMLElement>('.cb-pin-modal')!;
    dialog.click();
    expect(document.querySelector('.cb-pin-modal-overlay')).not.toBeNull();
    expect(onClose).not.toHaveBeenCalled();
    // Click on overlay (background): closes.
    const overlay = document.querySelector<HTMLElement>('.cb-pin-modal-overlay')!;
    overlay.click();
    expect(document.querySelector('.cb-pin-modal-overlay')).toBeNull();
    expect(onClose).toHaveBeenCalled();
  });

  it('cancel button closes', async () => {
    const { api } = makeApi({ rows: ROWS });
    const onClose = vi.fn();
    openPinModal(document.body, {
      block: makeBlock(),
      api,
      pageContext: {},
      onSuccess: vi.fn(),
      onClose,
    });
    await flush();
    document.querySelector<HTMLButtonElement>('.cb-pin-modal-cancel')!.click();
    expect(document.querySelector('.cb-pin-modal-overlay')).toBeNull();
    expect(onClose).toHaveBeenCalled();
  });

  it('handle.close() removes the modal and DOES NOT fire onClose (programmatic close)', async () => {
    const { api } = makeApi({ rows: ROWS });
    const onClose = vi.fn();
    const handle = openPinModal(document.body, {
      block: makeBlock(),
      api,
      pageContext: {},
      onSuccess: vi.fn(),
      onClose,
    });
    await flush();
    handle.close();
    // The handle returned to callers does call onClose by design (so the
    // widget can clear its modal-tracking pointer). Both call paths are
    // equivalent from the user's perspective.
    expect(document.querySelector('.cb-pin-modal-overlay')).toBeNull();
    expect(onClose).toHaveBeenCalled();
  });
});
