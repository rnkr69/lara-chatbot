import { describe, expect, it, beforeEach } from 'vitest';
import { installApi } from '../../resources/js/api.js';
import { renderBlock, renderChartBlock, setChartLabels, resetChartLabels, BUILTIN_BLOCK_RENDERERS } from '../../resources/js/blocks.js';
import { renderChartBlockChartjs } from '../../resources/js/chart-default.js';

beforeEach(() => {
  delete (window as Window).Chatbot;
  delete (window as Window).__chatbot_widget_initialized__;
  // Strip any leftover host templates from previous tests so the cascade
  // doesn't accidentally pick them up.
  document.querySelectorAll('template[data-chatbot-block-template]').forEach((n) => n.remove());
  resetChartLabels();
  installApi();
});

describe('renderBlock — text & actions', () => {
  it('renders text blocks via markdown', () => {
    const node = renderBlock({ type: 'text', data: { content: '**bold** and `code`' } }, { send: () => undefined });
    expect(node.innerHTML).toContain('<strong>bold</strong>');
    expect(node.innerHTML).toContain('<code>code</code>');
  });

  it('renders actions with prompt buttons that call host.send', () => {
    const sent: string[] = [];
    const node = renderBlock(
      {
        type: 'actions',
        data: { actions: [{ label: 'Open dashboard', prompt: 'open the dashboard' }, { label: '' /* dropped */ }] },
      },
      { send: (p) => { sent.push(p); } },
    );
    const buttons = node.querySelectorAll('button');
    expect(buttons.length).toBe(1);
    buttons[0]!.click();
    expect(sent).toEqual(['open the dashboard']);
  });

  it('renders actions that invoke a registered tool', () => {
    const calls: unknown[] = [];
    window.Chatbot!.registerTool('navigate', (args) => { calls.push(args); });
    const node = renderBlock(
      {
        type: 'actions',
        data: { actions: [{ label: 'Go', tool: 'navigate', args: { url: '/x' } }] },
      },
      { send: () => undefined },
    );
    node.querySelector('button')!.click();
    expect(calls).toEqual([{ url: '/x' }]);
  });

  it('falls back to a placeholder for unknown block types', () => {
    const node = renderBlock({ type: 'sparkline', data: {} }, { send: () => undefined });
    expect(node.textContent).toContain('[unsupported block: sparkline]');
  });

  it('uses a host-registered renderer for custom block types', () => {
    window.Chatbot!.registerBlockRenderer('chart', (data) => {
      const el = document.createElement('div');
      el.dataset['t'] = JSON.stringify(data);
      return el;
    });
    const node = renderBlock({ type: 'chart', data: { labels: ['a'] } }, { send: () => undefined });
    expect(node.dataset['t']).toBe('{"labels":["a"]}');
  });
});

describe('renderBlock — card', () => {
  it('renders title, subtitle, description and field rows', () => {
    const node = renderBlock(
      {
        type: 'card',
        data: {
          title: 'Order #142',
          subtitle: 'Pending shipment',
          description: 'Estimated delivery **next week**.',
          fields: [
            { label: 'Customer', value: 'Acme Inc.' },
            { label: 'Total', value: 1234.5 },
          ],
        },
      },
      { send: () => undefined },
    );
    expect(node.querySelector('.cb-card-title')!.textContent).toBe('Order #142');
    expect(node.querySelector('.cb-card-subtitle')!.textContent).toBe('Pending shipment');
    const dts = node.querySelectorAll('dt');
    const dds = node.querySelectorAll('dd');
    expect(dts.length).toBe(2);
    expect(dts[0]!.textContent).toBe('Customer');
    expect(dds[1]!.textContent).toBe('1234.5');
    expect(node.querySelector('.cb-card-description')!.innerHTML).toContain('<strong>next week</strong>');
  });

  it('omits sections when fields are empty/missing', () => {
    const node = renderBlock({ type: 'card', data: { title: 'Bare' } }, { send: () => undefined });
    expect(node.querySelector('.cb-card-title')!.textContent).toBe('Bare');
    expect(node.querySelector('.cb-card-subtitle')).toBeNull();
    expect(node.querySelector('.cb-card-description')).toBeNull();
    expect(node.querySelector('dl')).toBeNull();
  });

  it('renders nested action buttons and bubbles host.send', () => {
    const sent: string[] = [];
    const node = renderBlock(
      {
        type: 'card',
        data: {
          title: 'Pick one',
          actions: [{ label: 'Yes', prompt: 'confirm' }],
        },
      },
      { send: (p) => { sent.push(p); } },
    );
    const btn = node.querySelector('.cb-card .actions button') as HTMLButtonElement;
    btn.click();
    expect(sent).toEqual(['confirm']);
  });
});

