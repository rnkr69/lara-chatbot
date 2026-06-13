/**
 * v2.0 / E5 — `DashboardApp` orchestrator.
 *
 * Boot:
 *   1. Reads the root's `data-*` (E4 injects them).
 *   2. Applies theme (`light`/`dark`/`auto` with `prefers-color-scheme`).
 *   3. Mounts `mountDashboardSidebar` and starts the list.
 *   4. Resolves the active slug: localStorage (D16 mirror) → `data-default-slug` →
 *      first dashboard in the list once the API responds → null if there are
 *      none.
 *   5. Loads the active dashboard (`api.showDashboard`) and mounts the grid +
 *      cards.
 *   6. Fires `streamRefreshAll` (bulk SSE) if any widget has
 *      `refresh_policy='on_open'`. Each `widget_refreshed` frame updates the
 *      corresponding card in-place.
 *   7. Grid `change` listener → debounced PATCH per moved widget.
 *
 * Layout DOM final:
 *   #chatbot-dashboard-root.cb-dashboard-root
 *     <aside class="cb-dashboard-sidebar">…</aside>
 *     <main class="cb-dashboard-main">
 *       <header class="cb-dashboard-header">
 *         <h1>{dashboard.name}</h1>
 *         <button class="cb-dashboard-refresh-all">↻ all</button>
 *       </header>
 *       <div class="cb-dashboard-grid-host">
 *         <div class="grid-stack">…cards…</div>
 *       </div>
 *     </main>
 */

import { DashboardApi, DashboardHttpError, streamRefreshAll } from './api.js';
import { mountDashboardSidebar, type DashboardSidebarHandle, type DashboardSidebarLabels } from './sidebar.js';
import { mountWidgetCard, type WidgetCardHandle, type WidgetCardLabels } from './widget-card.js';
import { mountGrid, type GridHandle, type GridLayoutChange, type GridStackFactory } from './grid.js';
import { loadActiveDashboard, saveActiveDashboard } from './persistence.js';
import { pickString } from '../i18n-bridge.js';
import type { ChatbotDashboardI18n } from '../i18n-bridge.js';
import type { DashboardDetail, DashboardWidget } from './types.js';

export interface DashboardAppOptions {
  /** Root element produced by the blade (`#chatbot-dashboard-root`). */
  root: HTMLElement;
  /** Injectables for tests (mock fetch/gridstack/window.confirm). */
  fetcher?: typeof fetch;
  gridFactory?: GridStackFactory;
  confirmer?: (msg: string) => boolean;
  /** Debounce window for layout PATCH (default 500ms). */
  layoutDebounceMs?: number;
  /**
   * v2.0 / E9 — `i18n.dashboard.*` subtree drained from `data-i18n` on the
   * root. Subkeys (`sidebar`, `card`, `header`, `pin`, `chart`, `kpi`) are
   * applied to the relevant mounters. Missing keys fall back to the inline
   * defaults of each module.
   */
  i18n?: ChatbotDashboardI18n;
}

export interface DashboardAppHandle {
  destroy(): void;
}

