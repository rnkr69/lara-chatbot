/**
 * v2.0 / E5 — REST + SSE client for the Dashboard API (E4).
 *
 * Typed wrappers over `fetch`. Reads the CSRF token from the meta tag exactly
 * like the widget's `sidebar.ts` (there is no session reuse between the
 * dashboard and the widget bundle: both live on different pages).
 *
 * `streamRefreshAll` consumes the bulk SSE of `POST /dashboards/{slug}/refresh`.
 * It reuses `nextBlock` from the chat SSE (`resources/js/sse.ts`) for buffering
 * but parses the frames with its own filter (`widget_refreshed` + `done`).
 */

import { nextBlock } from '../sse.js';
import type {
  DashboardDetail,
  DashboardRow,
  RefreshDoneFrame,
  WidgetPosition,
  WidgetRefreshedFrame,
} from './types.js';

export interface ApiOptions {
  /** Base URL of the JSON CRUD (e.g. `/chatbot/dashboards`). */
  endpoint: string;
  /** Optional Bearer for hosts that use tokens instead of cookies. */
  bearer?: string | null;
  /** Injectable for tests. */
  fetcher?: typeof fetch;
}

interface ListResponse { data: DashboardRow[] }
interface ShowResponse { data: DashboardDetail }
interface CreateUpdateResponse { data: DashboardRow }

/**
 * v2.0 / E6 — payload shape for `pinWidget`. Matches `PinWidgetRequest`
 * exactly (block_id optional, snapshot.data required, source.tool/args
 * required, page_context_keys/page_context/suggested_title/position
 * optional). The server filters page_context by source.page_context_keys
 * and applies a 16 KB cap; here we just forward the active page context
 * as-is.
 *
 * v2.1.2 (#27) — `block_ordinal` is the stable half of the replay
 * descriptor (the N-th block of its type in the tool's output). The
 * server persists it into `source.block_ordinal`; `ReplayService` uses it
 * to re-select THIS block in multi-block tools instead of always taking
 * `blocks[0]`. Optional: a block streamed before 2.1.2 won't carry it.
 */
export interface PinWidgetPayload {
  block_id?: string;
  block_ordinal?: number;
  block_type: string;
  snapshot: { data: Record<string, unknown> };
  source: {
    tool: string;
    args: Record<string, unknown>;
    page_context_keys?: string[];
  };
  suggested_title?: string;
  page_context?: Record<string, unknown>;
  position?: WidgetPosition;
}

/**
 * Thrown by `DashboardApi.pinWidget` on non-2xx responses. Holds the raw
 * server `message` plus a per-key `errors` map so callers can map the
 * 422 surface to localized strings (the modal in pin-modal.ts maps
 * `errors.dashboard` → dashboard_full, `errors.source.tool` → tool_*).
 */
export class PinWidgetError extends Error {
  constructor(
    public readonly status: number,
    public readonly serverMessage: string,
    public readonly errors: Record<string, string[]>,
  ) {
    super(serverMessage !== '' ? serverMessage : `Pin failed (HTTP ${status})`);
    this.name = 'PinWidgetError';
  }
}

/**
 * v2.1 (#13) — thrown by `DashboardApi.showDashboard` on a non-2xx response.
 * Carries `status` so the caller can branch on 404 (a stale localStorage
 * slug pointing at a deleted/pruned dashboard) vs. other failures, instead
 * of dumping a raw `GET … → HTTP 404` string into the page.
 */
export class DashboardHttpError extends Error {
  constructor(public readonly status: number, message: string) {
    super(message);
    this.name = 'DashboardHttpError';
  }
}

function readCsrf(): string | null {
  if (typeof document === 'undefined') return null;
  const el = document.querySelector('meta[name="csrf-token"]');
  const v = el?.getAttribute('content');
  return typeof v === 'string' && v !== '' ? v : null;
}

export class DashboardApi {
  private readonly fetcher: typeof fetch;

  constructor(private readonly opts: ApiOptions) {
    this.fetcher = opts.fetcher ?? fetch.bind(globalThis);
  }

  private headers(method: string, contentType = false): Record<string, string> {
    const h: Record<string, string> = { Accept: 'application/json' };
    if (this.opts.bearer) h['Authorization'] = `Bearer ${this.opts.bearer}`;
    if (method !== 'GET') {
      const csrf = readCsrf();
      if (csrf) h['X-CSRF-TOKEN'] = csrf;
    }
    if (contentType) h['Content-Type'] = 'application/json';
    return h;
  }

