/**
 * E17 — conversations sidebar for the Web Component's `mode="page"`.
 *
 * Encapsulates:
 *   - GET /chatbot/conversations (list + search with `?q=`).
 *   - DELETE /chatbot/conversations/{id} (with native `confirm()`).
 *   - Click on an item → `onSelect(id)` callback (the widget bridges it to
 *     `setConversationId` + cross-tab persistence).
 *
 * The module does not touch the widget's DOM directly — it receives a host
 * element (the sidebar root inside the shadow DOM) and mounts inside it. The
 * return value (`SidebarHandle`) lets the widget refresh the list, mark
 * another item as active, or destroy the sidebar on disconnect.
 */

interface ConversationRow {
  id: string | number;
  title: string | null;
  updated_at: string | null;
}

export interface SidebarOptions {
  /** Base URL of the conversations CRUD (E10), e.g. `/chatbot/conversations`. */
  endpoint: string;
  /** Optional bearer token (auth-aware via `setUser`). */
  bearer?: string | null;
  /** Active conversation on mount; highlighted in the list. null = no selection. */
  activeId?: string | number | null;
  /** Called when the user clicks an item in the list. */
  onSelect: (id: string | number) => void;
  /** Called after deleting the active conversation (the widget clears it). */
  onDeleteActive?: () => void;
  /**
   * Called when the user presses "+ New conversation". The widget aborts any
   * in-flight stream, resets the active id and focuses the input. The
   * conversation is actually created on the backend when the first message is
   * sent.
   */
  onNew?: () => void;
  /** Label of the "+ New conversation" button. Default: "+ New conversation". */
  newLabel?: string;
  /** Aria-label of the "+ New conversation" button. Default: same as the label. */
  newAriaLabel?: string;
  /**
   * Injectable for tests — lets you mock `fetch` without touching `globalThis`.
   * In production the global `fetch` is used.
   */
  fetcher?: typeof fetch;
  /**
   * Injectable for tests — replaces `window.confirm` (which jsdom resolves to
   * `true` by default and forces a `vi.spyOn`). In production uses `confirm()`.
   */
  confirmer?: (message: string) => boolean;
  /** Debounce in ms for the search input. Default 300. */
  searchDebounceMs?: number;
}

export interface SidebarHandle {
  /** Reloads the list (with the current search query). */
  refresh(): Promise<void>;
  /** Marks another id as active (does not call `onSelect`). */
  setActive(id: string | number | null): void;
  /** Unmounts DOM + cancels timers. */
  destroy(): void;
}

