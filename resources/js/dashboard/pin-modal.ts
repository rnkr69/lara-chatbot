/**
 * v2.0 / E6 — modal "Pin to dashboard".
 *
 * Mounted into the widget's shadow root (NOT into document.body): the dim
 * overlay is scoped to the chat panel, the styles inherit the widget's
 * shadow CSS, and the host page's own styling can never bleed in. Same
 * pattern as the toast at `widget.ts` (the toast uses `this.shadow.appendChild`).
 *
 * Behavior:
 *   1. Lists user's dashboards via `DashboardApi.listDashboards()`. Pre-selects
 *      `is_default: true`, falling back to the first row if none flagged.
 *   2. Toggles between "Existing" (select dropdown) and "Create" (inline
 *      text input). When the user has zero dashboards, "Create" is the only
 *      option (the toggle is hidden).
 *   3. Title input pre-filled with `data.title || data.caption || data.label
 *      || capitalize(block_type)`. Editable; sent as `suggested_title`.
 *   4. Submit:
 *        - "Existing": POST `pinWidget(slug, …)`.
 *        - "Create":   `createDashboard(name)` then `pinWidget(newSlug, …)`.
 *      On success: closes modal, calls `onSuccess({slug, name})`. The widget
 *      shows the toast.
 *      On `PinWidgetError`:
 *        - 422 with `errors.dashboard` non-empty → error_dashboard_full
 *        - 422 with `errors.source.tool` mentioning "not pinnable" → error_tool_unpinnable
 *        - 422 with `errors.source.tool` mentioning "not registered" → error_tool_missing
 *        - any other → error_generic with the server message in tooltip.
 *      Modal stays open on error so the user can pick another dashboard.
 *   5. Closes via ESC or click on the dim overlay (NOT on the dialog itself).
 *      `cancel` button does the same. `close()` returned for the caller.
 *
 * Page context: the widget passes `getPageContext()` snapshot at the moment
 * of the click (not at modal open) — keeps the modal stateless about page
 * navigation. Server filters by `source.page_context_keys` (E1-stamped on
 * the block) + applies the 16 KB cap (E4 controller).
 */

import { DashboardApi, PinWidgetError } from './api.js';
import type { BlockPayload } from '../types.js';
import type { DashboardRow } from './types.js';

export interface PinModalLabels {
  modal_title: string;
  modal_select_label: string;
  modal_create_inline: string;
  modal_create_name: string;
  modal_title_label: string;
  modal_title_placeholder: string;
  submit: string;
  cancel: string;
  error_dashboard_full: string;
  error_tool_unpinnable: string;
  error_tool_missing: string;
  error_generic: string;
  loading: string;
}

const DEFAULT_LABELS: PinModalLabels = {
  modal_title: 'Pin to dashboard',
  modal_select_label: 'Dashboard',
  modal_create_inline: 'Create new dashboard…',
  modal_create_name: 'Dashboard name',
  modal_title_label: 'Title',
  modal_title_placeholder: 'Optional title…',
  submit: 'Pin',
  cancel: 'Cancel',
  error_dashboard_full: 'This dashboard is full. Pick another or unpin first.',
  error_tool_unpinnable: 'This block cannot be pinned (its tool is not pinnable).',
  error_tool_missing: 'The tool that produced this block is no longer registered.',
  error_generic: 'Could not pin to dashboard.',
  loading: 'Loading dashboards…',
};

export interface PinModalSuccess {
  dashboardSlug: string;
  dashboardName: string;
}

export interface PinModalOptions {
  block: BlockPayload;
  api: DashboardApi;
  pageContext: Record<string, unknown>;
  labels?: Partial<PinModalLabels>;
  onSuccess(info: PinModalSuccess): void;
  onClose(): void;
}

export interface PinModalHandle {
  close(): void;
}

/**
 * Heuristic for the title pre-fill. Order: explicit title → caption →
 * label → capitalized block type. Editable in the modal.
 */
export function suggestTitle(block: BlockPayload): string {
  const data = block.data ?? {};
  const candidates = ['title', 'caption', 'label'] as const;
  for (const key of candidates) {
    const v = data[key];
    if (typeof v === 'string' && v.trim() !== '') return v.trim();
  }
  if (typeof block.type === 'string' && block.type !== '') {
    return block.type.charAt(0).toUpperCase() + block.type.slice(1);
  }
  return '';
}