export function startDashboardApp(opts: DashboardAppOptions): DashboardAppHandle {
  const { root } = opts;
  const endpoint = root.dataset['dashboardsEndpoint'] ?? '';
  if (endpoint === '') {
    renderRootError(root, 'Missing data-dashboards-endpoint on dashboard root');
    return { destroy: () => undefined };
  }

  const theme = root.dataset['theme'] ?? 'auto';
  applyTheme(root, theme);
  // v2.2.2 (PR-C) — in `auto`, watch the host's `<html data-bs-theme>` plus
  // the OS `prefers-color-scheme` media query so the dashboard tracks the
  // chrome's light/dark toggle (Backpack-Tabler default, Tabler / AdminLTE /
  // Filament variants) in runtime, not just at boot. Teardown is wired into
  // the returned `destroy()` so SPA frameworks moving the root around do not
  // leak observers.
  const themeWatcherTeardown = setupThemeWatcher(root, theme);

  // v2.1.3 — `data-debug` is still stamped by `DashboardController` (kept for
  // forward-compat with any future host-debug affordance) but no longer read
  // here: the "View source" (👁) button that #17 used it for is gone (see
  // widget-card.ts header notes for #33/#32). Hosts can drop the attribute
  // without effect; we leave the controller line in place because removing it
  // would be a contract change for nothing.

  // v2.0 / E9 — i18n flow. Each subtree drains into the mounter that owns
  // those labels; pin lives in pin-modal/pin-button (widget bundle), not
  // here.
  const headerI18n = (opts.i18n?.header ?? {}) as Record<string, unknown>;
  const sidebarI18n = (opts.i18n?.sidebar ?? {}) as Record<string, unknown>;
  const cardI18n = (opts.i18n?.card ?? {}) as Record<string, unknown>;
  const refreshAllLabel = pickString(headerI18n, 'refresh_all', 'Refresh all');
  const emptyMainLabel = pickString(headerI18n, 'empty_main', 'No dashboard selected');
  const emptyMainHintLabel = pickString(headerI18n, 'empty_main_hint', 'Create one from the sidebar to start pinning blocks.');

  const apiBearer = root.dataset['bearer'] ?? null;
  const api = new DashboardApi({
    endpoint,
    bearer: apiBearer,
    ...(opts.fetcher ? { fetcher: opts.fetcher } : {}),
  });

  root.classList.add('cb-dashboard-root');

  // ── Layout shell ──────────────────────────────────────────────────────
  const sidebarHost = document.createElement('div');
  sidebarHost.className = 'cb-dashboard-sidebar-host';
  const main = document.createElement('main');
  main.className = 'cb-dashboard-main';
  root.append(sidebarHost, main);

  const header = document.createElement('header');
  header.className = 'cb-dashboard-header';
  const titleEl = document.createElement('h1');
  titleEl.className = 'cb-dashboard-title';
  titleEl.textContent = '';
  const refreshAllBtn = document.createElement('button');
  refreshAllBtn.type = 'button';
  refreshAllBtn.className = 'cb-dashboard-refresh-all';
  refreshAllBtn.textContent = '↻';
  refreshAllBtn.title = refreshAllLabel;
  refreshAllBtn.setAttribute('aria-label', refreshAllLabel);
  refreshAllBtn.disabled = true;
  refreshAllBtn.addEventListener('click', () => {
    if (activeSlug !== null) void refreshAll(activeSlug);
  });
  header.append(titleEl, refreshAllBtn);
  main.appendChild(header);

  const gridHost = document.createElement('div');
  gridHost.className = 'cb-dashboard-grid-host';
  main.appendChild(gridHost);

  const emptyMain = document.createElement('div');
  emptyMain.className = 'cb-dashboard-main-empty';
  emptyMain.hidden = true;
  main.appendChild(emptyMain);

  // ── State ─────────────────────────────────────────────────────────────
  let activeSlug: string | null = null;
  let currentDashboard: DashboardDetail | null = null;
  let grid: GridHandle | null = null;
  let sidebar: DashboardSidebarHandle | null = null;
  const cards = new Map<number, { card: WidgetCardHandle; item: HTMLElement }>();
  let refreshStream: { abort(): void } | null = null;
  const pendingLayoutChanges = new Map<number, GridLayoutChange>();
  let layoutFlushTimer: ReturnType<typeof setTimeout> | null = null;
  const debounceMs = opts.layoutDebounceMs ?? 500;

  // ── Sidebar ───────────────────────────────────────────────────────────
  const sidebarLabels = mapSidebarLabels(sidebarI18n);
  const sidebarOpts: Parameters<typeof mountDashboardSidebar>[1] = {
    api,
    activeSlug: loadActiveDashboard() ?? root.dataset['defaultSlug'] ?? null,
    labels: sidebarLabels,
    onSelect: (slug) => {
      if (slug !== activeSlug) {
        void loadDashboard(slug);
      }
    },
    onActiveDeleted: () => {
      // v2.1.1 (#22) — re-run the boot auto-selection (the user's default,
      // else the first row) over the dashboards that remain, instead of
      // leaving "No dashboard selected" up until an F5. The sidebar has
      // already dropped the deleted row from its list by the time this fires.
      const remaining = sidebar?.getRows() ?? [];
      const next = remaining.find((r) => r.is_default) ?? remaining[0];
      if (next) {
        sidebar?.setActive(next.slug);
        void loadDashboard(next.slug);
        return;
      }
      // Genuinely the last dashboard — fall through to the empty state.
      teardownDashboard();
      activeSlug = null;
      saveActiveDashboard(null);
      titleEl.textContent = '';
      refreshAllBtn.disabled = true;
      renderEmptyMain();
    },
    onChanged: (active) => {
      if (activeSlug === null) return;
      // v2.1 (#9) — a rename of the *active* dashboard re-derives its slug
      // AND changes its name. The sidebar already updated its own state;
      // mirror it here so our slug, the <h1> and localStorage stay in sync.
      if (active !== null && currentDashboard !== null
        && (active.slug !== activeSlug || active.name !== currentDashboard.name)) {
        activeSlug = active.slug;
        currentDashboard.slug = active.slug;
        currentDashboard.name = active.name;
        titleEl.textContent = active.name;
      }
      saveActiveDashboard(activeSlug);
    },
  };
  if (typeof opts.confirmer === 'function') sidebarOpts.confirmer = opts.confirmer;
  sidebar = mountDashboardSidebar(sidebarHost, sidebarOpts);

  const cardLabels = mapCardLabels(cardI18n);

  // ── Initial dashboard resolution ──────────────────────────────────────
  // Priority: localStorage → data-default-slug → the user's default (or
  // first) dashboard once the sidebar's initial list resolves → empty state.
  const stored = loadActiveDashboard();
  const fromAttr = root.dataset['defaultSlug'] ?? null;
  const initialSlug = stored ?? fromAttr;
  if (initialSlug !== null && initialSlug !== '') {
    void loadDashboard(initialSlug);
  } else {
    // v2.1 (#14) — no stored/attr slug. Await the sidebar's initial list
    // (no fixed-timeout race) and auto-select the user's default — or the
    // first row — instead of leaving "No dashboard selected" up while the
    // sidebar clearly lists dashboards.
    void sidebar?.ready.then(() => {
      if (activeSlug !== null) return; // user navigated while the list loaded
      const rows = sidebar?.getRows() ?? [];
      const def = rows.find((r) => r.is_default) ?? rows[0];
      if (def) {
        sidebar?.setActive(def.slug);
        void loadDashboard(def.slug);
      } else {
        renderEmptyMain();
      }
    });
  }

  // v2.2.1 (PR-B) — listen for `chatbot:dashboard-mutation` so the dashboard
  // refreshes without F5 when the chat invokes one of the 5 mutating tools
  // (add_to_dashboard / edit_widget / delete_widget / edit_dashboard /
  // delete_dashboard). The widget bundle dispatches this event after each
  // tool emits a card block with `meta.side_effects`. Same listener also
  // refreshes `page_context.dashboard` so the next chat turn sees the new
  // state (closes finding #2 of the v2.2.0 E2E run: page context frozen
  // after a client-side switch).
  const mutationListener = (e: Event): void => {
    const ce = e as CustomEvent;
    const detail = ce.detail as { type?: unknown; [k: string]: unknown };
    if (!detail || typeof detail !== 'object') return;
    void handleDashboardMutation(detail);
  };
  document.addEventListener('chatbot:dashboard-mutation', mutationListener as EventListener);

  async function handleDashboardMutation(detail: Record<string, unknown>): Promise<void> {
    const type = typeof detail['type'] === 'string' ? detail['type'] : '';
    const dashboardSlug = typeof detail['dashboard_slug'] === 'string' ? detail['dashboard_slug'] : null;
    switch (type) {
      case 'widget_added':
      case 'widget_deleted':
      case 'widget_updated': {
        // Reload the active dashboard if the mutation targeted it. The
        // showDashboard endpoint already returns the full widget list, so
        // a single fetch covers add/delete/edit uniformly without surgical
        // diff logic that would have to mirror the server merge semantics.
        if (dashboardSlug !== null && dashboardSlug === activeSlug) {
          await loadDashboard(dashboardSlug);
        }
        // widget_count badge on the sidebar changes for add/delete.
        if (type !== 'widget_updated') {
          await sidebar?.refresh();
        }
        return;
      }
      case 'dashboard_updated': {
        const newSlug = typeof detail['new_slug'] === 'string' && detail['new_slug'] !== ''
          ? detail['new_slug']
          : null;
        const newName = typeof detail['new_name'] === 'string' ? detail['new_name'] : null;
        await sidebar?.refresh();
        // If the rename / set_default touches the active dashboard, mirror
        // the new slug/name locally and rewrite the URL so a refresh lands
        // on the renamed dashboard (the controller reads `?dashboard=`).
        if (dashboardSlug !== null && dashboardSlug === activeSlug) {
          if (newSlug !== null) {
            activeSlug = newSlug;
            saveActiveDashboard(newSlug);
            sidebar?.setActive(newSlug);
            if (currentDashboard !== null) currentDashboard.slug = newSlug;
            if (typeof window !== 'undefined' && window.history?.replaceState) {
              try {
                const url = new URL(window.location.href);
                url.searchParams.set('dashboard', newSlug);
                window.history.replaceState({}, '', url);
              } catch { /* ignore — non-URL-parseable href */ }
            }
          }
          if (newName !== null) {
            titleEl.textContent = newName;
            if (currentDashboard !== null) currentDashboard.name = newName;
          }
          emitActivePageContext();
        }
        return;
      }
      case 'dashboard_deleted': {
        await sidebar?.refresh();
        if (dashboardSlug === null || dashboardSlug !== activeSlug) return;
        const promotedSlug = typeof detail['promoted_slug'] === 'string' && detail['promoted_slug'] !== ''
          ? detail['promoted_slug']
          : null;
        const rows = sidebar?.getRows() ?? [];
        const fallback = promotedSlug
          ?? rows.find((r) => r.is_default)?.slug
          ?? rows[0]?.slug
          ?? null;
        if (fallback !== null) {
          sidebar?.setActive(fallback);
          await loadDashboard(fallback);
        } else {
          teardownDashboard();
          activeSlug = null;
          saveActiveDashboard(null);
          titleEl.textContent = '';
          refreshAllBtn.disabled = true;
          renderEmptyMain();
        }
        return;
      }
      default:
        return; // unknown side effect → ignore (forward-compat).
    }
  }

  /**
   * v2.2.1 (PR-B) — rebuild the `dashboard` page context entry from the
   * currently loaded `DashboardDetail` and push it via
   * `window.Chatbot.setPageContext({dashboard: {...}})`. Matches the shape
   * the server emits in `data-dashboard-context` (DashboardController @
   * `resolveDashboardContext`). Without this call the LLM would keep
   * resolving widget titles against the snapshot taken at page load — stale
   * after every client-side switch or mutation.
   *
   * Skipped when no dashboard is active (post-delete with empty state) or
   * when the widget bundle's API is not present (standalone dashboard mode
   * with `mount_widget = false`).
   */
  function emitActivePageContext(): void {
    if (currentDashboard === null) return;
    if (typeof window === 'undefined') return;
    const chatbot = window.Chatbot;
    if (!chatbot || typeof chatbot.setPageContext !== 'function') return;
    const dashboard = {
      slug: currentDashboard.slug,
      name: currentDashboard.name,
      is_default: currentDashboard.is_default,
      widgets: currentDashboard.widgets.map((w) => ({
        id: w.id,
        title: w.title,
        block_type: w.block_type,
        position: w.position,
        refresh_policy: w.refresh_policy,
        last_refresh_status: w.last_refresh_status,
      })),
    };
    chatbot.setPageContext({ dashboard });
  }

  async function loadDashboard(slug: string): Promise<void> {
    teardownDashboard();
    activeSlug = slug;
    saveActiveDashboard(slug);
    sidebar?.setActive(slug);
    refreshAllBtn.disabled = true;
    titleEl.textContent = '…';

    let detail: DashboardDetail;
    try {
      detail = await api.showDashboard(slug);
    } catch (err) {
      // v2.1 (#13) — a stale localStorage slug (the dashboard was deleted in
      // another tab, or pruned) 404s here. Drop the stale key and fall back
      // to the user's default (or first) dashboard instead of dumping the
      // raw HTTP error string into the <main>.
      if (err instanceof DashboardHttpError && err.status === 404 && activeSlug === slug) {
        saveActiveDashboard(null);
        activeSlug = null;
        // The sidebar's list may still be in flight — wait for it before
        // choosing a fallback.
        await sidebar?.ready;
        if (activeSlug !== null) return; // a newer selection took over meanwhile
        const rows = sidebar?.getRows() ?? [];
        const fallback = rows.find((r) => r.is_default)
          ?? rows.find((r) => r.slug !== slug)
          ?? null;
        if (fallback) {
          sidebar?.setActive(fallback.slug);
          void loadDashboard(fallback.slug);
        } else {
          titleEl.textContent = '';
          renderEmptyMain();
        }
        return;
      }
      titleEl.textContent = '';
      renderRootError(emptyMain, err instanceof Error ? err.message : 'Failed to load dashboard');
      emptyMain.hidden = false;
      return;
    }
    if (activeSlug !== slug) return; // user switched mid-flight

    currentDashboard = detail;
    titleEl.textContent = detail.name;
    refreshAllBtn.disabled = false;
    emptyMain.hidden = true;
    mountGridAndCards(detail);
    // v2.2.1 (PR-B) — refresh `page_context.dashboard` so the LLM sees the
    // newly-loaded dashboard in the next chat turn. Closes finding #2 of the
    // v2.2.0 E2E run (page context frozen after a client-side switch).
    emitActivePageContext();
    if (detail.widgets.some((w) => w.refresh_policy === 'on_open')) {
      runBulkRefresh(slug);
    }
  }

  function teardownDashboard(): void {
    if (refreshStream) {
      try { refreshStream.abort(); } catch { /* ignore */ }
      refreshStream = null;
    }
    if (layoutFlushTimer !== null) {
      clearTimeout(layoutFlushTimer);
      layoutFlushTimer = null;
    }
    pendingLayoutChanges.clear();
    cards.forEach(({ card }) => card.destroy());
    cards.clear();
    grid?.destroy();
    grid = null;
    gridHost.innerHTML = '';
    currentDashboard = null;
  }

  function mountGridAndCards(detail: DashboardDetail): void {
    grid = opts.gridFactory ? mountGrid(gridHost, opts.gridFactory) : mountGrid(gridHost);
    grid.onLayoutChange((changes) => {
      for (const change of changes) pendingLayoutChanges.set(change.widgetId, change);
      if (layoutFlushTimer !== null) clearTimeout(layoutFlushTimer);
      layoutFlushTimer = setTimeout(() => void flushLayoutChanges(), debounceMs);
    });
    for (const widget of detail.widgets) addWidgetToGrid(widget);
  }

  function addWidgetToGrid(widget: DashboardWidget): void {
    if (grid === null) return;
    const card = mountWidgetCard({
      widget,
      labels: cardLabels,
      onRefresh: () => void refreshSingle(widget.id),
      onRemove: () => void removeWidget(widget.id),
      onRetitle: (next) => void retitleWidget(widget.id, next),
    });
    const item = grid.addWidget({
      widgetId: widget.id,
      position: widget.position,
      card: card.el,
    });
    cards.set(widget.id, { card, item });
  }

  async function flushLayoutChanges(): Promise<void> {
    layoutFlushTimer = null;
    if (activeSlug === null) {
      pendingLayoutChanges.clear();
      return;
    }
    const slug = activeSlug;
    const changes = Array.from(pendingLayoutChanges.values());
    pendingLayoutChanges.clear();
    const results = await Promise.allSettled(
      changes.map((c) => api.updateWidget(slug, c.widgetId, {
        position: { x: c.x, y: c.y, w: c.w, h: c.h },
      })),
    );
    const failed = results.filter((r) => r.status === 'rejected');
    if (failed.length > 0) {
      // The user already sees the optimistic layout; surface a single banner
      // so they know the server didn't accept the move. Re-fetch resets state
      // on the next reload — we don't try to revert mid-session because the
      // user may have made compounding moves we'd undo wrongly.
      console.error('[chatbot] layout PATCH partial failure', failed);
    }
  }

  async function refreshSingle(widgetId: number): Promise<void> {
    if (activeSlug === null) return;
    const entry = cards.get(widgetId);
    if (!entry) return;
    entry.card.setRefreshing(true);
    try {
      const fresh = await api.refreshWidget(activeSlug, widgetId);
      entry.card.update(fresh.snapshot, fresh.status, fresh.error, fresh.last_refreshed_at);
    } catch (err) {
      console.error('[chatbot] refresh widget failed', err);
    } finally {
      entry.card.setRefreshing(false);
    }
  }

  async function removeWidget(widgetId: number): Promise<void> {
    if (activeSlug === null) return;
    const entry = cards.get(widgetId);
    if (!entry) return;
    try {
      await api.deleteWidget(activeSlug, widgetId);
    } catch (err) {
      console.error('[chatbot] delete widget failed', err);
      return;
    }
    grid?.removeWidget(entry.item);
    entry.card.destroy();
    cards.delete(widgetId);
  }

  async function retitleWidget(widgetId: number, title: string | null): Promise<void> {
    if (activeSlug === null) return;
    try {
      await api.updateWidget(activeSlug, widgetId, { title });
    } catch (err) {
      console.error('[chatbot] retitle widget failed', err);
    }
  }

  function runBulkRefresh(slug: string): void {
    if (refreshStream) {
      try { refreshStream.abort(); } catch { /* ignore */ }
    }
    cards.forEach(({ card }) => card.setRefreshing(true));
    refreshAllBtn.disabled = true;
    const streamOpts: Parameters<typeof streamRefreshAll>[0] = { endpoint, slug };
    if (opts.fetcher) streamOpts.fetcher = opts.fetcher;
    refreshStream = streamRefreshAll(streamOpts, {
      onWidget: (frame) => {
        const entry = cards.get(frame.widget_id);
        if (!entry) return;
        entry.card.update(frame.snapshot, frame.status, frame.error, frame.last_refreshed_at);
        entry.card.setRefreshing(false);
      },
      onDone: () => {
        cards.forEach(({ card }) => card.setRefreshing(false));
        refreshAllBtn.disabled = false;
        refreshStream = null;
      },
      onError: (msg, code) => {
        cards.forEach(({ card }) => card.setRefreshing(false));
        refreshAllBtn.disabled = false;
        refreshStream = null;
        console.error(`[chatbot] bulk refresh failed (${code ?? 'unknown'}):`, msg);
      },
    });
  }

  async function refreshAll(slug: string): Promise<void> {
    if (currentDashboard === null) return;
    runBulkRefresh(slug);
  }

  function renderEmptyMain(): void {
    teardownDashboard();
    emptyMain.hidden = false;
    emptyMain.innerHTML = '';
    const wrap = document.createElement('div');
    wrap.className = 'cb-dashboard-main-empty-inner';
    const h2 = document.createElement('h2');
    h2.textContent = emptyMainLabel;
    const p = document.createElement('p');
    p.textContent = emptyMainHintLabel;
    wrap.append(h2, p);
    emptyMain.appendChild(wrap);
  }

  return {
    destroy(): void {
      themeWatcherTeardown();
      document.removeEventListener('chatbot:dashboard-mutation', mutationListener as EventListener);
      teardownDashboard();
      sidebar?.destroy();
      sidebar = null;
    },
  };
}

