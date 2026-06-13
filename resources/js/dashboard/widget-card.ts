/**
 * v2.0 / E5 — generic card for a dashboard widget.
 *
 * The card body is delegated to `renderBlock()` (resources/js/blocks.ts):
 * zero duplication of renderers between the floating widget and the dashboard.
 *
 * The header exposes:
 *   - Editable title (override `widget.title`, or `block_type` by default).
 *   - Status pill (`fresh`/`stale`/`error`/`unauthorized`/`source_missing`).
 *   - Menu with: ↻ refresh, ✕ remove.
 *
 * v2.1.3 (#33 + cleanup) — the relative "last refreshed" ("just now"/"5m"/…)
 * and the 👁 "View source" button have been removed from the header. The
 * former because it inflated the header's fixed width (~50–60 px) and left the
 * title `h3` with no room on narrow cards (measured live: 8 px for a default
 * `gs-w:3` card — see #32); the latter was a debug affordance that left the
 * button orphaned when `app.debug=false` and that, when on, stole header space
 * the same way. The info they gave is in the widget's own `source`/
 * `last_refreshed_at` for anyone who wants to reconstruct it.
 *
 * The block renderer receives a `BlockHost.send` that no-ops with a toast —
 * on the dashboard there is no chat to send prompts to; the buttons embedded
 * in `actions`/`list` stay visible but inert. E9 may enrich this by opening
 * `/chatbot?prompt=…` in a new conversation.
 */

import type { BlockPayload } from '../types.js';
import { renderBlock } from '../blocks.js';
import type {
  DashboardWidget,
  RefreshStatus,
  WidgetRefreshError,
  WidgetSnapshot,
} from './types.js';

export interface WidgetCardLabels {
  refresh: string;
  remove: string;
  unauthorized: string;
  error: string;
  stale: string;
  sourceMissing: string;
  noTitle: string;
  refreshing: string;
  inertActionsHint: string;
}

const DEFAULT_LABELS: WidgetCardLabels = {
  refresh: 'Refresh',
  remove: 'Remove',
  unauthorized: 'Unauthorized',
  error: 'Error',
  stale: 'Stale',
  sourceMissing: 'Source missing',
  noTitle: 'Untitled widget',
  refreshing: 'Refreshing…',
  inertActionsHint: 'Open the chat to run actions.',
};

export interface WidgetCardOptions {
  widget: DashboardWidget;
  labels?: Partial<WidgetCardLabels>;
  onRefresh(): void;
  onRemove(): void;
  onRetitle?(newTitle: string | null): void;
  onActionAttempt?(message: string): void;
}

export interface WidgetCardHandle {
  /** DOM root (`.cb-dashboard-card`) that gridstack places inside `.grid-stack-item-content`. */
  readonly el: HTMLElement;
  /** Replaces the snapshot + status after a refresh. */
  update(snapshot: WidgetSnapshot | null, status: RefreshStatus, error: WidgetRefreshError | null, lastRefreshedAt: string | null): void;
  /** Subtle spinner in the header. */
  setRefreshing(on: boolean): void;
  /** Edits the title visually (does not call `onRetitle`). */
  setTitle(title: string | null): void;
  destroy(): void;
}

const STATUS_CLASS: Record<RefreshStatus, string> = {
  fresh: 'cb-status-fresh',
  stale: 'cb-status-stale',
  error: 'cb-status-error',
  unauthorized: 'cb-status-unauthorized',
  source_missing: 'cb-status-source-missing',
};

function mergeLabels(partial?: Partial<WidgetCardLabels>): WidgetCardLabels {
  if (!partial) return DEFAULT_LABELS;
  return { ...DEFAULT_LABELS, ...partial };
}

function statusLabel(status: RefreshStatus, labels: WidgetCardLabels): string {
  switch (status) {
    case 'fresh': return '';
    case 'stale': return labels.stale;
    case 'error': return labels.error;
    case 'unauthorized': return labels.unauthorized;
    case 'source_missing': return labels.sourceMissing;
  }
}

