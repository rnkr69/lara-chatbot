/**
 * v2.0 / E5 — JSON shapes consumed by the dashboard bundle.
 *
 * The E4 Resources (`DashboardResource`, `DashboardWidgetResource`) are the
 * source of truth; these interfaces reflect them in TS. They keep `unknown`
 * for the opaque fields (snapshot.data, source.args, metadata) that each
 * renderer interprets in its own way.
 */

export type RefreshPolicy = 'on_open' | 'manual' | 'never';

export type RefreshStatus = 'fresh' | 'stale' | 'error' | 'unauthorized' | 'source_missing';

export interface DashboardRow {
  id: number;
  slug: string;
  name: string;
  is_default: boolean;
  layout_version: number;
  metadata: Record<string, unknown> | null;
  /** Only present in /dashboards (index) — `show` omits it. */
  widget_count?: number;
  created_at: string | null;
  updated_at: string | null;
}

export interface WidgetPosition {
  x: number;
  y: number;
  w: number;
  h: number;
}

export interface WidgetSnapshot {
  data: unknown;
  captured_at: string | null;
  byte_size?: number;
  truncated?: boolean;
}

export interface WidgetSource {
  tool: string;
  args: Record<string, unknown>;
  page_context_snapshot?: Record<string, unknown> | null;
  captured_scope?: unknown;
}

export interface WidgetRefreshError {
  category: string;
  message: string;
  captured_at: string | null;
}

export interface DashboardWidget {
  id: number;
  block_type: string;
  title: string | null;
  position: WidgetPosition;
  snapshot: WidgetSnapshot;
  source: WidgetSource | null;
  source_signature: string;
  refresh_policy: RefreshPolicy;
  last_refresh_status: RefreshStatus;
  last_refresh_error: WidgetRefreshError | null;
  last_refreshed_at: string | null;
  order_index: number;
  created_at: string | null;
  updated_at: string | null;
}

export interface DashboardDetail extends DashboardRow {
  widgets: DashboardWidget[];
}

/**
 * Frame emitted by `POST /dashboards/{slug}/refresh` (bulk SSE).
 *
 * The E4 controller emits an `event: widget_refreshed` for each widget that
 * `replayBulk` processes, and a final `event: done` with the total.
 */
export interface WidgetRefreshedFrame {
  widget_id: number;
  status: RefreshStatus;
  snapshot: WidgetSnapshot | null;
  error: WidgetRefreshError | null;
  last_refreshed_at: string;
}

export interface RefreshDoneFrame {
  widget_count: number;
}
