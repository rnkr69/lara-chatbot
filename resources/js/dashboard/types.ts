/**
 * v2.0 / E5 — shapes JSON consumidos por el bundle del dashboard.
 *
 * Los Resources de E4 (`DashboardResource`, `DashboardWidgetResource`) son la
 * fuente de la verdad; estas interfaces los reflejan en TS. Mantienen `unknown`
 * para los campos opacos (snapshot.data, source.args, metadata) que cada
 * renderer interpreta a su manera.
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
  /** Sólo presente en /dashboards (index) — `show` lo omite. */
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
 * Frame emitido por `POST /dashboards/{slug}/refresh` (SSE bulk).
 *
 * El controller E4 emite un `event: widget_refreshed` por cada widget que
 * `replayBulk` procesa, y un `event: done` final con el total.
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