describe('renderBlock — table', () => {
  it('renders explicit columns + rows of objects', () => {
    const node = renderBlock(
      {
        type: 'table',
        data: {
          caption: 'Recent orders',
          columns: [
            { key: 'id', label: 'ID' },
            { key: 'total', label: 'Total' },
          ],
          rows: [
            { id: 1, total: 99 },
            { id: 2, total: 250 },
            { id: 3, total: 12.5 },
          ],
        },
      },
      { send: () => undefined },
    );
    expect(node.querySelector('caption')!.textContent).toBe('Recent orders');
    const headers = Array.from(node.querySelectorAll('thead th')).map((th) => th.textContent);
    expect(headers).toEqual(['ID', 'Total']);
    const rows = node.querySelectorAll('tbody tr');
    expect(rows.length).toBe(3);
    const lastCells = Array.from(rows[2]!.querySelectorAll('td')).map((td) => td.textContent);
    expect(lastCells).toEqual(['3', '12.5']);
  });

  it('infers columns from the first row when columns are missing', () => {
    const node = renderBlock(
      {
        type: 'table',
        data: { rows: [{ name: 'Ada', role: 'Admin' }, { name: 'Linus', role: 'User' }] },
      },
      { send: () => undefined },
    );
    const headers = Array.from(node.querySelectorAll('thead th')).map((th) => th.textContent);
    expect(headers).toEqual(['name', 'role']);
    const firstRow = Array.from(node.querySelectorAll('tbody tr')[0]!.querySelectorAll('td')).map((td) => td.textContent);
    expect(firstRow).toEqual(['Ada', 'Admin']);
  });

  it('shows an empty hint when there are no rows', () => {
    const node = renderBlock({ type: 'table', data: { rows: [] } }, { send: () => undefined });
    expect(node.querySelector('.cb-table-empty')!.textContent).toBe('No rows.');
  });

  it('handles array rows when columns are given by index', () => {
    const node = renderBlock(
      {
        type: 'table',
        data: {
          columns: ['name', 'count'],
          rows: [['A', 1], ['B', 2]],
        },
      },
      { send: () => undefined },
    );
    const headers = Array.from(node.querySelectorAll('thead th')).map((th) => th.textContent);
    expect(headers).toEqual(['name', 'count']);
    const rows = node.querySelectorAll('tbody tr');
    expect(rows[1]!.querySelectorAll('td')[1]!.textContent).toBe('2');
  });
});

describe('renderBlock — list', () => {
  it('renders a plain unordered list of strings', () => {
    const node = renderBlock(
      { type: 'list', data: { title: 'Steps', items: ['One', 'Two', 'Three'] } },
      { send: () => undefined },
    );
    expect(node.querySelector('.cb-list-title')!.textContent).toBe('Steps');
    expect(node.querySelector('ul')).toBeTruthy();
    const lis = node.querySelectorAll('li');
    expect(lis.length).toBe(3);
    expect(lis[1]!.textContent).toBe('Two');
  });

  it('uses an ordered list when ordered=true', () => {
    const node = renderBlock(
      { type: 'list', data: { ordered: true, items: ['A', 'B'] } },
      { send: () => undefined },
    );
    expect(node.querySelector('ol')).toBeTruthy();
    expect(node.querySelector('ul')).toBeNull();
  });

  it('renders item-as-button when prompt or tool is provided', () => {
    const sent: string[] = [];
    const calls: unknown[] = [];
    window.Chatbot!.registerTool('jump', (a) => { calls.push(a); });
    const node = renderBlock(
      {
        type: 'list',
        data: {
          items: [
            { text: 'Open A', prompt: 'open A' },
            { text: 'Run B', tool: 'jump', args: { id: 7 } },
            { label: 'C only label, plain' },
          ],
        },
      },
      { send: (p) => { sent.push(p); } },
    );
    const buttons = node.querySelectorAll('button');
    expect(buttons.length).toBe(2);
    buttons[0]!.click();
    buttons[1]!.click();
    expect(sent).toEqual(['open A']);
    expect(calls).toEqual([{ id: 7 }]);
    // Third item rendered as plain text (no button).
    const items = node.querySelectorAll('li');
    expect(items[2]!.querySelector('button')).toBeNull();
    expect(items[2]!.textContent).toBe('C only label, plain');
  });
});