/**
 * Maps a `PinWidgetError` (server 4xx response) to a localized message
 * key. Returned label key is looked up in the merged `labels` table.
 */
function mapPinError(err: PinWidgetError, labels: PinModalLabels): string {
  if (err.status === 422) {
    const dashErrors = err.errors['dashboard'] ?? [];
    if (dashErrors.length > 0) return labels.error_dashboard_full;
    const toolErrors = err.errors['source.tool'] ?? [];
    for (const msg of toolErrors) {
      const lower = msg.toLowerCase();
      if (lower.includes('not pinnable')) return labels.error_tool_unpinnable;
      if (lower.includes('not registered')) return labels.error_tool_missing;
    }
    // A 422 with an unmapped validation message — it IS a user-meaningful
    // validation error, so surface the server's own wording.
    if (err.serverMessage !== '') return err.serverMessage;
  }
  // v2.1 (#11) — 404 / 5xx / anything non-422: the server message is
  // technical (e.g. the route is missing because the dashboard is disabled,
  // or an internal 500). Show the localized generic message instead of
  // leaking a raw `POST … → HTTP 404` string to the user.
  return labels.error_generic;
}

export function openPinModal(
  host: ShadowRoot | HTMLElement,
  opts: PinModalOptions,
): PinModalHandle {
  const labels = { ...DEFAULT_LABELS, ...(opts.labels ?? {}) };

  let closed = false;
  let rows: DashboardRow[] = [];
  let mode: 'existing' | 'create' = 'existing';
  let submitting = false;

  // ── Overlay ───────────────────────────────────────────────────────────
  const overlay = document.createElement('div');
  overlay.className = 'cb-pin-modal-overlay';
  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) close(false);
  });

  const dialog = document.createElement('div');
  dialog.className = 'cb-pin-modal';
  dialog.setAttribute('role', 'dialog');
  dialog.setAttribute('aria-modal', 'true');
  const titleId = `cb-pin-modal-title-${Math.random().toString(36).slice(2, 8)}`;
  dialog.setAttribute('aria-labelledby', titleId);

  const header = document.createElement('div');
  header.className = 'cb-pin-modal-header';
  const titleEl = document.createElement('h3');
  titleEl.id = titleId;
  titleEl.className = 'cb-pin-modal-title';
  titleEl.textContent = labels.modal_title;
  header.appendChild(titleEl);
  dialog.appendChild(header);

  const body = document.createElement('div');
  body.className = 'cb-pin-modal-body';
  dialog.appendChild(body);

  const errorEl = document.createElement('div');
  errorEl.className = 'cb-pin-modal-error';
  errorEl.setAttribute('role', 'alert');
  errorEl.hidden = true;
  dialog.appendChild(errorEl);

  const footer = document.createElement('div');
  footer.className = 'cb-pin-modal-footer';
  const cancelBtn = document.createElement('button');
  cancelBtn.type = 'button';
  cancelBtn.className = 'cb-pin-modal-cancel';
  cancelBtn.textContent = labels.cancel;
  cancelBtn.addEventListener('click', () => close(false));
  const submitBtn = document.createElement('button');
  submitBtn.type = 'button';
  submitBtn.className = 'cb-pin-modal-submit';
  submitBtn.textContent = labels.submit;
  submitBtn.addEventListener('click', () => { void handleSubmit(); });
  footer.append(cancelBtn, submitBtn);
  dialog.appendChild(footer);

  // Loading skeleton — replaced once `listDashboards()` resolves.
  const loading = document.createElement('div');
  loading.className = 'cb-pin-modal-loading';
  loading.textContent = labels.loading;
  body.appendChild(loading);

  overlay.appendChild(dialog);
  host.appendChild(overlay);

  // ESC close. Bound to dialog (which is inside the shadow root, so it
  // receives the event when focus is inside the modal).
  const onKeyDown = (e: KeyboardEvent): void => {
    if (e.key === 'Escape') {
      e.preventDefault();
      close(false);
    }
  };
  dialog.addEventListener('keydown', onKeyDown);

  // Focus management — focus the cancel button as a safe default.
  // Real focus moves to the select / input once they are rendered.
  queueMicrotask(() => cancelBtn.focus());

  // Lazy-built form refs (filled by renderForm()).
  let selectEl: HTMLSelectElement | null = null;
  let createInputEl: HTMLInputElement | null = null;
  let titleInputEl: HTMLInputElement | null = null;
  let modeRadios: { existing: HTMLInputElement; create: HTMLInputElement } | null = null;

  function renderForm(): void {
    body.innerHTML = '';

    const hasExisting = rows.length > 0;
    if (!hasExisting) {
      mode = 'create';
    }

    if (hasExisting) {
      const radios = document.createElement('div');
      radios.className = 'cb-pin-modal-mode';

      const existingId = `${titleId}-mode-existing`;
      const createId = `${titleId}-mode-create`;

      const existingLabel = document.createElement('label');
      existingLabel.className = 'cb-pin-modal-mode-row';
      const existingRadio = document.createElement('input');
      existingRadio.type = 'radio';
      existingRadio.name = `${titleId}-mode`;
      existingRadio.id = existingId;
      existingRadio.value = 'existing';
      existingRadio.checked = mode === 'existing';
      existingRadio.addEventListener('change', () => {
        if (existingRadio.checked) { mode = 'existing'; refreshSelectability(); }
      });
      existingLabel.appendChild(existingRadio);
      const existingText = document.createElement('span');
      existingText.textContent = labels.modal_select_label;
      existingLabel.appendChild(existingText);
      radios.appendChild(existingLabel);

      // Select is on its own row (under the radios) so screen readers
      // announce label → control adjacency clearly.
      const select = document.createElement('select');
      select.className = 'cb-pin-modal-select';
      select.setAttribute('aria-label', labels.modal_select_label);
      const defaultRow = rows.find((r) => r.is_default) ?? rows[0];
      for (const row of rows) {
        const opt = document.createElement('option');
        opt.value = row.slug;
        const label = row.is_default ? `${row.name} ★` : row.name;
        opt.textContent = label;
        if (row.slug === defaultRow?.slug) opt.selected = true;
        select.appendChild(opt);
      }
      select.addEventListener('focus', () => {
        if (modeRadios) { modeRadios.existing.checked = true; mode = 'existing'; refreshSelectability(); }
      });
      selectEl = select;
      radios.appendChild(select);

      const createLabel = document.createElement('label');
      createLabel.className = 'cb-pin-modal-mode-row';
      const createRadio = document.createElement('input');
      createRadio.type = 'radio';
      createRadio.name = `${titleId}-mode`;
      createRadio.id = createId;
      createRadio.value = 'create';
      createRadio.checked = mode === 'create';
      createRadio.addEventListener('change', () => {
        if (createRadio.checked) { mode = 'create'; refreshSelectability(); }
      });
      createLabel.appendChild(createRadio);
      const createText = document.createElement('span');
      createText.textContent = labels.modal_create_inline;
      createLabel.appendChild(createText);
      radios.appendChild(createLabel);

      modeRadios = { existing: existingRadio, create: createRadio };

      body.appendChild(radios);
    }

    const createInput = document.createElement('input');
    createInput.type = 'text';
    createInput.className = 'cb-pin-modal-create-input';
    createInput.placeholder = labels.modal_create_name;
    createInput.maxLength = 120;
    createInput.setAttribute('aria-label', labels.modal_create_name);
    // v2.1.1 (#24) — a form field needs an id or name (Chrome DevTools issue).
    createInput.name = 'chatbot_pin_dashboard_name';
    createInput.addEventListener('focus', () => {
      if (modeRadios) { modeRadios.create.checked = true; mode = 'create'; refreshSelectability(); }
    });
    createInputEl = createInput;
    body.appendChild(createInput);

    // Title (always editable, applies to either path).
    const titleLabel = document.createElement('label');
    titleLabel.className = 'cb-pin-modal-title-row';
    const titleSpan = document.createElement('span');
    titleSpan.textContent = labels.modal_title_label;
    titleLabel.appendChild(titleSpan);
    const titleInput = document.createElement('input');
    titleInput.type = 'text';
    titleInput.className = 'cb-pin-modal-title-input';
    titleInput.placeholder = labels.modal_title_placeholder;
    titleInput.maxLength = 180;
    titleInput.value = suggestTitle(opts.block);
    // v2.1.1 (#24) — id/name for the form field (it is already labelled by the
    // wrapping <label>, but Chrome's issue is specifically about id/name).
    titleInput.name = 'chatbot_pin_title';
    titleLabel.appendChild(titleInput);
    titleInputEl = titleInput;
    body.appendChild(titleLabel);

    refreshSelectability();
    queueMicrotask(() => {
      if (!hasExisting) createInput.focus();
      else selectEl?.focus();
    });
  }

  function refreshSelectability(): void {
    if (selectEl) selectEl.disabled = mode !== 'existing';
    if (createInputEl) createInputEl.disabled = mode !== 'create';
  }

  function showError(msg: string): void {
    errorEl.textContent = msg;
    errorEl.hidden = false;
  }

  function clearError(): void {
    errorEl.hidden = true;
    errorEl.textContent = '';
  }

  async function handleSubmit(): Promise<void> {
    if (submitting || closed) return;
    clearError();

    const block = opts.block;
    const source = block.source;
    if (!source) {
      showError(labels.error_generic);
      return;
    }

    let targetSlug: string;
    let targetName: string;

    submitting = true;
    submitBtn.disabled = true;
    cancelBtn.disabled = true;

    try {
      if (mode === 'create') {
        const name = (createInputEl?.value ?? '').trim();
        if (name === '') {
          showError(labels.modal_create_name);
          submitting = false;
          submitBtn.disabled = false;
          cancelBtn.disabled = false;
          createInputEl?.focus();
          return;
        }
        const created = await opts.api.createDashboard({ name });
        targetSlug = created.slug;
        targetName = created.name;
      } else {
        const slug = selectEl?.value ?? '';
        const row = rows.find((r) => r.slug === slug);
        if (!row) {
          showError(labels.error_generic);
          submitting = false;
          submitBtn.disabled = false;
          cancelBtn.disabled = false;
          return;
        }
        targetSlug = row.slug;
        targetName = row.name;
      }

      const titleVal = (titleInputEl?.value ?? '').trim();
      const payload = {
        block_type: block.type,
        snapshot: { data: block.data ?? {} },
        source: {
          tool: source.tool,
          args: source.args ?? {},
          ...(source.page_context_keys ? { page_context_keys: source.page_context_keys } : {}),
        },
        ...(typeof block.id === 'string' && block.id !== '' ? { block_id: block.id } : {}),
        // v2.1.2 (#27) — stable half of the replay descriptor. Without it
        // the server-side replay falls back to `blocks[0]` for multi-block
        // tools and pins the wrong block (silent data corruption).
        ...(typeof block.blockOrdinal === 'number' ? { block_ordinal: block.blockOrdinal } : {}),
        ...(titleVal !== '' ? { suggested_title: titleVal } : {}),
        ...(Object.keys(opts.pageContext).length > 0 ? { page_context: opts.pageContext } : {}),
      };

      await opts.api.pinWidget(targetSlug, payload);

      opts.onSuccess({ dashboardSlug: targetSlug, dashboardName: targetName });
      close(true);
    } catch (err) {
      if (err instanceof PinWidgetError) {
        showError(mapPinError(err, labels));
      } else {
        // v2.1 (#11) — a non-structured failure: a network error, or
        // `createDashboard` throwing a raw `POST … → HTTP nnn` Error when
        // the dashboard routes are not registered. Log it for operators;
        // never surface the raw HTTP string to the user.
        console.error('[chatbot] pin failed:', err);
        showError(labels.error_generic);
      }
      submitting = false;
      submitBtn.disabled = false;
      cancelBtn.disabled = false;
    }
  }

  function close(_succeeded: boolean): void {
    if (closed) return;
    closed = true;
    dialog.removeEventListener('keydown', onKeyDown);
    overlay.remove();
    if (!_succeeded) opts.onClose();
  }

  // Kick off initial load.
  void (async () => {
    try {
      rows = await opts.api.listDashboards();
      if (closed) return;
      renderForm();
    } catch (err) {
      if (closed) return;
      // No dashboards reachable — fall back to "create" mode so the user
      // can still pin (createDashboard + pinWidget happen at submit).
      // v2.1 (#11) — log the raw error; show the localized generic instead
      // of the raw `GET … → HTTP nnn` string.
      console.error('[chatbot] failed to list dashboards for pin modal:', err);
      rows = [];
      renderForm();
      showError(labels.error_generic);
    }
  })();

  return { close: () => close(false) };
}