  async listDashboards(): Promise<DashboardRow[]> {
    const res = await this.fetcher(this.opts.endpoint, {
      method: 'GET',
      credentials: 'same-origin',
      headers: this.headers('GET'),
    });
    if (!res.ok) throw new Error(`GET ${this.opts.endpoint} → HTTP ${res.status}`);
    const json = (await res.json()) as ListResponse;
    return Array.isArray(json?.data) ? json.data : [];
  }

  async showDashboard(slug: string): Promise<DashboardDetail> {
    const url = `${this.opts.endpoint}/${encodeURIComponent(slug)}`;
    const res = await this.fetcher(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: this.headers('GET'),
    });
    if (!res.ok) throw new DashboardHttpError(res.status, `GET ${url} → HTTP ${res.status}`);
    const json = (await res.json()) as ShowResponse;
    return json.data;
  }

  async createDashboard(payload: { name: string; is_default?: boolean }): Promise<DashboardRow> {
    const res = await this.fetcher(this.opts.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: this.headers('POST', true),
      body: JSON.stringify(payload),
    });
    if (!res.ok) throw new Error(`POST ${this.opts.endpoint} → HTTP ${res.status}`);
    const json = (await res.json()) as CreateUpdateResponse;
    return json.data;
  }

  async updateDashboard(
    slug: string,
    payload: { name?: string; is_default?: boolean; metadata?: Record<string, unknown> },
  ): Promise<DashboardRow> {
    const url = `${this.opts.endpoint}/${encodeURIComponent(slug)}`;
    const res = await this.fetcher(url, {
      method: 'PATCH',
      credentials: 'same-origin',
      headers: this.headers('PATCH', true),
      body: JSON.stringify(payload),
    });
    if (!res.ok) throw new Error(`PATCH ${url} → HTTP ${res.status}`);
    const json = (await res.json()) as CreateUpdateResponse;
    return json.data;
  }

  async deleteDashboard(slug: string): Promise<void> {
    const url = `${this.opts.endpoint}/${encodeURIComponent(slug)}`;
    const res = await this.fetcher(url, {
      method: 'DELETE',
      credentials: 'same-origin',
      headers: this.headers('DELETE'),
    });
    if (!res.ok && res.status !== 204) throw new Error(`DELETE ${url} → HTTP ${res.status}`);
  }

  async updateWidget(
    slug: string,
    widgetId: number,
    payload: {
      position?: WidgetPosition;
      title?: string | null;
      refresh_policy?: 'on_open' | 'manual' | 'never';
    },
  ): Promise<void> {
    const url = `${this.opts.endpoint}/${encodeURIComponent(slug)}/widgets/${widgetId}`;
    const res = await this.fetcher(url, {
      method: 'PATCH',
      credentials: 'same-origin',
      headers: this.headers('PATCH', true),
      body: JSON.stringify(payload),
    });
    if (!res.ok) throw new Error(`PATCH ${url} → HTTP ${res.status}`);
  }

  async deleteWidget(slug: string, widgetId: number): Promise<void> {
    const url = `${this.opts.endpoint}/${encodeURIComponent(slug)}/widgets/${widgetId}`;
    const res = await this.fetcher(url, {
      method: 'DELETE',
      credentials: 'same-origin',
      headers: this.headers('DELETE'),
    });
    if (!res.ok && res.status !== 204) throw new Error(`DELETE ${url} → HTTP ${res.status}`);
  }

  /**
   * v2.0 / E6 — POST `/chatbot/dashboards/{slug}/widgets` (pin a block from
   * the chat). Mirror of `PinWidgetRequest` (server-side validation in
   * `src/Http/Requests/PinWidgetRequest.php`). Throws `PinWidgetError` with
   * structured status/message/errors so the caller (pin-modal) can map
   * 422.errors.dashboard / 422.errors.source.tool to localized messages
   * instead of leaking raw server strings.
   */
  async pinWidget(slug: string, payload: PinWidgetPayload): Promise<void> {
    const url = `${this.opts.endpoint}/${encodeURIComponent(slug)}/widgets`;
    const res = await this.fetcher(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: this.headers('POST', true),
      body: JSON.stringify(payload),
    });
    if (res.ok) return;
    let body: { message?: unknown; errors?: unknown } = {};
    try { body = await res.json() as typeof body; } catch { /* keep empty */ }
    const message = typeof body.message === 'string' ? body.message : '';
    const errors: Record<string, string[]> = {};
    if (body.errors && typeof body.errors === 'object' && !Array.isArray(body.errors)) {
      for (const [k, v] of Object.entries(body.errors as Record<string, unknown>)) {
        if (Array.isArray(v)) {
          errors[k] = v.filter((s): s is string => typeof s === 'string');
        }
      }
    }
    throw new PinWidgetError(res.status, message, errors);
  }

  /**
   * Manual refresh of ONE widget — returns the fresh snapshot (status + data).
   * Rate-limited 60/min server-side (same bucket as refreshAll).
   */
  async refreshWidget(slug: string, widgetId: number): Promise<WidgetRefreshedFrame> {
    const url = `${this.opts.endpoint}/${encodeURIComponent(slug)}/widgets/${widgetId}/refresh`;
    const res = await this.fetcher(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: this.headers('POST', true),
      body: '{}',
    });
    if (res.status === 429) throw new Error('Rate limit exceeded');
    if (!res.ok) throw new Error(`POST ${url} → HTTP ${res.status}`);
    const json = (await res.json()) as { data: WidgetRefreshedFrame };
    return json.data;
  }
}