export function mountSidebar(host: HTMLElement, opts: SidebarOptions): SidebarHandle {
  const fetcher = opts.fetcher ?? fetch.bind(globalThis);
  const confirmer = opts.confirmer ?? ((msg: string) => window.confirm(msg));
  const debounceMs = opts.searchDebounceMs ?? 300;

  let activeId: string | number | null = opts.activeId ?? null;
  let query = '';
  let searchTimer: ReturnType<typeof setTimeout> | null = null;
  let lastFetchToken = 0;
  let destroyed = false;

  const root = document.createElement('aside');
  root.className = 'cb-sidebar';
  root.setAttribute('aria-label', 'Conversations');

  // "+ New conversation" header — only rendered when the host wires onNew.
  if (typeof opts.onNew === 'function') {
    const headerWrap = document.createElement('div');
    headerWrap.className = 'cb-sidebar-header';
    const newBtn = document.createElement('button');
    newBtn.type = 'button';
    newBtn.className = 'cb-sidebar-new';
    newBtn.textContent = opts.newLabel ?? '+ New conversation';
    newBtn.setAttribute('aria-label', opts.newAriaLabel ?? opts.newLabel ?? 'Start a new conversation');
    newBtn.addEventListener('click', () => {
      try { opts.onNew?.(); } catch (err) {
        console.error('[chatbot] sidebar onNew threw', err);
      }
    });
    headerWrap.appendChild(newBtn);
    root.appendChild(headerWrap);
  }

  const searchWrap = document.createElement('div');
  searchWrap.className = 'cb-sidebar-search';
  const searchInput = document.createElement('input');
  searchInput.type = 'search';
  searchInput.className = 'cb-sidebar-search-input';
  searchInput.placeholder = 'Search…';
  searchInput.setAttribute('aria-label', 'Search conversations');
  // v2.1.3 (#37): same class of fix as #29's textarea — give the input a `name`
  // so Chrome no longer reports "A form field element should have an id or name
  // attribute" for the widget's mode="page" sidebar search. #29 covered the
  // chat textarea; #37 closes the third (and last) form control that lived in
  // the widget's shadow DOM without one.
  searchInput.name = 'chatbot_conversation_search';
  searchWrap.appendChild(searchInput);
  root.appendChild(searchWrap);

  const listEl = document.createElement('ul');
  listEl.className = 'cb-sidebar-list';
  root.appendChild(listEl);

  const emptyEl = document.createElement('div');
  emptyEl.className = 'cb-sidebar-empty';
  emptyEl.textContent = 'No conversations';
  emptyEl.hidden = true;
  root.appendChild(emptyEl);

  const errorEl = document.createElement('div');
  errorEl.className = 'cb-sidebar-error';
  errorEl.hidden = true;
  root.appendChild(errorEl);

  host.appendChild(root);

  searchInput.addEventListener('input', () => {
    if (searchTimer !== null) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      searchTimer = null;
      query = searchInput.value.trim();
      void load();
    }, debounceMs);
  });

  function buildUrl(): string {
    if (query === '') return opts.endpoint;
    const sep = opts.endpoint.includes('?') ? '&' : '?';
    return `${opts.endpoint}${sep}q=${encodeURIComponent(query)}`;
  }

  function readCsrf(): string | null {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta) return null;
    const v = meta.getAttribute('content');
    return typeof v === 'string' && v !== '' ? v : null;
  }

  function buildHeaders(method: string): Record<string, string> {
    const headers: Record<string, string> = {
      Accept: 'application/json',
    };
    if (opts.bearer) headers['Authorization'] = `Bearer ${opts.bearer}`;
    if (method !== 'GET') {
      const csrf = readCsrf();
      if (csrf) headers['X-CSRF-TOKEN'] = csrf;
    }
    return headers;
  }

  async function load(): Promise<void> {
    if (destroyed) return;
    const token = ++lastFetchToken;
    errorEl.hidden = true;
    try {
      const res = await fetcher(buildUrl(), {
        method: 'GET',
        credentials: 'same-origin',
        headers: buildHeaders('GET'),
      });
      if (token !== lastFetchToken || destroyed) return;
      if (!res.ok) {
        showError(`Failed to load conversations (${res.status})`);
        renderRows([]);
        return;
      }
      const json = (await res.json()) as Record<string, unknown>;
      const rows = extractRows(json);
      if (token !== lastFetchToken || destroyed) return;
      renderRows(rows);
    } catch {
      if (token !== lastFetchToken || destroyed) return;
      showError('Failed to load conversations');
      renderRows([]);
    }
  }

  function extractRows(json: Record<string, unknown>): ConversationRow[] {
    const data = json['data'];
    if (!Array.isArray(data)) return [];
    const out: ConversationRow[] = [];
    for (const raw of data) {
      if (!raw || typeof raw !== 'object') continue;
      const r = raw as Record<string, unknown>;
      const id = r['id'];
      if (typeof id !== 'string' && typeof id !== 'number') continue;
      out.push({
        id,
        title: typeof r['title'] === 'string' ? (r['title'] as string) : null,
        updated_at: typeof r['updated_at'] === 'string' ? (r['updated_at'] as string) : null,
      });
    }
    return out;
  }

  function renderRows(rows: ConversationRow[]): void {
    listEl.innerHTML = '';
    if (rows.length === 0) {
      emptyEl.hidden = false;
      return;
    }
    emptyEl.hidden = true;
    for (const row of rows) {
      listEl.appendChild(renderRow(row));
    }
  }

  function renderRow(row: ConversationRow): HTMLElement {
    const item = document.createElement('li');
    item.className = 'cb-sidebar-item';
    item.dataset['id'] = String(row.id);
    if (activeId !== null && String(row.id) === String(activeId)) {
      item.classList.add('cb-sidebar-item-active');
      item.setAttribute('aria-current', 'true');
    }

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'cb-sidebar-item-button';
    const title = document.createElement('span');
    title.className = 'cb-sidebar-item-title';
    title.textContent = row.title && row.title !== '' ? row.title : `Conversation ${row.id}`;
    const meta = document.createElement('span');
    meta.className = 'cb-sidebar-item-meta';
    meta.textContent = row.updated_at ? formatDate(row.updated_at) : '';
    btn.append(title, meta);
    btn.addEventListener('click', () => {
      setActive(row.id);
      opts.onSelect(row.id);
    });
    item.appendChild(btn);

    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'cb-sidebar-item-delete';
    del.setAttribute('aria-label', 'Delete conversation');
    del.textContent = '✕';
    del.addEventListener('click', (e) => {
      e.stopPropagation();
      void deleteRow(row);
    });
    item.appendChild(del);

    return item;
  }

  function formatDate(iso: string): string {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const now = new Date();
    const sameDay = d.toDateString() === now.toDateString();
    if (sameDay) {
      return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    }
    return d.toLocaleDateString();
  }

  async function deleteRow(row: ConversationRow): Promise<void> {
    if (!confirmer('Delete this conversation?')) return;
    const url = `${opts.endpoint}/${encodeURIComponent(String(row.id))}`;
    try {
      const res = await fetcher(url, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: buildHeaders('DELETE'),
      });
      if (!res.ok && res.status !== 204) {
        showError(`Failed to delete (${res.status})`);
        return;
      }
      // Remove from DOM and check if it was the active one.
      const wasActive = activeId !== null && String(activeId) === String(row.id);
      const node = listEl.querySelector<HTMLElement>(
        `.cb-sidebar-item[data-id="${cssEscape(String(row.id))}"]`,
      );
      node?.remove();
      if (listEl.children.length === 0) emptyEl.hidden = false;
      if (wasActive) {
        activeId = null;
        opts.onDeleteActive?.();
      }
    } catch {
      showError('Failed to delete');
    }
  }

  function setActive(id: string | number | null): void {
    activeId = id;
    const items = listEl.querySelectorAll<HTMLElement>('.cb-sidebar-item');
    items.forEach((el) => {
      const itemId = el.dataset['id'] ?? '';
      const match = id !== null && String(id) === itemId;
      el.classList.toggle('cb-sidebar-item-active', match);
      if (match) el.setAttribute('aria-current', 'true');
      else el.removeAttribute('aria-current');
    });
  }

  function showError(msg: string): void {
    errorEl.textContent = msg;
    errorEl.hidden = false;
  }

  function destroy(): void {
    destroyed = true;
    if (searchTimer !== null) {
      clearTimeout(searchTimer);
      searchTimer = null;
    }
    root.remove();
  }

  // Initial load.
  void load();

  return {
    refresh: () => load(),
    setActive,
    destroy,
  };
}

/**
 * jsdom's CSS.escape isn't always available; provide a safe fallback that
 * escapes characters the dataset attribute selector would otherwise choke on.
 * Production browsers use `CSS.escape` directly when present.
 */
function cssEscape(value: string): string {
  if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
    return CSS.escape(value);
  }
  return value.replace(/["\\\n\r]/g, (c) => `\\${c}`);
}
