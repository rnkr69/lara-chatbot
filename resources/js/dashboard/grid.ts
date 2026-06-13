/**
 * v2.0 / E5 — `gridstack` wrapper for the dashboard.
 *
 * Isolates the gridstack bundle behind a small surface:
 *   - `mount(host)` — `GridStack.init` with the package config (12 cols).
 *   - `addWidget(card)` — wraps the `.cb-dashboard-card` from `widget-card.ts`
 *     in `.grid-stack-item > .grid-stack-item-content` and registers it with
 *     gridstack at its `position {x,y,w,h}`. Returns the `.grid-stack-item`
 *     so the caller can extract it on `remove`.
 *   - `onLayoutChange(cb)` — listens for the `change` event and notifies with
 *     `{widgetId, x, y, w, h}` for each moved node (debouncing is done by the
 *     caller; gridstack already groups changes from the same gesture).
 *   - `removeWidget(item)` — removes the node from gridstack and from the DOM.
 *   - `destroy()` — `GridStack.destroy(true)`.
 *
 * Takes `GridStack` by default from the top-level import, but accepts an
 * injected `factory` for Vitest (gridstack runs layout queries that jsdom does
 * not implement). The production bundle uses the real module.
 */

import { GridStack, type GridStackNode, type GridStackOptions } from 'gridstack';

export interface GridLayoutChange {
  widgetId: number;
  x: number;
  y: number;
  w: number;
  h: number;
}

export interface AddWidgetOptions {
  widgetId: number;
  position: { x: number; y: number; w: number; h: number };
  card: HTMLElement;
}

export type GridStackFactory = (
  el: HTMLElement,
  opts: GridStackOptions,
) => Pick<GridStack, 'on' | 'off' | 'addWidget' | 'removeWidget' | 'destroy'>;

export interface GridHandle {
  addWidget(opts: AddWidgetOptions): HTMLElement;
  removeWidget(item: HTMLElement): void;
  onLayoutChange(cb: (changes: GridLayoutChange[]) => void): void;
  destroy(): void;
}

export function mountGrid(
  host: HTMLElement,
  factory: GridStackFactory = (el, opts) => GridStack.init(opts, el),
): GridHandle {
  const gridRoot = document.createElement('div');
  gridRoot.className = 'grid-stack';
  host.appendChild(gridRoot);

  const grid = factory(gridRoot, {
    column: 12,
    cellHeight: 80,
    float: false,
    margin: 8,
    handle: '.cb-dashboard-card-header',
    minRow: 1,
  });

  let listener: ((changes: GridLayoutChange[]) => void) | null = null;

  // gridstack emits 'change' with nodes whose widgetId lives in `el.dataset.widgetId`.
  // We extract the id from there so we don't couple to its internal API.
  grid.on('change', (_event, items) => {
    if (listener === null) return;
    const nodes = Array.isArray(items) ? items as GridStackNode[] : [items as GridStackNode];
    const changes: GridLayoutChange[] = [];
    for (const node of nodes) {
      if (!node || !node.el) continue;
      const raw = (node.el as HTMLElement).dataset['widgetId'];
      const widgetId = raw ? Number(raw) : NaN;
      if (!Number.isFinite(widgetId)) continue;
      changes.push({
        widgetId,
        x: Number(node.x ?? 0),
        y: Number(node.y ?? 0),
        w: Number(node.w ?? 1),
        h: Number(node.h ?? 1),
      });
    }
    if (changes.length > 0) listener(changes);
  });

  return {
    addWidget(opts): HTMLElement {
      const item = document.createElement('div');
      item.className = 'grid-stack-item';
      item.dataset['widgetId'] = String(opts.widgetId);
      const content = document.createElement('div');
      content.className = 'grid-stack-item-content';
      content.appendChild(opts.card);
      item.appendChild(content);
      // Gridstack v11 accepts `el` on the descriptor (re-uses an existing
       // node) but only types it on `GridStackNode`, not `GridStackWidget`.
       // Cast widens the parameter to match — runtime branch in gridstack.js
       // at line 351 (`if (node?.el) { el = node.el; }`) handles this path.
      grid.addWidget({
        el: item,
        id: String(opts.widgetId),
        x: opts.position.x,
        y: opts.position.y,
        w: opts.position.w,
        h: opts.position.h,
      } as GridStackNode);
      return item;
    },
    removeWidget(item): void {
      grid.removeWidget(item, true);
    },
    onLayoutChange(cb): void {
      listener = cb;
    },
    destroy(): void {
      grid.off('change');
      grid.destroy(true);
    },
  };
}
