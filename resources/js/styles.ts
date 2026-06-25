export const SHADOW_CSS = `
:host {
  --cb-bg: #ffffff;
  --cb-fg: #1f2937;
  --cb-muted: #6b7280;
  --cb-accent: #2563eb;
  --cb-accent-fg: #ffffff;
  --cb-border: #e5e7eb;
  --cb-bubble-user: #2563eb;
  --cb-bubble-user-fg: #ffffff;
  --cb-bubble-assistant: #f3f4f6;
  --cb-bubble-assistant-fg: #111827;
  /* v2.1 (E13) — secondary surface used by the shared block primitives
     (table headers). Tracks --cb-bubble-assistant so the chat look is
     unchanged; the dashboard defines its own value on .cb-dashboard-root. */
  --cb-block-head-bg: var(--cb-bubble-assistant);
  --cb-shadow: 0 10px 25px -5px rgba(0,0,0,0.15), 0 8px 10px -6px rgba(0,0,0,0.08);
  --cb-radius: 12px;
  --cb-z: 2147483000;
  font-family: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  position: fixed;
  bottom: 16px;
  right: 16px;
  left: auto;
  z-index: var(--cb-z);
  color: var(--cb-fg);
}
:host([data-position="left"]) {
  left: 16px;
  right: auto;
}
:host([hidden]) { display: none !important; }

@media (prefers-color-scheme: dark) {
  :host {
    --cb-bg: #111827;
    --cb-fg: #f9fafb;
    --cb-muted: #9ca3af;
    --cb-border: #374151;
    --cb-bubble-assistant: #1f2937;
    --cb-bubble-assistant-fg: #f9fafb;
  }
}
/* v2.2.2 (PR-C) — explicit theme override. \`applyTheme()\` projects the
   resolved mode on \`data-theme-effective\` (light|dark) so a host toggle
   (\`<html data-bs-theme>\` flip in Backpack-Tabler / Tabler / Bootstrap 5
   admins, or an explicit \`data-theme="dark"\` on the custom element) wins
   over the OS-level \`prefers-color-scheme\` media query above. Specificity
   (0,2,0) beats the media's (0,1,0) inner selector, so order in source is
   not load-bearing — kept here for readability. */
:host([data-theme-effective="dark"]) {
  --cb-bg: #111827;
  --cb-fg: #f9fafb;
  --cb-muted: #9ca3af;
  --cb-border: #374151;
  --cb-bubble-assistant: #1f2937;
  --cb-bubble-assistant-fg: #f9fafb;
}
:host([data-theme-effective="light"]) {
  --cb-bg: #ffffff;
  --cb-fg: #1f2937;
  --cb-muted: #6b7280;
  --cb-border: #e5e7eb;
  --cb-bubble-assistant: #f3f4f6;
  --cb-bubble-assistant-fg: #111827;
}

button { font: inherit; cursor: pointer; }

.launcher {
  width: 56px; height: 56px; border-radius: 999px; border: 0;
  background: var(--cb-accent); color: var(--cb-accent-fg);
  box-shadow: var(--cb-shadow);
  display: flex; align-items: center; justify-content: center;
  font-size: 24px;
}
.launcher:focus-visible { outline: 3px solid var(--cb-accent); outline-offset: 2px; }

.panel {
  width: min(380px, calc(100vw - 32px));
  height: min(560px, calc(100vh - 32px));
  background: var(--cb-bg); color: var(--cb-fg);
  border: 1px solid var(--cb-border);
  border-radius: var(--cb-radius);
  box-shadow: var(--cb-shadow);
  display: flex; flex-direction: column; overflow: hidden;
  /* v2.0 / E6 — establish a positioning context for the pin modal
     overlay so absolute inset:0 covers the panel (and only the panel),
     not the entire viewport. */
  position: relative;
}
:host([data-state="fullscreen"]) .panel {
  width: 100vw; height: 100vh;
  position: fixed; inset: 0;
  border-radius: 0; border: 0;
}
:host([data-state="minimized"]) .panel { display: none; }
:host([data-state="closed"]) .panel { display: none; }
:host([data-state="open"]) .launcher,
:host([data-state="fullscreen"]) .launcher { display: none; }
/* The closed and minimized states both fall back to the launcher bubble so the
   chat is always reopenable. (The header has no minimize button anymore; the
   minimized state is only reachable via the window.Chatbot API and must not
   leave a blank widget.) */
:host([data-state="closed"]) .launcher,
:host([data-state="minimized"]) .launcher { display: flex; }

.header {
  display: flex; align-items: center; gap: 8px;
  padding: 12px 14px; border-bottom: 1px solid var(--cb-border);
}
.header h2 { font-size: 14px; margin: 0; flex: 1; font-weight: 600; }
.header button {
  background: transparent; border: 0; color: var(--cb-muted);
  width: 28px; height: 28px; border-radius: 6px;
}
.header button:hover { background: var(--cb-border); color: var(--cb-fg); }

.body {
  flex: 1; overflow-y: auto; padding: 12px 14px;
  display: flex; flex-direction: column; gap: 10px;
}
.msg { max-width: 85%; padding: 8px 12px; border-radius: 12px; line-height: 1.4; font-size: 14px; word-wrap: break-word; }
.msg.user { align-self: flex-end; background: var(--cb-bubble-user); color: var(--cb-bubble-user-fg); border-bottom-right-radius: 4px; }
.msg.assistant { align-self: flex-start; background: var(--cb-bubble-assistant); color: var(--cb-bubble-assistant-fg); border-bottom-left-radius: 4px; }
.msg p { margin: 0 0 6px 0; }
.msg p:last-child { margin: 0; }
.msg code { background: rgba(0,0,0,0.08); padding: 1px 4px; border-radius: 4px; font-size: 13px; }
.msg a { color: var(--cb-accent); }
.msg.assistant.pending::after {
  content: "▍"; display: inline-block; animation: cb-blink 1s step-end infinite;
}
@keyframes cb-blink { 50% { opacity: 0; } }

.actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.actions button {
  padding: 6px 10px; border-radius: 6px; border: 1px solid var(--cb-border);
  background: var(--cb-bg); color: var(--cb-fg); font-size: 13px;
}
.actions button:hover { background: var(--cb-border); }

.composer {
  display: flex; gap: 8px; padding: 10px 12px; border-top: 1px solid var(--cb-border);
}
.composer textarea {
  flex: 1; resize: none; min-height: 36px; max-height: 120px;
  padding: 8px 10px; border: 1px solid var(--cb-border); border-radius: 8px;
  font: inherit; background: var(--cb-bg); color: var(--cb-fg);
}
.composer textarea:focus { outline: 2px solid var(--cb-accent); outline-offset: -1px; }
.composer button.send {
  background: var(--cb-accent); color: var(--cb-accent-fg);
  border: 0; border-radius: 8px; padding: 0 14px;
}
.composer button.send:disabled { opacity: 0.5; cursor: not-allowed; }

.toast {
  position: fixed; bottom: 80px; right: 16px; left: auto;
  background: var(--cb-fg); color: var(--cb-bg);
  padding: 10px 14px; border-radius: 8px; box-shadow: var(--cb-shadow);
  font-size: 13px; max-width: 280px;
  animation: cb-toast-in 0.18s ease-out;
}
:host([data-position="left"]) .toast { right: auto; left: 16px; }
@keyframes cb-toast-in { from { transform: translateY(8px); opacity: 0; } }

.error-banner {
  background: #fee2e2; color: #991b1b; border-bottom: 1px solid #fecaca;
  padding: 6px 12px; font-size: 13px;
}
@media (prefers-color-scheme: dark) {
  .error-banner { background: #7f1d1d; color: #fee2e2; border-bottom-color: #991b1b; }
}
:host([data-theme-effective="dark"]) .error-banner { background: #7f1d1d; color: #fee2e2; border-bottom-color: #991b1b; }
:host([data-theme-effective="light"]) .error-banner { background: #fee2e2; color: #991b1b; border-bottom-color: #fecaca; }

/* v2.1 (#3) — inline error block inside an assistant message when the stream
   emits an "event: error" frame (LLM provider down, network, 5xx). Without
   it the bubble rendered completely empty. .msg.assistant.failed is a hook
   for hosts that want to restyle the failed turn. */
.cb-block-error {
  margin-top: 4px; padding: 8px 12px;
  background: #fee2e2; color: #991b1b;
  border: 1px solid #fecaca; border-radius: 8px;
  font-size: 13px;
}
@media (prefers-color-scheme: dark) {
  .cb-block-error { background: #7f1d1d; color: #fee2e2; border-color: #991b1b; }
}
:host([data-theme-effective="dark"]) .cb-block-error { background: #7f1d1d; color: #fee2e2; border-color: #991b1b; }
:host([data-theme-effective="light"]) .cb-block-error { background: #fee2e2; color: #991b1b; border-color: #fecaca; }

/* v2.1 (E13) — the typed-block primitives (card / table / list) moved to the
   shared block-styles.ts module so the dashboard bundle gets them too
   (finding #16). chart and kpi keep their CSS here: chart renders a JSON
   dump in the widget vs. a Chart.js canvas in the dashboard, and kpi ships
   rules in both bundles. */
.cb-chart {
  margin-top: 8px; padding: 10px 12px;
  border: 1px dashed var(--cb-border); border-radius: 10px;
  font-size: 12px; color: var(--cb-muted);
  display: flex; flex-direction: column; gap: 4px;
}
.cb-chart-title { margin: 0; font-size: 14px; font-weight: 600; color: var(--cb-fg); }
.cb-chart-payload pre {
  margin: 4px 0 0; padding: 6px 8px;
  background: var(--cb-bubble-assistant); color: var(--cb-bubble-assistant-fg);
  border-radius: 6px; font-size: 12px;
  max-height: 200px; overflow: auto; white-space: pre-wrap; word-break: break-word;
}

/* v2.0 / E8 — kpi block (built-in renderer; renderer lives in resources/js/kpi.ts
   and is registered in BUILTIN_BLOCK_RENDERERS so the widget AND the dashboard
   bundles share it). Dashboard ships duplicated rules in its own styles entry. */
.cb-kpi {
  margin-top: 8px; padding: 10px 12px;
  border: 1px solid var(--cb-border); border-radius: 10px;
  background: var(--cb-bg);
  display: flex; flex-direction: column; gap: 4px;
  min-width: 0;
}
.cb-kpi-label {
  font-size: 11px; font-weight: 600;
  text-transform: uppercase; letter-spacing: 0.04em;
  color: var(--cb-muted);
}
.cb-kpi-value-row {
  display: flex; align-items: baseline; gap: 6px;
  flex-wrap: wrap; min-width: 0;
}
.cb-kpi-value {
  /* v2.1.3 (#33): nowrap + ellipsis so the big number never breaks intra-token
     ("$123." / "6K") when the chat panel is narrow. Matches the dashboard
     card override. */
  font-size: clamp(20px, 5vw, 32px); font-weight: 700;
  color: var(--cb-fg); line-height: 1.1;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  min-width: 0;
}
.cb-kpi-unit { font-size: 12px; color: var(--cb-muted); font-weight: 500; }
.cb-kpi-delta {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 12px; font-weight: 600; color: var(--cb-muted);
}
.cb-kpi-trend-up    { color: #16a34a; }
.cb-kpi-trend-down  { color: #dc2626; }
.cb-kpi-trend-flat  { color: var(--cb-muted); }
.cb-kpi-trend-arrow { font-size: 13px; line-height: 1; }
.cb-kpi-caption { font-size: 11px; color: var(--cb-muted); line-height: 1.3; }
.cb-kpi-empty .cb-kpi-value { color: var(--cb-muted); }
@media (prefers-color-scheme: dark) {
  .cb-kpi-trend-up   { color: #4ade80; }
  .cb-kpi-trend-down { color: #f87171; }
}
:host([data-theme-effective="dark"]) .cb-kpi-trend-up   { color: #4ade80; }
:host([data-theme-effective="dark"]) .cb-kpi-trend-down { color: #f87171; }
:host([data-theme-effective="light"]) .cb-kpi-trend-up   { color: #16a34a; }
:host([data-theme-effective="light"]) .cb-kpi-trend-down { color: #dc2626; }

/* E16 confirmation banner — rendered inline under the assistant message
   that proposed a frontend_action with confirmation=confirm|manual. */
.cb-confirm-banner {
  margin-top: 8px; padding: 8px 12px;
  border: 1px solid var(--cb-border); border-radius: 10px;
  background: var(--cb-bg);
  display: flex; flex-direction: column; gap: 6px;
  font-size: 13px;
}
.cb-confirm-title { font-weight: 600; color: var(--cb-fg); }
.cb-confirm-buttons { display: flex; gap: 6px; }
.cb-confirm-buttons button {
  padding: 4px 10px; border-radius: 6px; font-size: 13px; border: 1px solid var(--cb-border);
  background: var(--cb-bg); color: var(--cb-fg);
}
.cb-confirm-buttons button:hover:not(:disabled) { background: var(--cb-border); }
.cb-confirm-buttons button:disabled { opacity: 0.5; cursor: not-allowed; }
.cb-confirm-buttons .cb-confirm-accept {
  background: var(--cb-accent); color: var(--cb-accent-fg); border-color: var(--cb-accent);
}
.cb-confirm-buttons .cb-confirm-accept:hover:not(:disabled) { filter: brightness(0.95); }
.cb-confirm-status { color: var(--cb-muted); font-size: 12px; }

/* v1.1.1 (finding #14.e) — ephemeral status line shown during multi-step
   tool cascades so the user knows the LLM is doing work rather than
   hung. Sits inside the current assistant message as a direct child;
   gets stashed/restored across text deltas like the confirm banner. */
.cb-tool-status {
  margin-top: 6px;
  padding: 4px 10px;
  border-radius: 6px;
  background: var(--cb-bubble-assistant);
  color: var(--cb-muted);
  font-size: 12px;
  font-style: italic;
  opacity: 0.85;
}

/* v1.1.1 (finding #14.d) — suggested prompts shown in the empty state. */
.cb-suggested-prompts {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 12px;
}
.cb-suggested-prompts button {
  padding: 6px 12px;
  border-radius: 999px;
  border: 1px solid var(--cb-border);
  background: var(--cb-bg);
  color: var(--cb-fg);
  font: inherit;
  font-size: 13px;
  cursor: pointer;
}
.cb-suggested-prompts button:hover { background: var(--cb-border); }

/* E17 — page mode (chatbot-widget mode="page"). Replaces the floating
   panel with a fullscreen layout with a sidebar on the left. The FAB
   (.launcher) is hidden because there is nothing to open/close — the page IS
   the chat. */
:host([data-mode="page"]) {
  position: static;
  inset: auto;
  display: block;
  width: 100%;
  height: 100%;
  min-height: inherit;
  bottom: auto; right: auto; left: auto;
}
:host([data-mode="page"]) .launcher { display: none !important; }
/* Bug #12 — body is the only 1fr track so it scrolls internally; the
   sidebar's grid-area spans the four rows naturally. See the commit
   for the full rationale. */
:host([data-mode="page"]) .panel {
  width: 100%;
  height: 100%;
  min-height: 0;
  border-radius: 0;
  border: 0;
  box-shadow: none;
  display: grid;
  grid-template-columns: minmax(220px, 280px) 1fr;
  grid-template-rows: auto auto 1fr auto;
  grid-template-areas:
    "sidebar header"
    "sidebar error"
    "sidebar body"
    "sidebar composer";
  overflow: hidden;
}
:host([data-mode="page"]) .panel.cb-page-layout-no-sidebar {
  grid-template-columns: 1fr;
  grid-template-areas:
    "header"
    "error"
    "body"
    "composer";
}
:host([data-mode="page"]) .panel > .cb-sidebar    { grid-area: sidebar; }
:host([data-mode="page"]) .panel > .header        { grid-area: header; }
:host([data-mode="page"]) .panel > .error-banner  { grid-area: error; }
:host([data-mode="page"]) .panel > .body          { grid-area: body; min-height: 0; }
:host([data-mode="page"]) .panel > .composer      { grid-area: composer; }
:host([data-mode="page"]) .panel > slot[name="footer"] { grid-area: composer; }
/* In page mode the panel is always shown — defeat the floating
   "data-state=closed/minimized hide" rules. */
:host([data-mode="page"]) .panel { display: grid !important; }

.cb-sidebar {
  border-right: 1px solid var(--cb-border);
  background: var(--cb-bg);
  display: flex; flex-direction: column;
  min-height: 0;
  overflow: hidden;
}
.cb-sidebar-search {
  padding: 10px 10px 6px;
  border-bottom: 1px solid var(--cb-border);
}
.cb-sidebar-search-input {
  width: 100%;
  padding: 6px 10px;
  font: inherit; font-size: 13px;
  border: 1px solid var(--cb-border);
  border-radius: 6px;
  background: var(--cb-bg); color: var(--cb-fg);
  box-sizing: border-box;
}
.cb-sidebar-search-input:focus {
  outline: 2px solid var(--cb-accent);
  outline-offset: -1px;
}
.cb-sidebar-list {
  list-style: none;
  margin: 0;
  padding: 6px 0;
  flex: 1;
  overflow-y: auto;
}
.cb-sidebar-item {
  display: flex; align-items: stretch;
  padding: 0 6px;
}
.cb-sidebar-item-button {
  flex: 1; min-width: 0;
  background: transparent; border: 0;
  text-align: left; padding: 8px 8px;
  display: flex; flex-direction: column; gap: 2px;
  border-radius: 6px;
  color: var(--cb-fg);
}
.cb-sidebar-item-button:hover { background: var(--cb-bubble-assistant); }
.cb-sidebar-item-active .cb-sidebar-item-button {
  background: var(--cb-bubble-assistant);
  font-weight: 600;
}
.cb-sidebar-item-title {
  font-size: 13px; line-height: 1.3;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.cb-sidebar-item-meta {
  font-size: 11px; color: var(--cb-muted);
}
.cb-sidebar-item-delete {
  opacity: 0; visibility: hidden;
  align-self: center;
  width: 24px; height: 24px;
  border: 0; border-radius: 4px;
  background: transparent;
  color: var(--cb-muted);
  font-size: 13px; line-height: 1;
}
.cb-sidebar-item:hover .cb-sidebar-item-delete,
.cb-sidebar-item:focus-within .cb-sidebar-item-delete {
  opacity: 1; visibility: visible;
}
.cb-sidebar-item-delete:hover { background: var(--cb-border); color: var(--cb-fg); }
.cb-sidebar-empty {
  padding: 20px 12px;
  font-size: 13px;
  color: var(--cb-muted);
  text-align: center;
}
.cb-sidebar-error {
  padding: 8px 12px;
  font-size: 12px;
  color: #991b1b;
  background: #fee2e2;
  border-bottom: 1px solid #fecaca;
}
@media (prefers-color-scheme: dark) {
  .cb-sidebar-error { background: #7f1d1d; color: #fee2e2; border-bottom-color: #991b1b; }
}
:host([data-theme-effective="dark"]) .cb-sidebar-error { background: #7f1d1d; color: #fee2e2; border-bottom-color: #991b1b; }
:host([data-theme-effective="light"]) .cb-sidebar-error { background: #fee2e2; color: #991b1b; border-bottom-color: #fecaca; }
.cb-loading {
  padding: 20px;
  text-align: center;
  color: var(--cb-muted);
  font-size: 13px;
}
.cb-sidebar-header {
  padding: 8px 10px;
  border-bottom: 1px solid var(--cb-border);
}
.cb-sidebar-new {
  width: 100%;
  padding: 8px 10px;
  border: 1px solid var(--cb-border);
  border-radius: 6px;
  background: var(--cb-bg);
  color: var(--cb-fg);
  font: inherit;
  font-size: 13px;
  cursor: pointer;
  text-align: center;
}
.cb-sidebar-new:hover { background: var(--cb-bubble-assistant); }
/* In page mode the chrome buttons (fullscreen / close) make no sense — the
   page is already the chat. The "new conversation" pen icon in the header also
   duplicates the sidebar's "+ New conversation". */
:host([data-mode="page"]) .header .cb-header-fullscreen,
:host([data-mode="page"]) .header .cb-header-close,
:host([data-mode="page"]) .header .cb-header-new {
  display: none;
}
.cb-header-title-link {
  color: inherit;
  text-decoration: none;
  cursor: pointer;
  border-radius: 4px;
  padding: 2px 6px;
  margin: -2px -6px;
}
.cb-header-title-link:hover { text-decoration: underline; background: var(--cb-bubble-assistant); }
.cb-header-title-link:focus-visible { outline: 2px solid var(--cb-accent); outline-offset: 1px; }

/* v2.0 / E6 — pin button overlay on chat blocks. The wrapper is added in
   widget.ts:fillAssistantNode for blocks where the SSE orchestrator (E1)
   stamped pinnable=true + source. Hover OR keyboard focus reveals the
   button so mouse and keyboard users both reach it. */
.cb-pin-wrapper {
  position: relative;
}
.cb-pin-button {
  position: absolute;
  top: 4px;
  right: 4px;
  width: 26px;
  height: 26px;
  border: 1px solid var(--cb-border);
  border-radius: 6px;
  background: var(--cb-bg);
  color: var(--cb-fg);
  font-size: 13px;
  line-height: 1;
  padding: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 0;
  visibility: hidden;
  transition: opacity 0.12s ease-out;
  z-index: 2;
}
.cb-pin-wrapper:hover .cb-pin-button,
.cb-pin-wrapper:focus-within .cb-pin-button,
.cb-pin-button:focus-visible {
  opacity: 1;
  visibility: visible;
}
.cb-pin-button:hover { background: var(--cb-bubble-assistant); }
.cb-pin-button:focus-visible {
  outline: 2px solid var(--cb-accent);
  outline-offset: 1px;
}

/* v2.0 / E6 — pin modal. Lives inside the widget's shadow root; the
   overlay covers the panel (panel is position:relative) so the dim only
   affects the chat surface, not the whole viewport. */
.cb-pin-modal-overlay {
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
  padding: 16px;
  box-sizing: border-box;
}
.cb-pin-modal {
  background: var(--cb-bg);
  color: var(--cb-fg);
  border: 1px solid var(--cb-border);
  border-radius: 10px;
  box-shadow: var(--cb-shadow);
  width: 100%;
  max-width: 320px;
  max-height: 100%;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
}
.cb-pin-modal-header {
  padding: 12px 14px;
  border-bottom: 1px solid var(--cb-border);
}
.cb-pin-modal-title {
  margin: 0;
  font-size: 14px;
  font-weight: 600;
}
.cb-pin-modal-body {
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.cb-pin-modal-loading {
  font-size: 13px;
  color: var(--cb-muted);
  text-align: center;
  padding: 12px 0;
}
.cb-pin-modal-mode {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.cb-pin-modal-mode-row {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  cursor: pointer;
}
.cb-pin-modal-select,
.cb-pin-modal-create-input,
.cb-pin-modal-title-input {
  width: 100%;
  padding: 6px 8px;
  font: inherit;
  font-size: 13px;
  border: 1px solid var(--cb-border);
  border-radius: 6px;
  background: var(--cb-bg);
  color: var(--cb-fg);
  box-sizing: border-box;
}
.cb-pin-modal-select:focus,
.cb-pin-modal-create-input:focus,
.cb-pin-modal-title-input:focus {
  outline: 2px solid var(--cb-accent);
  outline-offset: -1px;
}
.cb-pin-modal-select:disabled,
.cb-pin-modal-create-input:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.cb-pin-modal-title-row {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 12px;
  color: var(--cb-muted);
}
.cb-pin-modal-error {
  margin: 0 14px 10px;
  padding: 8px 10px;
  background: #fee2e2;
  color: #991b1b;
  border: 1px solid #fecaca;
  border-radius: 6px;
  font-size: 12px;
}
@media (prefers-color-scheme: dark) {
  .cb-pin-modal-error { background: #7f1d1d; color: #fee2e2; border-color: #991b1b; }
}
:host([data-theme-effective="dark"]) .cb-pin-modal-error { background: #7f1d1d; color: #fee2e2; border-color: #991b1b; }
:host([data-theme-effective="light"]) .cb-pin-modal-error { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
.cb-pin-modal-footer {
  padding: 10px 14px;
  border-top: 1px solid var(--cb-border);
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
.cb-pin-modal-cancel,
.cb-pin-modal-submit {
  padding: 6px 14px;
  border-radius: 6px;
  font: inherit;
  font-size: 13px;
  border: 1px solid var(--cb-border);
  background: var(--cb-bg);
  color: var(--cb-fg);
}
.cb-pin-modal-cancel:hover:not(:disabled) { background: var(--cb-border); }
.cb-pin-modal-submit {
  background: var(--cb-accent);
  color: var(--cb-accent-fg);
  border-color: var(--cb-accent);
}
.cb-pin-modal-submit:hover:not(:disabled) { filter: brightness(0.95); }
.cb-pin-modal-cancel:disabled,
.cb-pin-modal-submit:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
`;
