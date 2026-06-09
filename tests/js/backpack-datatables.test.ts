import { describe, expect, it, beforeEach, afterEach, vi } from 'vitest';
import {
  collectSelectedIds,
  decorateCrudTables,
  extractRowId,
  installBackpackBulkSelectionSync,
  installBackpackDataTablesDecoration,
  isDtDecorationEnabled,
  isDtSelectedSyncEnabled,
  __resetForTests,
} from '../../resources/js/backpack-datatables.js';

beforeEach(() => {
  document.head.innerHTML = '';
  document.body.innerHTML = '';
  __resetForTests();
});

afterEach(() => {
  delete (window as unknown as { Chatbot?: unknown }).Chatbot;
});

describe('isDtDecorationEnabled', () => {
  it('returns false when the meta tag is absent', () => {
    expect(isDtDecorationEnabled()).toBe(false);
  });

  it('returns true when the meta payload opts in', () => {
    document.head.innerHTML =
      "<meta name=\"chatbot:options\" content='{\"backpack\":{\"dt_row_decoration\":true}}'>";
    expect(isDtDecorationEnabled()).toBe(true);
  });

  it('returns false when the meta payload opts out', () => {
    document.head.innerHTML =
      "<meta name=\"chatbot:options\" content='{\"backpack\":{\"dt_row_decoration\":false}}'>";
    expect(isDtDecorationEnabled()).toBe(false);
  });

  it('returns false on malformed JSON', () => {
    document.head.innerHTML =
      "<meta name=\"chatbot:options\" content='{not json'>";
    expect(isDtDecorationEnabled()).toBe(false);
  });
});

describe('extractRowId', () => {
  function row(html: string): HTMLElement {
    const tbl = document.createElement('table');
    tbl.innerHTML = `<tbody><tr>${html}</tr></tbody>`;
    return tbl.querySelector('tr') as HTMLElement;
  }

  it('reads the id from a /show link', () => {
    expect(extractRowId(row('<td><a href="/admin/mission/42/show">view</a></td>'))).toBe('42');
  });

  it('falls back to /edit when /show is missing', () => {
    expect(extractRowId(row('<td><a href="/admin/mission/15/edit">edit</a></td>'))).toBe('15');
  });

  it('returns null when no link matches', () => {
    expect(extractRowId(row('<td><a href="/admin/mission">list</a></td>'))).toBeNull();
  });

  it('handles trailing querystring fragments', () => {
    expect(extractRowId(row('<td><a href="/admin/mission/7/show?from=grid">v</a></td>'))).toBe('7');
  });
});

describe('decorateCrudTables', () => {
  it('decorates rows in #crudTable with data-chatbot-row-id from the show link', () => {
    document.body.innerHTML = `
      <table id="crudTable">
        <tbody>
          <tr><td>92</td><td><a href="/admin/mission/92/show"></a></td></tr>
          <tr><td>85</td><td><a href="/admin/mission/85/show"></a></td></tr>
        </tbody>
      </table>`;

    decorateCrudTables();

    const rows = document.querySelectorAll('#crudTable tbody tr[data-chatbot-row-id]');
    expect(rows.length).toBe(2);
    expect(rows[0].getAttribute('data-chatbot-row-id')).toBe('92');
    expect(rows[1].getAttribute('data-chatbot-row-id')).toBe('85');
  });

  it('decorates the FIRST row even when the first cell is a checkbox+dtr-control (finding #20)', () => {
    // This is the exact failure mode the host hit: the first <td> is a
    // checkbox + DataTables responsive expander, so textContent is empty.
    // Parsing the preview link sidesteps that.
    document.body.innerHTML = `
      <table id="crudTable">
        <tbody>
          <tr>
            <td><input type="checkbox"><span class="dtr-control"></span></td>
            <td>Mission Alpha</td>
            <td><a href="/admin/mission/100/show">Preview</a></td>
          </tr>
        </tbody>
      </table>`;

    decorateCrudTables();

    expect(
      document.querySelector('#crudTable tbody tr')!.getAttribute('data-chatbot-row-id'),
    ).toBe('100');
  });

  it('is idempotent — already-decorated rows are skipped', () => {
    document.body.innerHTML = `
      <table id="crudTable">
        <tbody>
          <tr data-chatbot-row-id="prev"><td><a href="/admin/mission/9/show"></a></td></tr>
        </tbody>
      </table>`;

    decorateCrudTables();

    expect(
      document.querySelector('#crudTable tbody tr')!.getAttribute('data-chatbot-row-id'),
    ).toBe('prev');
  });

  it('ignores rows without a link match', () => {
    document.body.innerHTML = `
      <table id="crudTable">
        <tbody>
          <tr><td>no link here</td></tr>
        </tbody>
      </table>`;

    decorateCrudTables();

    expect(
      document.querySelector('#crudTable tbody tr')!.hasAttribute('data-chatbot-row-id'),
    ).toBe(false);
  });
});

