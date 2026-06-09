/**
 * v1.1.3 (#20) — Internalized Backpack DataTables row decoration.
 *
 * Backpack admin grids render a jQuery DataTables instance under
 * `#crudTable`. For the chatbot widget to reliably target specific rows
 * (e.g. `toggle_visibility({selector: 'tr[data-chatbot-row-id="42"]'})`)
 * every row needs a stable `data-chatbot-row-id` attribute. Until v1.1.3
 * the host had to wire its own `draw.dt` hook; from now on the package
 * does it.
 *
 * Activation: emit `<meta name="chatbot:options" content='{"backpack":
 * {"dt_row_decoration":true}}'>`. The Blade directive
 * `@chatbotBackpackContext` already emits this tag when the package config
 * `chatbot.backpack.datatables_row_decoration` is true (default).
 *
 * The id is parsed from the Preview link (`<a href=".../{id}/show">`),
 * falling back to the Edit link (`/{id}/edit`). textContent of the first
 * cell is intentionally NOT used — DataTables ResponsivePlugin renders
 * the first cell with a checkbox + expand control, which yields no text
 * for the first row and breaks the naive index-extraction approach the
 * host used before (see finding #20 reproduction).
 *
 * Idempotent: rows already decorated are skipped, so a re-fire of
 * `draw.dt` (filter, paginate, search) doesn't churn the DOM.
 */

interface JQueryLike {
  (selector: string): {
    on(event: string, handler: (e: unknown) => void): unknown;
    off(event: string, handler?: (e: unknown) => void): unknown;
    length: number;
  };
}

interface ChatbotOptionsPayload {
  backpack?: {
    dt_row_decoration?: boolean;
    dt_selected_sync?: boolean;
  };
}

interface ChatbotApiLike {
  setPageContext?: (ctx: Record<string, unknown>) => void;
  // v1.1.8 (#34): peek at the current crud subtree so the bulk-selection
  // sync expresses merge intent at the call site, independent of the
  // setPageContext() merge semantics.
  __internal?: {
    getPageContext?: () => Record<string, unknown>;
  };
}

const DOM_READY_EVENTS = ['DOMContentLoaded', 'inertia:navigate', 'livewire:navigated'];

let installed = false;
let selectedSyncInstalled = false;

/**
 * Read the `<meta name="chatbot:options">` payload, if any, and decide
 * whether the DataTables decoration hook should be active.
 */
export function isDtDecorationEnabled(): boolean {
  if (typeof document === 'undefined') return false;
  const meta = document.querySelector('meta[name="chatbot:options"]');
  if (!meta) return false;
  const raw = meta.getAttribute('content');
  if (raw === null || raw === '') return false;
  try {
    const parsed = JSON.parse(raw) as ChatbotOptionsPayload;
    return parsed?.backpack?.dt_row_decoration === true;
  } catch {
    return false;
  }
}

/**
 * Finding #25/1.1.4 sister hook (v1.1.4, finding #26) — opt-in to mirror
 * the Backpack bulk-action checkbox state into the page context, so the
 * LLM sees `crud.selected_ids` up to date after every user click. Returns
 * `true` only when the meta payload carries `dt_selected_sync: true`.
 */
export function isDtSelectedSyncEnabled(): boolean {
  if (typeof document === 'undefined') return false;
  const meta = document.querySelector('meta[name="chatbot:options"]');
  if (!meta) return false;
  const raw = meta.getAttribute('content');
  if (raw === null || raw === '') return false;
  try {
    const parsed = JSON.parse(raw) as ChatbotOptionsPayload;
    return parsed?.backpack?.dt_selected_sync === true;
  } catch {
    return false;
  }
}

/**
 * Extract the numeric id from the first row link that points at a
 * `/show` or `/edit` action. Returns null when neither is present.
 */
