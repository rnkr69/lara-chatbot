/**
 * v2.0 / E5 — sidebar of the user's dashboards.
 *
 * Same shape as `resources/js/sidebar.ts` (the conversations sidebar) but over
 * `DashboardApi`. Encapsulates the list + create + inline rename + delete
 * (native `confirm()`) + set-default.
 *
 * Empty state: inline CTA with input + "Create" (user's choice, not a modal).
 *
 * The sidebar host receives only `onSelect(slug)` + `onActiveDeleted()` and
 * `onChanged()` so the app can re-query the list if it needs to (e.g. after a
 * set-default that reorders is_default).
 */

import type { DashboardApi } from './api.js';
import type { DashboardRow } from './types.js';

export interface DashboardSidebarLabels {
  newCta: string;
  newPlaceholder: string;
  create: string;
  rename: string;
  delete: string;
  setDefault: string;
  defaultBadge: string;
  emptyTitle: string;
  emptyHint: string;
  error: string;
  confirmDelete: string;
  searchPlaceholder?: string;
}

const DEFAULT_LABELS: DashboardSidebarLabels = {
  newCta: '+ New dashboard',
  newPlaceholder: 'Name…',
  create: 'Create',
  rename: 'Rename',
  delete: 'Delete',
  setDefault: 'Make default',
  defaultBadge: 'default',
  emptyTitle: 'No dashboards yet',
  emptyHint: 'Create one to start pinning blocks.',
  error: 'Failed to update',
  confirmDelete: 'Delete this dashboard? Widgets will be removed.',
};

/**
 * v2.1.1 (#23) — defensive: collapse a trailing run of periods to a single
 * one before feeding the delete confirmation to the native `confirm()`. The
 * package neither concatenates a period nor ships a double-period string, so
 * this is not reproducible from the package alone — but a malformed i18n
 * override on a host could produce "…will be removed.." which looks broken.
 */
function normalizeConfirmMessage(msg: string): string {
  return msg.replace(/\.{2,}$/, '.');
}

export interface DashboardSidebarOptions {
  api: DashboardApi;
  activeSlug?: string | null;
  labels?: Partial<DashboardSidebarLabels>;
  onSelect(slug: string): void;
  onActiveDeleted?(): void;
  /**
   * Called after the sidebar mutates rows (create / rename / set-default /
   * delete). `active` is the row currently marked active, or null. v2.1 (#9):
   * a rename of the active dashboard re-derives its slug AND changes its
   * name — the host reads `active` to keep its own state, the `<h1>` and
   * localStorage in sync.
   */
  onChanged?(active: DashboardRow | null): void;
  /** Injectable for tests. */
  confirmer?: (message: string) => boolean;
}

export interface DashboardSidebarHandle {
  /**
   * v2.1 (#14) — resolves once the constructor-triggered initial
   * `listDashboards()` has settled (success or handled error). The host
   * awaits this to auto-select a dashboard without racing a fixed timeout.
   */
  ready: Promise<void>;
  /** Refreshes the list via API. */
  refresh(): Promise<void>;
  /** Marks another slug as active (does not call `onSelect`). */
  setActive(slug: string | null): void;
  /** Returns the last loaded list (defensive for tests/app). */
  getRows(): readonly DashboardRow[];
  destroy(): void;
}