describe('chart renderer unification (v0.4.4)', () => {
  it('wires Chart.js as the core built-in chart renderer (same on every surface)', () => {
    // The widget, the /chatbot page and the dashboard all render blocks via
    // renderBlock() → BUILTIN_BLOCK_RENDERERS. Asserting the built-in `chart`
    // entry IS renderChartBlockChartjs proves charts render identically
    // everywhere — no placeholder in the widget anymore.
    expect(BUILTIN_BLOCK_RENDERERS['chart']).toBe(renderChartBlockChartjs);
  });
});

describe('renderBlock — chart (Chart.js built-in)', () => {
  it('renders a Chart.js canvas for a valid chart — the built-in renderer, not a placeholder', () => {
    // v0.4.4 — Chart.js is the CORE built-in for `chart`, so the widget bundle
    // (this test imports blocks.ts directly, no dashboard) renders a real chart.
    // We assert the synchronous output (the `.cb-chart-chartjs` canvas wrapper);
    // Chart.js construction is deferred to a microtask, and in jsdom — which has
    // no canvas — it would throw and swap in the placeholder, so we do NOT flush
    // microtasks here. The real-draw smoke test lives in chart-default.test.ts
    // (mocked Chart) and Playwright (real canvas).
    const node = renderBlock(
      { type: 'chart', data: { kind: 'bar', labels: ['Q1', 'Q2', 'Q3'], series: [1, 2, 3], title: 'Revenue' } },
      { send: () => undefined },
    );
    expect(node.classList.contains('cb-chart-chartjs')).toBe(true);
    expect(node.querySelector('canvas')).not.toBeNull();
    expect(node.querySelector('.cb-chart-title')!.textContent).toBe('Revenue');
    // Not the placeholder: no "not registered" / "invalid" note.
    expect(node.querySelector('.cb-chart-note')).toBeNull();
  });

  it('falls back to the invalid-data placeholder for a chart with no usable type', () => {
    const node = renderBlock(
      { type: 'chart', data: { title: 'Revenue', series: [1, 2, 3] } }, // no type/kind → invalid
      { send: () => undefined },
    );
    expect(node.querySelector('.cb-chart-title')!.textContent).toBe('Revenue');
    const note = node.querySelector('.cb-chart-note')!.textContent ?? '';
    expect(note).toBe('Chart data is invalid or incomplete.');
    expect(note).not.toContain('not registered');
    expect(node.querySelector('pre')!.textContent).toBe('[\n  1,\n  2,\n  3\n]');
  });
});

