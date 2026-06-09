import { installApi } from './api.js';
import { WidgetStateMachine } from './state.js';
import { SHADOW_CSS } from './styles.js';
import { BLOCK_STYLES } from './block-styles.js';
import { streamPost } from './sse.js';
import { renderBlock } from './blocks.js';
import { renderMarkdown } from './markdown.js';
import { handleFrontendAction, runPrimitive, type PrimitiveResult } from './actions.js';
import { attachConfirmBanner, deriveConfirmUrl, postConfirm, type ConfirmEnvironment } from './confirm.js';
import { readMetaContext } from './page-context.js';
import { getMode, type RuntimeMode } from './runtime.js';
import { mountSidebar, type SidebarHandle } from './sidebar.js';
import { DashboardApi } from './dashboard/api.js';
import { wrapWithPinButton } from './dashboard/pin-button.js';
import { openPinModal } from './dashboard/pin-modal.js';
import { parseI18nFromElement, pickString, pickObject, type ChatbotI18n } from './i18n-bridge.js';
import { setKpiLabels } from './kpi.js';
import {
  loadState,
  saveState,
  loadActiveConversation,
  saveActiveConversation,
  clearActiveConversation,
  loadActiveUser,
  saveActiveUser,
  makeDebouncedSaver,
  type PersistedState,
  type DebouncedSaver,
} from './persistence.js';
import type {
  BlockPayload,
  ChatMessage,
  FrontendActionPayload,
  SseFrame,
  WidgetMode,
  WidgetState,
} from './types.js';
import { readV2BlockMetadata } from './block-metadata.js';

const OBSERVED_ATTRS = [
  'data-endpoint',
  'data-conversation-id',
  'data-conversations-endpoint',
  'data-position',
  'data-default-open',
  'data-user-id',
  // v2.2.2 (PR-C): `data-theme` was advertised in host snippets (`auto`/
  // `light`/`dark`) but the bundle never read it — the widget picked a
  // mode exclusively from `@media (prefers-color-scheme: dark)` and was
  // deaf to any light/dark toggle the host wired up. Observing it here
  // routes the value through `applyTheme()` which projects the result on
  // `data-theme-effective` so the Shadow CSS can react.
  'data-theme',
  'mode',
] as const;

export class ChatbotWidgetElement extends HTMLElement {
  static get observedAttributes(): readonly string[] { return OBSERVED_ATTRS; }

  private readonly api = installApi();
  private readonly machine = new WidgetStateMachine('closed');
  private shadow!: ShadowRoot;
  private panelEl!: HTMLElement;
  private bodyEl!: HTMLDivElement;
  private inputEl!: HTMLTextAreaElement;
  private sendBtn!: HTMLButtonElement;
  private errorBanner!: HTMLDivElement;
  private launcherEl!: HTMLButtonElement;
  private messages: ChatMessage[] = [];
  private currentAssistant: ChatMessage | null = null;
  private streaming = false;
  private currentStream: { abort(): void } | null = null;
  private conversationId: string | number | null = null;
  private bootstrapped = false;
  private mode: RuntimeMode = 'mpa';
  private widgetMode: WidgetMode = 'widget';
  private saver: DebouncedSaver | null = null;
  private spaNavListener: (() => void) | null = null;
  private confirmDisposers = new Set<() => void>();
  private sidebar: SidebarHandle | null = null;
  private headerLink: HTMLAnchorElement | null = null;
  // v2.0 / E6 — lazy-instantiated for the pin modal. Null until the user
  // clicks 📌 on a pinnable block; null after that if the dashboards
  // endpoint cannot be derived (host did not configure data-endpoint
  // or data-dashboards-endpoint, or the dashboard feature is disabled).
  private dashboardApi: DashboardApi | null = null;
  private pinModalCloser: (() => void) | null = null;
  // v2.0 / E9 — i18n payload parsed from `data-i18n` on the custom element.
  // Empty `{}` is a valid steady state — every consumer uses `pickString`
  // with an inline English fallback, so a missing `data-i18n` keeps the
  // widget working as before.
  private i18n: ChatbotI18n = {};
  // v2.2.2 (PR-C) — runtime signals the widget listens to in `data-theme="auto"`
  // mode so it tracks the host's light/dark toggle (Backpack-Tabler, AdminLTE,
  // Filament — anything that drives `<html data-bs-theme>`) plus changes to
  // the OS `prefers-color-scheme`. All null in `light` / `dark` mode where
  // the value is explicit and not meant to follow anything.
  private themeObserver: MutationObserver | null = null;
  private themePrefersDarkMql: MediaQueryList | null = null;
  private themePrefersDarkListener: (() => void) | null = null;

  connectedCallback(): void {
    if (!this.bootstrapped) this.bootstrap();
    // v2.2.2 (PR-C): resolve the effective light/dark mode and (re-)wire the
    // observers BEFORE rendering decisions so the first paint already has
    // the right CSS variables — avoids a brief flash of the wrong theme on
    // re-mount in MPA hosts where the host's `<html data-bs-theme>` is
    // already set to `light` even though the OS is in dark mode.
    this.applyTheme();
    this.setupThemeObserver();
    // Finding #30 (1.1.4.1): re-run gating on every connect, not just on the
    // very first bootstrap. The widget can be disconnected/reconnected by
    // SPA frameworks moving DOM nodes; in that path `bootstrap()` is skipped
    // (`bootstrapped` is already true) but `rehydrate()` still runs below
    // and would happily restore the previous user's conversationId from
    // storage. Calling gating here guarantees it runs before rehydrate even
    // in the reconnection path. Idempotent and a no-op when stored===current.
    this.applyUserGating(this.getAttribute('data-user-id'));
    // Rehydrate persisted state first; data-default-open is a fallback used
    // when no prior session exists.
    const restored = this.rehydrate();
    if (!restored) {
      const initial = this.getAttribute('data-default-open');
      if (initial === 'true' || initial === '1') {
        this.machine.transition('open');
      }
    }
    // Page mode: the panel is always shown — bypass the floating state machine
    // (the CSS rule `:host([data-mode="page"]) .panel { display: grid !important }`
    // takes care of rendering, but we also force the logical state to `open`
    // so the launcher logic and focus paths stay consistent).
    if (this.widgetMode === 'page' && this.machine.state !== 'open') {
      this.machine.transition('open');
    }
    this.applyStateAttr(this.machine.state);

    // v1.1.1 (finding #14.d): fetch + render suggested prompts in the
    // empty state. Only when there's no current conversation and no
    // pre-fetched messages, to avoid replacing real content.
    if (this.messages.length === 0 && this.conversationId === null) {
      void this.renderSuggestedPrompts();
    }
  }

  attributeChangedCallback(name: string, _old: string | null, value: string | null): void {
    if (name === 'mode') {
      const next: WidgetMode = value === 'page' ? 'page' : 'widget';
      if (next !== this.widgetMode) {
        this.widgetMode = next;
        this.applyModeAttr();
        this.applyModeLayout();
      }
    }
    if (name === 'data-conversation-id') {
      this.conversationId = value && value !== '' ? value : null;
      this.persist();
      // E17 / D16: mirror to localStorage so the widget flotante and the
      // dedicated `/chatbot` page agree on the active conversation across
      // tabs. Null clears the cross-tab key.
      saveActiveConversation(this.conversationId);
      this.sidebar?.setActive(this.conversationId);
      this.refreshHeaderLink();
    }
    if (name === 'data-conversations-endpoint') {
      // Re-mount the sidebar if the page is currently using one.
      if (this.widgetMode === 'page' && this.bootstrapped) {
        this.applyModeLayout();
      }
    }
    if (name === 'data-position') {
      // Mirrored to host attribute already; CSS handles layout.
    }
    if (name === 'data-user-id' && this.bootstrapped) {
      // v1.1.3 (#21) — host can swap users at runtime (SPA login flow); if
      // the new id mismatches what we persisted, drop the previous active
      // conversation to avoid leaking it into the new session.
      this.applyUserGating(value);
    }
    if (name === 'data-theme') {
      // v2.2.2 (PR-C): re-derive the effective mode and rewire the observer
      // — switching auto↔explicit changes whether we need to track the host
      // `<html data-bs-theme>` mutations + the `prefers-color-scheme` MQL.
      this.applyTheme();
      this.teardownThemeObserver();
      this.setupThemeObserver();
    }
  }

