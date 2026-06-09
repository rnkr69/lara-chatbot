import { describe, expect, it, vi } from 'vitest';
import { DashboardApi, streamRefreshAll } from '../../../resources/js/dashboard/api.js';

interface FetchCall {
  url: string;
  method: string;
  body: string | null;
  headers: Record<string, string>;
}

function jsonFetcher(handler: (call: FetchCall) => { status?: number; body?: unknown }): {
  fetcher: typeof fetch;
  calls: FetchCall[];
} {
  const calls: FetchCall[] = [];
  const fetcher = (async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = typeof input === 'string' ? input : (input as URL).toString();
    const method = init?.method ?? 'GET';
    const body = typeof init?.body === 'string' ? init.body : null;
    const headers: Record<string, string> = {};
    if (init?.headers) {
      const h = new Headers(init.headers);
      h.forEach((v, k) => { headers[k] = v; });
    }
    const call: FetchCall = { url, method, body, headers };
    calls.push(call);
    const { status = 200, body: responseBody } = handler(call);
    // 204 No Content forbids a body in the Response constructor.
    if (status === 204) return new Response(null, { status });
    const responseBodyStr = responseBody === undefined ? '' : JSON.stringify(responseBody);
    return new Response(responseBodyStr, {
      status,
      headers: { 'Content-Type': 'application/json' },
    });
  }) as typeof fetch;
  return { fetcher, calls };
}

describe('DashboardApi — CRUD', () => {
  it('listDashboards GET /endpoint returns data[]', async () => {
    const { fetcher, calls } = jsonFetcher(() => ({
      body: { data: [{ id: 1, slug: 'a', name: 'A', is_default: true }] },
    }));
    const api = new DashboardApi({ endpoint: '/chatbot/dashboards', fetcher });
    const rows = await api.listDashboards();
    expect(rows).toHaveLength(1);
    expect(rows[0]?.slug).toBe('a');
    expect(calls[0]?.url).toBe('/chatbot/dashboards');
    expect(calls[0]?.method).toBe('GET');
  });

  it('showDashboard GET /endpoint/{slug} returns data', async () => {
    const { fetcher, calls } = jsonFetcher(() => ({
      body: { data: { id: 1, slug: 'a', name: 'A', is_default: true, widgets: [] } },
    }));
    const api = new DashboardApi({ endpoint: '/chatbot/dashboards', fetcher });
    const detail = await api.showDashboard('a');
    expect(detail.slug).toBe('a');
    expect(calls[0]?.url).toBe('/chatbot/dashboards/a');
  });

  it('createDashboard POSTs JSON and sends X-CSRF-TOKEN from meta', async () => {
    document.head.innerHTML = '<meta name="csrf-token" content="abc123">';
    const { fetcher, calls } = jsonFetcher(() => ({
      body: { data: { id: 2, slug: 'mi-panel', name: 'Mi Panel', is_default: false } },
    }));
    const api = new DashboardApi({ endpoint: '/chatbot/dashboards', fetcher });
    const row = await api.createDashboard({ name: 'Mi Panel' });
    expect(row.slug).toBe('mi-panel');
    expect(calls[0]?.method).toBe('POST');
    expect(calls[0]?.headers['x-csrf-token']).toBe('abc123');
    expect(JSON.parse(calls[0]?.body ?? '{}')).toEqual({ name: 'Mi Panel' });
  });

  it('updateDashboard PATCHes the slug', async () => {
    const { fetcher, calls } = jsonFetcher(() => ({
      body: { data: { id: 1, slug: 'renamed', name: 'Renamed', is_default: true } },
    }));
    const api = new DashboardApi({ endpoint: '/chatbot/dashboards', fetcher });
    await api.updateDashboard('old', { name: 'Renamed' });
    expect(calls[0]?.method).toBe('PATCH');
    expect(calls[0]?.url).toBe('/chatbot/dashboards/old');
  });

  it('deleteDashboard accepts 204', async () => {
    const { fetcher } = jsonFetcher(() => ({ status: 204 }));
    const api = new DashboardApi({ endpoint: '/chatbot/dashboards', fetcher });
    await expect(api.deleteDashboard('a')).resolves.toBeUndefined();
  });

  it('updateWidget PATCHes the position payload', async () => {
    const { fetcher, calls } = jsonFetcher(() => ({ body: {} }));
    const api = new DashboardApi({ endpoint: '/chatbot/dashboards', fetcher });
    await api.updateWidget('a', 7, { position: { x: 0, y: 1, w: 4, h: 2 } });
    expect(calls[0]?.url).toBe('/chatbot/dashboards/a/widgets/7');
    expect(JSON.parse(calls[0]?.body ?? '{}')).toEqual({ position: { x: 0, y: 1, w: 4, h: 2 } });
  });

  it('refreshWidget surfaces 429 as Rate limit exceeded', async () => {
    const { fetcher } = jsonFetcher(() => ({ status: 429, body: { error: 'rate' } }));
    const api = new DashboardApi({ endpoint: '/chatbot/dashboards', fetcher });
    await expect(api.refreshWidget('a', 7)).rejects.toThrow(/Rate limit/);
  });

  it('refreshWidget returns the data envelope on success', async () => {
    const { fetcher } = jsonFetcher(() => ({
      body: {
        data: {
          widget_id: 7,
          status: 'fresh',
          snapshot: { data: { rows: [] }, captured_at: null },
          error: null,
          last_refreshed_at: '2026-05-13T10:00:00.000Z',
        },
      },
    }));
    const api = new DashboardApi({ endpoint: '/chatbot/dashboards', fetcher });
    const result = await api.refreshWidget('a', 7);
    expect(result.status).toBe('fresh');
    expect(result.widget_id).toBe(7);
  });
});