describe('renderBlock — cascade order', () => {
  it('uses the host JS renderer over the slot template and the builtin', () => {
    // Place a host template that would otherwise be picked up.
    const tpl = document.createElement('template');
    tpl.setAttribute('data-chatbot-block-template', 'card');
    tpl.innerHTML = '<div class="from-template" data-bind="title"></div>';
    document.body.appendChild(tpl);

    window.Chatbot!.registerBlockRenderer('card', () => {
      const el = document.createElement('div');
      el.className = 'from-renderer';
      return el;
    });

    const node = renderBlock(
      { type: 'card', data: { title: 'X' } },
      { send: () => undefined },
    );

    expect(node.classList.contains('from-renderer')).toBe(true);
    expect(node.querySelector('.from-template')).toBeNull();

    tpl.remove();
  });

  it('uses the slot template over the builtin', () => {
    const tpl = document.createElement('template');
    tpl.setAttribute('data-chatbot-block-template', 'card');
    tpl.innerHTML = `
      <article class="host-card">
        <h2 data-bind="title"></h2>
        <p data-bind="description"></p>
      </article>
    `.trim();
    document.body.appendChild(tpl);

    const node = renderBlock(
      { type: 'card', data: { title: 'Order 99', description: 'Ready' } },
      { send: () => undefined },
    );

    expect(node.querySelector('.host-card, h2, p')).toBeTruthy();
    expect(node.querySelector('h2')!.textContent).toBe('Order 99');
    expect(node.querySelector('p')!.textContent).toBe('Ready');
    // Builtin classes should NOT be present (no .cb-card-title from the builtin).
    expect(node.querySelector('.cb-card-title')).toBeNull();

    tpl.remove();
  });

  it('falls back to builtin when neither registered renderer nor template exists', () => {
    const node = renderBlock({ type: 'card', data: { title: 'Built-in' } }, { send: () => undefined });
    expect(node.querySelector('.cb-card-title')!.textContent).toBe('Built-in');
  });

  it('falls through to the cascade if a host renderer throws', () => {
    window.Chatbot!.registerBlockRenderer('card', () => {
      throw new Error('boom');
    });
    const errors: unknown[] = [];
    const original = console.error;
    console.error = (...args: unknown[]) => { errors.push(args); };
    try {
      const node = renderBlock({ type: 'card', data: { title: 'After throw' } }, { send: () => undefined });
      expect(node.querySelector('.cb-card-title')!.textContent).toBe('After throw');
    } finally {
      console.error = original;
    }
    expect(errors.length).toBeGreaterThan(0);
  });
});

describe('renderBlock — v1.1 alias resolution & meta.customError (findings #5/#6)', () => {
  it('renders a card from `header` alias when the LLM forgets `title`', () => {
    const warns: unknown[] = [];
    const original = console.warn;
    console.warn = (...args: unknown[]) => { warns.push(args); };
    try {
      const node = renderBlock(
        { type: 'card', data: { header: 'Mission #25 — Aurora', description: 'on its way' } },
        { send: () => undefined },
      );
      expect(node.querySelector('.cb-card-title')!.textContent).toBe('Mission #25 — Aurora');
    } finally {
      console.warn = original;
    }
    // Warning fired so the host operator can tighten the prompt.
    expect(warns.length).toBeGreaterThan(0);
  });

  it('renders a placeholder when no recognised content keys are present', () => {
    const warns: unknown[] = [];
    const original = console.warn;
    console.warn = (...args: unknown[]) => { warns.push(args); };
    try {
      const node = renderBlock(
        { type: 'card', data: { not_a_known_key: 'x' } },
        { send: () => undefined },
      );
      expect(node.classList.contains('cb-block-invalid')).toBe(true);
      expect(node.textContent).toContain('[card: invalid');
    } finally {
      console.warn = original;
    }
    expect(warns.length).toBeGreaterThan(0);
  });

  it('renders a table from `items` alias when the LLM forgets `rows`', () => {
    const warns: unknown[] = [];
    const original = console.warn;
    console.warn = (...args: unknown[]) => { warns.push(args); };
    try {
      const node = renderBlock(
        { type: 'table', data: { items: [{ id: 1, name: 'A' }, { id: 2, name: 'B' }] } },
        { send: () => undefined },
      );
      const headers = Array.from(node.querySelectorAll('thead th')).map((th) => th.textContent);
      expect(headers).toEqual(['id', 'name']);
      expect(node.querySelectorAll('tbody tr').length).toBe(2);
    } finally {
      console.warn = original;
    }
    expect(warns.length).toBeGreaterThan(0);
  });

  it('chart fallback distinguishes "invalid data" from "renderer threw" (never "not registered")', () => {
    // Case A: invalid chart data (no type) → the built-in Chart.js renderer
    // delegates to the placeholder with invalidData. Since v0.4.4 a renderer is
    // ALWAYS registered, so the message must never say "not registered".
    const invalid = renderBlock({ type: 'chart', data: { title: 'A' } }, { send: () => undefined });
    const noteA = invalid.querySelector('.cb-chart-note')!.textContent ?? '';
    expect(noteA).toBe('Chart data is invalid or incomplete.');
    expect(noteA).not.toContain('not registered');

    // Case B: a host override throws → renderBlock falls through to the built-in
    // with meta.customError, which surfaces the throw via the placeholder.
    window.Chatbot!.registerBlockRenderer('chart', () => { throw new Error('signature mismatch'); });
    const original = console.error;
    console.error = () => undefined; // silence the captured error log
    try {
      const threw = renderBlock({ type: 'chart', data: { title: 'B' } }, { send: () => undefined });
      const note = threw.querySelector('.cb-chart-note')!.textContent ?? '';
      expect(note).toContain('Chart renderer threw');
      expect(note).toContain('signature mismatch');
      expect(note).not.toContain('not registered');
    } finally {
      console.error = original;
    }
  });

  it('chart placeholder says "invalid data" — not "not registered" — when meta.invalidData is set (#25)', () => {
    // A registered renderer (chart-default.ts) rejected the data and fell back
    // here. The renderer IS registered — the message must not claim otherwise.
    const node = renderChartBlock({ title: 'C' }, { send: () => undefined }, { invalidData: true });
    const note = node.querySelector('.cb-chart-note')!.textContent ?? '';
    expect(note).toBe('Chart data is invalid or incomplete.');
    expect(note).not.toContain('not registered');
  });

  it('setChartLabels overrides the invalid-data placeholder message (#25)', () => {
    setChartLabels({ invalid_data: 'Invalid chart data.' });
    const node = renderChartBlock({ title: 'D' }, { send: () => undefined }, { invalidData: true });
    expect(node.querySelector('.cb-chart-note')!.textContent).toBe('Invalid chart data.');
  });
});

