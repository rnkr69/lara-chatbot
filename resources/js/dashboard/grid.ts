/**
 * v2.0 / E5 — wrapper de `gridstack` para el dashboard.
 *
 * Aísla el bundle de gridstack detrás de una superficie pequeña:
 *   - `mount(host)` — `GridStack.init` con la config del paquete (12 cols).
 *   - `addWidget(card)` — envuelve la `.cb-dashboard-card` de `widget-card.ts`
 *     en `.grid-stack-item > .grid-stack-item-content` y la registra en
 *     gridstack con su `position {x,y,w,h}`. Devuelve el `.grid-stack-item`
 *     para que el caller pueda extraerlo en `remove`.
 *   - `onLayoutChange(cb)` — escucha el evento `change` y notifica con
 *     `{widgetId, x, y, w, h}` por cada nodo movido (debouncing lo hace el
 *     caller; gridstack ya agrupa cambios del mismo gesto).
 *   - `removeWidget(item)` — saca el nodo de gridstack y del DOM.
 *   - `destroy()` — `GridStack.destroy(true)`.
 *
 * Toma `GridStack` por defecto del import top-level, pero acepta `factory`
 * inyectado para Vitest (gridstack hace queries de layout que jsdom no
 * implementa). El bundle de producción usa el módulo real.
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

  // gridstack emite 'change' con nodos cuyo widgetId vive en `el.dataset.widgetId`.
  // Extraemos el id desde ahí para no acoplarnos a su API interna.
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
