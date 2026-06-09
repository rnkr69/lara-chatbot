/**
 * v2.0 / E5 — entry del bundle `chatbot-dashboard.js`.
 *
 * Espera al DOM ready y bootstrap-ea `DashboardApp` sobre el root inyectado
 * por la blade (`#chatbot-dashboard-root`). Idempotente: si el script se
 * carga dos veces (custom layout del host que duplica `<script>` por error)
 * la segunda invocación es no-op.
 *
 * v2.0 / E7 — antes de arrancar la app, instala el chart renderer built-in
 * según `data-chart-renderer` (inyectado por DashboardController desde
 * `config('chatbot.dashboard.chart_renderer')`):
 *
 *   - `'chartjs'` (default): pre-registra `renderChartBlockChartjs` en
 *     `window.Chatbot.__internal.getBlockRenderer('chart')`. Si `window.Chatbot`
 *     no existe (caso típico: la dashboard view es página dedicada y no carga
 *     `chatbot-widget.js`), montamos un shim mínimo con `registerBlockRenderer`
 *     + `__internal.getBlockRenderer` para que el cascade de `blocks.ts:480`
 *     funcione tal cual. Si `window.Chatbot` YA existe Y el host ya registró
 *     `'chart'` en él (override deliberado), no clobeamos.
 *   - `'none'`: no se registra nada. El cascade cae al placeholder built-in
 *     ("Chart renderer not registered…"). El host puede registrar el suyo
 *     ANTES de cargar este bundle vía un widget loader propio.
 *
 * Expone `window.ChatbotDashboard` con un par de hooks mínimos para que
 * hosts puedan re-iniciar el bundle desde código (p. ej. tras un cambio de
 * usuario en SPA). API deliberadamente pequeña; ampliable cuando E8 añada
 * más superficies.
 */

import { startDashboardApp, type DashboardAppHandle } from './app.js';
import { injectStyles } from './styles.js';
import { renderChartBlockChartjs } from './chart-default.js';
import { parseI18nFromElement, pickObject, type ChatbotDashboardI18n } from '../i18n-bridge.js';
import { setKpiLabels } from '../kpi.js';
import { setChartLabels } from '../blocks.js';
import type { BlockRenderer, ChatbotApi } from '../types.js';

declare global {
  interface Window {
    ChatbotDashboard?: ChatbotDashboardApi;
    Chatbot?: ChatbotApi;
  }
}

interface ChatbotDashboardApi {
  /** Returns the active app handle, or null if no root was found. */
  getHandle(): DashboardAppHandle | null;
}

const SENTINEL = '__chatbot_dashboard_initialized__';
type Globals = { [SENTINEL]?: true };

/**
 * Installs a minimal `window.Chatbot` shim when the widget bundle is not
 * present on the page. Only the two surfaces that `renderBlock()`
 * (`resources/js/blocks.ts`) actually reads are populated:
 *
 *   - `registerBlockRenderer(type, fn)`
 *   - `__internal.getBlockRenderer(type)`
 *
 * The remaining `ChatbotApi` methods are noop stubs (cast through unknown) so
 * the TS types match without forcing every consumer to null-check anew.
 */
function installChatbotShim(): void {
  if (typeof window === 'undefined') return;
  if (window.Chatbot) return; // host loaded the widget bundle first — respect it.
  const renderers = new Map<string, BlockRenderer>();
  const shim = {
    // v2.1.3 (#35) — marker the widget bundle's `installApi()` looks for.
    // When the dashboard bundle loads first, this shim populates `window.Chatbot`
    // so the built-in chart renderer can register through the documented API.
    // When `chatbot-widget.js` runs afterwards, `installApi()` sees this flag,
    // builds the REAL API, copies any renderers already in `renderers`, and
    // overwrites `window.Chatbot`. Without the flag the widget bundle returned
    // this shim back to every host — `whenReady`/`registerTool`/etc. silently
    // no-op'd, leaving the host's JS inert in dashboard layout mode.
    __chatbot_shim__: true as const,
    open: () => undefined,
    close: () => undefined,
    toggle: () => undefined,
    setPageContext: () => undefined,
    clearPageContext: () => undefined,
    registerTool: () => undefined,
    registerBlockRenderer(type: string, renderer: BlockRenderer): void {
      if (typeof type !== 'string' || type === '') return;
      if (typeof renderer !== 'function') return;
      renderers.set(type, renderer);
    },
    registerNavigator: () => undefined,
    setUser: () => undefined,
    newChat: () => undefined,
    whenReady: () => undefined,
    __internal: {
      getTool: () => undefined,
      getBlockRenderer: (type: string): BlockRenderer | undefined => renderers.get(type),
      getNavigator: () => null,
      getPageContext: () => ({}),
      getBearer: () => null,
      emitOpen: () => undefined,
      emitClose: () => undefined,
      emitToggle: () => undefined,
      emitNewChat: () => undefined,
      onOpenRequest: () => undefined,
      onCloseRequest: () => undefined,
      onToggleRequest: () => undefined,
      onNewChatRequest: () => undefined,
    },
  };
  window.Chatbot = shim as unknown as ChatbotApi;
}

