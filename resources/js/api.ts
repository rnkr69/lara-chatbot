import type {
  ChatbotApi,
  ChatbotInternal,
  PageContext,
  ToolHandler,
  BlockRenderer,
  NavigatorFn,
} from './types.js';
import { emitContextChanged } from './page-context.js';

declare global {
  interface Window {
    Chatbot?: ChatbotApi;
  }
}

// v2.1.3 (#35): bundle-specific init flags. Before v2.1.3 the widget bundle
// used the unscoped names `__chatbot_initialized__`/`__chatbot_ready__`, which
// were easy to confuse with `chatbot-dashboard.js`'s `__chatbot_dashboard_initialized__`.
// They never actually collided (each bundle only reads its own flag) but the
// real bug was the shim-installation path below — see `SHIM_FLAG` and
// `installApi()` for the upgrade logic. Renaming here makes the ownership
// obvious to anyone inspecting `window` from devtools.
const SENTINEL = '__chatbot_widget_initialized__';
const READY_FLAG = '__chatbot_widget_ready__';
const READY_EVENT = 'chatbot:ready';
/**
 * v2.1.3 (#35): when `chatbot-dashboard.js` runs before `chatbot-widget.js`
 * (the natural order in a Backpack layout-mode dashboard: the dashboard
 * bundle is inlined inside `@section('content')` and runs before anything
 * pushed to `after_scripts`), the dashboard installs a minimal shim onto
 * `window.Chatbot` (see `dashboard/index.ts::installChatbotShim`) so its
 * built-in chart renderer can register through the documented API. Until
 * v2.1.3, `installApi()` saw an existing `window.Chatbot` and short-circuited,
 * RETURNING THE SHIM — meaning the widget bundle's real `whenReady`/`registerTool`/
 * `registerBlockRenderer`/`registerNavigator` were never installed, host
 * integrations went silently inert (no error, no console warning), and the
 * caveat from #31 was effectively unreachable without a fragile load-order
 * hack on the host side. The shim now tags itself with `SHIM_FLAG` so the
 * widget bundle's `installApi()` can detect it and upgrade in place,
 * preserving any renderers already registered against the shim.
 */
const SHIM_FLAG = '__chatbot_shim__';
type ShimMarker = Record<string, unknown> & {
  [SHIM_FLAG]?: true;
  __internal?: { getBlockRenderer?: (type: string) => BlockRenderer | undefined };
};
type Globals = { [SENTINEL]?: true; [READY_FLAG]?: true };