export function mountWidgetCard(opts: WidgetCardOptions): WidgetCardHandle {
  const labels = mergeLabels(opts.labels);

  const root = document.createElement('div');
  root.className = 'cb-dashboard-card';
  root.dataset['widgetId'] = String(opts.widget.id);
  root.dataset['blockType'] = opts.widget.block_type;

  // ── Header ────────────────────────────────────────────────────────────
  const header = document.createElement('div');
  header.className = 'cb-dashboard-card-header';

  const titleEl = document.createElement('h3');
  titleEl.className = 'cb-dashboard-card-title';
  const initialTitle = opts.widget.title && opts.widget.title.trim() !== ''
    ? opts.widget.title
    : opts.widget.block_type;
  titleEl.textContent = initialTitle;
  // v2.1 (#12) — keep the `title` HTML attribute (the hover tooltip) in sync
  // with the real title. It used to stay pinned to the "Untitled widget"
  // i18n fallback even when the widget had a persisted title.
  titleEl.title = initialTitle;
  if (typeof opts.onRetitle === 'function') {
    titleEl.contentEditable = 'plaintext-only';
    titleEl.spellcheck = false;
    titleEl.addEventListener('blur', () => {
      const next = (titleEl.textContent ?? '').trim();
      const normalized = next === '' ? null : next;
      opts.onRetitle?.(normalized);
    });
    titleEl.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        titleEl.blur();
      }
    });
  }

  const statusPill = document.createElement('span');
  statusPill.className = 'cb-dashboard-card-status';
  statusPill.hidden = true;

  const menu = document.createElement('div');
  menu.className = 'cb-dashboard-card-menu';

  const refreshBtn = document.createElement('button');
  refreshBtn.type = 'button';
  refreshBtn.className = 'cb-dashboard-card-btn cb-dashboard-card-refresh';
  refreshBtn.setAttribute('aria-label', labels.refresh);
  refreshBtn.title = labels.refresh;
  refreshBtn.textContent = '↻';
  refreshBtn.addEventListener('click', () => opts.onRefresh());

  const removeBtn = document.createElement('button');
  removeBtn.type = 'button';
  removeBtn.className = 'cb-dashboard-card-btn cb-dashboard-card-remove';
  removeBtn.setAttribute('aria-label', labels.remove);
  removeBtn.title = labels.remove;
  removeBtn.textContent = '✕';
  removeBtn.addEventListener('click', () => opts.onRemove());

  menu.append(refreshBtn, removeBtn);
  header.append(titleEl, statusPill, menu);
  root.appendChild(header);

  // ── Body (renderBlock) ────────────────────────────────────────────────
  const body = document.createElement('div');
  body.className = 'cb-dashboard-card-body';
  root.appendChild(body);

  // ── Error banner (hidden when fresh) ──────────────────────────────────
  const errorBanner = document.createElement('div');
  errorBanner.className = 'cb-dashboard-card-error';
  errorBanner.hidden = true;
  root.appendChild(errorBanner);

  function renderBody(snapshot: WidgetSnapshot | null): void {
    body.innerHTML = '';
    if (snapshot === null || snapshot.data === null || snapshot.data === undefined) {
      const empty = document.createElement('div');
      empty.className = 'cb-dashboard-card-empty';
      empty.textContent = '—';
      body.appendChild(empty);
      return;
    }
    const data = (snapshot.data && typeof snapshot.data === 'object' && !Array.isArray(snapshot.data))
      ? (snapshot.data as Record<string, unknown>)
      : { value: snapshot.data };
    const block: BlockPayload = {
      type: opts.widget.block_type,
      data,
    };
    const node = renderBlock(block, {
      send: (prompt) => {
        const hint = opts.onActionAttempt;
        if (typeof hint === 'function') {
          hint(`${labels.inertActionsHint} (${prompt.slice(0, 40)}${prompt.length > 40 ? '…' : ''})`);
        }
      },
    });
    body.appendChild(node);
  }

  function applyStatus(status: RefreshStatus, error: WidgetRefreshError | null): void {
    // Drop any previously applied status class before stamping the new one.
    for (const cls of Object.values(STATUS_CLASS)) root.classList.remove(cls);
    root.classList.add(STATUS_CLASS[status]);
    const text = statusLabel(status, labels);
    if (text === '') {
      statusPill.hidden = true;
      statusPill.textContent = '';
    } else {
      statusPill.hidden = false;
      statusPill.textContent = text;
    }
    if (error !== null && (status === 'error' || status === 'unauthorized' || status === 'source_missing')) {
      errorBanner.textContent = error.message ?? text;
      errorBanner.hidden = false;
    } else {
      errorBanner.textContent = '';
      errorBanner.hidden = true;
    }
  }

  // Initial paint.
  renderBody(opts.widget.snapshot);
  applyStatus(opts.widget.last_refresh_status, opts.widget.last_refresh_error);

  return {
    el: root,
    update(snapshot, status, error, _lastRefreshedAt): void {
      // v2.1.3 — `lastRefreshedAt` is still in the contract but no longer
      // rendered; we keep accepting it so callers don't have to branch.
      if (snapshot !== null) renderBody(snapshot);
      applyStatus(status, error);
    },
    setRefreshing(on): void {
      root.classList.toggle('cb-dashboard-card-refreshing', on);
      refreshBtn.disabled = on;
      refreshBtn.textContent = on ? '⟳' : '↻';
    },
    setTitle(title): void {
      const resolved = title && title.trim() !== '' ? title : opts.widget.block_type;
      titleEl.textContent = resolved;
      titleEl.title = resolved;
    },
    destroy(): void {
      root.remove();
    },
  };
}