/**
 * v2.2.2 (PR-C) — resolve the effective light/dark mode from the declared
 * `data-theme` value on `#chatbot-dashboard-root`. Mirrors the widget's
 * cascade (see `resources/js/widget.ts:applyTheme()`):
 *
 *   1. Explicit `light` / `dark` always wins.
 *   2. `auto` (default) defers to `<html data-bs-theme>` when the host
 *      declares it — the canonical hook for Bootstrap 5 / Tabler /
 *      Backpack-Tabler / AdminLTE / Filament shells that ship a light/dark
 *      toggle in their topbar.
 *   3. Falls back to `prefers-color-scheme` of the OS.
 */
function resolveTheme(declared: string): 'light' | 'dark' {
  const d = declared.toLowerCase();
  if (d === 'light' || d === 'dark') return d;
  const bs = document.documentElement.getAttribute('data-bs-theme');
  if (bs === 'light' || bs === 'dark') return bs;
  if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  return 'light';
}

function applyTheme(root: HTMLElement, theme: string): void {
  root.classList.remove('cb-theme-light', 'cb-theme-dark');
  root.classList.add(resolveTheme(theme) === 'dark' ? 'cb-theme-dark' : 'cb-theme-light');
}

/**
 * v2.2.2 (PR-C) — in `auto` mode, keep the dashboard's light/dark class in
 * sync with the host toggle (`<html data-bs-theme>` flips driven by
 * Backpack-Tabler / Tabler / Bootstrap 5 color-mode scripts) and with the
 * OS-level `prefers-color-scheme` media query. In explicit `light` / `dark`
 * mode this is a no-op — the value is fixed and not meant to follow
 * anything.
 *
 * Returns a teardown function that disconnects both observers; callers must
 * invoke it on destroy() so the dashboard can be GC'd cleanly when SPA
 * frameworks remove the root.
 */
