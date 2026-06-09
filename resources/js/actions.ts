import type { FrontendActionPayload } from './types.js';
import { selectDefaultNavigator } from './navigators.js';

export interface ActionEnvironment {
  /** Shadow root host (for in-shadow toasts when relevant). */
  hostElement: HTMLElement;
  /** Append a toast to the document. */
  showToast(message: string, durationMs: number): void;
}

/**
 * v1.1.3 (#16) — structured outcome of a primitive. Every primitive returns
 * one of these so the widget can decide whether to POST-back to the
 * backend (only on failure) and surface a toast to the user. On success
 * we skip the POST entirely — the happy path stays as cheap as before.
 */
export type PrimitiveResult =
  | { ok: true }
  | {
      ok: false;
      /** Stable machine-readable code (e.g. `no_form_matched`). */
      error: string;
      /** Human-readable explanation appended to logs / toasts / prompt. */
      message: string;
      /** Free-form context appended verbatim to the LLM `[FAILED]` line. */
      [key: string]: unknown;
    };

const PRIMITIVE_OK: PrimitiveResult = Object.freeze({ ok: true });

function primitiveFail(error: string, message: string, extra: Record<string, unknown> = {}): PrimitiveResult {
  return { ok: false, error, message, ...extra };
}

type Primitive = (args: Record<string, unknown>, env: ActionEnvironment) => PrimitiveResult;

function asString(value: unknown, fallback = ''): string {
  return typeof value === 'string' ? value : fallback;
}

function asNumber(value: unknown, fallback: number): number {
  return typeof value === 'number' && Number.isFinite(value) ? value : fallback;
}

/**
 * jsdom in test envs may not ship `CSS.escape`. Mirror the same defensive
 * fallback used by `sidebar.ts` / `slot-templates.ts` so primitives stay
 * testable without requiring a full browser. Production browsers use the
 * native `CSS.escape` whenever it is available.
 */
function cssEscape(value: string): string {
  if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
    return CSS.escape(value);
  }
  return value.replace(/[^a-zA-Z0-9_-]/g, (ch) => `\\${ch}`);
}

const navigate: Primitive = (args) => {
  const url = asString(args['url']);
  if (url === '') {
    return primitiveFail('empty_url', 'navigate requires a non-empty url argument.');
  }
  // Only allow same-origin or relative paths by default; remote nav goes through
  // the host registering its own `navigate` tool override.
  let target: string | null = null;
  if (url.startsWith('/') || url.startsWith('#') || url.startsWith('?')) {
    target = url;
  } else {
    try {
      const parsed = new URL(url, window.location.href);
      if (parsed.origin === window.location.origin) target = parsed.toString();
    } catch {
      return primitiveFail('invalid_url', `navigate received a malformed URL "${url}".`, { url });
    }
  }
  if (target === null) {
    return primitiveFail('cross_origin', `navigate refused cross-origin URL "${url}". Register a custom navigator if remote navigation is required.`, { url });
  }
  // E13 cascade: host-registered navigator (registerNavigator) wins over the
  // mode-detected default. The host-tool override (registerTool('navigate'))
  // is even higher priority and is checked earlier in handleFrontendAction.
  const hostNav = window.Chatbot?.__internal.getNavigator?.();
  if (hostNav) {
    try { hostNav(target); return PRIMITIVE_OK; } catch (err) {
      console.error('[chatbot] registered navigator threw', err);
      // Fall through to default so the action still has a chance to land.
    }
  }
  selectDefaultNavigator()(target);
  return PRIMITIVE_OK;
};

const toggleVisibility: Primitive = (args) => {
  const selector = asString(args['selector']);
  if (selector === '') {
    return primitiveFail('empty_selector', 'toggle_visibility requires a non-empty selector argument.');
  }
  let nodes: NodeListOf<Element>;
  try {
    nodes = document.querySelectorAll(selector);
  } catch {
    return primitiveFail('invalid_selector', `toggle_visibility could not parse selector "${selector}".`, { selector });
  }
  if (nodes.length === 0) {
    return primitiveFail('no_match', `toggle_visibility found no elements matching "${selector}".`, { selector });
  }
  const force = args['visible'];
  nodes.forEach((node) => {
    if (!(node instanceof HTMLElement)) return;
    if (typeof force === 'boolean') {
      node.style.display = force ? '' : 'none';
    } else {
      node.style.display = node.style.display === 'none' ? '' : 'none';
    }
  });
  return PRIMITIVE_OK;
};

