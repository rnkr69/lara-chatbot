import { describe, expect, it, beforeEach, vi } from 'vitest';
import { mountWidgetCard } from '../../../resources/js/dashboard/widget-card.js';
import type { DashboardWidget } from '../../../resources/js/dashboard/types.js';

function makeWidget(overrides: Partial<DashboardWidget> = {}): DashboardWidget {
  return {
    id: 1,
    block_type: 'table',
    title: null,
    position: { x: 0, y: 0, w: 4, h: 2 },
    snapshot: {
      data: { rows: [{ id: 1, name: 'Alice' }, { id: 2, name: 'Bob' }] },
      captured_at: '2026-05-13T09:00:00.000Z',
    },
    source: { tool: 'list_users', args: {} },
    source_signature: 'abc',
    refresh_policy: 'on_open',
    last_refresh_status: 'fresh',
    last_refresh_error: null,
    last_refreshed_at: '2026-05-13T09:30:00.000Z',
    order_index: 0,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('mountWidgetCard — header + body + status', () => {
  it('renders the title falling back to block_type when null', () => {
    const handle = mountWidgetCard({
      widget: makeWidget(),
      onRefresh: () => undefined,
      onRemove: () => undefined,
    });
    expect(handle.el.querySelector('.cb-dashboard-card-title')?.textContent).toBe('table');
  });

  it('renders the widget title when provided', () => {
    const handle = mountWidgetCard({
      widget: makeWidget({ title: 'Pinned users' }),
      onRefresh: () => undefined,
      onRemove: () => undefined,
    });
    expect(handle.el.querySelector('.cb-dashboard-card-title')?.textContent).toBe('Pinned users');
  });

  it('keeps the title HTML attribute in sync with the real title (#12)', () => {
    // v2.1 (#12) — the `title` attr (hover tooltip) used to stay pinned to
    // the "Untitled widget" i18n fallback even for a titled widget.
    const handle = mountWidgetCard({
      widget: makeWidget({ title: 'Pinned users' }),
      onRefresh: () => undefined,
      onRemove: () => undefined,
    });
    const titleEl = handle.el.querySelector<HTMLElement>('.cb-dashboard-card-title')!;
    expect(titleEl.title).toBe('Pinned users');

    // setTitle updates both the text and the attribute…
    handle.setTitle('Renamed');
    expect(titleEl.textContent).toBe('Renamed');
    expect(titleEl.title).toBe('Renamed');

    // …and clearing the title falls back to the block_type for both.
    handle.setTitle(null);
    expect(titleEl.textContent).toBe('table');
    expect(titleEl.title).toBe('table');
  });

  it('delegates body rendering to renderBlock — table headers/rows visible', () => {
    const handle = mountWidgetCard({
      widget: makeWidget(),
      onRefresh: () => undefined,
      onRemove: () => undefined,
    });
    const table = handle.el.querySelector('table.cb-table');
    expect(table).not.toBeNull();
    const rows = table!.querySelectorAll('tbody tr');
    expect(rows.length).toBe(2);
  });

  it('hides the status pill when fresh and shows it when stale', () => {
    const handle = mountWidgetCard({
      widget: makeWidget(),
      onRefresh: () => undefined,
      onRemove: () => undefined,
    });
    const pill = handle.el.querySelector<HTMLElement>('.cb-dashboard-card-status');
    expect(pill?.hidden).toBe(true);
    handle.update(null, 'stale', null, '2026-05-13T10:00:00.000Z');
    expect(pill?.hidden).toBe(false);
    expect(pill?.textContent).toBe('Stale');
  });

  it('exposes the error banner with the server message on error status', () => {
    const handle = mountWidgetCard({
      widget: makeWidget(),
      onRefresh: () => undefined,
      onRemove: () => undefined,
    });
    handle.update(null, 'error', { category: 'runtime', message: 'boom', captured_at: null }, null);
    const banner = handle.el.querySelector<HTMLElement>('.cb-dashboard-card-error');
    expect(banner?.hidden).toBe(false);
    expect(banner?.textContent).toBe('boom');
  });

  it('setRefreshing toggles class + disables the refresh button', () => {
    const handle = mountWidgetCard({
      widget: makeWidget(),
      onRefresh: () => undefined,
      onRemove: () => undefined,
    });
    handle.setRefreshing(true);
    expect(handle.el.classList.contains('cb-dashboard-card-refreshing')).toBe(true);
    const btn = handle.el.querySelector<HTMLButtonElement>('.cb-dashboard-card-refresh');
    expect(btn?.disabled).toBe(true);
    handle.setRefreshing(false);
    expect(handle.el.classList.contains('cb-dashboard-card-refreshing')).toBe(false);
    expect(btn?.disabled).toBe(false);
  });

  it('fires onRefresh / onRemove when buttons are clicked', () => {
    const onRefresh = vi.fn();
    const onRemove = vi.fn();
    const handle = mountWidgetCard({ widget: makeWidget(), onRefresh, onRemove });
    handle.el.querySelector<HTMLButtonElement>('.cb-dashboard-card-refresh')?.click();
    handle.el.querySelector<HTMLButtonElement>('.cb-dashboard-card-remove')?.click();
    expect(onRefresh).toHaveBeenCalledTimes(1);
    expect(onRemove).toHaveBeenCalledTimes(1);
  });

  it('v2.1.3: never mounts the 👁 view-source button or its expander panel (formerly #17)', () => {
    // v2.1.3 — the debug-gated 👁 button + the `<details>` source panel that
    // #17 introduced are gone entirely; the body of the card is now just the
    // block renderer's output. Verify both DOM nodes are absent in both code
    // paths (with and without source) so any regression that reintroduces the
    // header bloat is caught here. The `debug` option no longer exists on
    // `WidgetCardOptions` — callers stopped passing it (dashboard/app.ts).
    const withSource = mountWidgetCard({
      widget: makeWidget(),
      onRefresh: () => undefined,
      onRemove: () => undefined,
    });
    expect(withSource.el.querySelector('.cb-dashboard-card-source')).toBeNull();
    expect(withSource.el.querySelector('.cb-dashboard-card-source-panel')).toBeNull();

    const withoutSource = mountWidgetCard({
      widget: makeWidget({ source: null }),
      onRefresh: () => undefined,
      onRemove: () => undefined,
    });
    expect(withoutSource.el.querySelector('.cb-dashboard-card-source')).toBeNull();
    expect(withoutSource.el.querySelector('.cb-dashboard-card-source-panel')).toBeNull();
  });

  it('v2.1.3 (#33): never mounts the .cb-dashboard-card-refreshed "just now" label', () => {
    // The relative timestamp ("just now"/"5m"/…) was suctioning ~50–60 px out
    // of the header's fixed-width budget, which was the root cause behind the
    // #32 title collapse (`h3` shrinking to one letter on a narrow card). The
    // node is gone now; this guards against a regression that re-adds it.
    const handle = mountWidgetCard({
      widget: makeWidget(),
      onRefresh: () => undefined,
      onRemove: () => undefined,
    });
    expect(handle.el.querySelector('.cb-dashboard-card-refreshed')).toBeNull();
  });

  it('fires onRetitle on blur when the title editor is committed', () => {
    const onRetitle = vi.fn();
    const handle = mountWidgetCard({
      widget: makeWidget({ title: 'old' }),
      onRefresh: () => undefined,
      onRemove: () => undefined,
      onRetitle,
    });
    const title = handle.el.querySelector<HTMLElement>('.cb-dashboard-card-title');
    title!.textContent = 'new name';
    title!.dispatchEvent(new Event('blur'));
    expect(onRetitle).toHaveBeenCalledWith('new name');
  });
});