export function extractRowId(row: Element): string | null {
  const links = row.querySelectorAll('a[href*="/show"], a[href*="/edit"]');
  for (const link of Array.from(links)) {
    const href = link.getAttribute('href');
    if (typeof href !== 'string' || href === '') continue;
    const match = href.match(/\/(\d+)\/(?:show|edit)(?:\?|#|$)/);
    if (match && match[1]) return match[1];
  }
  return null;
}

/**
 * Decorate every row of every `#crudTable` instance currently in the DOM.
 * Safe to call repeatedly — already-decorated rows are skipped.
 */
export function decorateCrudTables(scope: ParentNode = document): void {
  const rows = scope.querySelectorAll('table#crudTable tbody tr');
  rows.forEach((row) => {
    if (!(row instanceof HTMLElement)) return;
    if (row.hasAttribute('data-chatbot-row-id')) return;
    const id = extractRowId(row);
    if (id !== null) {
      row.setAttribute('data-chatbot-row-id', id);
    }
  });
}

/**
 * Install the hook. Idempotent — the second call is a no-op. The wiring
 * runs immediately (catches rows already on the page) and on jQuery's
 * `draw.dt` event so subsequent paginations/filters re-decorate.
 *
 * Outside Backpack (no jQuery, no DataTables) the function bails out
 * silently — hosts that don't use Backpack pay nothing.
 */
export function installBackpackDataTablesDecoration(): void {
  if (installed) return;
  if (typeof window === 'undefined') return;
  if (!isDtDecorationEnabled()) return;
  installed = true;

  // First pass for rows already in the DOM (server-rendered initial page).
  decorateCrudTables();

  // Subsequent DataTables re-renders (filter, sort, page change).
  const wireJQuery = (): void => {
    const $ = (window as unknown as { jQuery?: JQueryLike }).jQuery;
    if (typeof $ !== 'function') return;
    try {
      $('table#crudTable').on('draw.dt', () => {
        decorateCrudTables();
      });
    } catch {
      // Old or shimmed jQuery; ignore.
    }
  };
  wireJQuery();

  // SPA hosts swap whole sections of DOM on navigation — re-run the
  // decorator after the new page renders.
  DOM_READY_EVENTS.forEach((evt) => {
    window.addEventListener(evt, () => {
      decorateCrudTables();
      wireJQuery();
    });
  });
}

/**
 * v1.1.4 (#26) — Backpack bulk-action checkboxes carry the row PK in
 * `data-primary-key-value`. Collect the values from every CHECKED
 * `input.crud_bulk_actions_line_checkbox` inside the (optionally scoped)
 * table.
 */
export function collectSelectedIds(scope: ParentNode = document): string[] {
  const checkboxes = scope.querySelectorAll<HTMLInputElement>(
    'table#crudTable input.crud_bulk_actions_line_checkbox:checked',
  );
  const out: string[] = [];
  checkboxes.forEach((cb) => {
    const v = cb.dataset.primaryKeyValue ?? cb.getAttribute('data-primary-key-value');
    if (typeof v === 'string' && v !== '') out.push(v);
  });
  return out;
}

/**
 * v1.1.4 (#26) — Install a `change` listener on the bulk-action
 * checkboxes of `#crudTable` so the page context the LLM sees is always
 * in sync with the user's actual selection.
 *
 * Without this hook the page provider emits `crud.selected_ids` once at
 * render time (server-side); any subsequent checkbox toggle leaves the
 * context stale and the LLM either re-asks the selection or operates on
 * the wrong ids.
 *
 * Opt-in via the `chatbot:options` meta payload (`backpack.dt_selected_sync`).
 * The package defaults the flag to `true` independently of
 * `dt_row_decoration`; hosts that don't want it set
 * `CHATBOT_BACKPACK_DT_SELECTED_SYNC=false`.
 *
 * Idempotent: re-installs are a no-op. Bails out silently when
 * `window.Chatbot.setPageContext` isn't available yet (the host loaded
 * the bundle in a stripped-down mode).
 */
export function installBackpackBulkSelectionSync(): void {
  if (selectedSyncInstalled) return;
  if (typeof window === 'undefined' || typeof document === 'undefined') return;
  if (!isDtSelectedSyncEnabled()) return;
  selectedSyncInstalled = true;

  const push = (): void => {
    const api = (window as unknown as { Chatbot?: ChatbotApiLike }).Chatbot;
    if (typeof api?.setPageContext !== 'function') return;
    // v1.1.8 (#34): merge into the existing `crud` subtree explicitly rather
    // than handing setPageContext a `{ crud: { selected_ids } }` payload that
    // pre-1.1.8 would have wiped `crud.form` / `crud.filters` / `crud.entity`
    // emitted by the server via @chatbotBackpackContext. setPageContext is
    // now one-level-deep-merging too, so this is defence-in-depth — but it
    // also makes the intent obvious at the call site and survives a future
    // regression of the merge contract.
    const current = api.__internal?.getPageContext?.() ?? {};
    const prevCrud = (current as { crud?: Record<string, unknown> }).crud;
    const crudMerged: Record<string, unknown> = {
      ...(prevCrud && typeof prevCrud === 'object' && !Array.isArray(prevCrud)
        ? prevCrud
        : {}),
      selected_ids: collectSelectedIds(),
    };
    api.setPageContext({ crud: crudMerged });
  };

  // Delegated listener: a single handler on `document` survives DataTables
  // tearing down and recreating the table body on pagination/filter.
  document.addEventListener('change', (ev) => {
    const t = ev.target as HTMLElement | null;
    if (!t || typeof t.matches !== 'function') return;
    if (
      t.matches(
        'table#crudTable input.crud_bulk_actions_line_checkbox, table#crudTable input.crud_bulk_actions_main_checkbox',
      )
    ) {
      push();
    }
  });

  // Seed once on mount (covers Backpack's restoration of the bulk-action
  // store after a paginate / filter draw — checkboxes can come back
  // checked without firing `change`).
  push();

  // SPA-style hosts swap whole sections of DOM on navigation; reseed on
  // page-ready signals so the new `#crudTable` is reflected.
  DOM_READY_EVENTS.forEach((evt) => {
    window.addEventListener(evt, push);
  });
}

/**
 * Test seam: reset the installed guard so unit tests can re-install with
 * different meta-tag scenarios. Not part of the public bundle API.
 */
export function __resetForTests(): void {
  installed = false;
  selectedSyncInstalled = false;
}