const showToastPrim: Primitive = (args, env) => {
  const message = asString(args['message']);
  if (message === '') {
    return primitiveFail('empty_message', 'show_toast requires a non-empty message argument.');
  }
  const duration = Math.max(1000, asNumber(args['duration'], 4000));
  env.showToast(message, duration);
  return PRIMITIVE_OK;
};

const downloadFile: Primitive = (args) => {
  const url = asString(args['download_url']);
  if (url === '') {
    return primitiveFail('empty_url', 'download_file requires a non-empty download_url argument.');
  }
  // Only follow http(s) signed URLs the backend produced.
  if (!/^https?:\/\//i.test(url)) {
    return primitiveFail('non_http_url', `download_file refused non-http(s) URL "${url}". Use a signed URL produced by the backend.`, { url });
  }
  const filename = asString(args['filename']);
  const a = document.createElement('a');
  a.href = url;
  a.rel = 'noopener noreferrer';
  if (filename !== '') a.download = filename;
  // Some browsers ignore `download` on cross-origin links; fall back to opening
  // the URL in a new tab so the user still gets the file.
  a.target = '_blank';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  return PRIMITIVE_OK;
};

/**
 * `fill_form` — sets values on a form's controls and optionally submits.
 *
 * Form lookup cascade (v1.1.2, findings #9.f):
 *   1. If `selector` is provided: `document.querySelector(selector)`. If the
 *      element is a `<form>`, use it; otherwise look for the first `<form>`
 *      descendant. Recommended for Backpack — the page context emits
 *      `crud.form.selector = '[bp-section="crud-operation-create"] form'`
 *      which works without any view overrides.
 *   2. If `form_id` is provided: by `<form id="X">` or `[data-chatbot-form="X"]`.
 *      Used when the host has explicitly tagged forms with stable ids.
 *   3. Auto-discovery across common host patterns (`main form`,
 *      `form#crudTable`, `form.form`, then any `form`). Drops a console.warn
 *      so the host operator knows the LLM did not specify a target.
 *   4. If lookup fails: console.warn with the attempted selector/id and the
 *      list of forms actually present (for debugging) — no silent return.
 *
 * If both `selector` and `form_id` are provided, `selector` wins.
 *
 * Values dispatch both `input` and `change` events so framework listeners
 * (Vue/React/Alpine, Select2, Choices.js, Flatpickr…) see the update.
 */