export interface BulkRefreshHandlers {
  /** Called for each `widget_refreshed` frame with the result of one widget. */
  onWidget(frame: WidgetRefreshedFrame): void;
  /** Called with the final `done` frame (total widgets processed). */
  onDone(frame: RefreshDoneFrame): void;
  /** Called if the connection fails, 429, or the stream is cut off. */
  onError(message: string, code?: 'rate_limited' | 'http' | 'network'): void;
}

/**
 * Consumes the bulk SSE of `POST /dashboards/{slug}/refresh`. Reuses `nextBlock`
 * for the buffer + a dedicated parser (the bulk event names are not in the
 * chat SSE's KNOWN_EVENTS). Returns an `abort()` in case the page is closed
 * midway.
 */
export function streamRefreshAll(
  opts: ApiOptions & { slug: string },
  handlers: BulkRefreshHandlers,
): { abort(): void } {
  const fetcher = opts.fetcher ?? fetch.bind(globalThis);
  const controller = new AbortController();
  const url = `${opts.endpoint}/${encodeURIComponent(opts.slug)}/refresh`;
  const headers: Record<string, string> = {
    Accept: 'text/event-stream',
    'Content-Type': 'application/json',
  };
  if (opts.bearer) headers['Authorization'] = `Bearer ${opts.bearer}`;
  const csrf = readCsrf();
  if (csrf) headers['X-CSRF-TOKEN'] = csrf;

  (async () => {
    let response: Response;
    try {
      response = await fetcher(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers,
        body: '{}',
        signal: controller.signal,
      });
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Network error';
      handlers.onError(msg, 'network');
      return;
    }

    if (response.status === 429) {
      handlers.onError('Rate limit exceeded', 'rate_limited');
      return;
    }
    if (!response.ok) {
      handlers.onError(`HTTP ${response.status}`, 'http');
      return;
    }
    if (!response.body) {
      handlers.onError('Response has no body', 'http');
      return;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder('utf-8');
    let buffer = '';
    try {
      while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });
        while (true) {
          const split = nextBlock(buffer);
          if (!split) break;
          buffer = split.rest;
          const parsed = parseBulkFrame(split.block);
          if (!parsed) continue;
          if (parsed.event === 'widget_refreshed') {
            handlers.onWidget(parsed.data as unknown as WidgetRefreshedFrame);
          } else if (parsed.event === 'done') {
            handlers.onDone(parsed.data as unknown as RefreshDoneFrame);
            return;
          }
        }
      }
    } catch (err) {
      if (controller.signal.aborted) return;
      const msg = err instanceof Error ? err.message : 'Stream error';
      handlers.onError(msg, 'network');
    }
  })();

  return { abort: () => controller.abort() };
}

interface BulkFrame {
  event: 'widget_refreshed' | 'done';
  data: Record<string, unknown>;
}

function parseBulkFrame(block: string): BulkFrame | null {
  let event = '';
  const dataLines: string[] = [];
  for (const rawLine of block.split('\n')) {
    if (rawLine === '' || rawLine.startsWith(':')) continue;
    const colon = rawLine.indexOf(':');
    const field = colon === -1 ? rawLine : rawLine.slice(0, colon);
    const value = colon === -1 ? '' : rawLine.slice(colon + 1).replace(/^ /, '');
    if (field === 'event') event = value;
    else if (field === 'data') dataLines.push(value);
  }
  if (event !== 'widget_refreshed' && event !== 'done') return null;
  const dataRaw = dataLines.join('\n');
  if (dataRaw === '') return { event, data: {} };
  try {
    const parsed = JSON.parse(dataRaw);
    if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) return null;
    return { event, data: parsed as Record<string, unknown> };
  } catch {
    return null;
  }
}