function makeApi(): ChatbotApi {
  const tools = new Map<string, ToolHandler>();
  const renderers = new Map<string, BlockRenderer>();
  const openListeners = new Set<() => void>();
  const closeListeners = new Set<() => void>();
  const toggleListeners = new Set<() => void>();
  const newChatListeners = new Set<() => void>();
  let pageContext: PageContext = {};
  let bearer: string | null = null;
  let navigator: NavigatorFn | null = null;

  const internal: ChatbotInternal = {
    getTool: (name) => tools.get(name),
    getBlockRenderer: (type) => renderers.get(type),
    getNavigator: () => navigator,
    getPageContext: () => pageContext,
    getBearer: () => bearer,
    emitOpen: () => openListeners.forEach((cb) => cb()),
    emitClose: () => closeListeners.forEach((cb) => cb()),
    emitToggle: () => toggleListeners.forEach((cb) => cb()),
    emitNewChat: () => newChatListeners.forEach((cb) => cb()),
    onOpenRequest: (cb) => { openListeners.add(cb); },
    onCloseRequest: (cb) => { closeListeners.add(cb); },
    onToggleRequest: (cb) => { toggleListeners.add(cb); },
    onNewChatRequest: (cb) => { newChatListeners.add(cb); },
  };

  return {
    open: () => internal.emitOpen(),
    close: () => internal.emitClose(),
    toggle: () => internal.emitToggle(),
    setPageContext(ctx) {
      if (!ctx || typeof ctx !== 'object' || Array.isArray(ctx)) return;
      // E14 D14 + v1.1.8 (#34): one-level-deep merge. Top-level keys from `ctx`
      // overwrite the previous values, preserving keys not present in `ctx`.
      // For keys whose previous AND incoming value are both plain objects
      // (not arrays, not null), the sub-objects get their keys merged instead
      // of the whole object replaced — so a partial update like
      // `setPageContext({ crud: { selected_ids: [...] } })` no longer wipes
      // `crud.form` / `crud.filters` / `crud.entity` emitted server-side.
      // Arrays and primitives still replace wholesale (matches the documented
      // §3.2 semantics for arrays and keeps the contract predictable).
      const merged: Record<string, unknown> = { ...pageContext };
      for (const [k, v] of Object.entries(ctx)) {
        const prev = merged[k];
        if (
          v !== null && typeof v === 'object' && !Array.isArray(v)
          && prev !== null && typeof prev === 'object' && !Array.isArray(prev)
        ) {
          merged[k] = {
            ...(prev as Record<string, unknown>),
            ...(v as Record<string, unknown>),
          };
        } else {
          merged[k] = v;
        }
      }
      pageContext = merged as PageContext;
      emitContextChanged(pageContext);
    },
    clearPageContext() {
      pageContext = {};
      emitContextChanged(pageContext);
    },
    registerTool(name, handler) {
      if (typeof name !== 'string' || name === '') {
        throw new Error('registerTool: name must be a non-empty string');
      }
      if (typeof handler !== 'function') {
        throw new Error('registerTool: handler must be a function');
      }
      tools.set(name, handler);
    },
    registerBlockRenderer(type, renderer) {
      if (typeof type !== 'string' || type === '') {
        throw new Error('registerBlockRenderer: type must be a non-empty string');
      }
      if (typeof renderer !== 'function') {
        throw new Error('registerBlockRenderer: renderer must be a function');
      }
      renderers.set(type, renderer);
    },
    registerNavigator(fn) {
      if (typeof fn !== 'function') {
        throw new Error('registerNavigator: fn must be a function');
      }
      navigator = fn;
    },
    setUser(token) {
      bearer = typeof token === 'string' && token !== '' ? token : null;
    },
    newChat: () => internal.emitNewChat(),
    whenReady(cb) {
      if (typeof cb !== 'function') return;
      const target = window as Window & Globals;
      if (target[READY_FLAG]) {
        // Already ready: defer to a microtask so the contract is consistent
        // (cb never runs synchronously inside whenReady itself, which would
        // surprise hosts wiring side effects after the call site).
        queueMicrotask(() => {
          try { cb(); } catch (err) { console.error('[chatbot] whenReady cb threw', err); }
        });
        return;
      }
      const handler = (): void => {
        try { cb(); } catch (err) { console.error('[chatbot] whenReady cb threw', err); }
      };
      document.addEventListener(READY_EVENT, handler, { once: true });
    },
    __internal: internal,
  };
}

/**
 * v1.1 (findings #8): mark the bundle as fully initialized and emit the
 * `chatbot:ready` event on `document`. Idempotent — only fires once per
 * window. Called from `index.ts` after `defineWidget()` so listeners can
 * trust that both `window.Chatbot.*` and the `<chatbot-widget>` custom
 * element are available.
 */
export function markReady(target: Window & Globals = window as Window & Globals): void {
  if (target[READY_FLAG]) return;
  target[READY_FLAG] = true;
  if (typeof document !== 'undefined' && typeof CustomEvent === 'function') {
    document.dispatchEvent(new CustomEvent(READY_EVENT, {
      detail: { api: target.Chatbot ?? null },
    }));
  }
}

/**
 * Installs the global `window.Chatbot` API exactly once. If a previous instance
 * exists (e.g. the script tag was duplicated by a host bundling mistake), the
 * existing one is preserved and returned — registrations stay coherent.
 *
 * v2.1.3 (#35): exception to "preserve existing" — when the existing
 * `window.Chatbot` is the dashboard bundle's shim (tagged with `SHIM_FLAG`),
 * we BUILD the real API and copy any block renderers the shim accumulated
 * (the dashboard's chart renderer registers through the shim before this
 * function ever runs). Without this upgrade, the widget bundle hands every
 * consumer the shim, whose `whenReady` and `registerTool` are no-ops.
 */
export function installApi(target: Window & Globals = window as Window & Globals): ChatbotApi {
  if (target.Chatbot && target[SENTINEL]) return target.Chatbot;
  const existing = target.Chatbot as (ChatbotApi & ShimMarker) | undefined;
  if (existing && existing[SHIM_FLAG] !== true) {
    target[SENTINEL] = true;
    return existing;
  }
  const api = makeApi();
  if (existing && existing[SHIM_FLAG] === true) {
    // Migrate any renderers already registered against the shim. The shim's
    // internal `getBlockRenderer` reads its private Map; we walk known types
    // (today only `chart` ships built-in, but future shim consumers can grow)
    // and re-register on the real API.
    const shimInternal = existing.__internal;
    if (shimInternal && typeof shimInternal.getBlockRenderer === 'function') {
      const chartRenderer = shimInternal.getBlockRenderer('chart');
      if (typeof chartRenderer === 'function') {
        api.registerBlockRenderer('chart', chartRenderer);
      }
    }
  }
  target.Chatbot = api;
  target[SENTINEL] = true;
  return api;
}
