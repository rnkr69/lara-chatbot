import { describe, expect, it, vi, beforeEach } from 'vitest';
import { mountGrid, type GridLayoutChange } from '../../../resources/js/dashboard/grid.js';
import type { GridStackNode, GridStackOptions } from 'gridstack';

interface FakeGrid {
  on: ReturnType<typeof vi.fn>;
  off: ReturnType<typeof vi.fn>;
  addWidget: ReturnType<typeof vi.fn>;
  removeWidget: ReturnType<typeof vi.fn>;
  destroy: ReturnType<typeof vi.fn>;
  /** Trigger the registered 'change' handler from the test. */
  trigger(event: string, items: GridStackNode[]): void;
}

function makeFakeGrid(): FakeGrid {
  const handlers = new Map<string, (...args: unknown[]) => void>();
  const grid: FakeGrid = {
    on: vi.fn((event: string, handler: (...args: unknown[]) => void) => {
      handlers.set(event, handler);
    }) as unknown as FakeGrid['on'],
    off: vi.fn(),
    addWidget: vi.fn(),
    removeWidget: vi.fn(),
    destroy: vi.fn(),
    trigger(event, items) {
      const h = handlers.get(event);
      if (h) h({} as Event, items);
    },
  };
  return grid;
}

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('mountGrid — wrapper around gridstack', () => {
  it('creates a .grid-stack container inside the host', () => {
    const fake = makeFakeGrid();
    const host = document.createElement('div');
    document.body.appendChild(host);
    mountGrid(host, (_el, _opts) => fake as unknown as ReturnType<typeof mountGrid> & FakeGrid);
    expect(host.querySelector('.grid-stack')).not.toBeNull();
  });

  it('passes 12 columns + drag handle config to GridStack.init', () => {
    const fake = makeFakeGrid();
    let capturedOpts: GridStackOptions | null = null;
    mountGrid(document.createElement('div'), (_el, opts) => {
      capturedOpts = opts;
      return fake as unknown as ReturnType<typeof mountGrid> & FakeGrid;
    });
    expect(capturedOpts).not.toBeNull();
    expect(capturedOpts!.column).toBe(12);
    expect(capturedOpts!.handle).toBe('.cb-dashboard-card-header');
  });

  it('addWidget wraps the card in .grid-stack-item > .grid-stack-item-content', () => {
    const fake = makeFakeGrid();
    const grid = mountGrid(document.createElement('div'), () => fake as unknown as ReturnType<typeof mountGrid> & FakeGrid);
    const card = document.createElement('div');
    card.className = 'cb-dashboard-card';
    const item = grid.addWidget({ widgetId: 7, position: { x: 1, y: 2, w: 3, h: 4 }, card });
    expect(item.className).toBe('grid-stack-item');
    expect(item.dataset['widgetId']).toBe('7');
    const content = item.querySelector('.grid-stack-item-content');
    expect(content).not.toBeNull();
    expect(content?.firstChild).toBe(card);
    expect(fake.addWidget).toHaveBeenCalledTimes(1);
    expect(fake.addWidget.mock.calls[0]?.[0]).toMatchObject({ x: 1, y: 2, w: 3, h: 4 });
  });

  it('onLayoutChange forwards gridstack change events with widgetIds extracted', () => {
    const fake = makeFakeGrid();
    const grid = mountGrid(document.createElement('div'), () => fake as unknown as ReturnType<typeof mountGrid> & FakeGrid);
    const received: GridLayoutChange[][] = [];
    grid.onLayoutChange((changes) => received.push(changes));

    const fakeEl = document.createElement('div');
    fakeEl.dataset['widgetId'] = '42';
    const node: GridStackNode = { x: 0, y: 1, w: 4, h: 2, el: fakeEl } as GridStackNode;
    fake.trigger('change', [node]);

    expect(received).toHaveLength(1);
    expect(received[0]).toEqual([{ widgetId: 42, x: 0, y: 1, w: 4, h: 2 }]);
  });

  it('drops change events for nodes that lack a numeric widgetId', () => {
    const fake = makeFakeGrid();
    const grid = mountGrid(document.createElement('div'), () => fake as unknown as ReturnType<typeof mountGrid> & FakeGrid);
    const received: GridLayoutChange[][] = [];
    grid.onLayoutChange((changes) => received.push(changes));

    const fakeEl = document.createElement('div'); // no data-widget-id
    fake.trigger('change', [{ x: 0, y: 0, w: 1, h: 1, el: fakeEl } as GridStackNode]);

    expect(received).toHaveLength(0);
  });
});