function setupThemeWatcher(root: HTMLElement, theme: string): () => void {
  const declared = theme.toLowerCase();
  if (declared !== 'auto') return () => undefined;

  const reapply = (): void => applyTheme(root, theme);
  const mo = (typeof MutationObserver !== 'undefined') ? new MutationObserver(reapply) : null;
  if (mo) {
    mo.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['data-bs-theme', 'data-theme'],
    });
  }

  let mql: MediaQueryList | null = null;
  let mqlListener: (() => void) | null = null;
  if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
    mql = window.matchMedia('(prefers-color-scheme: dark)');
    mqlListener = reapply;
    if (typeof mql.addEventListener === 'function') {
      mql.addEventListener('change', mqlListener);
    }
  }

  return () => {
    mo?.disconnect();
    if (mql !== null && mqlListener !== null && typeof mql.removeEventListener === 'function') {
      mql.removeEventListener('change', mqlListener);
    }
  };
}

function renderRootError(host: HTMLElement, message: string): void {
  const banner = document.createElement('div');
  banner.className = 'cb-dashboard-fatal';
  banner.textContent = message;
  host.appendChild(banner);
}

/**
 * v2.0 / E9 — map the snake_case keys from `chatbot::chatbot.dashboard.sidebar`
 * (PHP shape) into the camelCase `DashboardSidebarLabels` the mounter consumes.
 * Each key passes through only when it's a non-empty string; missing keys are
 * absent from the partial and `mountDashboardSidebar` falls back to its inline
 * defaults.
 */