describe('installBackpackDataTablesDecoration', () => {
  it('does nothing when the meta tag is missing', () => {
    document.body.innerHTML = `
      <table id="crudTable">
        <tbody><tr><td><a href="/admin/mission/3/show"></a></td></tr></tbody>
      </table>`;
    installBackpackDataTablesDecoration();
    expect(document.querySelector('#crudTable tbody tr')!.hasAttribute('data-chatbot-row-id'))
      .toBe(false);
  });

  it('decorates rows on first install when the meta tag opts in', () => {
    document.head.innerHTML =
      "<meta name=\"chatbot:options\" content='{\"backpack\":{\"dt_row_decoration\":true}}'>";
    document.body.innerHTML = `
      <table id="crudTable">
        <tbody><tr><td><a href="/admin/mission/3/show"></a></td></tr></tbody>
      </table>`;
    installBackpackDataTablesDecoration();
    expect(document.querySelector('#crudTable tbody tr')!.getAttribute('data-chatbot-row-id'))
      .toBe('3');
  });

  it('wires draw.dt re-decoration when jQuery is present', () => {
    document.head.innerHTML =
      "<meta name=\"chatbot:options\" content='{\"backpack\":{\"dt_row_decoration\":true}}'>";
    document.body.innerHTML = `
      <table id="crudTable">
        <tbody><tr><td><a href="/admin/mission/3/show"></a></td></tr></tbody>
      </table>`;
    const on = vi.fn();
    (window as unknown as { jQuery: unknown }).jQuery = (() => ({ on, length: 1 })) as unknown;
    installBackpackDataTablesDecoration();
    expect(on).toHaveBeenCalledWith('draw.dt', expect.any(Function));
    delete (window as unknown as { jQuery?: unknown }).jQuery;
  });
});

describe('isDtSelectedSyncEnabled (#26)', () => {
  it('returns false when the meta tag is absent', () => {
    expect(isDtSelectedSyncEnabled()).toBe(false);
  });

  it('returns true when the payload opts in', () => {
    document.head.innerHTML =
      "<meta name=\"chatbot:options\" content='{\"backpack\":{\"dt_selected_sync\":true}}'>";
    expect(isDtSelectedSyncEnabled()).toBe(true);
  });

  it('returns false when the payload opts out', () => {
    document.head.innerHTML =
      "<meta name=\"chatbot:options\" content='{\"backpack\":{\"dt_selected_sync\":false}}'>";
    expect(isDtSelectedSyncEnabled()).toBe(false);
  });
});

describe('collectSelectedIds (#26)', () => {
  it('returns the primary keys of every checked bulk-action checkbox', () => {
    document.body.innerHTML = `
      <table id="crudTable">
        <tbody>
          <tr><td><input type="checkbox" class="crud_bulk_actions_line_checkbox" data-primary-key-value="7" checked></td></tr>
          <tr><td><input type="checkbox" class="crud_bulk_actions_line_checkbox" data-primary-key-value="9"></td></tr>
          <tr><td><input type="checkbox" class="crud_bulk_actions_line_checkbox" data-primary-key-value="12" checked></td></tr>
        </tbody>
      </table>`;

    expect(collectSelectedIds()).toEqual(['7', '12']);
  });

  it('ignores checkboxes outside #crudTable', () => {
    document.body.innerHTML = `
      <input type="checkbox" class="crud_bulk_actions_line_checkbox" data-primary-key-value="100" checked>
      <table id="crudTable"><tbody></tbody></table>`;

    expect(collectSelectedIds()).toEqual([]);
  });

  it('returns an empty array when nothing is selected', () => {
    document.body.innerHTML = `
      <table id="crudTable">
        <tbody>
          <tr><td><input type="checkbox" class="crud_bulk_actions_line_checkbox" data-primary-key-value="1"></td></tr>
        </tbody>
      </table>`;

    expect(collectSelectedIds()).toEqual([]);
  });
});