  /**
   * v2.2.2 (PR-C) — resolve the effective light/dark mode and project it on
   * `data-theme-effective` so the Shadow CSS can pick the right CSS variable
   * set via `:host([data-theme-effective="dark"])` / `…="light"` selectors.
   *
   * Resolution order:
   *   1. `data-theme="light"` / `"dark"` — explicit, wins.
   *   2. `data-theme="auto"` (or absent) and `<html data-bs-theme>` is
   *      `light` / `dark` — follow the host toggle. Canonical hook for
   *      Bootstrap 5 hosts (Backpack-Tabler, Tabler standalone, AdminLTE
   *      variants, Filament, etc.).
   *   3. Fall back to the `prefers-color-scheme` media query.
   */
  private applyTheme(): void {
    const declared = (this.getAttribute('data-theme') ?? 'auto').toLowerCase();
    let effective: 'light' | 'dark';
    if (declared === 'light' || declared === 'dark') {
      effective = declared;
    } else {
      const bsTheme = document.documentElement.getAttribute('data-bs-theme');
      if (bsTheme === 'light' || bsTheme === 'dark') {
        effective = bsTheme;
      } else {
        const prefersDark = typeof window.matchMedia === 'function'
          && window.matchMedia('(prefers-color-scheme: dark)').matches;
        effective = prefersDark ? 'dark' : 'light';
      }
    }
    if (this.getAttribute('data-theme-effective') !== effective) {
      this.setAttribute('data-theme-effective', effective);
    }
  }