describe('streamRefreshAll — SSE parsing', () => {
  function sseFetcher(chunks: string[], status = 200): typeof fetch {
    return (async () => {
      const encoder = new TextEncoder();
      const stream = new ReadableStream<Uint8Array>({
        start(controller) {
          for (const chunk of chunks) controller.enqueue(encoder.encode(chunk));
          controller.close();
        },
      });
      return new Response(stream, {
        status,
        headers: { 'Content-Type': 'text/event-stream' },
      });
    }) as unknown as typeof fetch;
  }

  it('parses widget_refreshed + done frames in order', async () => {
    const widgets: number[] = [];
    let doneCount = -1;
    const fetcher = sseFetcher([
      'event: widget_refreshed\ndata: {"widget_id":1,"status":"fresh","snapshot":null,"error":null,"last_refreshed_at":"2026-05-13T10:00:00.000Z"}\n\n',
      'event: widget_refreshed\ndata: {"widget_id":2,"status":"stale","snapshot":null,"error":null,"last_refreshed_at":"2026-05-13T10:00:00.000Z"}\n\n',
      'event: done\ndata: {"widget_count":2}\n\n',
    ]);
    await new Promise<void>((resolve) => {
      streamRefreshAll(
        { endpoint: '/chatbot/dashboards', slug: 'a', fetcher },
        {
          onWidget: (f) => widgets.push(f.widget_id),
          onDone: (f) => { doneCount = f.widget_count; resolve(); },
          onError: () => resolve(),
        },
      );
    });
    expect(widgets).toEqual([1, 2]);
    expect(doneCount).toBe(2);
  });

  it('reports 429 via onError code=rate_limited', async () => {
    const fetcher = sseFetcher([], 429);
    let code: string | undefined;
    await new Promise<void>((resolve) => {
      streamRefreshAll(
        { endpoint: '/chatbot/dashboards', slug: 'a', fetcher },
        {
          onWidget: () => undefined,
          onDone: () => resolve(),
          onError: (_msg, c) => { code = c; resolve(); },
        },
      );
    });
    expect(code).toBe('rate_limited');
  });

  it('ignores unknown event types in the stream', async () => {
    const widgets: number[] = [];
    const fetcher = sseFetcher([
      'event: keepalive\ndata: {}\n\n',
      'event: widget_refreshed\ndata: {"widget_id":5,"status":"fresh","snapshot":null,"error":null,"last_refreshed_at":"2026-05-13T10:00:00.000Z"}\n\n',
      'event: done\ndata: {"widget_count":1}\n\n',
    ]);
    await new Promise<void>((resolve) => {
      streamRefreshAll(
        { endpoint: '/chatbot/dashboards', slug: 'a', fetcher },
        {
          onWidget: (f) => widgets.push(f.widget_id),
          onDone: () => resolve(),
          onError: () => resolve(),
        },
      );
    });
    expect(widgets).toEqual([5]);
  });
});

// Coverage guard: avoid unused-import warnings from vi when no spies are needed.
void vi;