function mapSidebarLabels(snake: Record<string, unknown>): Partial<DashboardSidebarLabels> {
  const out: Partial<DashboardSidebarLabels> = {};
  const map: Array<[string, keyof DashboardSidebarLabels]> = [
    ['new_cta', 'newCta'],
    ['new_placeholder', 'newPlaceholder'],
    ['create', 'create'],
    ['rename', 'rename'],
    ['delete', 'delete'],
    ['set_default', 'setDefault'],
    ['default_badge', 'defaultBadge'],
    ['empty_title', 'emptyTitle'],
    ['empty_hint', 'emptyHint'],
    ['error', 'error'],
    ['confirm_delete', 'confirmDelete'],
  ];
  for (const [src, dst] of map) {
    const v = snake[src];
    if (typeof v === 'string' && v !== '') out[dst] = v;
  }
  return out;
}

/**
 * v2.0 / E9 — same mapping as `mapSidebarLabels` for the widget-card labels.
 */
function mapCardLabels(snake: Record<string, unknown>): Partial<WidgetCardLabels> {
  const out: Partial<WidgetCardLabels> = {};
  // v2.1.3 — `view_source` and `just_now` were translated keys for affordances
  // (👁 button + "just now" header label) that no longer render. The lang
  // entries stay in `chatbot.php` for backwards compatibility with hosts that
  // published a custom translation file; they're simply unused now and can be
  // removed in a future major.
  const map: Array<[string, keyof WidgetCardLabels]> = [
    ['refresh', 'refresh'],
    ['remove', 'remove'],
    ['unauthorized', 'unauthorized'],
    ['error', 'error'],
    ['stale', 'stale'],
    ['source_missing', 'sourceMissing'],
    ['no_title', 'noTitle'],
    ['refreshing', 'refreshing'],
    ['inert_actions_hint', 'inertActionsHint'],
  ];
  for (const [src, dst] of map) {
    const v = snake[src];
    if (typeof v === 'string' && v !== '') out[dst] = v;
  }
  return out;
}