export function mountDashboardSidebar(
  host: HTMLElement,
  opts: DashboardSidebarOptions,
): DashboardSidebarHandle {
  const labels = { ...DEFAULT_LABELS, ...(opts.labels ?? {}) };
  const confirmer = opts.confirmer ?? ((m: string) => window.confirm(m));

  let activeSlug: string | null = opts.activeSlug ?? null;
  let rows: DashboardRow[] = [];
  let destroyed = false;

  /** The row currently marked active, or null — passed to `onChanged`. */
  const activeRow = (): DashboardRow | null =>
    rows.find((r) => r.slug === activeSlug) ?? null;

  const root = document.createElement('aside');
  root.className = 'cb-dashboard-sidebar';
  root.setAttribute('aria-label', 'Dashboards');

  // ── Create form (always visible; doubles as empty-state CTA) ──────────
  const createWrap = document.createElement('form');
  createWrap.className = 'cb-dashboard-sidebar-new';
  const createInput = document.createElement('input');
  createInput.type = 'text';
  createInput.className = 'cb-dashboard-sidebar-new-input';
  createInput.placeholder = labels.newPlaceholder;
  createInput.maxLength = 120;
  // v2.1.1 (#24) — a form field needs an id or name (Chrome DevTools issue);
  // the aria-label gives it an accessible name (it has only a placeholder).
  createInput.name = 'chatbot_dashboard_name';
  createInput.setAttribute('aria-label', labels.newPlaceholder);
  const createBtn = document.createElement('button');
  createBtn.type = 'submit';
  createBtn.className = 'cb-dashboard-sidebar-new-btn';
  createBtn.textContent = labels.create;
  createWrap.append(createInput, createBtn);
  createWrap.addEventListener('submit', (e) => {
    e.preventDefault();
    void handleCreate();
  });
  root.appendChild(createWrap);

  const listEl = document.createElement('ul');
  listEl.className = 'cb-dashboard-sidebar-list';
  root.appendChild(listEl);

  const emptyEl = document.createElement('div');
  emptyEl.className = 'cb-dashboard-sidebar-empty';
  const emptyTitle = document.createElement('strong');
  emptyTitle.textContent = labels.emptyTitle;
  const emptyHint = document.createElement('p');
  emptyHint.textContent = labels.emptyHint;
  emptyEl.append(emptyTitle, emptyHint);
  emptyEl.hidden = true;
  root.appendChild(emptyEl);

  const errorEl = document.createElement('div');
  errorEl.className = 'cb-dashboard-sidebar-error';
  errorEl.hidden = true;
  root.appendChild(errorEl);

  host.appendChild(root);

  async function handleCreate(): Promise<void> {
    const name = createInput.value.trim();
    if (name === '') return;
    createBtn.disabled = true;
    try {
      const row = await opts.api.createDashboard({ name });
      createInput.value = '';
      await load();
      activeSlug = row.slug;
      renderRows();
      opts.onSelect(row.slug);
      opts.onChanged?.(activeRow());
    } catch (err) {
      showError(err instanceof Error ? err.message : labels.error);
    } finally {
      createBtn.disabled = false;
    }
  }

  async function load(): Promise<void> {
    if (destroyed) return;
    errorEl.hidden = true;
    try {
      rows = await opts.api.listDashboards();
      if (destroyed) return;
      renderRows();
    } catch (err) {
      if (destroyed) return;
      showError(err instanceof Error ? err.message : labels.error);
      rows = [];
      renderRows();
    }
  }

  function renderRows(): void {
    listEl.innerHTML = '';
    if (rows.length === 0) {
      emptyEl.hidden = false;
      return;
    }
    emptyEl.hidden = true;
    for (const row of rows) listEl.appendChild(renderRow(row));
  }

  function renderRow(row: DashboardRow): HTMLElement {
    const item = document.createElement('li');
    item.className = 'cb-dashboard-sidebar-item';
    item.dataset['slug'] = row.slug;
    if (activeSlug !== null && row.slug === activeSlug) {
      item.classList.add('cb-dashboard-sidebar-item-active');
      item.setAttribute('aria-current', 'true');
    }

    const main = document.createElement('button');
    main.type = 'button';
    main.className = 'cb-dashboard-sidebar-item-main';
    const title = document.createElement('span');
    title.className = 'cb-dashboard-sidebar-item-title';
    title.textContent = row.name;
    main.appendChild(title);
    if (row.is_default) {
      const badge = document.createElement('span');
      badge.className = 'cb-dashboard-sidebar-item-default';
      badge.textContent = labels.defaultBadge;
      main.appendChild(badge);
    }
    if (typeof row.widget_count === 'number') {
      const count = document.createElement('span');
      count.className = 'cb-dashboard-sidebar-item-count';
      count.textContent = String(row.widget_count);
      main.appendChild(count);
    }
    main.addEventListener('click', () => {
      setActive(row.slug);
      opts.onSelect(row.slug);
    });
    item.appendChild(main);

    const renameBtn = document.createElement('button');
    renameBtn.type = 'button';
    renameBtn.className = 'cb-dashboard-sidebar-item-action cb-dashboard-sidebar-item-rename';
    renameBtn.setAttribute('aria-label', labels.rename);
    renameBtn.title = labels.rename;
    renameBtn.textContent = '✎';
    renameBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      startRename(item, row);
    });
    item.appendChild(renameBtn);

    if (!row.is_default) {
      const setDefaultBtn = document.createElement('button');
      setDefaultBtn.type = 'button';
      setDefaultBtn.className = 'cb-dashboard-sidebar-item-action cb-dashboard-sidebar-item-default-action';
      setDefaultBtn.setAttribute('aria-label', labels.setDefault);
      setDefaultBtn.title = labels.setDefault;
      setDefaultBtn.textContent = '★';
      setDefaultBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        void handleSetDefault(row);
      });
      item.appendChild(setDefaultBtn);
    }

    const deleteBtn = document.createElement('button');
    deleteBtn.type = 'button';
    deleteBtn.className = 'cb-dashboard-sidebar-item-action cb-dashboard-sidebar-item-delete';
    deleteBtn.setAttribute('aria-label', labels.delete);
    deleteBtn.title = labels.delete;
    deleteBtn.textContent = '✕';
    deleteBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      void handleDelete(row);
    });
    item.appendChild(deleteBtn);

    return item;
  }

  function startRename(item: HTMLElement, row: DashboardRow): void {
    const titleSpan = item.querySelector<HTMLElement>('.cb-dashboard-sidebar-item-title');
    if (!titleSpan) return;
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'cb-dashboard-sidebar-rename-input';
    input.value = row.name;
    input.maxLength = 120;
    // v2.1.1 (#24) — id/name + accessible name for the inline rename field.
    input.name = 'chatbot_dashboard_rename';
    input.setAttribute('aria-label', labels.rename);
    titleSpan.replaceWith(input);
    input.focus();
    input.select();

    let committed = false;
    const commit = async (save: boolean): Promise<void> => {
      if (committed) return;
      committed = true;
      const newName = input.value.trim();
      const restoreSpan = document.createElement('span');
      restoreSpan.className = 'cb-dashboard-sidebar-item-title';
      restoreSpan.textContent = row.name;
      input.replaceWith(restoreSpan);
      if (!save || newName === '' || newName === row.name) return;
      try {
        const updated = await opts.api.updateDashboard(row.slug, { name: newName });
        // Server re-derives the slug; rebuild the row in-place.
        const idx = rows.findIndex((r) => r.slug === row.slug);
        if (idx !== -1) rows[idx] = { ...rows[idx], ...updated };
        if (activeSlug === row.slug && updated.slug !== row.slug) {
          activeSlug = updated.slug;
        }
        renderRows();
        opts.onChanged?.(activeRow());
      } catch (err) {
        showError(err instanceof Error ? err.message : labels.error);
      }
    };
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); void commit(true); }
      else if (e.key === 'Escape') { e.preventDefault(); void commit(false); }
    });
    input.addEventListener('blur', () => { void commit(true); });
  }

  async function handleSetDefault(row: DashboardRow): Promise<void> {
    try {
      await opts.api.updateDashboard(row.slug, { is_default: true });
      // Toggle is_default flag locally — server promotes one, demotes the rest.
      rows = rows.map((r) => ({ ...r, is_default: r.slug === row.slug }));
      renderRows();
      opts.onChanged?.(activeRow());
    } catch (err) {
      showError(err instanceof Error ? err.message : labels.error);
    }
  }

  async function handleDelete(row: DashboardRow): Promise<void> {
    if (!confirmer(normalizeConfirmMessage(labels.confirmDelete))) return;
    const wasActive = activeSlug === row.slug;
    try {
      await opts.api.deleteDashboard(row.slug);
      rows = rows.filter((r) => r.slug !== row.slug);
      if (wasActive) {
        activeSlug = null;
        opts.onActiveDeleted?.();
      }
      renderRows();
      opts.onChanged?.(activeRow());
    } catch (err) {
      showError(err instanceof Error ? err.message : labels.error);
    }
  }

  function setActive(slug: string | null): void {
    activeSlug = slug;
    listEl.querySelectorAll<HTMLElement>('.cb-dashboard-sidebar-item').forEach((el) => {
      const match = slug !== null && el.dataset['slug'] === slug;
      el.classList.toggle('cb-dashboard-sidebar-item-active', match);
      if (match) el.setAttribute('aria-current', 'true');
      else el.removeAttribute('aria-current');
    });
  }

  function showError(msg: string): void {
    errorEl.textContent = msg;
    errorEl.hidden = false;
  }

  // v2.1 (#14) — keep a handle on the initial load so the host can await it
  // (`load()` already swallows its own errors, so this never rejects).
  const ready = load();

  return {
    ready,
    refresh: () => load(),
    setActive,
    getRows: () => rows,
    destroy(): void {
      destroyed = true;
      root.remove();
    },
  };
}