describe('installBackpackBulkSelectionSync (#26)', () => {
  function mountTable(): void {
    document.body.innerHTML = `
      <table id="crudTable">
        <tbody>
          <tr><td><input type="checkbox" class="crud_bulk_actions_line_checkbox" data-primary-key-value="1"></td></tr>
          <tr><td><input type="checkbox" class="crud_bulk_actions_line_checkbox" data-primary-key-value="2"></td></tr>
        </tbody>
      </table>`;
  }

  function stubChatbot(): ReturnType<typeof vi.fn> {
    const setPageContext = vi.fn();
    (window as unknown as { Chatbot: { setPageContext: typeof setPageContext } }).Chatbot = {
      setPageContext,
    };
    return setPageContext;
  }

  it('does nothing when the meta tag is missing', () => {
    mountTable();
    const setPageContext = stubChatbot();

    installBackpackBulkSelectionSync();

    const cb = document.querySelector<HTMLInputElement>('input.crud_bulk_actions_line_checkbox')!;
    cb.checked = true;
    cb.dispatchEvent(new Event('change', { bubbles: true }));

    expect(setPageContext).not.toHaveBeenCalled();
  });

  it('seeds selected_ids on install when the meta opts in', () => {
    document.head.innerHTML =
      "<meta name=\"chatbot:options\" content='{\"backpack\":{\"dt_selected_sync\":true}}'>";
    mountTable();
    // Pretend the user already selected one row before the bundle mounted.
    document.querySelector<HTMLInputElement>(
      'input[data-primary-key-value="2"]',
    )!.checked = true;
    const setPageContext = stubChatbot();

    installBackpackBulkSelectionSync();

    expect(setPageContext).toHaveBeenCalledWith({ crud: { selected_ids: ['2'] } });
  });

  it('pushes selected_ids on every checkbox toggle', () => {
    document.head.innerHTML =
      "<meta name=\"chatbot:options\" content='{\"backpack\":{\"dt_selected_sync\":true}}'>";
    mountTable();
    const setPageContext = stubChatbot();
    installBackpackBulkSelectionSync();
    setPageContext.mockClear();

    const first = document.querySelector<HTMLInputElement>('input[data-primary-key-value="1"]')!;
    first.checked = true;
    first.dispatchEvent(new Event('change', { bubbles: true }));

    expect(setPageContext).toHaveBeenLastCalledWith({ crud: { selected_ids: ['1'] } });

    const second = document.querySelector<HTMLInputElement>('input[data-primary-key-value="2"]')!;
    second.checked = true;
    second.dispatchEvent(new Event('change', { bubbles: true }));

    expect(setPageContext).toHaveBeenLastCalledWith({ crud: { selected_ids: ['1', '2'] } });

    first.checked = false;
    first.dispatchEvent(new Event('change', { bubbles: true }));

    expect(setPageContext).toHaveBeenLastCalledWith({ crud: { selected_ids: ['2'] } });
  });

  it('ignores change events on inputs outside #crudTable', () => {
    document.head.innerHTML =
      "<meta name=\"chatbot:options\" content='{\"backpack\":{\"dt_selected_sync\":true}}'>";
    mountTable();
    const setPageContext = stubChatbot();
    installBackpackBulkSelectionSync();
    setPageContext.mockClear();

    document.body.insertAdjacentHTML(
      'beforeend',
      '<input id="alien" type="checkbox" class="crud_bulk_actions_line_checkbox" data-primary-key-value="999">',
    );
    const alien = document.getElementById('alien') as HTMLInputElement;
    alien.checked = true;
    alien.dispatchEvent(new Event('change', { bubbles: true }));

    expect(setPageContext).not.toHaveBeenCalled();
  });

  it('preserves the existing crud subtree when pushing selected_ids (#34)', () => {
    document.head.innerHTML =
      "<meta name=\"chatbot:options\" content='{\"backpack\":{\"dt_selected_sync\":true}}'>";
    mountTable();
    const setPageContext = vi.fn();
    // Server already emitted a full crud payload via @chatbotBackpackContext;
    // the live page context simulates that state.
    const liveCtx: Record<string, unknown> = {
      crud: {
        entity: 'mission',
        form: { selector: 'form#m', fields: [{ name: 'hazmat_certified' }] },
        filters: { available: ['destination'], applied: {} },
      },
    };
    (window as unknown as { Chatbot: unknown }).Chatbot = {
      setPageContext,
      __internal: { getPageContext: () => liveCtx },
    };

    installBackpackBulkSelectionSync();

    // Seed call on install: selected_ids piggybacks on top of the existing
    // crud subtree instead of wiping form/filters/entity.
    expect(setPageContext).toHaveBeenCalledWith({
      crud: {
        entity: 'mission',
        form: { selector: 'form#m', fields: [{ name: 'hazmat_certified' }] },
        filters: { available: ['destination'], applied: {} },
        selected_ids: [],
      },
    });
  });

  it('falls back to a plain selected_ids payload when __internal is unavailable (#34)', () => {
    document.head.innerHTML =
      "<meta name=\"chatbot:options\" content='{\"backpack\":{\"dt_selected_sync\":true}}'>";
    mountTable();
    const setPageContext = stubChatbot();   // no __internal — old/stripped host

    installBackpackBulkSelectionSync();

    // No crash, no extra keys — just the selected_ids subkey. The api.ts
    // one-level-deep merge does the real preservation in this code path.
    expect(setPageContext).toHaveBeenCalledWith({ crud: { selected_ids: [] } });
  });

  it('bails out silently when window.Chatbot.setPageContext is missing', () => {
    document.head.innerHTML =
      "<meta name=\"chatbot:options\" content='{\"backpack\":{\"dt_selected_sync\":true}}'>";
    mountTable();
    // No Chatbot stub.

    expect(() => installBackpackBulkSelectionSync()).not.toThrow();

    // After install, a change event must also not throw.
    const cb = document.querySelector<HTMLInputElement>('input.crud_bulk_actions_line_checkbox')!;
    cb.checked = true;
    expect(() => cb.dispatchEvent(new Event('change', { bubbles: true }))).not.toThrow();
  });
});