function configureChartRenderer(root: HTMLElement): void {
  const rendererName = (root.dataset['chartRenderer'] ?? 'chartjs').toLowerCase();
  if (rendererName !== 'chartjs') return;
  installChatbotShim();
  const existing = window.Chatbot?.__internal.getBlockRenderer('chart');
  if (existing !== undefined) return; // host override wins.
  window.Chatbot?.registerBlockRenderer('chart', renderChartBlockChartjs);
}

/**
 * v2.2 — Reads `data-dashboard-context` from the dashboard root and emits
 * it via `Chatbot.setPageContext({dashboard: {...}})` so the LLM can
 * resolve "the KPI widget" → `widget_id` directly from
 * `page_context.dashboard.widgets`. Without this, the new edit/delete
 * tools (PR-B) would have to fuzzy-match titles against an opaque list
 * (or hallucinate ids).
 *
 * Timing: the dashboard bundle typically loads BEFORE the widget bundle
 * (the dashboard bundle is inlined in `@section('content')`, the widget
 * is pushed to `after_scripts`). At dashboard-boot, `window.Chatbot` is
 * either undefined or the shim (`installChatbotShim` above) — both lack a
 * working `setPageContext`. We therefore queue the call on the widget
 * bundle's `chatbot:ready` event (dispatched by `markReady` in
 * `api.ts:170` AFTER `installApi()` upgrades the shim). In standalone
 * mode the event never fires; that's fine — there is no chat consuming
 * the page_context anyway.
 *
 * Empty / missing attribute → no call (the controller emits `[]` when
 * the user has no dashboards; we avoid setting `dashboard: []` which
 * would pollute the system prompt).
 */
function emitDashboardContext(root: HTMLElement): void {
  if (typeof window === 'undefined') return;
  const raw = root.getAttribute('data-dashboard-context');
  if (!raw) return;
  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch {
    console.warn('[chatbot:dashboard] data-dashboard-context is not parseable JSON');
    return;
  }
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return;
  if (Object.keys(parsed as Record<string, unknown>).length === 0) return;

  const dashboard = parsed as Record<string, unknown>;
  const apply = (): void => {
    const api = window.Chatbot;
    if (!api || typeof api.setPageContext !== 'function') return;
    api.setPageContext({ dashboard });
  };

  // Already ready? — fire immediately. setPageContext on the shim is a
  // noop (standalone w/o widget bundle); on the real API it lands.
  const readyFlag = (window as { __chatbot_widget_ready__?: true }).__chatbot_widget_ready__;
  if (readyFlag === true) {
    apply();
    return;
  }

  if (typeof document !== 'undefined') {
    document.addEventListener('chatbot:ready', apply, { once: true });
  }
}

function bootstrap(): DashboardAppHandle | null {
  const root = document.getElementById('chatbot-dashboard-root');
  if (!(root instanceof HTMLElement)) {
    console.warn('[chatbot:dashboard] #chatbot-dashboard-root not found in DOM');
    return null;
  }
  configureChartRenderer(root);
  emitDashboardContext(root);

  // v2.1 (E14 / #19) — `data-use-bootstrap` is stamped by DashboardController
  // from `chatbot.backpack.use_bootstrap`. When set, the host's Bootstrap
  // (Backpack layout mode) styles the block primitives, so the bundle skips
  // injecting its own block CSS — the Bootstrap classes the renderers always
  // emit take over. The marker class lets host/future CSS branch on the mode.
  const useBootstrap = root.dataset['useBootstrap'] === '1';
  if (useBootstrap) root.classList.add('cb-use-bootstrap');
  injectStyles(useBootstrap);

  // v2.0 / E9 — drain `data-i18n` once at bootstrap. The `dashboard.*` subtree
  // flows down to `startDashboardApp`; `dashboard.kpi.*` is wired into the
  // shared kpi.ts singleton (the widget bundle does the same when it boots on
  // a page that also embeds it — both calls converge on identical labels
  // because the PHP source is the same).
  const i18n = parseI18nFromElement(root);
  const dashboardI18n = (i18n.dashboard ?? {}) as ChatbotDashboardI18n;
  const kpiLabels = pickObject(i18n.dashboard as Record<string, unknown> | undefined, 'kpi');
  if (typeof kpiLabels['no_value'] === 'string' && kpiLabels['no_value'] !== '') {
    setKpiLabels({ no_value: kpiLabels['no_value'] });
  }
  // v2.1.1 (#25) — drain `dashboard.chart` into the chart placeholder's i18n
  // singleton. Like kpi above, the placeholder is a built-in renderer with no
  // labels channel; the invalidData path is dashboard-only (chart-default.ts).
  const chartLabels = pickObject(i18n.dashboard as Record<string, unknown> | undefined, 'chart');
  if (typeof chartLabels['invalid_data'] === 'string' && chartLabels['invalid_data'] !== '') {
    setChartLabels({ invalid_data: chartLabels['invalid_data'] });
  }

  return startDashboardApp({ root, i18n: dashboardI18n });
}

function install(target: Window & Globals = window as Window & Globals): void {
  if (target[SENTINEL]) return;
  target[SENTINEL] = true;

  let handle: DashboardAppHandle | null = null;
  const start = (): void => { handle = bootstrap(); };
  if (typeof document === 'undefined') return;
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    queueMicrotask(start);
  }
  target.ChatbotDashboard = {
    getHandle: () => handle,
  };
}

install();

export { startDashboardApp, configureChartRenderer, installChatbotShim, emitDashboardContext };