  /**
   * v2.2.2 (PR-C) — observe runtime signals so the widget keeps in sync with
   * the host toggle once the user flips it. Only active in `data-theme="auto"`
   * (or absent); `light` / `dark` are explicit and immune to host signals.
   *
   * Two signals are wired:
   *  - `MutationObserver` on `<html>`'s `data-bs-theme` / `data-theme`
   *    attributes — the canonical client-side toggle in Backpack-Tabler /
   *    Tabler / Bootstrap 5 admin shells.
   *  - `MediaQueryList.change` on `(prefers-color-scheme: dark)` — covers
   *    hosts that delegate to the OS, and OS-level toggles while the page
   *    is open.
   *
   * Idempotent — calling twice does not double-subscribe; tear down first
   * when reconfiguring.
   */
  private setupThemeObserver(): void {
    const declared = (this.getAttribute('data-theme') ?? 'auto').toLowerCase();
    if (declared !== 'auto') return;
    if (this.themeObserver === null && typeof MutationObserver !== 'undefined') {
      this.themeObserver = new MutationObserver(() => this.applyTheme());
      this.themeObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-bs-theme', 'data-theme'],
      });
    }
    if (this.themePrefersDarkMql === null && typeof window.matchMedia === 'function') {
      this.themePrefersDarkMql = window.matchMedia('(prefers-color-scheme: dark)');
      this.themePrefersDarkListener = (): void => this.applyTheme();
      if (typeof this.themePrefersDarkMql.addEventListener === 'function') {
        this.themePrefersDarkMql.addEventListener('change', this.themePrefersDarkListener);
      }
    }
  }

  private teardownThemeObserver(): void {
    if (this.themeObserver !== null) {
      this.themeObserver.disconnect();
      this.themeObserver = null;
    }
    if (this.themePrefersDarkMql !== null && this.themePrefersDarkListener !== null) {
      if (typeof this.themePrefersDarkMql.removeEventListener === 'function') {
        this.themePrefersDarkMql.removeEventListener('change', this.themePrefersDarkListener);
      }
      this.themePrefersDarkMql = null;
      this.themePrefersDarkListener = null;
    }
  }

  private bootstrap(): void {
    this.bootstrapped = true;
    this.mode = getMode();
    this.widgetMode = this.getAttribute('mode') === 'page' ? 'page' : 'widget';
    this.saver = makeDebouncedSaver(250);
    this.shadow = this.attachShadow({ mode: 'open' });
    this.applyModeAttr();

    // v2.0 / E9 — drain the i18n payload from `data-i18n`. Done once at boot;
    // the widget does not observe changes to the attribute (i18n at runtime
    // would require a full re-render of every node that reads a label).
    this.i18n = parseI18nFromElement(this);
    const kpiLabels = pickObject(this.i18n.dashboard as Record<string, unknown> | undefined, 'kpi');
    if (typeof kpiLabels['no_value'] === 'string' && kpiLabels['no_value'] !== '') {
      setKpiLabels({ no_value: kpiLabels['no_value'] });
    }

    const style = document.createElement('style');
    style.textContent = SHADOW_CSS + BLOCK_STYLES;
    this.shadow.appendChild(style);

    // Launcher (closed/minimized state)
    this.launcherEl = document.createElement('button');
    this.launcherEl.type = 'button';
    this.launcherEl.className = 'launcher';
    this.launcherEl.setAttribute('aria-label', 'Open chatbot');
    this.launcherEl.innerHTML = '<span aria-hidden="true">💬</span>';
    this.launcherEl.addEventListener('click', () => this.requestState('open'));
    this.shadow.appendChild(this.launcherEl);

    // Panel
    const panel = document.createElement('section');
    panel.className = 'panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', pickString(this.i18n as Record<string, unknown>, 'title', 'Chatbot'));
    this.panelEl = panel;

    // Header (with named slot for host customization). In widget mode the
    // title is an <a> pointing to /chatbot?conversation_id={current} so the
    // user can open the dedicated page from the floating widget; in page
    // mode we are already there and the title stays a plain <h2>.
    const header = document.createElement('header');
    header.className = 'header';
    const headerSlot = document.createElement('slot');
    headerSlot.name = 'header';
    const title = document.createElement('h2');
    const titleLabel = pickString(this.i18n as Record<string, unknown>, 'title', 'Chatbot');
    const openFullPageLabel = pickString(this.i18n as Record<string, unknown>, 'open_full_page', 'Open full chat page');
    if (this.widgetMode === 'page') {
      title.textContent = titleLabel;
    } else {
      const link = document.createElement('a');
      link.className = 'cb-header-title-link';
      link.textContent = titleLabel;
      link.setAttribute('aria-label', openFullPageLabel);
      link.title = openFullPageLabel;
      const target = this.getAttribute('data-page-target') ?? '_blank';
      link.target = target;
      link.rel = 'noopener noreferrer';
      link.href = this.computePageUrl();
      title.appendChild(link);
      this.headerLink = link;
    }
    headerSlot.appendChild(title);
    header.appendChild(headerSlot);

    const newBtn = document.createElement('button');
    newBtn.type = 'button';
    newBtn.className = 'cb-header-new';
    const newConvLabel = pickString(this.i18n as Record<string, unknown>, 'new_conversation', 'New conversation');
    newBtn.setAttribute('aria-label', newConvLabel);
    newBtn.title = newConvLabel;
    newBtn.textContent = '✎';
    newBtn.addEventListener('click', () => this.startNewConversation());
    const fullBtn = document.createElement('button');
    fullBtn.type = 'button';
    fullBtn.className = 'cb-header-fullscreen';
    fullBtn.setAttribute('aria-label', 'Toggle fullscreen');
    fullBtn.textContent = '⤢';
    fullBtn.addEventListener('click', () => {
      this.requestState(this.machine.state === 'fullscreen' ? 'open' : 'fullscreen');
    });
    const minBtn = document.createElement('button');
    minBtn.type = 'button';
    minBtn.className = 'cb-header-minimize';
    minBtn.setAttribute('aria-label', 'Minimize');
    minBtn.textContent = '—';
    minBtn.addEventListener('click', () => this.requestState('minimized'));
    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'cb-header-close';
    closeBtn.setAttribute('aria-label', 'Close');
    closeBtn.textContent = '✕';
    closeBtn.addEventListener('click', () => this.requestState('closed'));
    header.append(newBtn, fullBtn, minBtn, closeBtn);
    panel.appendChild(header);

    // Error banner (hidden by default)
    this.errorBanner = document.createElement('div');
    this.errorBanner.className = 'error-banner';
    this.errorBanner.hidden = true;
    panel.appendChild(this.errorBanner);

    // Body
    this.bodyEl = document.createElement('div');
    this.bodyEl.className = 'body';
    panel.appendChild(this.bodyEl);

    // Composer (with named slot for footer customization)
    const composer = document.createElement('form');
    composer.className = 'composer';
    composer.addEventListener('submit', (e) => { e.preventDefault(); this.submit(); });
    this.inputEl = document.createElement('textarea');
    this.inputEl.rows = 1;
    // v2.1.2 (#29) — `name` so the field is not flagged by Chrome's "a form
    // field element should have an id or name attribute" a11y issue. The
    // #24 fix covered the dashboard bundle's inputs; this is the same class
    // of fix for the widget bundle's chat textarea.
    this.inputEl.name = 'chatbot_message';
    this.inputEl.placeholder = 'Type a message…';
    this.inputEl.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        this.submit();
      }
    });
    this.inputEl.addEventListener('input', () => this.persist());
    this.sendBtn = document.createElement('button');
    this.sendBtn.type = 'submit';
    this.sendBtn.className = 'send';
    this.sendBtn.textContent = 'Send';
    composer.append(this.inputEl, this.sendBtn);

    const footerSlot = document.createElement('slot');
    footerSlot.name = 'footer';

    panel.append(composer, footerSlot);
    this.shadow.appendChild(panel);

    // Wire global API to local state machine.
    this.api.__internal.onOpenRequest(() => this.requestState('open'));
    this.api.__internal.onCloseRequest(() => this.requestState('closed'));
    this.api.__internal.onToggleRequest(() => {
      this.requestState(this.machine.state === 'open' || this.machine.state === 'fullscreen' ? 'closed' : 'open');
    });
    this.api.__internal.onNewChatRequest(() => this.startNewConversation());
    this.machine.onChange((next) => {
      this.applyStateAttr(next);
      this.persist();
    });

    // v1.1.3 (#21) — Cross-user storage gating. If the auth user changed
    // since the last boot (e.g. logout/login in the same browser), the
    // cross-tab active conversation belongs to the previous user. Clearing
    // it here prevents the new user from inheriting a stale conversation_id
    // that the backend will rightfully 404 on `forUser($user)`.
    this.applyUserGating(this.getAttribute('data-user-id'));

    // Read initial conversation id.
    this.conversationId = this.getAttribute('data-conversation-id');

    // E17: if mode='page', mount the sidebar (it loads conversations and wires
    // selection/delete back into the widget). Idempotent — safe to call again
    // if the mode flips later via attribute change.
    this.applyModeLayout();

    // E14: bootstrap the page context from the declarative meta tag, if any.
    // setPageContext() merges one level deep (v1.1.8 #34) + emits
    // chatbot:context-changed, so host listeners see the initial value too.
    const initialContext = readMetaContext();
    if (Object.keys(initialContext).length > 0) {
      this.api.setPageContext(initialContext);
    }

    // SPA mode: on navigation we (a) re-read the meta tag and emit the change
    // event so listeners and downstream tools stay in sync, and (b) cancel
    // any in-flight stream so a half-rendered response from the previous
    // route does not show up on the new one. The widget itself stays mounted
    // across SPA transitions — UI state and the next turn naturally pick up
    // from where they were.
    if (this.mode === 'spa') {
      const handler = (): void => {
        if (this.currentStream) {
          try { this.currentStream.abort(); } catch { /* ignore */ }
          this.currentStream = null;
        }
        const next = readMetaContext();
        if (Object.keys(next).length > 0) {
          this.api.setPageContext(next);
        }
      };
      window.addEventListener('inertia:navigate', handler);
      window.addEventListener('livewire:navigated', handler);
      window.addEventListener('popstate', handler);
      this.spaNavListener = (): void => {
        window.removeEventListener('inertia:navigate', handler);
        window.removeEventListener('livewire:navigated', handler);
        window.removeEventListener('popstate', handler);
      };
    }
  }

  /**
   * v1.1.3 (#21) — Compare the current auth user id (passed via
   * `data-user-id` or set later via `Chatbot.setUser`) against the one
   * persisted in `localStorage`. If they differ, purge the cross-tab
   * active conversation so the new user starts with an empty widget. The
   * server still validates ownership via `forUser($user)` on every
   * endpoint, but this prevents the UI from briefly showing the previous
   * user's conversation id in URLs and headers.
   */
  private applyUserGating(rawCurrentId: string | null | undefined): void {
    const current = typeof rawCurrentId === 'string' ? rawCurrentId.trim() : '';
    const stored = loadActiveUser();
    if (current === '') {
      // No auth context emitted — leave storage as-is. A guest visit
      // doesn't grant us enough info to decide if the previous tenant
      // matches; the server will gate the actual data.
      return;
    }
    if (stored !== null && stored !== current) {
      clearActiveConversation();
      // Finding #24 (1.1.4): el state per-pestaña en sessionStorage
      // también persiste `conversationId` y sobrevive a logout/login en
      // la misma pestaña. `clearActiveConversation()` arriba sólo borra
      // la clave de localStorage (cross-tab); sin esta purga,
      // `rehydrate()` haría fallback al `state.conversationId` viejo y
      // `saveActiveConversation(effectiveConvId)` re-escribiría la conv
      // del usuario anterior — deshaciendo el gating.
      const tabState = loadState();
      if (tabState.conversationId !== null) {
        saveState({ ...tabState, conversationId: null });
      }
      // Finding #30 (1.1.4.1): el storage queda limpio pero el atributo
      // `data-conversation-id` y `this.conversationId` siguen apuntando
      // a la conv anterior si el HTML los emitía o si el atributo fue
      // procesado por `attributeChangedCallback` antes del gate. En ese
      // caso `rehydrate()` ya no leerá storage pero la propia llamada a
      // `this.setAttribute('data-conversation-id', …)` (o el flush del
      // saver pendiente) re-escribiría el valor viejo a storage. Purga
      // todo el state in-memory + cancela cualquier escritura pendiente.
      this.conversationId = null;
      this.saver?.cancel();
      if (this.hasAttribute('data-conversation-id')) {
        // removeAttribute dispara attributeChangedCallback que pone
        // `this.conversationId=null`, `saveActiveConversation(null)` y
        // schedule un persist con conv=null — redundante pero correcto.
        this.removeAttribute('data-conversation-id');
      }
      this.sidebar?.setActive(null);
    }
    saveActiveUser(current);
  }

  /**
   * Pulls persisted state out of storage (if any) and applies it.
   *
   * Order of precedence for `conversationId` (E17 / D16):
   *   1. `chatbot:active-conversation:v1` in **localStorage** — cross-tab key
   *      shared between widget flotante and `/chatbot`. Wins when present.
   *   2. `chatbot:state:v1` in sessionStorage (E13) — per-tab fallback.
   *
   * `draft` and `isOpen` always come from sessionStorage (per-tab). Page mode
   * ignores `isOpen` entirely (the page is always open by definition).
   *
   * The returned `restored` flag tells `connectedCallback` whether the user
   * had a previous session worth honoring — only `state.isOpen` or
   * `state.draft` qualify. The conversationId alone does NOT mark the session
   * as restored: a host can mount a widget with `data-conversation-id="x"`
   * declaratively (which mirrors to the cross-tab key) without that meaning
   * "the user had the widget open"; in that case `data-default-open` should
   * still apply.
   */
  private rehydrate(): boolean {
    const state = loadState();
    const crossTabActive = loadActiveConversation();
    let restored = false;
    const effectiveConvId = crossTabActive ?? state.conversationId;
    if (effectiveConvId !== null) {
      this.conversationId = effectiveConvId;
      this.setAttribute('data-conversation-id', String(effectiveConvId));
      // If only sessionStorage had it, propagate to localStorage so other
      // tabs / `/chatbot` can pick it up.
      if (crossTabActive === null) saveActiveConversation(effectiveConvId);
      // MPA hosts (Backpack, WP, etc.) remount the widget on every
      // navigation. Without this fetch, the user sees an empty chat after
      // every page change despite the server-side conversation being
      // intact. Fire-and-forget — rehydrate stays sync; the body fills in
      // shortly after the first paint. Pending action banners are
      // re-attached after the history renders so the user can complete
      // any confirm/manual flow they left dangling.
      void this.fetchAndRenderConversation(effectiveConvId).then(() => {
        return this.rehydratePendingActions(effectiveConvId);
      });
    }
    if (state.draft !== '' && this.inputEl) {
      this.inputEl.value = state.draft;
      restored = true;
    }
    if (state.isOpen && this.widgetMode !== 'page') {
      if (this.machine.state === 'closed') this.machine.transition('open');
      restored = true;
    }
    return restored;
  }

  /** Mirror `widgetMode` to a host attribute so CSS hooks can target it. */
  private applyModeAttr(): void {
    this.setAttribute('data-mode', this.widgetMode);
  }

  /**
   * Mount or unmount the sidebar based on the current mode. Called from
   * bootstrap and again whenever `mode` flips via attribute change.
   *
   * Page mode without a derivable conversations endpoint falls back to a
   * sidebar-less layout (the panel grid collapses to a single column). Since
   * v2.2.1 the endpoint is also derived from `data-endpoint` when explicit
   * `data-conversations-endpoint` is missing, so a sidebar-less page only
   * occurs when neither attribute is resolvable (e.g. `data-endpoint` doesn't
   * end in `/stream`).
   */
  private applyModeLayout(): void {
    if (!this.panelEl) return;
    if (this.widgetMode === 'page') {
      const endpoint = this.deriveConversationsEndpoint();
      // Tear down any previous sidebar (mode flip / endpoint change).
      this.sidebar?.destroy();
      this.sidebar = null;
      if (endpoint && endpoint !== '') {
        this.panelEl.classList.remove('cb-page-layout-no-sidebar');
        this.sidebar = mountSidebar(this.panelEl, {
          endpoint,
          bearer: this.api.__internal.getBearer(),
          activeId: this.conversationId,
          onSelect: (id) => this.selectConversation(id),
          onDeleteActive: () => this.clearConversation(),
          onNew: () => this.startNewConversation(),
        });
      } else {
        this.panelEl.classList.add('cb-page-layout-no-sidebar');
      }
    } else {
      // Falling back from page → widget: dispose sidebar.
      this.sidebar?.destroy();
      this.sidebar = null;
      this.panelEl.classList.remove('cb-page-layout-no-sidebar');
    }
  }

  /**
   * Switch the active conversation (called by the sidebar). Persists
   * locally + cross-tab, clears the in-memory message buffer, then fetches
   * and renders the conversation's historical messages so the user sees
   * the full thread immediately.
   */
  private selectConversation(id: string | number): void {
    if (this.conversationId !== null && String(this.conversationId) === String(id)) return;
    if (this.currentStream) {
      try { this.currentStream.abort(); } catch { /* ignore */ }
      this.currentStream = null;
      this.streaming = false;
      if (this.sendBtn) this.sendBtn.disabled = false;
    }
    this.conversationId = id;
    this.setAttribute('data-conversation-id', String(id));
    saveActiveConversation(id);
    this.persist();
    this.refreshHeaderLink();
    void this.fetchAndRenderConversation(id);
  }

  /**
   * Fetch the conversation's messages from `GET /chatbot/conversations/{id}`
   * and render them into the body. Used by selectConversation (sidebar
   * click) and rehydrate (MPA navigation). A 404 means the conversation was
   * deleted by another tab or expired — we drop our local state and
   * refresh the sidebar.
   */
  /**
   * v2.2.1 — Resolve the URL of `GET /chatbot/conversations` (history list +
   * detail). Priority:
   *   1. explicit `data-conversations-endpoint`,
   *   2. derive from `data-endpoint` by stripping `/stream` and appending
   *      `/conversations`.
   *
   * MPA hosts (Backpack, WP, admin shells) remount the widget on every page
   * navigation. Without a way to resolve this endpoint, `fetchAndRenderConversation`
   * silently no-op'd and the user saw an empty chat body after navigating —
   * even though `data-conversation-id` was rehydrated and the conversation
   * existed in the DB. The fallback closes that gap for hosts that copied the
   * canonical mount snippet from `docs/getting-started.md` (which only lists
   * `data-endpoint`). Hosts with non-conventional URLs (custom prefix,
   * subdomain routing, route names remapped) keep declaring it explicitly —
   * `explicit` always wins.
   */
  private deriveConversationsEndpoint(): string | null {
    const explicit = this.getAttribute('data-conversations-endpoint');
    if (explicit && explicit !== '') return explicit;
    const stream = this.getAttribute('data-endpoint') ?? '';
    if (stream !== '' && stream.endsWith('/stream')) {
      return stream.slice(0, -'/stream'.length) + '/conversations';
    }
    return null;
  }

  /**
   * Resolve the URL of `GET /chatbot/actions` (v1.1). Priority:
   *   1. explicit `data-actions-endpoint`,
   *   2. derive from `data-conversations-endpoint` by swapping the trailing
   *      `/conversations` for `/actions`,
   *   3. derive from `data-endpoint` by stripping `/stream` and appending
   *      `/actions`.
   */
  private deriveActionsEndpoint(): string | null {
    const explicit = this.getAttribute('data-actions-endpoint');
    if (explicit && explicit !== '') return explicit;
    const conversations = this.deriveConversationsEndpoint();
    if (conversations !== null) {
      const match = conversations.match(/^(.*)\/conversations(\?.*)?$/);
      if (match) return `${match[1]}/actions${match[2] ?? ''}`;
    }
    const stream = this.getAttribute('data-endpoint') ?? '';
    if (stream !== '' && stream.endsWith('/stream')) {
      return stream.slice(0, -'/stream'.length) + '/actions';
    }
    return null;
  }

  /**
   * v1.1 — best-effort rehydration of pending action banners after a page
   * navigation in MPA hosts. Without this, any `confirm`/`manual` banner
   * created before the nav was lost (no banner to click); the row stayed in
   * `pending` until TTL expiry. Errors are swallowed silently — the worst
   * case is the v1.0 behaviour (the user gets the banner again on the next
   * stream).
   */
  private async rehydratePendingActions(conversationId: string | number): Promise<void> {
    const endpoint = this.deriveActionsEndpoint();
    if (endpoint === null) return;
    const sep = endpoint.includes('?') ? '&' : '?';
    const url = `${endpoint}${sep}status=pending&conversation_id=${encodeURIComponent(String(conversationId))}`;
    const headers: Record<string, string> = { Accept: 'application/json' };
    const bearer = this.api.__internal.getBearer();
    if (bearer && bearer !== '') headers['Authorization'] = `Bearer ${bearer}`;
    let response: Response;
    try {
      response = await fetch(url, { method: 'GET', credentials: 'same-origin', headers });
    } catch { return; }
    if (!response.ok) return;
    let payload: unknown;
    try { payload = await response.json(); }
    catch { return; }
    if (!payload || typeof payload !== 'object') return;
    const data = (payload as Record<string, unknown>)['data'];
    if (!Array.isArray(data)) return;
    for (const row of data) {
      if (!row || typeof row !== 'object') continue;
      const r = row as Record<string, unknown>;
      const tool = typeof r['tool'] === 'string' ? r['tool'] : '';
      const actionId = typeof r['action_id'] === 'string' ? r['action_id'] : '';
      const confirmation = r['confirmation'];
      if (tool === '' || actionId === '') continue;
      if (confirmation !== 'confirm' && confirmation !== 'manual') continue;
      const args = r['args'];
      const flowPayload: FrontendActionPayload = {
        tool,
        args: (args && typeof args === 'object' && !Array.isArray(args))
          ? args as Record<string, unknown>
          : {},
        action_id: actionId,
        confirmation,
      };
      this.attachConfirmFlow(flowPayload);
    }
  }

  private async fetchAndRenderConversation(id: string | number): Promise<void> {
    if (!this.bodyEl) return;
    const endpoint = this.deriveConversationsEndpoint();
    if (endpoint === null) {
      // No endpoint configured and no fallback derivable — clear the body but
      // don't try to fetch.
      this.messages = [];
      this.bodyEl.innerHTML = '';
      return;
    }

    this.messages = [];
    this.bodyEl.innerHTML = '';
    const loading = document.createElement('div');
    loading.className = 'cb-loading';
    loading.textContent = pickString(this.i18n as Record<string, unknown>, 'loading_conversation', 'Loading conversation…');
    this.bodyEl.appendChild(loading);

    let response: Response;
    try {
      const headers: Record<string, string> = { Accept: 'application/json' };
      const bearer = this.api.__internal.getBearer();
      if (bearer && bearer !== '') headers['Authorization'] = `Bearer ${bearer}`;
      response = await fetch(`${endpoint}/${encodeURIComponent(String(id))}`, {
        method: 'GET',
        credentials: 'same-origin',
        headers,
      });
    } catch {
      loading.remove();
      this.showError(pickString(this.i18n as Record<string, unknown>, 'failed_to_load_conversation', 'Failed to load conversation'));
      return;
    }

    loading.remove();

    const failedLabel = pickString(this.i18n as Record<string, unknown>, 'failed_to_load_conversation', 'Failed to load conversation');
    if (!response.ok) {
      if (response.status === 404) {
        // Stale id — drop it so the next turn starts fresh.
        this.clearConversation();
        this.sidebar?.refresh();
        return;
      }
      this.showError(`${failedLabel} (HTTP ${response.status})`);
      return;
    }

    let payload: unknown;
    try { payload = await response.json(); }
    catch { this.showError(failedLabel); return; }

    const messagesData = this.extractMessagesArray(payload);
    if (messagesData.length === 0) return;

    // Server returns messages id desc; render chronologically.
    messagesData.reverse();
    for (const stored of messagesData) {
      const internal = this.adaptStoredMessage(stored);
      if (internal === null) continue;
      this.messages.push(internal);
      this.appendMessageElement(internal);
    }
    this.bodyEl.scrollTop = this.bodyEl.scrollHeight;
  }

  private extractMessagesArray(payload: unknown): Array<Record<string, unknown>> {
    // v2.1.3 (#36): the server returns `{ data: <ConversationResource>, messages: { data: [...] } }` —
    // `additional(['messages' => ...])` puts the messages envelope at the TOP level, sibling of `data`,
    // not nested inside. The previous read path `payload.data.messages.data` always resolved to
    // undefined → 0 messages rendered, so the dedicated page (`/chatbot?conversation_id=…`) and
    // sidebar clicks both painted an empty body despite a 200 OK response. The Pest assertion in
    // `tests/Feature/Http/ConversationControllerTest.php` (line ~259) reads `messages.data` —
    // matching the shape we read here.
    if (!payload || typeof payload !== 'object') return [];
    const messages = (payload as Record<string, unknown>)['messages'];
    if (!messages || typeof messages !== 'object') return [];
    const inner = (messages as Record<string, unknown>)['data'];
    return Array.isArray(inner) ? inner as Array<Record<string, unknown>> : [];
  }

  /**
   * v2.2.1 (PR-B) — emit a `chatbot:dashboard-mutation` CustomEvent at the
   * `document` level when a freshly-streamed block carries
   * `meta.side_effects`. The dashboard bundle (if mounted on the same page)
   * subscribes and refreshes the UI without F5. Namespace `chatbot:` mirrors
   * the existing `chatbot:ready` / `chatbot:page-context-changed` events.
   *
   * Defensive: validates the bag is an object with a string `type` before
   * dispatching. Other shapes are ignored — the event contract is small and
   * a malformed `meta` (host renderer munging the stream, future tool author
   * stamping nonsense) shouldn't crash the chat. The `detail` is the entire
   * `side_effects` object — listeners switch on `detail.type`.
   */
  private maybeEmitSideEffects(block: BlockPayload): void {
    const meta = block.meta;
    if (!meta || typeof meta !== 'object') return;
    const raw = (meta as Record<string, unknown>)['side_effects'];
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return;
    const detail = raw as Record<string, unknown>;
    if (typeof detail['type'] !== 'string' || detail['type'] === '') return;
    if (typeof document === 'undefined') return;
    document.dispatchEvent(new CustomEvent('chatbot:dashboard-mutation', { detail }));
  }

  private adaptStoredMessage(stored: Record<string, unknown>): ChatMessage | null {
    const role = stored['role'];
    if (role !== 'user' && role !== 'assistant') return null;
    const id = stored['id'];
    const idStr = typeof id === 'number' || typeof id === 'string' ? String(id) : '';
    if (idStr === '') return null;

    const content = Array.isArray(stored['content']) ? stored['content'] : [];
    let text = '';
    const blocks: BlockPayload[] = [];
    for (const blk of content) {
      if (!blk || typeof blk !== 'object') continue;
      const blkType = (blk as Record<string, unknown>)['type'];
      if (blkType === 'text') {
        const t = (blk as Record<string, unknown>)['text'];
        if (typeof t === 'string') text += t;
      } else if (typeof blkType === 'string' && blkType !== '') {
        const data = (blk as Record<string, unknown>)['data'];
        const payload: BlockPayload = {
          type: blkType,
          data: (data && typeof data === 'object' && !Array.isArray(data))
            ? data as Record<string, unknown>
            : {},
        };
        // v2.0 — propagate id/source/pinnable when the stored shape carries
        // them. Persisted messages from v1.x simply lack these fields and
        // load as plain blocks.
        readV2BlockMetadata(blk as Record<string, unknown>, payload);
        blocks.push(payload);
      }
    }

    return {
      id: role === 'user' ? `u-${idStr}` : `a-${idStr}`,
      role,
      text,
      blocks,
      pending: false,
    };
  }

  /**
   * Compute the URL of the dedicated page for the current conversation. In
   * priority: `data-page-url` host override → `data-endpoint` with `/stream`
   * stripped. Appends `?conversation_id=X` if a conversation is active.
   */
  private computePageUrl(): string {
    const explicit = this.getAttribute('data-page-url');
    let base: string | null = null;
    if (explicit && explicit !== '') {
      base = explicit;
    } else {
      const endpoint = this.getAttribute('data-endpoint') ?? '';
      if (endpoint !== '' && endpoint.endsWith('/stream')) {
        base = endpoint.slice(0, -'/stream'.length);
      }
    }
    if (base === null) return '#';
    const id = this.conversationId;
    if (id === null || id === '') return base;
    const sep = base.includes('?') ? '&' : '?';
    return `${base}${sep}conversation_id=${encodeURIComponent(String(id))}`;
  }

  /** Refresh the header link's href when the active conversation changes. */
  private refreshHeaderLink(): void {
    if (this.headerLink) {
      this.headerLink.href = this.computePageUrl();
    }
  }

  /**
   * Start a fresh conversation: aborts any in-flight stream, drops the active
   * id and visible history, deselects the sidebar item if present, refocuses
   * the input. The next message creates the conversation server-side.
   */
  private startNewConversation(): void {
    if (this.currentStream) {
      try { this.currentStream.abort(); } catch { /* ignore */ }
      this.currentStream = null;
      this.streaming = false;
      if (this.sendBtn) this.sendBtn.disabled = false;
    }
    this.clearConversation();
    this.sidebar?.setActive(null);
    queueMicrotask(() => this.inputEl?.focus());
  }

  /** Drop the active conversation (page mode + sidebar deleted the active). */
  private clearConversation(): void {
    this.conversationId = null;
    this.removeAttribute('data-conversation-id');
    clearActiveConversation();
    this.messages = [];
    if (this.bodyEl) this.bodyEl.innerHTML = '';
    this.persist();
    this.refreshHeaderLink();
    // v1.1.1: re-render the empty-state suggestions when the user lands on
    // a fresh conversation.
    void this.renderSuggestedPrompts();
  }

  /**
   * v1.1.1 (finding #14.d) — fetch + render the configured suggested
   * prompts inside the empty body. No-op if the endpoint isn't reachable,
   * if there are no prompts configured, or if the body already has
   * content (defensive — never replace real messages).
   */
  private async renderSuggestedPrompts(): Promise<void> {
    if (!this.bodyEl || this.bodyEl.children.length > 0) return;

    const endpoint = this.getSuggestedPromptsEndpoint();
    if (endpoint === '') return;

    let data: Array<{ label: string; prompt: string }> = [];
    try {
      const headers: Record<string, string> = { Accept: 'application/json' };
      const bearer = this.api.__internal.getBearer();
      if (bearer && bearer !== '') headers['Authorization'] = `Bearer ${bearer}`;
      const response = await fetch(endpoint, { method: 'GET', credentials: 'same-origin', headers });
      if (!response.ok) return;
      const payload = await response.json();
      const raw = (payload && typeof payload === 'object' && 'data' in payload) ? (payload as Record<string, unknown>)['data'] : [];
      if (!Array.isArray(raw)) return;
      data = raw.filter((it): it is { label: string; prompt: string } => {
        return !!it && typeof it === 'object'
          && typeof (it as Record<string, unknown>)['label'] === 'string'
          && typeof (it as Record<string, unknown>)['prompt'] === 'string';
      });
    } catch { return; }

    if (data.length === 0) return;
    if (!this.bodyEl || this.bodyEl.children.length > 0) return; // race guard

    const wrap = document.createElement('div');
    wrap.className = 'cb-suggested-prompts';
    data.forEach((item) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = item.label;
      btn.addEventListener('click', () => {
        wrap.remove();
        if (this.inputEl) this.inputEl.value = item.prompt;
        this.send(item.prompt);
      });
      wrap.appendChild(btn);
    });
    this.bodyEl.appendChild(wrap);
  }

  /**
   * Locates the suggested prompts endpoint. Hosts can override via the
   * `data-suggested-prompts-endpoint` attribute on the widget; otherwise
   * we derive it from the conversations endpoint (sibling `/suggested-prompts`).
   */
  private getSuggestedPromptsEndpoint(): string {
    const explicit = this.getAttribute('data-suggested-prompts-endpoint');
    if (explicit && explicit !== '') return explicit;
    const convs = this.deriveConversationsEndpoint();
    if (convs !== null) {
      return convs.replace(/\/conversations\/?$/, '/suggested-prompts');
    }
    // Default for floating widgets that don't pass an explicit endpoint:
    // hit the default route under the package's prefix. Hosts that mounted
    // the package under a custom prefix should pass `data-suggested-prompts-endpoint`.
    const ep = this.getAttribute('data-endpoint');
    if (ep && ep !== '') {
      return ep.replace(/\/stream\/?$/, '/suggested-prompts');
    }
    return '/chatbot/suggested-prompts';
  }

  private persist(): void {
    if (!this.saver || !this.inputEl) return;
    const snapshot: PersistedState = {
      conversationId: this.conversationId,
      isOpen: this.machine.state === 'open' || this.machine.state === 'fullscreen',
      draft: this.inputEl.value,
    };
    this.saver.save(snapshot);
  }

  private applyStateAttr(state: WidgetState): void {
    this.setAttribute('data-state', state);
  }

  private requestState(next: WidgetState): void {
    if (next === this.machine.state) return;
    if (!this.machine.canTransition(next)) return;
    this.machine.transition(next);
    if (next === 'open' || next === 'fullscreen') {
      // Defer focus until next microtask so the textarea is laid out.
      queueMicrotask(() => this.inputEl?.focus());
    }
  }

  private submit(): void {
    if (this.streaming) return;
    const value = this.inputEl.value.trim();
    if (value === '') return;
    this.inputEl.value = '';
    this.persist();
    this.send(value);
  }

  private send(text: string): void {
    const endpoint = this.getAttribute('data-endpoint');
    if (!endpoint || endpoint === '') {
      this.showError('Missing data-endpoint attribute on <chatbot-widget>');
      return;
    }
    const userMsg: ChatMessage = {
      id: `u-${Date.now()}`,
      role: 'user',
      text,
      blocks: [],
      pending: false,
    };
    this.messages.push(userMsg);
    this.appendMessageElement(userMsg);

    const assistant: ChatMessage = {
      id: `a-${Date.now()}`,
      role: 'assistant',
      text: '',
      blocks: [],
      pending: true,
    };
    this.messages.push(assistant);
    this.currentAssistant = assistant;
    this.appendMessageElement(assistant);

    this.streaming = true;
    this.sendBtn.disabled = true;

    const body: Record<string, unknown> = { message: text };
    if (this.conversationId !== null && this.conversationId !== '') body['conversation_id'] = this.conversationId;
    const ctx = this.api.__internal.getPageContext();
    if (Object.keys(ctx).length > 0) body['page_context'] = ctx;

    this.currentStream = streamPost(
      {
        url: endpoint,
        body,
        bearer: this.api.__internal.getBearer(),
      },
      {
        onFrame: (frame) => this.handleFrame(frame),
        onError: (msg, code) => {
          this.showError(`${code ?? 'error'}: ${msg}`);
        },
        onClose: (reason) => {
          this.streaming = false;
          this.sendBtn.disabled = false;
          this.currentStream = null;
          if (this.currentAssistant) {
            this.currentAssistant.pending = false;
            this.refreshAssistantNode(this.currentAssistant);
            this.currentAssistant = null;
          }
          if (reason === 'fatal' || reason === 'rate_limited') {
            // already surfaced via onError
          }
        },
      },
    );
  }

  private handleFrame(frame: SseFrame): void {
    switch (frame.event) {
      case 'text': {
        const delta = typeof frame.data['delta'] === 'string' ? frame.data['delta'] : '';
        if (this.currentAssistant) {
          this.currentAssistant.text += delta;
          this.refreshAssistantNode(this.currentAssistant);
        }
        return;
      }
      case 'block': {
        const type = typeof frame.data['type'] === 'string' ? frame.data['type'] : '';
        const data = (frame.data['data'] && typeof frame.data['data'] === 'object')
          ? (frame.data['data'] as Record<string, unknown>)
          : {};
        if (this.currentAssistant && type !== '') {
          const block: BlockPayload = { type, data };
          // v2.0 — pick up id/source/pinnable stamped by the SSE orchestrator
          // (E1). Optional and back-compat: blocks from v1 emitters arrive
          // without them and remain anonymous/non-pinnable.
          readV2BlockMetadata(frame.data, block);
          // v2.2.1 (PR-B) — emit `chatbot:dashboard-mutation` when the block
          // carries a `side_effects` bag so the dashboard bundle (if mounted
          // on the same page) refreshes the UI without F5. Dispatched only on
          // the live frame (not on history hydration via fetchAndRenderConversation
          // / loadPersistedHistory) — replaying a side effect would double-fire
          // refreshes against state that already reflects the mutation.
          this.maybeEmitSideEffects(block);
          this.currentAssistant.blocks.push(block);
          this.refreshAssistantNode(this.currentAssistant);
        }
        return;
      }
      case 'frontend_action': {
        const payload = frame.data as unknown as FrontendActionPayload;
        if (typeof payload.tool !== 'string' || payload.tool === '') return;
        // E15: RenderBlockTool emits a frontend_action with tool="render_block"
        // and args={type, data}. We intercept here — instead of running it as
        // a side-effect tool, we push the block into the current assistant
        // message so the renderer cascade (host renderer > template > builtin)
        // produces the visible content. Keeping this in handleFrame (not in
        // handleFrontendAction) preserves actions.ts's "pure side-effect"
        // surface and avoids leaking widget state into the primitive.
        if (payload.tool === 'render_block') {
          const type = typeof payload.args?.['type'] === 'string' ? payload.args['type'] as string : '';
          const raw = payload.args?.['data'];
          const data = (raw && typeof raw === 'object' && !Array.isArray(raw))
            ? (raw as Record<string, unknown>)
            : {};
          if (this.currentAssistant && type !== '') {
            const block: BlockPayload = { type, data };
            // v2.0 — same v2 metadata extraction as the dedicated `block`
            // frame. The orchestrator merges id/source/pinnable into
            // `frontend_action.args` when stamping render_block calls.
            readV2BlockMetadata(payload.args as Record<string, unknown>, block);
            this.currentAssistant.blocks.push(block);
            this.refreshAssistantNode(this.currentAssistant);
          }
          return;
        }
        // E16: confirm/manual go through the banner + REST flow; only `auto`
        // executes the primitive immediately.
        if (payload.confirmation !== 'auto') {
          this.attachConfirmFlow(payload);
          return;
        }
        // v1.1.3 (#16): primitives now return a structured PrimitiveResult.
        // On failure: surface a toast to the user and POST-back to the
        // backend so the LLM sees the failure on its next turn. Success is
        // skipped (no HTTP, no toast) so the happy path stays as cheap as
        // before — every show_toast/render_block on a hot stream would
        // otherwise hit the server with a noop.
        const outcome = handleFrontendAction(payload, {
          hostElement: this,
          showToast: (msg, duration) => this.showToast(msg, duration),
        });
        if (outcome.ok === false) {
          this.reportAutoActionFailure(payload, outcome);
        }
        return;
      }
      case 'tool_call': {
        // v1.1.1 (finding #14.e): surface an ephemeral "Calling X…" status
        // line on the current assistant message so multi-step turns don't
        // feel hung during the 5–15s the LLM spends in tool cascades.
        const tname = typeof frame.data['name'] === 'string' ? frame.data['name'] : '';
        if (tname !== '' && this.currentAssistant) {
          this.setToolCallStatus(this.currentAssistant, `Calling ${tname}…`);
        }
        return;
      }
      case 'tool_result': {
        // Backend tool finished — clear the status line. The text/block
        // events that follow will continue updating the assistant message.
        if (this.currentAssistant) {
          this.setToolCallStatus(this.currentAssistant, '');
        }
        return;
      }
      case 'error': {
        const raw = typeof frame.data['message'] === 'string' ? frame.data['message'] : 'Stream error';
        // v2.1 (#3) — the raw provider message is technical (e.g. "Connection
        // refused for URI https://…"). Log it for operators, show the user a
        // localized non-technical message — AND write it into the assistant
        // message, which otherwise rendered completely empty with no feedback.
        console.error('[chatbot] stream error frame:', raw);
        const localized = pickString(
          this.i18n as Record<string, unknown>,
          'stream_error',
          'Something went wrong. Please try again.',
        );
        this.showError(localized);
        if (this.currentAssistant) {
          this.currentAssistant.error = localized;
          this.refreshAssistantNode(this.currentAssistant);
        }
        return;
      }
      case 'done': {
        const id = frame.data['message_id'];
        if (this.currentAssistant && (typeof id === 'number' || typeof id === 'string')) {
          const newId = `a-${id}`;
          // v1.1.7 (#33): mirror the id change into the DOM so the imminent
          // refreshAssistantNode() inside onClose can still locate the node.
          // Without this, the message DOM still carries the timestamp-based id
          // assigned at send() time, refreshAssistantNode early-returns, the
          // `pending` class never gets removed, and the `▍` streaming cursor
          // pseudo-element stays visible forever — one new ▍ per turn.
          const node = this.bodyEl.querySelector<HTMLElement>(
            `[data-msg-id="${CSS.escape(this.currentAssistant.id)}"]`,
          );
          if (node) node.dataset['msgId'] = newId;
          this.currentAssistant.id = newId;
        }
        // Backend now emits the conversation_id (server-assigned for fresh
        // conversations) so the widget can persist it cross-tab. Without
        // this, every page reload spawned a new conversation in MPA hosts.
        const convIdRaw = frame.data['conversation_id'];
        let convChanged = false;
        if ((typeof convIdRaw === 'number' || typeof convIdRaw === 'string') && convIdRaw !== '') {
          if (String(this.conversationId ?? '') !== String(convIdRaw)) {
            this.conversationId = convIdRaw;
            this.setAttribute('data-conversation-id', String(convIdRaw));
            saveActiveConversation(convIdRaw);
            this.persist();
            this.refreshHeaderLink();
            convChanged = true;
          }
        }
        // Auto-titling (v1.1): when ChatService derives a title from the
        // first user message, refresh the sidebar so the user sees the
        // generated title without an extra round-trip. Even when the conv id
        // didn't change, a freshly generated title justifies a refresh.
        const titleRaw = frame.data['conversation_title'];
        if (convChanged || (typeof titleRaw === 'string' && titleRaw !== '')) {
          this.sidebar?.refresh();
        }
        // The `done` frame triggers onClose in the SSE reader, which finalizes UI.
        return;
      }
    }
  }

  private appendMessageElement(msg: ChatMessage): void {
    const node = document.createElement('div');
    node.className = `msg ${msg.role}`;
    node.dataset['msgId'] = msg.id;
    if (msg.role === 'user') {
      node.textContent = msg.text;
    } else {
      this.fillAssistantNode(node, msg);
    }
    this.bodyEl.appendChild(node);
    this.bodyEl.scrollTop = this.bodyEl.scrollHeight;
  }

  private fillAssistantNode(node: HTMLElement, msg: ChatMessage): void {
    // Preserve confirm banners that live as direct children — they belong to
    // a separate UI lifecycle (the pending action) and must survive arbitrary
    // text deltas re-rendering the message. Without this stash/restore the
    // LLM emitting any text after a tool call destroys the banner before the
    // user can click, leaving a stuck pending_action.
    const banners = Array.from(node.querySelectorAll<HTMLElement>(':scope > .cb-confirm-banner'));
    // v1.1.1: same treatment for the ephemeral "Calling X…" tool-call
    // status line so text deltas during a multi-step tool cascade don't
    // wipe the indicator the user is reading.
    const toolStatus = Array.from(node.querySelectorAll<HTMLElement>(':scope > .cb-tool-status'));
    node.innerHTML = '';
    if (msg.text !== '') {
      const text = document.createElement('div');
      text.innerHTML = renderMarkdown(msg.text);
      node.appendChild(text);
    }
    for (const block of msg.blocks) {
      const blockEl = renderBlock(block, { send: (prompt) => this.send(prompt) });
      // v2.0 / E6 — wrap with the pin button overlay when the SSE
      // orchestrator (E1) stamped pinnable=true + source. The wrapper
      // is a no-op for v1 blocks and for tools that did not opt into
      // pinnable() — it just returns blockEl as-is. Re-rendering is
      // safe (no internal state in the wrapper) so refreshAssistantNode
      // running on every text delta does not break click handlers
      // (the modal lives outside this node, in the shadow root).
      const wrapped = wrapWithPinButton({
        block,
        rendered: blockEl,
        onPin: (b) => this.openPinModalForBlock(b),
        labels: this.pinButtonLabels(),
      });
      node.appendChild(wrapped);
    }
    // v2.1 (#3) — a stream `event: error` frame would otherwise leave the
    // assistant message with no text, no blocks and no visible feedback.
    if (msg.error !== undefined && msg.error !== '') {
      const errEl = document.createElement('div');
      errEl.className = 'cb-block-error';
      errEl.setAttribute('role', 'alert');
      errEl.textContent = msg.error;
      node.appendChild(errEl);
      node.classList.add('failed');
    } else {
      node.classList.remove('failed');
    }
    toolStatus.forEach((s) => node.appendChild(s));
    banners.forEach((banner) => node.appendChild(banner));
    if (msg.pending) node.classList.add('pending');
    else node.classList.remove('pending');
  }

  private refreshAssistantNode(msg: ChatMessage): void {
    const node = this.bodyEl.querySelector<HTMLElement>(`[data-msg-id="${CSS.escape(msg.id)}"]`);
    if (!node) {
      // v1.1.7 (#33, defensive): if the model id ever drifts out of sync with
      // the DOM again, surface it loudly instead of leaving a stuck `pending`
      // class on screen. The primary fix above keeps these in sync; this
      // warn exists so a future regression of the same shape gets caught.
      console.warn('[chatbot] refreshAssistantNode: no DOM node for', msg.id);
      return;
    }
    this.fillAssistantNode(node, msg);
    this.bodyEl.scrollTop = this.bodyEl.scrollHeight;
  }

  /**
   * v1.1.1 (finding #14.e): renders an ephemeral "Calling X…" status line
   * as a direct child of the assistant message. Replaces any prior status
   * (only one indicator at a time). Empty `text` removes the indicator.
   */
  private setToolCallStatus(msg: ChatMessage, text: string): void {
    const node = this.findAssistantNode(msg);
    if (!node) return;
    const existing = node.querySelector<HTMLElement>(':scope > .cb-tool-status');
    if (text === '') {
      if (existing) existing.remove();
      return;
    }
    let el = existing;
    if (!el) {
      el = document.createElement('div');
      el.className = 'cb-tool-status';
      node.appendChild(el);
    }
    el.textContent = text;
  }

  /**
   * E16: a `frontend_action` with `confirmation !== 'auto'` arrived. Attach a
   * banner under the current assistant message (if any) or under the body —
   * the banner orchestrates the REST flow and runs the primitive locally on
   * accept (for `confirm`) or reports done/not-done (for `manual`).
   */
  private attachConfirmFlow(payload: FrontendActionPayload): void {
    const parent = this.findAssistantNode(this.currentAssistant) ?? this.bodyEl;
    const env: ConfirmEnvironment = {
      parent,
      showToast: (msg, duration) => this.showToast(msg, duration),
      executePrimitive: (p) => Promise.resolve(runPrimitive(p, {
        hostElement: this,
        showToast: (msg, duration) => this.showToast(msg, duration),
      })),
      streamEndpoint: this.getAttribute('data-endpoint') ?? '',
      bearer: this.api.__internal.getBearer(),
    };
    const dispose = attachConfirmBanner(payload, env);
    this.confirmDisposers.add(dispose);
    this.bodyEl.scrollTop = this.bodyEl.scrollHeight;
  }

  /**
   * v1.1.3 (#16) — report a failed `confirmation=auto` primitive back to
   * the backend. Toasts the user immediately (so they don't think a silent
   * "filters applied ✅" was real) and POSTs `{accept:true, result:{ok:false,
   * error,message,...}}` to the confirm endpoint. The backend transitions
   * the pre-persisted PendingAction row to `executed` with the failure
   * payload; SystemPromptBuilder then surfaces it as `[FAILED]` on the
   * LLM's next turn.
   *
   * On the happy path (PrimitiveResult.ok === true) this method is not
   * called at all — no HTTP, no toast.
   */
  private reportAutoActionFailure(
    payload: FrontendActionPayload,
    outcome: PrimitiveResult,
  ): void {
    if (outcome.ok !== false) return;

    const toastMessage = `[chatbot] ${payload.tool} failed: ${outcome.message ?? outcome.error}`;
    this.showToast(toastMessage, 5000);

    const streamEndpoint = this.getAttribute('data-endpoint') ?? '';
    if (streamEndpoint === '' || !payload.action_id) return;
    const url = deriveConfirmUrl(streamEndpoint, payload.action_id);
    // Strip nothing — forward the entire failure payload so the LLM sees
    // the structured `error` / `message` / available_forms / etc.
    const result: Record<string, unknown> = { ...outcome };
    const bearer = this.api.__internal.getBearer();
    void postConfirm(url, { accept: true, result }, bearer);
  }

  private findAssistantNode(msg: ChatMessage | null): HTMLElement | null {
    if (msg === null) return null;
    return this.bodyEl.querySelector<HTMLElement>(`[data-msg-id="${CSS.escape(msg.id)}"]`);
  }

  private showError(message: string): void {
    this.errorBanner.textContent = message;
    this.errorBanner.hidden = false;
    window.setTimeout(() => { this.errorBanner.hidden = true; }, 6000);
  }

  private showToast(message: string, durationMs: number): void {
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.textContent = message;
    this.shadow.appendChild(toast);
    window.setTimeout(() => toast.remove(), durationMs);
  }

  /**
   * v2.0 / E6 — derive the dashboards JSON CRUD base URL from the host
   * attributes. Priority:
   *   1. explicit `data-dashboards-endpoint`,
   *   2. derive from `data-conversations-endpoint` by replacing the
   *      trailing `/conversations` with `/dashboards`,
   *   3. derive from `data-endpoint` by stripping `/stream` and
   *      appending `/dashboards`.
   * Returns null when neither attribute is configured (host has no
   * `/chatbot` mount path inferable) — the modal then cannot open and
   * we surface a console warning instead of throwing.
   */
  private deriveDashboardsEndpoint(): string | null {
    const explicit = this.getAttribute('data-dashboards-endpoint');
    if (explicit && explicit !== '') return explicit;
    const conversations = this.deriveConversationsEndpoint();
    if (conversations !== null) {
      const match = conversations.match(/^(.*)\/conversations(\?.*)?$/);
      if (match) return `${match[1]}/dashboards${match[2] ?? ''}`;
    }
    const stream = this.getAttribute('data-endpoint') ?? '';
    if (stream !== '' && stream.endsWith('/stream')) {
      return stream.slice(0, -'/stream'.length) + '/dashboards';
    }
    return null;
  }

  /**
   * v2.0 / E6 — URL of `/chatbot/dashboard?dashboard={slug}` for the
   * "View dashboard" link in the success toast. Mirror of
   * `computePageUrl()`. Priority: `data-dashboard-url` host override →
   * derive from `data-endpoint`. Returns null when not derivable.
   */
  private deriveDashboardPageUrl(slug: string): string | null {
    const explicit = this.getAttribute('data-dashboard-url');
    let base: string | null = null;
    if (explicit && explicit !== '') {
      base = explicit;
    } else {
      const endpoint = this.getAttribute('data-endpoint') ?? '';
      if (endpoint !== '' && endpoint.endsWith('/stream')) {
        base = endpoint.slice(0, -'/stream'.length) + '/dashboard';
      }
    }
    if (base === null) return null;
    const sep = base.includes('?') ? '&' : '?';
    return `${base}${sep}dashboard=${encodeURIComponent(slug)}`;
  }

  private getOrCreateDashboardApi(): DashboardApi | null {
    if (this.dashboardApi !== null) return this.dashboardApi;
    const endpoint = this.deriveDashboardsEndpoint();
    if (endpoint === null) return null;
    this.dashboardApi = new DashboardApi({
      endpoint,
      bearer: this.api.__internal.getBearer(),
    });
    return this.dashboardApi;
  }

  /**
   * v2.0 / E6 — open the pin modal for the given block. Idempotent:
   * if a modal is already open, reuses it (closes-then-opens). The
   * modal lives in the shadow root so the dim covers only the panel.
   */
  private openPinModalForBlock(block: BlockPayload): void {
    const api = this.getOrCreateDashboardApi();
    if (api === null) {
      console.warn('[chatbot] cannot open pin modal: dashboards endpoint not configured.');
      return;
    }
    if (this.pinModalCloser) {
      try { this.pinModalCloser(); } catch { /* ignore */ }
      this.pinModalCloser = null;
    }
    const handle = openPinModal(this.shadow, {
      block,
      api,
      pageContext: this.api.__internal.getPageContext(),
      labels: this.pinModalLabels(),
      onSuccess: ({ dashboardSlug, dashboardName }) => {
        this.pinModalCloser = null;
        this.showPinSuccessToast(dashboardName, dashboardSlug);
      },
      onClose: () => { this.pinModalCloser = null; },
    });
    this.pinModalCloser = handle.close;
  }

  /**
   * v2.0 / E9 — drain `i18n.dashboard.pin.{cta,tooltip}` into a `Partial<PinButtonLabels>`.
   * Each key passes through only when it's a non-empty string — empty / missing
   * values fall back to the inline defaults in `pin-button.ts`.
   */
  private pinButtonLabels(): { cta?: string; tooltip?: string } {
    const pin = pickObject(this.i18n.dashboard as Record<string, unknown> | undefined, 'pin');
    const out: { cta?: string; tooltip?: string } = {};
    if (typeof pin['cta'] === 'string' && pin['cta'] !== '') out.cta = pin['cta'];
    if (typeof pin['tooltip'] === 'string' && pin['tooltip'] !== '') out.tooltip = pin['tooltip'];
    return out;
  }

  /**
   * v2.0 / E9 — drain `i18n.dashboard.pin.*` into a `Partial<PinModalLabels>`
   * matching the snake_case shape of the modal's own interface. Same partial
   * semantics as `pinButtonLabels`.
   */
  private pinModalLabels(): Record<string, string> {
    const pin = pickObject(this.i18n.dashboard as Record<string, unknown> | undefined, 'pin');
    const keys = [
      'modal_title', 'modal_select_label', 'modal_create_inline',
      'modal_create_name', 'modal_title_label', 'modal_title_placeholder',
      'submit', 'cancel',
      'error_dashboard_full', 'error_tool_unpinnable',
      'error_tool_missing', 'error_generic',
    ] as const;
    const out: Record<string, string> = {};
    for (const k of keys) {
      const v = pin[k];
      if (typeof v === 'string' && v !== '') out[k] = v;
    }
    return out;
  }

  /**
   * v2.0 / E6 — toast with a "View dashboard" link. The base `showToast`
   * is plain text only; this variant builds a richer node so the user
   * can jump straight to /chatbot/dashboard?dashboard={slug} (target
   * _blank — the widget can be on any host page; navigating away kills
   * the user's context).
   */
  private showPinSuccessToast(dashboardName: string, dashboardSlug: string): void {
    const toast = document.createElement('div');
    toast.className = 'toast';

    const pin = pickObject(this.i18n.dashboard as Record<string, unknown> | undefined, 'pin');
    const addedTemplate = pickString(pin, 'toast_added', 'Added to :dashboard');
    const viewLabel = pickString(pin, 'toast_view', 'View dashboard');

    const text = document.createElement('span');
    // Laravel-style `:dashboard` placeholder substitution. If the host's
    // translation uses a different shape (e.g. `{dashboard}`), fall back to
    // appending the name so the toast never silently drops it.
    text.textContent = addedTemplate.includes(':dashboard')
      ? addedTemplate.replace(':dashboard', dashboardName)
      : `${addedTemplate} ${dashboardName}`;
    toast.appendChild(text);

    const url = this.deriveDashboardPageUrl(dashboardSlug);
    if (url !== null) {
      const sep = document.createElement('span');
      sep.textContent = ' · ';
      sep.style.opacity = '0.7';
      toast.appendChild(sep);

      const link = document.createElement('a');
      link.href = url;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.textContent = viewLabel;
      link.style.color = 'inherit';
      link.style.textDecoration = 'underline';
      toast.appendChild(link);
    }

    this.shadow.appendChild(toast);
    window.setTimeout(() => toast.remove(), 5000);
  }

  disconnectedCallback(): void {
    this.currentStream?.abort();
    this.saver?.flush();
    if (this.spaNavListener) {
      this.spaNavListener();
      this.spaNavListener = null;
    }
    this.confirmDisposers.forEach((d) => { try { d(); } catch { /* ignore */ } });
    this.confirmDisposers.clear();
    this.sidebar?.destroy();
    this.sidebar = null;
    if (this.pinModalCloser) {
      try { this.pinModalCloser(); } catch { /* ignore */ }
      this.pinModalCloser = null;
    }
    // v2.2.2 (PR-C): drop the MutationObserver + matchMedia listener so the
    // widget can be GC'd cleanly when SPA frameworks remove the element.
    // Re-attached on the next connectedCallback if the new host wires the
    // same `data-theme="auto"` contract.
    this.teardownThemeObserver();
  }
}

let defined = false;
export function defineWidget(): void {
  if (defined) return;
  if (typeof customElements === 'undefined') return;
  if (customElements.get('chatbot-widget')) {
    defined = true;
    return;
  }
  customElements.define('chatbot-widget', ChatbotWidgetElement);
  defined = true;
}