const fillForm: Primitive = (args) => {
  const selector = asString(args['selector']);
  const formId   = asString(args['form_id']);
  let form: HTMLFormElement | null = null;
  let lookupDescription = '';

  if (selector !== '') {
    lookupDescription = `selector "${selector}"`;
    try {
      const el = document.querySelector(selector);
      if (el instanceof HTMLFormElement) {
        form = el;
      } else if (el) {
        // The selector may target a wrapper (e.g. `[bp-section="..."]`); find
        // the form descendant.
        form = el.querySelector('form');
      }
    } catch {
      // Invalid selector — fall through to id / auto-discovery.
    }
  }

  if (form === null && formId !== '') {
    lookupDescription = `id="${formId}" or [data-chatbot-form="${formId}"]`;
    const direct = document.getElementById(formId);
    if (direct instanceof HTMLFormElement) {
      form = direct;
    } else {
      try {
        const wrapped = document.querySelector<HTMLFormElement>(
          `form[data-chatbot-form="${cssEscape(formId)}"], [data-chatbot-form="${cssEscape(formId)}"] form`,
        );
        if (wrapped) form = wrapped;
      } catch {
        // CSS.escape failure means the id is invalid — fall through to auto-discovery.
      }
    }
  }

  if (form === null && selector === '' && formId === '') {
    // Auto-discovery path: pick the first plausible form on the page. Order
    // matters — `main form` wins over `form#crudTable` (Backpack) wins over
    // any generic `.form`. Hosts that want determinism should pass a selector
    // or form_id.
    lookupDescription = 'auto-discovery (main form, form#crudTable, form.form)';
    try {
      form = document.querySelector<HTMLFormElement>(
        'main form, form#crudTable, form.form, form',
      );
      if (form) {
        console.warn(
          '[chatbot:fill_form] neither selector nor form_id was provided; '
          + 'auto-discovered first form on the page. Pass selector or form_id '
          + 'explicitly for deterministic targeting.',
          { form },
        );
      }
    } catch {
      form = null;
    }
  }

  if (form === null) {
    const available = Array.from(document.querySelectorAll('form'))
      .map((f, i) => f.id
        || f.getAttribute('data-chatbot-form')
        || f.parentElement?.getAttribute('bp-section')
        || `form[${i}]`);
    console.warn(
      `[chatbot:fill_form] no form matched ${lookupDescription}.`,
      { availableForms: available },
    );
    return primitiveFail(
      'no_form_matched',
      `fill_form could not locate a form (tried ${lookupDescription || 'auto-discovery'}). `
      + `Available forms on page: ${available.length === 0 ? '(none)' : available.join(', ')}. `
      + `On Backpack default list views there is no <form> for filters — use navigate({url: '?filter=value'}) instead.`,
      {
        attempted: { selector, form_id: formId },
        available_forms: available,
      },
    );
  }

  const missingFields: string[] = [];
  const fields = Array.isArray(args['fields']) ? (args['fields'] as Array<Record<string, unknown>>) : [];
  for (const entry of fields) {
    const name = asString(entry?.['name']);
    if (name === '') continue;
    // Lookup order (v1.1.1, findings #9.c): prefer the friendly alias
    // `[data-chatbot-field="X"]` over the HTML `[name="X"]`. Hosts expose
    // `data-chatbot-field` precisely so the LLM can use a stable label
    // (e.g. `first_option`) instead of an awkward HTML name
    // (e.g. `metadata[options][0][value]`). When both attributes coexist on
    // the same control they target it once.
    let ctrls: NodeListOf<Element>;
    try {
      ctrls = form.querySelectorAll(
        `[data-chatbot-field="${cssEscape(name)}"], [name="${cssEscape(name)}"]`,
      );
    } catch {
      continue;
    }
    if (ctrls.length === 0) {
      const presentNames = Array.from(form.querySelectorAll('[name], [data-chatbot-field]'))
        .map((el) => el.getAttribute('data-chatbot-field') || el.getAttribute('name'))
        .filter((n): n is string => typeof n === 'string' && n !== '');
      console.warn(
        `[chatbot:fill_form] field "${name}" not found in form.`,
        { availableNames: Array.from(new Set(presentNames)) },
      );
      missingFields.push(name);
      continue;
    }
    const value = entry['value'];
    ctrls.forEach((ctrl) => {
      if (!(ctrl instanceof HTMLInputElement
          || ctrl instanceof HTMLTextAreaElement
          || ctrl instanceof HTMLSelectElement)) return;
      const type = ctrl instanceof HTMLInputElement ? (ctrl.type || '').toLowerCase() : '';
      let handled = false;
      if (type === 'checkbox' && ctrl instanceof HTMLInputElement) {
        ctrl.checked = !!value;
        handled = true;
      } else if (type === 'radio' && ctrl instanceof HTMLInputElement) {
        ctrl.checked = String(ctrl.value) === String(value);
        handled = true;
      } else if (type === 'hidden' && ctrl instanceof HTMLInputElement) {
        // v1.1.7 (#32): Backpack boolean fields render as `<input type="hidden"
        // name="X" value="0">` paired with an unnamed `<input type="checkbox">`
        // in the same `.form-check` wrapper. The hidden is the only element
        // carrying `name`, so the [name="X"] lookup matches it exclusively —
        // but the toggleable element is the sibling. When (and only when) we
        // find that exact pattern, toggle the sibling and normalize the hidden
        // to '1'/'0'. Plain hidden inputs without a paired checkbox fall
        // through to the default String(value) write path (CSRF, state fields).
        const wrapper = ctrl.closest('.form-check, .form-group, .form-switch, fieldset, label');
        const pair = wrapper?.querySelector('input[type="checkbox"]:not([name])');
        if (pair instanceof HTMLInputElement) {
          pair.checked = !!value;
          pair.dispatchEvent(new Event('input',  { bubbles: true }));
          pair.dispatchEvent(new Event('change', { bubbles: true }));
          ctrl.value = value ? '1' : '0';
          handled = true;
        }
      }
      if (!handled) {
        ctrl.value = value === null || value === undefined ? '' : String(value);
      }
      ctrl.dispatchEvent(new Event('input',  { bubbles: true }));
      ctrl.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }

  if (args['submit'] === true) {
    if (typeof form.requestSubmit === 'function') form.requestSubmit();
    else form.submit();
  }

  if (missingFields.length > 0) {
    return primitiveFail(
      'fields_not_found',
      `fill_form located the form but ${missingFields.length} field${missingFields.length === 1 ? '' : 's'} `
      + `(${missingFields.join(', ')}) were not present. Other fields were applied.`,
      { missing_fields: missingFields },
    );
  }

  return PRIMITIVE_OK;
};

/**
 * `open_modal` — builds a self-contained overlay (no Bootstrap or other CSS
 * framework dependency). Renders a title, a body (plain text or escaped
 * HTML), and an optional set of action buttons that dispatch back through
 * the chatbot tool cascade. Closes on Escape, backdrop click, or the
 * built-in close button.
 */
const openModal: Primitive = (args) => {
  const title = asString(args['title']);
  const body  = asString(args['body']);
  const actions = Array.isArray(args['actions']) ? (args['actions'] as Array<Record<string, unknown>>) : [];

  const root = document.createElement('div');
  root.setAttribute('role', 'dialog');
  root.setAttribute('aria-modal', 'true');
  Object.assign(root.style, {
    position: 'fixed',
    inset: '0',
    background: 'rgba(0, 0, 0, 0.5)',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    zIndex: '2147483646',
  });

  const card = document.createElement('div');
  Object.assign(card.style, {
    background: '#fff',
    color: '#111',
    minWidth: '300px',
    maxWidth: 'min(90vw, 600px)',
    maxHeight: '80vh',
    overflow: 'auto',
    borderRadius: '8px',
    boxShadow: '0 10px 30px rgba(0, 0, 0, 0.3)',
    padding: '20px',
    font: 'inherit',
  });

  if (title !== '') {
    const h = document.createElement('h3');
    h.style.margin = '0 0 12px';
    h.textContent = title;
    card.appendChild(h);
  }

  if (body !== '') {
    const p = document.createElement('div');
    p.style.marginBottom = '16px';
    p.textContent = body;
    card.appendChild(p);
  }

  function close(): void {
    document.removeEventListener('keydown', onKey);
    if (root.parentNode) root.parentNode.removeChild(root);
  }

  function onKey(e: KeyboardEvent): void {
    if (e.key === 'Escape') close();
  }

  const closeBtn = document.createElement('button');
  closeBtn.type = 'button';
  closeBtn.setAttribute('aria-label', 'Close');
  closeBtn.textContent = '✕';
  Object.assign(closeBtn.style, {
    position: 'absolute', top: '8px', right: '12px',
    background: 'transparent', border: '0', font: 'inherit', fontSize: '20px',
    cursor: 'pointer', color: '#666',
  });
  closeBtn.addEventListener('click', close);
  card.style.position = 'relative';
  card.appendChild(closeBtn);

  if (actions.length > 0) {
    const actionsRow = document.createElement('div');
    Object.assign(actionsRow.style, {
      display: 'flex', gap: '8px', justifyContent: 'flex-end', marginTop: '12px',
    });
    actions.forEach((action) => {
      const label = asString(action['label']);
      if (label === '') return;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = label;
      Object.assign(btn.style, {
        padding: '8px 14px',
        border: '1px solid #ccc',
        borderRadius: '6px',
        background: '#f5f5f5',
        cursor: 'pointer',
        font: 'inherit',
      });
      btn.addEventListener('click', () => {
        const toolName = asString(action['tool']);
        const handler  = toolName !== '' ? window.Chatbot?.__internal.getTool(toolName) : null;
        if (handler) {
          try {
            const argsForHandler = (action['args'] && typeof action['args'] === 'object')
              ? action['args'] as Record<string, unknown>
              : {};
            void handler(argsForHandler, { actionId: 'open_modal_action', confirmation: 'auto' });
          } catch (err) {
            console.error('[chatbot] open_modal action handler threw', err);
          }
        }
        if (action['close_on_click'] !== false) close();
      });
      actionsRow.appendChild(btn);
    });
    card.appendChild(actionsRow);
  }

  root.appendChild(card);
  root.addEventListener('click', (e) => { if (e.target === root) close(); });
  document.addEventListener('keydown', onKey);
  document.body.appendChild(root);
  return PRIMITIVE_OK;
};

/**
 * `invoke_host_action` — generic delegation primitive. Looks up the host
 * registration for the given action name and forwards args; if the host
 * has not registered a handler we warn (the LLM is expected to know the
 * host's catalogue).
 */
const invokeHostAction: Primitive = (args) => {
  const actionName = asString(args['action_name']);
  if (actionName === '') {
    return primitiveFail('empty_action_name', 'invoke_host_action requires a non-empty action_name argument.');
  }
  const handler = window.Chatbot?.__internal.getTool(actionName);
  if (!handler) {
    console.warn(`[chatbot] invoke_host_action: no host action "${actionName}" registered`);
    return primitiveFail(
      'no_handler',
      `invoke_host_action could not find a host action registered as "${actionName}". `
      + `The host should register it via window.Chatbot.registerTool("${actionName}", fn).`,
      { action_name: actionName },
    );
  }
  const inner = (args['args'] && typeof args['args'] === 'object')
    ? args['args'] as Record<string, unknown>
    : {};
  try {
    void handler(inner, { actionId: 'invoke_host_action', confirmation: 'auto' });
  } catch (err) {
    console.error(`[chatbot] invoke_host_action: handler "${actionName}" threw`, err);
    return primitiveFail(
      'handler_threw',
      `invoke_host_action handler "${actionName}" threw: ${err instanceof Error ? err.message : String(err)}.`,
      { action_name: actionName },
    );
  }
  return PRIMITIVE_OK;
};

const PRIMITIVES: Record<string, Primitive> = {
  navigate,
  toggle_visibility: toggleVisibility,
  show_toast: showToastPrim,
  download_file: downloadFile,
  fill_form: fillForm,
  open_modal: openModal,
  invoke_host_action: invokeHostAction,
};

/**
 * Resolves the `tool` field of a `frontend_action` against the cascade
 * host-tool > primitive and runs it. Used both by the auto path
 * (`handleFrontendAction`) and by the confirm path (`confirm.ts` runs this
 * after the user accepts a `confirmation=confirm` action).
 *
 * Returns the structured `PrimitiveResult` (v1.1.3 #16) so the caller can
 * propagate failures back to the backend / LLM. Host-registered tools are
 * treated as opaque (we have no contract on their return shape) and are
 * reported as `{ok:true}` unless they throw synchronously.
 */
export function runPrimitive(
  payload: FrontendActionPayload,
  env: ActionEnvironment,
): PrimitiveResult {
  const handler = window.Chatbot?.__internal.getTool(payload.tool);
  if (handler) {
    try {
      void handler(payload.args, { actionId: payload.action_id, confirmation: payload.confirmation });
      return PRIMITIVE_OK;
    } catch (err) {
      console.error(`[chatbot] tool "${payload.tool}" threw`, err);
      return primitiveFail(
        'handler_threw',
        `Host-registered tool "${payload.tool}" threw: ${err instanceof Error ? err.message : String(err)}.`,
        { tool: payload.tool },
      );
    }
  }

  const primitive = PRIMITIVES[payload.tool];
  if (primitive) {
    try {
      return primitive(payload.args, env);
    } catch (err) {
      console.error(`[chatbot] primitive "${payload.tool}" threw`, err);
      return primitiveFail(
        'primitive_threw',
        `Primitive "${payload.tool}" threw: ${err instanceof Error ? err.message : String(err)}.`,
        { tool: payload.tool },
      );
    }
  }

  console.warn(`[chatbot] no handler registered for frontend tool "${payload.tool}"`);
  return primitiveFail(
    'unknown_tool',
    `No handler registered for frontend tool "${payload.tool}".`,
    { tool: payload.tool },
  );
}

/**
 * Auto-confirmation flow. Called by the widget when a `frontend_action`
 * arrives with `confirmation === 'auto'`. For `confirm`/`manual` the widget
 * routes through `confirm.ts` (banner + REST) instead.
 *
 * v1.1.3 (#16): returns the `PrimitiveResult` so the widget can POST-back
 * the failure (and toast the user) without changing the SSE contract.
 */
export function handleFrontendAction(
  payload: FrontendActionPayload,
  env: ActionEnvironment,
): PrimitiveResult {
  if (payload.confirmation !== 'auto') {
    // Defensive: the widget should have routed this through the confirm
    // banner before reaching here. Falling through without doing anything
    // (no in-memory queue anymore — that was the E12 placeholder).
    console.warn(
      `[chatbot] handleFrontendAction received confirmation="${payload.confirmation}" — `
      + `the widget should route this through the confirm banner.`,
    );
    return primitiveFail(
      'routing_error',
      `Auto handler invoked for confirmation="${payload.confirmation}". `
      + 'This action should have been routed through the confirm banner.',
    );
  }

  return runPrimitive(payload, env);
}

export const __INTERNALS_FOR_TEST = { PRIMITIVES };