describe('renderBlock — v2.1 / #19 Bootstrap host-native classes', () => {
  // The renderers ALWAYS emit Bootstrap classes alongside the `.cb-*` ones.
  // Where Bootstrap is absent (the widget's shadow DOM, the standalone
  // dashboard) they match nothing; where the host's Bootstrap is loaded
  // (dashboard in layout mode) it owns the look and the bundle skips its own
  // block CSS. So the contract is purely: the classes must be present.
  it('table carries Bootstrap classes alongside the .cb-* ones', () => {
    const node = renderBlock(
      { type: 'table', data: { rows: [{ id: 1 }] } },
      { send: () => undefined },
    );
    expect(node.classList.contains('cb-table-wrapper')).toBe(true);
    expect(node.classList.contains('table-responsive')).toBe(true);
    const table = node.querySelector('table')!;
    expect(table.classList.contains('cb-table')).toBe(true);
    for (const c of ['table', 'table-sm', 'table-striped', 'table-hover']) {
      expect(table.classList.contains(c)).toBe(true);
    }
  });

  it('card carries Bootstrap classes and a .card-body content wrapper', () => {
    const node = renderBlock(
      { type: 'card', data: { title: 'T', subtitle: 'S', description: 'D' } },
      { send: () => undefined },
    );
    expect(node.classList.contains('cb-card')).toBe(true);
    expect(node.classList.contains('card')).toBe(true);
    const body = node.querySelector('.cb-card-body')!;
    expect(body.classList.contains('card-body')).toBe(true);
    expect(node.querySelector('.cb-card-title')!.classList.contains('card-title')).toBe(true);
    expect(node.querySelector('.cb-card-subtitle')!.classList.contains('card-subtitle')).toBe(true);
    expect(node.querySelector('.cb-card-description')!.classList.contains('card-text')).toBe(true);
    // The content lives inside the body wrapper, not directly under .cb-card.
    expect(body.querySelector('.cb-card-title')).not.toBeNull();
  });

  it('list carries Bootstrap list-group classes', () => {
    const node = renderBlock(
      { type: 'list', data: { items: ['One', 'Two'] } },
      { send: () => undefined },
    );
    const list = node.querySelector('ul')!;
    expect(list.classList.contains('cb-list-items')).toBe(true);
    expect(list.classList.contains('list-group')).toBe(true);
    expect(list.classList.contains('list-group-flush')).toBe(true);
    const li = node.querySelector('li')!;
    expect(li.classList.contains('cb-list-item')).toBe(true);
    expect(li.classList.contains('list-group-item')).toBe(true);
  });
});
