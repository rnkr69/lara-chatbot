"use strict";(()=>{var mt=Object.defineProperty;var bt=(n,t,e)=>t in n?mt(n,t,{enumerable:!0,configurable:!0,writable:!0,value:e}):n[t]=e;var w=(n,t,e)=>bt(n,typeof t!="symbol"?t+"":t,e);var ht="chatbot:context",gt="chatbot:context-form",vt="chatbot:context-changed";function fe(n=document){let t={};if(typeof n.querySelector!="function")return t;let e=n.querySelector(`meta[name="${ht}"]`);if(e){let r=e.getAttribute("content");if(r){let o=r.trim();if(o!==""&&(o[0]==="{"||o[0]==="["))try{let i=JSON.parse(o);i&&typeof i=="object"&&!Array.isArray(i)&&Object.assign(t,i)}catch{}}}if(typeof n.querySelectorAll=="function"&&t.form===void 0){let r=n.querySelectorAll(`meta[name="${gt}"]`);if(r.length>0){let o=r[0]?.getAttribute("content");if(o)try{let i=JSON.parse(o);if(i&&typeof i=="object"&&!Array.isArray(i)){let a=i;a.form!==void 0&&(t.form=a.form)}}catch{}}}return t}function me(n,t=window){typeof t>"u"||typeof t.dispatchEvent!="function"||t.dispatchEvent(new CustomEvent(vt,{detail:n}))}var be="__chatbot_widget_initialized__",he="__chatbot_widget_ready__",Pe="chatbot:ready",Le="__chatbot_shim__";function yt(){let n=new Map,t=new Map,e=new Set,r=new Set,o=new Set,i=new Set,a={},s=null,d=null,l={getTool:c=>n.get(c),getBlockRenderer:c=>t.get(c),getNavigator:()=>d,getPageContext:()=>a,getBearer:()=>s,emitOpen:()=>e.forEach(c=>c()),emitClose:()=>r.forEach(c=>c()),emitToggle:()=>o.forEach(c=>c()),emitNewChat:()=>i.forEach(c=>c()),onOpenRequest:c=>{e.add(c)},onCloseRequest:c=>{r.add(c)},onToggleRequest:c=>{o.add(c)},onNewChatRequest:c=>{i.add(c)}};return{open:()=>l.emitOpen(),close:()=>l.emitClose(),toggle:()=>l.emitToggle(),setPageContext(c){if(!c||typeof c!="object"||Array.isArray(c))return;let u={...a};for(let[p,f]of Object.entries(c)){let v=u[p];f!==null&&typeof f=="object"&&!Array.isArray(f)&&v!==null&&typeof v=="object"&&!Array.isArray(v)?u[p]={...v,...f}:u[p]=f}a=u,me(a)},clearPageContext(){a={},me(a)},registerTool(c,u){if(typeof c!="string"||c==="")throw new Error("registerTool: name must be a non-empty string");if(typeof u!="function")throw new Error("registerTool: handler must be a function");n.set(c,u)},registerBlockRenderer(c,u){if(typeof c!="string"||c==="")throw new Error("registerBlockRenderer: type must be a non-empty string");if(typeof u!="function")throw new Error("registerBlockRenderer: renderer must be a function");t.set(c,u)},registerNavigator(c){if(typeof c!="function")throw new Error("registerNavigator: fn must be a function");d=c},setUser(c){s=typeof c=="string"&&c!==""?c:null},newChat:()=>l.emitNewChat(),whenReady(c){if(typeof c!="function")return;if(window[he]){queueMicrotask(()=>{try{c()}catch(f){console.error("[chatbot] whenReady cb threw",f)}});return}let p=()=>{try{c()}catch(f){console.error("[chatbot] whenReady cb threw",f)}};document.addEventListener(Pe,p,{once:!0})},__internal:l}}function Ne(n=window){n[he]||(n[he]=!0,typeof document<"u"&&typeof CustomEvent=="function"&&document.dispatchEvent(new CustomEvent(Pe,{detail:{api:n.Chatbot??null}})))}function ne(n=window){if(n.Chatbot&&n[be])return n.Chatbot;let t=n.Chatbot;if(t&&t[Le]!==!0)return n[be]=!0,t;let e=yt();if(t&&t[Le]===!0){let r=t.__internal;if(r&&typeof r.getBlockRenderer=="function"){let o=r.getBlockRenderer("chart");typeof o=="function"&&e.registerBlockRenderer("chart",o)}}return n.Chatbot=e,n[be]=!0,e}var Me={closed:new Set(["open","minimized","fullscreen"]),minimized:new Set(["open","closed","fullscreen"]),open:new Set(["closed","minimized","fullscreen"]),fullscreen:new Set(["open","closed","minimized"])},re=class{constructor(t="closed"){w(this,"current");w(this,"listeners",new Set);this.current=t}get state(){return this.current}canTransition(t){return t===this.current?!1:Me[this.current].has(t)}transition(t){if(t===this.current)return;if(!Me[this.current].has(t))throw new Error(`Illegal widget transition: ${this.current} -> ${t}`);let e=this.current;this.current=t;for(let r of this.listeners)r(t,e)}onChange(t){return this.listeners.add(t),()=>this.listeners.delete(t)}};var $e=`
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
  /* v2.1 (E13) \u2014 secondary surface used by the shared block primitives
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
/* v2.2.2 (PR-C) \u2014 explicit theme override. \`applyTheme()\` projects the
   resolved mode on \`data-theme-effective\` (light|dark) so a host toggle
   (\`<html data-bs-theme>\` flip in Backpack-Tabler / Tabler / Bootstrap 5
   admins, or an explicit \`data-theme="dark"\` on the custom element) wins
   over the OS-level \`prefers-color-scheme\` media query above. Specificity
   (0,2,0) beats the media's (0,1,0) inner selector, so order in source is
   not load-bearing \u2014 kept here for readability. */
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
  /* v2.0 / E6 \u2014 establish a positioning context for the pin modal
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
  content: "\u258D"; display: inline-block; animation: cb-blink 1s step-end infinite;
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

/* v2.1 (#3) \u2014 inline error block inside an assistant message when the stream
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

/* v2.1 (E13) \u2014 the typed-block primitives (card / table / list) moved to the
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

/* v2.0 / E8 \u2014 kpi block (built-in renderer; renderer lives in resources/js/kpi.ts
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

/* E16 confirmation banner \u2014 rendered inline under the assistant message
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

/* v1.1.1 (finding #14.e) \u2014 ephemeral status line shown during multi-step
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

/* v1.1.1 (finding #14.d) \u2014 suggested prompts shown in the empty state. */
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

/* E17 \u2014 page mode (chatbot-widget mode="page"). Replaces the floating
   panel with a fullscreen layout with a sidebar on the left. The FAB
   (.launcher) is hidden because there is nothing to open/close \u2014 the page IS
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
/* Bug #12 \u2014 body is the only 1fr track so it scrolls internally; the
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
/* In page mode the panel is always shown \u2014 defeat the floating
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
/* In page mode the chrome buttons (fullscreen / close) make no sense \u2014 the
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

/* v2.0 / E6 \u2014 pin button overlay on chat blocks. The wrapper is added in
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

/* v2.0 / E6 \u2014 pin modal. Lives inside the widget's shadow root; the
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
`;var Ie=`
.cb-card {
  margin-top: 8px;
  border: 1px solid var(--cb-border, #e5e7eb); border-radius: 10px;
  background: var(--cb-bg, #ffffff);
}
.cb-card-body { padding: 10px 12px; display: flex; flex-direction: column; gap: 6px; }
.cb-card-title { margin: 0; font-size: 14px; font-weight: 600; }
.cb-card-subtitle { font-size: 12px; color: var(--cb-muted, #6b7280); }
.cb-card-description { margin: 0; font-size: 13px; line-height: 1.4; }
.cb-card-fields { display: grid; grid-template-columns: max-content 1fr; gap: 4px 12px; margin: 4px 0 0; font-size: 13px; }
.cb-card-fields dt { color: var(--cb-muted, #6b7280); margin: 0; }
.cb-card-fields dd { margin: 0; word-break: break-word; }

.cb-table-wrapper { margin-top: 8px; overflow-x: auto; }
.cb-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.cb-table caption { text-align: left; padding: 0 0 4px; color: var(--cb-muted, #6b7280); font-size: 12px; }
.cb-table th, .cb-table td { padding: 6px 8px; border-bottom: 1px solid var(--cb-border, #e5e7eb); text-align: left; vertical-align: top; }
.cb-table th { font-weight: 600; background: var(--cb-block-head-bg, #f3f4f6); }
.cb-table tr:last-child td { border-bottom: 0; }
.cb-table-empty { padding: 8px 0; color: var(--cb-muted, #6b7280); font-size: 13px; }

.cb-list { margin-top: 8px; }
.cb-list-title { margin: 0 0 4px; font-size: 13px; font-weight: 600; }
.cb-list-items { margin: 0; padding-left: 18px; display: flex; flex-direction: column; gap: 2px; font-size: 13px; }
.cb-list-item-action {
  background: transparent; border: 0; padding: 0; color: var(--cb-accent, #2563eb);
  text-align: left; font: inherit; cursor: pointer;
}
.cb-list-item-action:hover { text-decoration: underline; }
`;var wt=new Set(["text","block","tool_call","tool_result","frontend_action","error","done"]);function xt(){return typeof document>"u"?null:document.querySelector('meta[name="csrf-token"]')?.getAttribute("content")??null}function Be(n){let t=n.replace(/\r\n?/g,`
`),e=t.indexOf(`

`);return e===-1?null:{block:t.slice(0,e),rest:t.slice(e+2)}}function kt(n){let t="",e=[];for(let o of n.split(`
`)){if(o===""||o.startsWith(":"))continue;let i=o.indexOf(":"),a=i===-1?o:o.slice(0,i),s=i===-1?"":o.slice(i+1).replace(/^ /,"");a==="event"?t=s:a==="data"&&e.push(s)}if(!t||!wt.has(t))return null;let r=e.join(`
`);if(r==="")return{event:t,data:{}};try{let o=JSON.parse(r);return o===null||typeof o!="object"||Array.isArray(o)?null:{event:t,data:o}}catch{return null}}function Et(n){return n+Math.floor(Math.random()*Math.min(1e3,n*.25))}function De(n,t){let e=new AbortController,r=()=>e.abort();n.signal&&(n.signal.aborted?e.abort():n.signal.addEventListener("abort",r,{once:!0}));let o=n.maxRetries??4,i=n.initialBackoffMs??1e3,a=n.maxBackoffMs??3e4,s=0;return(async()=>{for(;;)try{let l={Accept:"text/event-stream","Content-Type":"application/json",...n.headers??{}},c=n.csrfToken??xt();c&&(l["X-CSRF-TOKEN"]=c),n.bearer&&(l.Authorization=`Bearer ${n.bearer}`);let u=await fetch(n.url,{method:"POST",headers:l,body:JSON.stringify(n.body),credentials:"same-origin",signal:e.signal});if(u.status===429){t.onError?.("Rate limit exceeded","rate_limited"),t.onClose?.("rate_limited");return}if(!u.ok)throw new Error(`HTTP ${u.status}`);if(!u.body)throw new Error("Response has no body");t.onConnected?.(),s=0;let p=u.body.getReader(),f=new TextDecoder("utf-8"),v="",g=()=>{p.cancel().catch(()=>{})};for(e.signal.aborted?g():e.signal.addEventListener("abort",g,{once:!0});;){let{value:x,done:h}=await p.read();if(h)break;for(v+=f.decode(x,{stream:!0});;){let k=Be(v);if(!k)break;v=k.rest;let A=kt(k.block);if(A&&(t.onFrame(A),A.event==="done")){t.onClose?.("done");return}}}throw new Error("Stream ended before done")}catch(l){if(e.signal.aborted){t.onClose?.("aborted");return}if(s>=o){let u=l instanceof Error?l.message:"Unknown stream error";t.onError?.(u,"fatal"),t.onClose?.("fatal");return}let c=Math.min(a,i*2**s);s++,await new Promise(u=>{let p=setTimeout(u,Et(c));e.signal.aborted&&clearTimeout(p)})}})(),{abort(){e.abort()}}}var Ct={"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"};function He(n){return n.replace(/[&<>"']/g,t=>Ct[t]??t)}function _t(n){let t=n.trim();return t===""?null:t.startsWith("/")||t.startsWith("#")||t.startsWith("?")||/^https?:\/\//i.test(t)||/^mailto:/i.test(t)||/^tel:/i.test(t)?t:null}function At(n){let t=[],e=n.replace(/`([^`\n]+?)`/g,(r,o)=>`\0CODE${t.push(`<code>${o}</code>`)-1}\0`);return e=e.replace(/\[([^\]\n]+?)\]\(([^)\s]+?)\)/g,(r,o,i)=>{let a=_t(i);return a?`<a href="${He(a)}" target="_blank" rel="noopener noreferrer">${o}</a>`:r}),e=e.replace(/\*\*([^*\n]+?)\*\*/g,"<strong>$1</strong>"),e=e.replace(/(^|[^*])\*([^*\n]+?)\*(?!\*)/g,"$1<em>$2</em>"),e=e.replace(/ CODE(\d+) /g,(r,o)=>t[Number(o)]??""),e}function Y(n){return n===""?"":He(n).split(/\n{2,}/).filter(r=>r!=="").map(r=>`<p>${At(r).replace(/\n/g,"<br>")}</p>`).join("")}var St="data-chatbot-block-template",Oe="data-bind";function je(n){if(typeof document>"u"||typeof n!="string"||n==="")return null;let t;try{t=typeof CSS<"u"&&typeof CSS.escape=="function"?CSS.escape(n):n.replace(/"/g,'\\"')}catch{t=n.replace(/"/g,'\\"')}let e=`template[${St}="${t}"]`,r;try{r=document.querySelector(e)}catch{return null}return r instanceof HTMLTemplateElement?r:null}function Tt(n,t){if(t==="")return n;let e=t.split("."),r=n;for(let o of e){if(r==null)return;if(Array.isArray(r)){let i=Number(o);if(!Number.isInteger(i)||i<0)return;r=r[i];continue}if(typeof r!="object")return;r=r[o]}return r}function Rt(n){if(n==null)return"";if(typeof n=="string")return n;if(typeof n=="number"||typeof n=="boolean")return String(n);try{return JSON.stringify(n)}catch{return""}}function Fe(n,t){let e=n.content.cloneNode(!0);e.querySelectorAll(`[${Oe}]`).forEach(a=>{if(!(a instanceof HTMLElement))return;let s=a.getAttribute(Oe)??"",d=Tt(t,s);a.textContent=Rt(d)});let o=Array.from(e.children);if(o.length===1&&o[0]instanceof HTMLElement)return o[0];let i=document.createElement("div");return i.appendChild(e),i}var Lt={up:"\u2191",down:"\u2193",flat:"\u2192"},Pt={no_value:"\u2014"},oe={...Pt};function ze(n){oe={...oe,...n}}function O(n){return typeof n=="string"&&n!==""?n:null}function Nt(n){return n==="number"||n==="currency"||n==="percent"}function Mt(n){return n==="up"||n==="down"||n==="flat"}function $t(n){let t=O(n);if(t!==null)return t;if(typeof document<"u"){let e=O(document.documentElement.lang);if(e!==null)return e}return"en-US"}function It(n){return/^[A-Za-z]{3}$/.test(n)}function Bt(n){let t=O(n.currency);if(t!==null)return t.toUpperCase();let e=O(n.unit);return e!==null&&It(e)?e.toUpperCase():"USD"}function Dt(n){return n>0?"up":n<0?"down":"flat"}function Ht(n){try{let t={maximumFractionDigits:2};return n.compact&&(t.notation="compact"),n.format==="currency"?new Intl.NumberFormat(n.locale,{...t,style:"currency",currency:n.currency}):n.format==="percent"?new Intl.NumberFormat(n.locale,{...t,style:"percent"}):new Intl.NumberFormat(n.locale,t)}catch{return null}}function Ot(n){try{let t={maximumFractionDigits:2,signDisplay:"exceptZero"};return n.compact&&(t.notation="compact"),n.format==="currency"?new Intl.NumberFormat(n.locale,{...t,style:"currency",currency:n.currency}):n.format==="percent"?new Intl.NumberFormat(n.locale,{...t,style:"percent"}):new Intl.NumberFormat(n.locale,t)}catch{return null}}function jt(n){let t=document.createElement("div");if(t.className="block block-kpi cb-kpi cb-kpi-empty",n!==null){let r=document.createElement("div");r.className="cb-kpi-label",r.textContent=n,t.appendChild(r)}let e=document.createElement("div");return e.className="cb-kpi-value",e.textContent=oe.no_value,t.appendChild(e),t}var We=(n,t)=>{let e=O(n.label)??O(n.title)??O(n.name),r=n.value,o=n.format,i=Nt(o)?o:void 0,a=null,s=null;if(typeof r=="number"&&Number.isFinite(r))a=r;else if(typeof r=="string"&&r!=="")if(i!==void 0){let E=Number(r);Number.isFinite(E)?a=E:s=r}else s=r;if(a===null&&s===null)return jt(e);let d=$t(n.locale),l=Bt(n),c=a!==null&&Math.abs(a)>=1e5,u=Ht({format:i,locale:d,currency:l,compact:c}),p;if(s!==null)p=s;else if(a!==null&&u!==null)try{p=u.format(a)}catch{p=String(a)}else p=oe.no_value;let f=document.createElement("div");if(f.className="block block-kpi cb-kpi",e!==null){let E=document.createElement("div");E.className="cb-kpi-label",E.textContent=e,f.appendChild(E)}let v=document.createElement("div");v.className="cb-kpi-value-row";let g=document.createElement("span");g.className="cb-kpi-value",g.textContent=p,v.appendChild(g);let x=O(n.unit);if(x!==null&&!(i==="currency"&&s===null)){let E=document.createElement("span");E.className="cb-kpi-unit",E.textContent=x,v.appendChild(E)}f.appendChild(v);let k=n.delta,A=null,C=null;if(typeof k=="number"&&Number.isFinite(k)){C=Dt(k);let E=Ot({format:i,locale:d,currency:l,compact:Math.abs(k)>=1e5});if(E!==null)try{A=E.format(k)}catch{A=String(k)}else A=String(k)}else typeof k=="string"&&k!==""&&(A=k);let T=(Mt(n.trend)?n.trend:null)??C;if(A!==null||T!==null){let E=document.createElement("div");if(E.className="cb-kpi-delta",T!==null&&E.classList.add(`cb-kpi-trend-${T}`),T!==null){let P=document.createElement("span");P.className="cb-kpi-trend-arrow",P.setAttribute("aria-hidden","true"),P.textContent=Lt[T],E.appendChild(P)}if(A!==null){let P=document.createElement("span");P.className="cb-kpi-delta-value",P.textContent=A,E.appendChild(P)}f.appendChild(E)}let z=O(n.caption);if(z!==null){let E=document.createElement("div");E.className="cb-kpi-caption",E.textContent=z,f.appendChild(E)}return f};function L(n,t=""){return typeof n=="string"?n:t}function Ft(n,t,e,r){let o=L(n[t]);if(o!=="")return o;for(let i of e){let a=L(n[i]);if(a!=="")return console.warn(`[chatbot:block:${r}] expected key "${t}" missing; using alias "${i}". Tighten the LLM prompt or block contract.`),a}return""}function zt(n,t,e,r){if(Array.isArray(n[t]))return n[t];for(let o of e)if(Array.isArray(n[o]))return console.warn(`[chatbot:block:${r}] expected key "${t}" missing; using alias "${o}". Tighten the LLM prompt or block contract.`),n[o];return[]}function Wt(n,t,e){let r=document.createElement("div");return r.className=`block block-${n} cb-block-invalid`,r.setAttribute("role","note"),r.textContent=e.length>0?`[${n}: invalid \u2014 missing required "${t}" (got: ${e.join(", ")})]`:`[${n}: invalid \u2014 missing required "${t}"]`,r}function qt(n,t){let e=document.createElement("div");return e.className="block block-text",e.innerHTML=Y(L(n.content)),e}function Ut(n){if(!Array.isArray(n))return[];let t=[];for(let e of n){if(!e||typeof e!="object")continue;let r=e,o=L(r.label);if(o==="")continue;let i={label:o},a=L(r.prompt);a!==""&&(i.prompt=a);let s=L(r.tool);s!==""&&(i.tool=s),r.args&&typeof r.args=="object"&&!Array.isArray(r.args)&&(i.args=r.args),t.push(i)}return t}function qe(n,t){let e=document.createElement("div");e.className="block block-actions actions";let r=Ut(n.actions);for(let o of r){let i=document.createElement("button");i.type="button",i.textContent=o.label,i.addEventListener("click",()=>{if(o.prompt){t.send(o.prompt);return}if(o.tool){let a=window.Chatbot?.__internal.getTool(o.tool);a&&a(o.args??{},{actionId:`block-${Date.now()}`,confirmation:"auto"})}}),e.appendChild(i)}return e}function Kt(n,t){let e=Ft(n,"title",["header","name","label"],"card"),r=L(n.subtitle),o=L(n.description),i=n.fields,a=n.actions;if(!(e!==""||r!==""||o!==""||Array.isArray(i)&&i.length>0||Array.isArray(a)&&a.length>0))return console.warn("[chatbot:block:card] no recognised content keys; rendering placeholder.",{received:Object.keys(n)}),Wt("card","title",Object.keys(n));let d=document.createElement("div");d.className="block block-card cb-card card";let l=document.createElement("div");if(l.className="cb-card-body card-body",e!==""){let c=document.createElement("h3");c.className="cb-card-title card-title",c.textContent=e,l.appendChild(c)}if(r!==""){let c=document.createElement("div");c.className="cb-card-subtitle card-subtitle",c.textContent=r,l.appendChild(c)}if(o!==""){let c=document.createElement("p");c.className="cb-card-description card-text",c.innerHTML=Y(o),l.appendChild(c)}if(Array.isArray(i)&&i.length>0){let c=document.createElement("dl");c.className="cb-card-fields";for(let u of i){if(!u||typeof u!="object")continue;let p=u,f=L(p.label);if(f==="")continue;let v=document.createElement("dt");v.textContent=f;let g=document.createElement("dd"),x=p.value;g.textContent=x==null?"":String(x),c.append(v,g)}c.children.length>0&&l.appendChild(c)}if(Array.isArray(a)&&a.length>0){let c=qe({actions:a},t);l.appendChild(c)}return d.appendChild(l),d}function Gt(n){if(!Array.isArray(n))return[];let t=[];for(let e of n){if(typeof e=="string"&&e!==""){t.push({key:e,label:e});continue}if(!e||typeof e!="object")continue;let r=e,o=L(r.key);if(o==="")continue;let i=L(r.label,o);t.push({key:o,label:i})}return t}function ie(n){if(n==null)return"";if(typeof n=="string")return n;if(typeof n=="number"||typeof n=="boolean")return String(n);try{return JSON.stringify(n)}catch{return""}}function Vt(n,t){let e=document.createElement("div");e.className="block block-table cb-table-wrapper table-responsive";let r=L(n.caption),o=Gt(n.columns),i=zt(n,"rows",["items","data","records"],"table"),a=o;if(a.length===0&&i.length>0){let l=i[0];l&&typeof l=="object"&&!Array.isArray(l)&&(a=Object.keys(l).map(c=>({key:c,label:c})))}let s=document.createElement("table");if(s.className="cb-table table table-sm table-striped table-hover",r!==""){let l=document.createElement("caption");l.textContent=r,s.appendChild(l)}if(a.length>0){let l=document.createElement("thead"),c=document.createElement("tr");for(let u of a){let p=document.createElement("th");p.scope="col",p.textContent=u.label,c.appendChild(p)}l.appendChild(c),s.appendChild(l)}let d=document.createElement("tbody");for(let l of i){let c=document.createElement("tr");if(a.length===0){let u=document.createElement("td");u.textContent=ie(l),c.appendChild(u)}else if(Array.isArray(l))for(let u=0;u<a.length;u++){let p=document.createElement("td");p.textContent=ie(l[u]),c.appendChild(p)}else if(l&&typeof l=="object"){let u=l;for(let p of a){let f=document.createElement("td");f.textContent=ie(u[p.key]),c.appendChild(f)}}else{let u=document.createElement("td");u.colSpan=Math.max(1,a.length),u.textContent=ie(l),c.appendChild(u)}d.appendChild(c)}if(s.appendChild(d),i.length===0){let l=document.createElement("div");l.className="cb-table-empty",l.textContent=L(n.empty_text,"No rows."),e.appendChild(l)}return e.appendChild(s),e}function Jt(n){if(!Array.isArray(n))return[];let t=[];for(let e of n){if(typeof e=="string"){e!==""&&t.push({text:e});continue}if(!e||typeof e!="object")continue;let r=e,o=L(r.text)||L(r.label);if(o==="")continue;let i={text:o},a=L(r.prompt);a!==""&&(i.prompt=a);let s=L(r.tool);s!==""&&(i.tool=s),r.args&&typeof r.args=="object"&&!Array.isArray(r.args)&&(i.args=r.args),t.push(i)}return t}function Yt(n,t){let e=document.createElement("div");e.className="block block-list cb-list";let r=L(n.title);if(r!==""){let s=document.createElement("h3");s.className="cb-list-title",s.textContent=r,e.appendChild(s)}let o=n.ordered===!0,i=document.createElement(o?"ol":"ul");i.className="cb-list-items list-group list-group-flush";let a=Jt(n.items);for(let s of a){let d=document.createElement("li");if(d.className="cb-list-item list-group-item",s.prompt||s.tool){let l=document.createElement("button");l.type="button",l.className="cb-list-item-action",l.textContent=s.text,l.addEventListener("click",()=>{if(s.prompt){t.send(s.prompt);return}if(s.tool){let c=window.Chatbot?.__internal.getTool(s.tool);c&&c(s.args??{},{actionId:`list-${Date.now()}`,confirmation:"auto"})}}),d.appendChild(l)}else d.textContent=s.text;i.appendChild(d)}return e.appendChild(i),e}var Xt={invalid_data:"Chart data is invalid or incomplete."},Qt={...Xt};function Zt(n,t,e){let r=document.createElement("div");r.className="block block-chart cb-chart";let o=L(n.title);if(o!==""){let s=document.createElement("h3");s.className="cb-chart-title",s.textContent=o,r.appendChild(s)}let i=document.createElement("div");if(i.className="cb-chart-note",e?.customError!==void 0&&e.customError!==null){let s=e.customError,d=typeof s?.message=="string"&&s.message!==""?s.message:String(s);i.textContent=`Chart renderer threw: ${d}. Check the browser console for the stack trace.`}else e?.invalidData===!0?i.textContent=Qt.invalid_data:i.textContent='Chart renderer not registered. Call window.Chatbot.registerBlockRenderer("chart", fn) in the host.';r.appendChild(i);let a=n.series??n.points??n.values??null;if(a!=null){let s=document.createElement("details");s.className="cb-chart-payload";let d=document.createElement("summary");d.textContent="Payload",s.appendChild(d);let l=document.createElement("pre");try{l.textContent=JSON.stringify(a,null,2)}catch{l.textContent=""}s.appendChild(l),r.appendChild(s)}return r}var en={text:qt,actions:qe,card:Kt,table:Vt,list:Yt,chart:Zt,kpi:We};function Ue(n,t){let e=null,r=window.Chatbot?.__internal.getBlockRenderer(n.type);if(r)try{return r(n.data,t)}catch(s){e=s,console.error(`[chatbot] block renderer for "${n.type}" threw`,s)}let o=je(n.type);if(o)try{let s=Fe(o,n.data);return s.classList.contains(`block-${n.type}`)||s.classList.add("block",`block-${n.type}`),s}catch(s){console.error(`[chatbot] template binding for "${n.type}" failed`,s)}let i=en[n.type];if(i)return i(n.data,t,e!==null?{customError:e}:void 0);let a=document.createElement("div");return a.className="block block-unknown",a.textContent=`[unsupported block: ${n.type}]`,a}var ge=(n,t)=>{typeof window>"u"||(t?.replace===!0?window.location.replace(n):window.location.assign(n))},tn=(n,t)=>{let e=window;if(e.Inertia?.visit){e.Inertia.visit(n,t?{...t}:{});return}ge(n,t)},nn=(n,t)=>{let e=window;if(e.Livewire?.navigate){e.Livewire.navigate(n,t?{...t}:{});return}ge(n,t)};function Ke(){let n=window;return n.Inertia?.visit?tn:n.Livewire?.navigate?nn:ge}var j=Object.freeze({ok:!0});function N(n,t,e={}){return{ok:!1,error:n,message:t,...e}}function M(n,t=""){return typeof n=="string"?n:t}function rn(n,t){return typeof n=="number"&&Number.isFinite(n)?n:t}function ae(n){return typeof CSS<"u"&&typeof CSS.escape=="function"?CSS.escape(n):n.replace(/[^a-zA-Z0-9_-]/g,t=>`\\${t}`)}var on=n=>{let t=M(n.url);if(t==="")return N("empty_url","navigate requires a non-empty url argument.");let e=null;if(t.startsWith("/")||t.startsWith("#")||t.startsWith("?"))e=t;else try{let o=new URL(t,window.location.href);o.origin===window.location.origin&&(e=o.toString())}catch{return N("invalid_url",`navigate received a malformed URL "${t}".`,{url:t})}if(e===null)return N("cross_origin",`navigate refused cross-origin URL "${t}". Register a custom navigator if remote navigation is required.`,{url:t});let r=window.Chatbot?.__internal.getNavigator?.();if(r)try{return r(e),j}catch(o){console.error("[chatbot] registered navigator threw",o)}return Ke()(e),j},an=n=>{let t=M(n.selector);if(t==="")return N("empty_selector","toggle_visibility requires a non-empty selector argument.");let e;try{e=document.querySelectorAll(t)}catch{return N("invalid_selector",`toggle_visibility could not parse selector "${t}".`,{selector:t})}if(e.length===0)return N("no_match",`toggle_visibility found no elements matching "${t}".`,{selector:t});let r=n.visible;return e.forEach(o=>{o instanceof HTMLElement&&(typeof r=="boolean"?o.style.display=r?"":"none":o.style.display=o.style.display==="none"?"":"none")}),j},sn=(n,t)=>{let e=M(n.message);if(e==="")return N("empty_message","show_toast requires a non-empty message argument.");let r=Math.max(1e3,rn(n.duration,4e3));return t.showToast(e,r),j},cn=n=>{let t=M(n.download_url);if(t==="")return N("empty_url","download_file requires a non-empty download_url argument.");if(!/^https?:\/\//i.test(t))return N("non_http_url",`download_file refused non-http(s) URL "${t}". Use a signed URL produced by the backend.`,{url:t});let e=M(n.filename),r=document.createElement("a");return r.href=t,r.rel="noopener noreferrer",e!==""&&(r.download=e),r.target="_blank",document.body.appendChild(r),r.click(),document.body.removeChild(r),j},ln=n=>{let t=M(n.selector),e=M(n.form_id),r=null,o="";if(t!==""){o=`selector "${t}"`;try{let s=document.querySelector(t);s instanceof HTMLFormElement?r=s:s&&(r=s.querySelector("form"))}catch{}}if(r===null&&e!==""){o=`id="${e}" or [data-chatbot-form="${e}"]`;let s=document.getElementById(e);if(s instanceof HTMLFormElement)r=s;else try{let d=document.querySelector(`form[data-chatbot-form="${ae(e)}"], [data-chatbot-form="${ae(e)}"] form`);d&&(r=d)}catch{}}if(r===null&&t===""&&e===""){o="auto-discovery (main form, form#crudTable, form.form)";try{r=document.querySelector("main form, form#crudTable, form.form, form"),r&&console.warn("[chatbot:fill_form] neither selector nor form_id was provided; auto-discovered first form on the page. Pass selector or form_id explicitly for deterministic targeting.",{form:r})}catch{r=null}}if(r===null){let s=Array.from(document.querySelectorAll("form")).map((d,l)=>d.id||d.getAttribute("data-chatbot-form")||d.parentElement?.getAttribute("bp-section")||`form[${l}]`);return console.warn(`[chatbot:fill_form] no form matched ${o}.`,{availableForms:s}),N("no_form_matched",`fill_form could not locate a form (tried ${o||"auto-discovery"}). Available forms on page: ${s.length===0?"(none)":s.join(", ")}. On Backpack default list views there is no <form> for filters \u2014 use navigate({url: '?filter=value'}) instead.`,{attempted:{selector:t,form_id:e},available_forms:s})}let i=[],a=Array.isArray(n.fields)?n.fields:[];for(let s of a){let d=M(s?.name);if(d==="")continue;let l;try{l=r.querySelectorAll(`[data-chatbot-field="${ae(d)}"], [name="${ae(d)}"]`)}catch{continue}if(l.length===0){let u=Array.from(r.querySelectorAll("[name], [data-chatbot-field]")).map(p=>p.getAttribute("data-chatbot-field")||p.getAttribute("name")).filter(p=>typeof p=="string"&&p!=="");console.warn(`[chatbot:fill_form] field "${d}" not found in form.`,{availableNames:Array.from(new Set(u))}),i.push(d);continue}let c=s.value;l.forEach(u=>{if(!(u instanceof HTMLInputElement||u instanceof HTMLTextAreaElement||u instanceof HTMLSelectElement))return;let p=u instanceof HTMLInputElement?(u.type||"").toLowerCase():"",f=!1;if(p==="checkbox"&&u instanceof HTMLInputElement)u.checked=!!c,f=!0;else if(p==="radio"&&u instanceof HTMLInputElement)u.checked=String(u.value)===String(c),f=!0;else if(p==="hidden"&&u instanceof HTMLInputElement){let g=u.closest(".form-check, .form-group, .form-switch, fieldset, label")?.querySelector('input[type="checkbox"]:not([name])');g instanceof HTMLInputElement&&(g.checked=!!c,g.dispatchEvent(new Event("input",{bubbles:!0})),g.dispatchEvent(new Event("change",{bubbles:!0})),u.value=c?"1":"0",f=!0)}f||(u.value=c==null?"":String(c)),u.dispatchEvent(new Event("input",{bubbles:!0})),u.dispatchEvent(new Event("change",{bubbles:!0}))})}return n.submit===!0&&(typeof r.requestSubmit=="function"?r.requestSubmit():r.submit()),i.length>0?N("fields_not_found",`fill_form located the form but ${i.length} field${i.length===1?"":"s"} (${i.join(", ")}) were not present. Other fields were applied.`,{missing_fields:i}):j},dn=n=>{let t=M(n.title),e=M(n.body),r=Array.isArray(n.actions)?n.actions:[],o=document.createElement("div");o.setAttribute("role","dialog"),o.setAttribute("aria-modal","true"),Object.assign(o.style,{position:"fixed",inset:"0",background:"rgba(0, 0, 0, 0.5)",display:"flex",alignItems:"center",justifyContent:"center",zIndex:"2147483646"});let i=document.createElement("div");if(Object.assign(i.style,{background:"#fff",color:"#111",minWidth:"300px",maxWidth:"min(90vw, 600px)",maxHeight:"80vh",overflow:"auto",borderRadius:"8px",boxShadow:"0 10px 30px rgba(0, 0, 0, 0.3)",padding:"20px",font:"inherit"}),t!==""){let l=document.createElement("h3");l.style.margin="0 0 12px",l.textContent=t,i.appendChild(l)}if(e!==""){let l=document.createElement("div");l.style.marginBottom="16px",l.textContent=e,i.appendChild(l)}function a(){document.removeEventListener("keydown",s),o.parentNode&&o.parentNode.removeChild(o)}function s(l){l.key==="Escape"&&a()}let d=document.createElement("button");if(d.type="button",d.setAttribute("aria-label","Close"),d.textContent="\u2715",Object.assign(d.style,{position:"absolute",top:"8px",right:"12px",background:"transparent",border:"0",font:"inherit",fontSize:"20px",cursor:"pointer",color:"#666"}),d.addEventListener("click",a),i.style.position="relative",i.appendChild(d),r.length>0){let l=document.createElement("div");Object.assign(l.style,{display:"flex",gap:"8px",justifyContent:"flex-end",marginTop:"12px"}),r.forEach(c=>{let u=M(c.label);if(u==="")return;let p=document.createElement("button");p.type="button",p.textContent=u,Object.assign(p.style,{padding:"8px 14px",border:"1px solid #ccc",borderRadius:"6px",background:"#f5f5f5",cursor:"pointer",font:"inherit"}),p.addEventListener("click",()=>{let f=M(c.tool),v=f!==""?window.Chatbot?.__internal.getTool(f):null;if(v)try{let g=c.args&&typeof c.args=="object"?c.args:{};v(g,{actionId:"open_modal_action",confirmation:"auto"})}catch(g){console.error("[chatbot] open_modal action handler threw",g)}c.close_on_click!==!1&&a()}),l.appendChild(p)}),i.appendChild(l)}return o.appendChild(i),o.addEventListener("click",l=>{l.target===o&&a()}),document.addEventListener("keydown",s),document.body.appendChild(o),j},un=n=>{let t=M(n.action_name);if(t==="")return N("empty_action_name","invoke_host_action requires a non-empty action_name argument.");let e=window.Chatbot?.__internal.getTool(t);if(!e)return console.warn(`[chatbot] invoke_host_action: no host action "${t}" registered`),N("no_handler",`invoke_host_action could not find a host action registered as "${t}". The host should register it via window.Chatbot.registerTool("${t}", fn).`,{action_name:t});let r=n.args&&typeof n.args=="object"?n.args:{};try{e(r,{actionId:"invoke_host_action",confirmation:"auto"})}catch(o){return console.error(`[chatbot] invoke_host_action: handler "${t}" threw`,o),N("handler_threw",`invoke_host_action handler "${t}" threw: ${o instanceof Error?o.message:String(o)}.`,{action_name:t})}return j},pn={navigate:on,toggle_visibility:an,show_toast:sn,download_file:cn,fill_form:ln,open_modal:dn,invoke_host_action:un};function se(n,t){let e=window.Chatbot?.__internal.getTool(n.tool);if(e)try{return e(n.args,{actionId:n.action_id,confirmation:n.confirmation}),j}catch(o){return console.error(`[chatbot] tool "${n.tool}" threw`,o),N("handler_threw",`Host-registered tool "${n.tool}" threw: ${o instanceof Error?o.message:String(o)}.`,{tool:n.tool})}let r=pn[n.tool];if(r)try{return r(n.args,t)}catch(o){return console.error(`[chatbot] primitive "${n.tool}" threw`,o),N("primitive_threw",`Primitive "${n.tool}" threw: ${o instanceof Error?o.message:String(o)}.`,{tool:n.tool})}return console.warn(`[chatbot] no handler registered for frontend tool "${n.tool}"`),N("unknown_tool",`No handler registered for frontend tool "${n.tool}".`,{tool:n.tool})}function Ge(n,t){return n.confirmation!=="auto"?(console.warn(`[chatbot] handleFrontendAction received confirmation="${n.confirmation}" \u2014 the widget should route this through the confirm banner.`),N("routing_error",`Auto handler invoked for confirmation="${n.confirmation}". This action should have been routed through the confirm banner.`)):se(n,t)}function ve(n,t){if(n==="")return`/chatbot/actions/${t}/confirm`;if(n.endsWith("/stream"))return n.slice(0,-7)+`/actions/${t}/confirm`;let e=n.replace(/\/+$/,""),r=e.lastIndexOf("/");return`${r===-1?"":e.slice(0,r)}/actions/${t}/confirm`}function fn(){if(typeof document>"u")return null;let t=document.querySelector('meta[name="csrf-token"]')?.getAttribute("content");return t&&t!==""?t:null}async function V(n,t,e){let r={"Content-Type":"application/json",Accept:"application/json","X-Requested-With":"XMLHttpRequest"},o=fn();o!==null&&(r["X-CSRF-TOKEN"]=o),e!==null&&(r.Authorization=`Bearer ${e}`);let i;try{i=await fetch(n,{method:"POST",credentials:"same-origin",headers:r,body:JSON.stringify(t)})}catch(s){return console.error("[chatbot] confirm fetch failed",s),{ok:!1,status:0,data:null}}let a=null;try{let s=await i.json();s&&typeof s=="object"&&("data"in s&&s.data&&typeof s.data=="object"?a=s.data:"pending_action"in s&&s.pending_action&&typeof s.pending_action=="object"&&(a=s.pending_action))}catch{a=null}return{ok:i.ok,status:i.status,data:a}}function Ve(n,t){let e=document.createElement("div");e.className="cb-confirm-banner",e.dataset.actionId=n.action_id,e.dataset.confirmation=n.confirmation;let r=document.createElement("div");r.className="cb-confirm-title",r.textContent=n.confirmation==="manual"?`Manual action: ${n.tool}`:`Confirm action: ${n.tool}`,e.appendChild(r);let o=n.confirmation==="manual"?"Mark as done":"Accept",i=n.confirmation==="manual"?"Mark as not done":"Reject",a=document.createElement("button");a.type="button",a.className="cb-confirm-accept",a.textContent=o;let s=document.createElement("button");s.type="button",s.className="cb-confirm-reject",s.textContent=i;let d=document.createElement("div");d.className="cb-confirm-buttons",d.append(a,s),e.appendChild(d);let l=document.createElement("div");l.className="cb-confirm-status",l.hidden=!0,e.appendChild(l),t.parent.appendChild(e);let c=ve(t.streamEndpoint,n.action_id),u=!1;function p(h){a.disabled=h,s.disabled=h}function f(h){l.hidden=!1,l.textContent=h}async function v(){if(p(!0),n.confirmation==="manual"){let C={done:!0};try{let T=await t.executePrimitive(n);T&&typeof T=="object"&&T.ok===!1&&(C={done:!0,...T})}catch(T){C={done:!0,ok:!1,error:"primitive_threw",message:T instanceof Error?T.message:String(T)}}let I=await V(c,{accept:!0,result:C},t.bearer);if(!I.ok){f(`Could not record action (HTTP ${I.status}).`),p(!1);return}t.showToast(C.ok===!1?`Marked as done with error: ${n.tool} \u2014 ${C.message??C.error}`:`Marked as done: ${n.tool}`,3e3),x();return}let h=await V(c,{accept:!0},t.bearer);if(!h.ok){f(`Could not confirm action (HTTP ${h.status}).`),p(!1);return}let k={ok:!0};try{let C=await t.executePrimitive(n);C&&typeof C=="object"&&"ok"in C&&(k={...C})}catch(C){k={ok:!1,error:"primitive_threw",message:C instanceof Error?C.message:String(C)}}let A=await V(c,{accept:!0,result:k},t.bearer);if(!A.ok){f(`Action executed but result not recorded (HTTP ${A.status}).`),p(!1);return}k.ok===!1?t.showToast(`Action ${n.tool} failed: ${k.message??k.error}`,4e3):t.showToast(`Done: ${n.tool}`,3e3),x()}async function g(){p(!0);let h=await V(c,{accept:!1},t.bearer);if(!h.ok){f(`Could not reject action (HTTP ${h.status}).`),p(!1);return}t.showToast(`Rejected: ${n.tool}`,3e3),x()}function x(){u||(u=!0,e.remove())}return a.addEventListener("click",()=>{v()}),s.addEventListener("click",()=>{g()}),x}function mn(n={}){let t=n.windowRef??(typeof window<"u"?window:void 0),e=n.documentRef??(typeof document<"u"?document:void 0);if(!t||!e)return"mpa";let r=e.querySelector('meta[name="chatbot:mode"]');if(r){let o=(r.getAttribute("content")??"").trim().toLowerCase();if(o==="spa")return"spa";if(o==="mpa")return"mpa"}return t.Inertia||t.Livewire?"spa":"mpa"}var ye=null;function Je(n){return ye===null&&(ye=mn(n)),ye}function Ye(n,t){let e=t.fetcher??fetch.bind(globalThis),r=t.confirmer??(b=>window.confirm(b)),o=t.searchDebounceMs??300,i=t.activeId??null,a="",s=null,d=0,l=!1,c=document.createElement("aside");if(c.className="cb-sidebar",c.setAttribute("aria-label","Conversations"),typeof t.onNew=="function"){let b=document.createElement("div");b.className="cb-sidebar-header";let m=document.createElement("button");m.type="button",m.className="cb-sidebar-new",m.textContent=t.newLabel??"+ New conversation",m.setAttribute("aria-label",t.newAriaLabel??t.newLabel??"Start a new conversation"),m.addEventListener("click",()=>{try{t.onNew?.()}catch(y){console.error("[chatbot] sidebar onNew threw",y)}}),b.appendChild(m),c.appendChild(b)}let u=document.createElement("div");u.className="cb-sidebar-search";let p=document.createElement("input");p.type="search",p.className="cb-sidebar-search-input",p.placeholder="Search\u2026",p.setAttribute("aria-label","Search conversations"),p.name="chatbot_conversation_search",u.appendChild(p),c.appendChild(u);let f=document.createElement("ul");f.className="cb-sidebar-list",c.appendChild(f);let v=document.createElement("div");v.className="cb-sidebar-empty",v.textContent="No conversations",v.hidden=!0,c.appendChild(v);let g=document.createElement("div");g.className="cb-sidebar-error",g.hidden=!0,c.appendChild(g),n.appendChild(c),p.addEventListener("input",()=>{s!==null&&clearTimeout(s),s=setTimeout(()=>{s=null,a=p.value.trim(),A()},o)});function x(){if(a==="")return t.endpoint;let b=t.endpoint.includes("?")?"&":"?";return`${t.endpoint}${b}q=${encodeURIComponent(a)}`}function h(){let b=document.querySelector('meta[name="csrf-token"]');if(!b)return null;let m=b.getAttribute("content");return typeof m=="string"&&m!==""?m:null}function k(b){let m={Accept:"application/json"};if(t.bearer&&(m.Authorization=`Bearer ${t.bearer}`),b!=="GET"){let y=h();y&&(m["X-CSRF-TOKEN"]=y)}return m}async function A(){if(l)return;let b=++d;g.hidden=!0;try{let m=await e(x(),{method:"GET",credentials:"same-origin",headers:k("GET")});if(b!==d||l)return;if(!m.ok){q(`Failed to load conversations (${m.status})`),I([]);return}let y=await m.json(),S=C(y);if(b!==d||l)return;I(S)}catch{if(b!==d||l)return;q("Failed to load conversations"),I([])}}function C(b){let m=b.data;if(!Array.isArray(m))return[];let y=[];for(let S of m){if(!S||typeof S!="object")continue;let R=S,_=R.id;typeof _!="string"&&typeof _!="number"||y.push({id:_,title:typeof R.title=="string"?R.title:null,updated_at:typeof R.updated_at=="string"?R.updated_at:null})}return y}function I(b){if(f.innerHTML="",b.length===0){v.hidden=!1;return}v.hidden=!0;for(let m of b)f.appendChild(T(m))}function T(b){let m=document.createElement("li");m.className="cb-sidebar-item",m.dataset.id=String(b.id),i!==null&&String(b.id)===String(i)&&(m.classList.add("cb-sidebar-item-active"),m.setAttribute("aria-current","true"));let y=document.createElement("button");y.type="button",y.className="cb-sidebar-item-button";let S=document.createElement("span");S.className="cb-sidebar-item-title",S.textContent=b.title&&b.title!==""?b.title:`Conversation ${b.id}`;let R=document.createElement("span");R.className="cb-sidebar-item-meta",R.textContent=b.updated_at?z(b.updated_at):"",y.append(S,R),y.addEventListener("click",()=>{P(b.id),t.onSelect(b.id)}),m.appendChild(y);let _=document.createElement("button");return _.type="button",_.className="cb-sidebar-item-delete",_.setAttribute("aria-label","Delete conversation"),_.textContent="\u2715",_.addEventListener("click",B=>{B.stopPropagation(),E(b)}),m.appendChild(_),m}function z(b){let m=new Date(b);if(Number.isNaN(m.getTime()))return"";let y=new Date;return m.toDateString()===y.toDateString()?m.toLocaleTimeString(void 0,{hour:"2-digit",minute:"2-digit"}):m.toLocaleDateString()}async function E(b){if(!r("Delete this conversation?"))return;let m=`${t.endpoint}/${encodeURIComponent(String(b.id))}`;try{let y=await e(m,{method:"DELETE",credentials:"same-origin",headers:k("DELETE")});if(!y.ok&&y.status!==204){q(`Failed to delete (${y.status})`);return}let S=i!==null&&String(i)===String(b.id);f.querySelector(`.cb-sidebar-item[data-id="${bn(String(b.id))}"]`)?.remove(),f.children.length===0&&(v.hidden=!1),S&&(i=null,t.onDeleteActive?.())}catch{q("Failed to delete")}}function P(b){i=b,f.querySelectorAll(".cb-sidebar-item").forEach(y=>{let S=y.dataset.id??"",R=b!==null&&String(b)===S;y.classList.toggle("cb-sidebar-item-active",R),R?y.setAttribute("aria-current","true"):y.removeAttribute("aria-current")})}function q(b){g.textContent=b,g.hidden=!1}function pe(){l=!0,s!==null&&(clearTimeout(s),s=null),c.remove()}return A(),{refresh:()=>A(),setActive:P,destroy:pe}}function bn(n){return typeof CSS<"u"&&typeof CSS.escape=="function"?CSS.escape(n):n.replace(/["\\\n\r]/g,t=>`\\${t}`)}var X=class extends Error{constructor(e,r,o){super(r!==""?r:`Pin failed (HTTP ${e})`);this.status=e;this.serverMessage=r;this.errors=o;this.name="PinWidgetError"}},we=class extends Error{constructor(e,r){super(r);this.status=e;this.name="DashboardHttpError"}};function hn(){if(typeof document>"u")return null;let t=document.querySelector('meta[name="csrf-token"]')?.getAttribute("content");return typeof t=="string"&&t!==""?t:null}var ce=class{constructor(t){this.opts=t;w(this,"fetcher");this.fetcher=t.fetcher??fetch.bind(globalThis)}headers(t,e=!1){let r={Accept:"application/json"};if(this.opts.bearer&&(r.Authorization=`Bearer ${this.opts.bearer}`),t!=="GET"){let o=hn();o&&(r["X-CSRF-TOKEN"]=o)}return e&&(r["Content-Type"]="application/json"),r}async listDashboards(){let t=await this.fetcher(this.opts.endpoint,{method:"GET",credentials:"same-origin",headers:this.headers("GET")});if(!t.ok)throw new Error(`GET ${this.opts.endpoint} \u2192 HTTP ${t.status}`);let e=await t.json();return Array.isArray(e?.data)?e.data:[]}async showDashboard(t){let e=`${this.opts.endpoint}/${encodeURIComponent(t)}`,r=await this.fetcher(e,{method:"GET",credentials:"same-origin",headers:this.headers("GET")});if(!r.ok)throw new we(r.status,`GET ${e} \u2192 HTTP ${r.status}`);return(await r.json()).data}async createDashboard(t){let e=await this.fetcher(this.opts.endpoint,{method:"POST",credentials:"same-origin",headers:this.headers("POST",!0),body:JSON.stringify(t)});if(!e.ok)throw new Error(`POST ${this.opts.endpoint} \u2192 HTTP ${e.status}`);return(await e.json()).data}async updateDashboard(t,e){let r=`${this.opts.endpoint}/${encodeURIComponent(t)}`,o=await this.fetcher(r,{method:"PATCH",credentials:"same-origin",headers:this.headers("PATCH",!0),body:JSON.stringify(e)});if(!o.ok)throw new Error(`PATCH ${r} \u2192 HTTP ${o.status}`);return(await o.json()).data}async deleteDashboard(t){let e=`${this.opts.endpoint}/${encodeURIComponent(t)}`,r=await this.fetcher(e,{method:"DELETE",credentials:"same-origin",headers:this.headers("DELETE")});if(!r.ok&&r.status!==204)throw new Error(`DELETE ${e} \u2192 HTTP ${r.status}`)}async updateWidget(t,e,r){let o=`${this.opts.endpoint}/${encodeURIComponent(t)}/widgets/${e}`,i=await this.fetcher(o,{method:"PATCH",credentials:"same-origin",headers:this.headers("PATCH",!0),body:JSON.stringify(r)});if(!i.ok)throw new Error(`PATCH ${o} \u2192 HTTP ${i.status}`)}async deleteWidget(t,e){let r=`${this.opts.endpoint}/${encodeURIComponent(t)}/widgets/${e}`,o=await this.fetcher(r,{method:"DELETE",credentials:"same-origin",headers:this.headers("DELETE")});if(!o.ok&&o.status!==204)throw new Error(`DELETE ${r} \u2192 HTTP ${o.status}`)}async pinWidget(t,e){let r=`${this.opts.endpoint}/${encodeURIComponent(t)}/widgets`,o=await this.fetcher(r,{method:"POST",credentials:"same-origin",headers:this.headers("POST",!0),body:JSON.stringify(e)});if(o.ok)return;let i={};try{i=await o.json()}catch{}let a=typeof i.message=="string"?i.message:"",s={};if(i.errors&&typeof i.errors=="object"&&!Array.isArray(i.errors))for(let[d,l]of Object.entries(i.errors))Array.isArray(l)&&(s[d]=l.filter(c=>typeof c=="string"));throw new X(o.status,a,s)}async refreshWidget(t,e){let r=`${this.opts.endpoint}/${encodeURIComponent(t)}/widgets/${e}/refresh`,o=await this.fetcher(r,{method:"POST",credentials:"same-origin",headers:this.headers("POST",!0),body:"{}"});if(o.status===429)throw new Error("Rate limit exceeded");if(!o.ok)throw new Error(`POST ${r} \u2192 HTTP ${o.status}`);return(await o.json()).data}};var gn={cta:"Pin to dashboard",tooltip:"Pin this block to a dashboard"};function Xe(n){let{block:t,rendered:e}=n;if(t.pinnable!==!0||!t.source||typeof t.source.tool!="string"||t.source.tool==="")return e;let r={...gn,...n.labels??{}},o=document.createElement("div");o.className="cb-pin-wrapper",typeof t.id=="string"&&t.id!==""&&(o.dataset.blockId=t.id),o.appendChild(e);let i=document.createElement("button");return i.type="button",i.className="cb-pin-button",i.setAttribute("aria-label",r.cta),i.title=r.tooltip,i.textContent="\u{1F4CC}",i.addEventListener("click",a=>{a.preventDefault(),a.stopPropagation(),n.onPin(t,e)}),o.appendChild(i),o}var vn={modal_title:"Pin to dashboard",modal_select_label:"Dashboard",modal_create_inline:"Create new dashboard\u2026",modal_create_name:"Dashboard name",modal_title_label:"Title",modal_title_placeholder:"Optional title\u2026",submit:"Pin",cancel:"Cancel",error_dashboard_full:"This dashboard is full. Pick another or unpin first.",error_tool_unpinnable:"This block cannot be pinned (its tool is not pinnable).",error_tool_missing:"The tool that produced this block is no longer registered.",error_generic:"Could not pin to dashboard.",loading:"Loading dashboards\u2026"};function yn(n){let t=n.data??{},e=["title","caption","label"];for(let r of e){let o=t[r];if(typeof o=="string"&&o.trim()!=="")return o.trim()}return typeof n.type=="string"&&n.type!==""?n.type.charAt(0).toUpperCase()+n.type.slice(1):""}function wn(n,t){if(n.status===422){if((n.errors.dashboard??[]).length>0)return t.error_dashboard_full;let r=n.errors["source.tool"]??[];for(let o of r){let i=o.toLowerCase();if(i.includes("not pinnable"))return t.error_tool_unpinnable;if(i.includes("not registered"))return t.error_tool_missing}if(n.serverMessage!=="")return n.serverMessage}return t.error_generic}function Qe(n,t){let e={...vn,...t.labels??{}},r=!1,o=[],i="existing",a=!1,s=document.createElement("div");s.className="cb-pin-modal-overlay",s.addEventListener("click",m=>{m.target===s&&b(!1)});let d=document.createElement("div");d.className="cb-pin-modal",d.setAttribute("role","dialog"),d.setAttribute("aria-modal","true");let l=`cb-pin-modal-title-${Math.random().toString(36).slice(2,8)}`;d.setAttribute("aria-labelledby",l);let c=document.createElement("div");c.className="cb-pin-modal-header";let u=document.createElement("h3");u.id=l,u.className="cb-pin-modal-title",u.textContent=e.modal_title,c.appendChild(u),d.appendChild(c);let p=document.createElement("div");p.className="cb-pin-modal-body",d.appendChild(p);let f=document.createElement("div");f.className="cb-pin-modal-error",f.setAttribute("role","alert"),f.hidden=!0,d.appendChild(f);let v=document.createElement("div");v.className="cb-pin-modal-footer";let g=document.createElement("button");g.type="button",g.className="cb-pin-modal-cancel",g.textContent=e.cancel,g.addEventListener("click",()=>b(!1));let x=document.createElement("button");x.type="button",x.className="cb-pin-modal-submit",x.textContent=e.submit,x.addEventListener("click",()=>{pe()}),v.append(g,x),d.appendChild(v);let h=document.createElement("div");h.className="cb-pin-modal-loading",h.textContent=e.loading,p.appendChild(h),s.appendChild(d),n.appendChild(s);let k=m=>{m.key==="Escape"&&(m.preventDefault(),b(!1))};d.addEventListener("keydown",k),queueMicrotask(()=>g.focus());let A=null,C=null,I=null,T=null;function z(){p.innerHTML="";let m=o.length>0;if(m||(i="create"),m){let B=document.createElement("div");B.className="cb-pin-modal-mode";let U=`${l}-mode-existing`,F=`${l}-mode-create`,K=document.createElement("label");K.className="cb-pin-modal-mode-row";let D=document.createElement("input");D.type="radio",D.name=`${l}-mode`,D.id=U,D.value="existing",D.checked=i==="existing",D.addEventListener("change",()=>{D.checked&&(i="existing",E())}),K.appendChild(D);let Te=document.createElement("span");Te.textContent=e.modal_select_label,K.appendChild(Te),B.appendChild(K);let G=document.createElement("select");G.className="cb-pin-modal-select",G.setAttribute("aria-label",e.modal_select_label);let pt=o.find(W=>W.is_default)??o[0];for(let W of o){let te=document.createElement("option");te.value=W.slug;let ft=W.is_default?`${W.name} \u2605`:W.name;te.textContent=ft,W.slug===pt?.slug&&(te.selected=!0),G.appendChild(te)}G.addEventListener("focus",()=>{T&&(T.existing.checked=!0,i="existing",E())}),A=G,B.appendChild(G);let ee=document.createElement("label");ee.className="cb-pin-modal-mode-row";let H=document.createElement("input");H.type="radio",H.name=`${l}-mode`,H.id=F,H.value="create",H.checked=i==="create",H.addEventListener("change",()=>{H.checked&&(i="create",E())}),ee.appendChild(H);let Re=document.createElement("span");Re.textContent=e.modal_create_inline,ee.appendChild(Re),B.appendChild(ee),T={existing:D,create:H},p.appendChild(B)}let y=document.createElement("input");y.type="text",y.className="cb-pin-modal-create-input",y.placeholder=e.modal_create_name,y.maxLength=120,y.setAttribute("aria-label",e.modal_create_name),y.name="chatbot_pin_dashboard_name",y.addEventListener("focus",()=>{T&&(T.create.checked=!0,i="create",E())}),C=y,p.appendChild(y);let S=document.createElement("label");S.className="cb-pin-modal-title-row";let R=document.createElement("span");R.textContent=e.modal_title_label,S.appendChild(R);let _=document.createElement("input");_.type="text",_.className="cb-pin-modal-title-input",_.placeholder=e.modal_title_placeholder,_.maxLength=180,_.value=yn(t.block),_.name="chatbot_pin_title",S.appendChild(_),I=_,p.appendChild(S),E(),queueMicrotask(()=>{m?A?.focus():y.focus()})}function E(){A&&(A.disabled=i!=="existing"),C&&(C.disabled=i!=="create")}function P(m){f.textContent=m,f.hidden=!1}function q(){f.hidden=!0,f.textContent=""}async function pe(){if(a||r)return;q();let m=t.block,y=m.source;if(!y){P(e.error_generic);return}let S,R;a=!0,x.disabled=!0,g.disabled=!0;try{if(i==="create"){let U=(C?.value??"").trim();if(U===""){P(e.modal_create_name),a=!1,x.disabled=!1,g.disabled=!1,C?.focus();return}let F=await t.api.createDashboard({name:U});S=F.slug,R=F.name}else{let U=A?.value??"",F=o.find(K=>K.slug===U);if(!F){P(e.error_generic),a=!1,x.disabled=!1,g.disabled=!1;return}S=F.slug,R=F.name}let _=(I?.value??"").trim(),B={block_type:m.type,snapshot:{data:m.data??{}},source:{tool:y.tool,args:y.args??{},...y.page_context_keys?{page_context_keys:y.page_context_keys}:{}},...typeof m.id=="string"&&m.id!==""?{block_id:m.id}:{},...typeof m.blockOrdinal=="number"?{block_ordinal:m.blockOrdinal}:{},..._!==""?{suggested_title:_}:{},...Object.keys(t.pageContext).length>0?{page_context:t.pageContext}:{}};await t.api.pinWidget(S,B),t.onSuccess({dashboardSlug:S,dashboardName:R}),b(!0)}catch(_){_ instanceof X?P(wn(_,e)):(console.error("[chatbot] pin failed:",_),P(e.error_generic)),a=!1,x.disabled=!1,g.disabled=!1}}function b(m){r||(r=!0,d.removeEventListener("keydown",k),s.remove(),m||t.onClose())}return(async()=>{try{if(o=await t.api.listDashboards(),r)return;z()}catch(m){if(r)return;console.error("[chatbot] failed to list dashboards for pin modal:",m),o=[],z(),P(e.error_generic)}})(),{close:()=>b(!1)}}function Ze(n){if(!n||typeof n.getAttribute!="function")return{};let t=n.getAttribute("data-i18n");if(t===null||t==="")return{};let e;try{e=JSON.parse(t)}catch(r){let o=t.length>80?`${t.slice(0,80)}\u2026`:t;return console.warn(`[chatbot:i18n] failed to parse data-i18n: ${o}`,r),{}}return e===null||typeof e!="object"||Array.isArray(e)?{}:e}function $(n,t,e){if(!n)return e;let r=n[t];return typeof r=="string"&&r!==""?r:e}function Q(n,t){if(!n)return{};let e=n[t];return e===null||typeof e!="object"||Array.isArray(e)?{}:e}var et="chatbot:state:v1",xe="chatbot:active-conversation:v1",ke="chatbot:active-user:v1",xn={conversationId:null,isOpen:!1,draft:""};function Z(){return{...xn}}function tt(){try{return typeof window>"u"?null:window.sessionStorage}catch{return null}}function Ee(){let n=tt();if(!n)return Z();let t;try{t=n.getItem(et)}catch{return Z()}if(t===null||t==="")return Z();try{let e=JSON.parse(t);return!e||typeof e!="object"?Z():{conversationId:typeof e.conversationId=="string"||typeof e.conversationId=="number"?e.conversationId:null,isOpen:e.isOpen===!0,draft:typeof e.draft=="string"?e.draft:""}}catch{return Z()}}function le(n){let t=tt();if(t)try{t.setItem(et,JSON.stringify(n))}catch{}}function de(){try{return typeof window>"u"?null:window.localStorage}catch{return null}}function nt(){let n=de();if(!n)return null;let t;try{t=n.getItem(xe)}catch{return null}if(t===null||t==="")return null;try{let e=JSON.parse(t);return typeof e=="string"||typeof e=="number"?e:null}catch{return null}}function J(n){let t=de();if(t)try{if(n===null){t.removeItem(xe);return}t.setItem(xe,JSON.stringify(n))}catch{}}function Ce(){J(null)}function rt(){let n=de();if(!n)return null;try{let t=n.getItem(ke);return t===null||t===""?null:t}catch{return null}}function ot(n){let t=de();if(t)try{if(n==null||n===""){t.removeItem(ke);return}t.setItem(ke,String(n))}catch{}}function it(n=250){let t=null,e=null;return{save(o){t=o,e!==null&&clearTimeout(e),e=setTimeout(()=>{e=null,t!==null&&(le(t),t=null)},n)},flush:()=>{e!==null&&(clearTimeout(e),e=null),t!==null&&(le(t),t=null)},cancel(){e!==null&&(clearTimeout(e),e=null),t=null}}}function ue(n,t){let e=n.id;typeof e=="string"&&e!==""&&(t.id=e),n.pinnable===!0&&(t.pinnable=!0);let o=n.block_ordinal;typeof o=="number"&&Number.isInteger(o)&&o>=0&&(t.blockOrdinal=o);let i=n.meta;i&&typeof i=="object"&&!Array.isArray(i)&&(t.meta=i);let a=n.source;if(!a||typeof a!="object"||Array.isArray(a))return;let s=a,d=s.tool,l=s.args;if(typeof d!="string"||d===""||l==null||typeof l!="object")return;let c;if(Array.isArray(l)){if(l.length>0)return;c={}}else c=l;let u={tool:d,args:c},p=s.page_context_keys;if(Array.isArray(p)){let f=p.filter(v=>typeof v=="string");f.length>0&&(u.page_context_keys=f)}t.source=u}var kn=["data-endpoint","data-conversation-id","data-conversations-endpoint","data-position","data-default-open","data-user-id","data-theme","mode"],Ae=class extends HTMLElement{constructor(){super(...arguments);w(this,"api",ne());w(this,"machine",new re("closed"));w(this,"shadow");w(this,"panelEl");w(this,"bodyEl");w(this,"inputEl");w(this,"sendBtn");w(this,"errorBanner");w(this,"launcherEl");w(this,"messages",[]);w(this,"currentAssistant",null);w(this,"streaming",!1);w(this,"currentStream",null);w(this,"conversationId",null);w(this,"bootstrapped",!1);w(this,"mode","mpa");w(this,"widgetMode","widget");w(this,"saver",null);w(this,"spaNavListener",null);w(this,"confirmDisposers",new Set);w(this,"sidebar",null);w(this,"headerLink",null);w(this,"dashboardApi",null);w(this,"pinModalCloser",null);w(this,"i18n",{});w(this,"themeObserver",null);w(this,"themePrefersDarkMql",null);w(this,"themePrefersDarkListener",null)}static get observedAttributes(){return kn}connectedCallback(){if(this.bootstrapped||this.bootstrap(),this.applyTheme(),this.setupThemeObserver(),this.applyUserGating(this.getAttribute("data-user-id")),!this.rehydrate()){let r=this.getAttribute("data-default-open");(r==="true"||r==="1")&&this.machine.transition("open")}this.widgetMode==="page"&&this.machine.state!=="open"&&this.machine.transition("open"),this.applyStateAttr(this.machine.state),this.messages.length===0&&this.conversationId===null&&this.renderSuggestedPrompts()}attributeChangedCallback(e,r,o){if(e==="mode"){let i=o==="page"?"page":"widget";i!==this.widgetMode&&(this.widgetMode=i,this.applyModeAttr(),this.applyModeLayout())}e==="data-conversation-id"&&(this.conversationId=o&&o!==""?o:null,this.persist(),J(this.conversationId),this.sidebar?.setActive(this.conversationId),this.refreshHeaderLink()),e==="data-conversations-endpoint"&&this.widgetMode==="page"&&this.bootstrapped&&this.applyModeLayout(),e==="data-user-id"&&this.bootstrapped&&this.applyUserGating(o),e==="data-theme"&&(this.applyTheme(),this.teardownThemeObserver(),this.setupThemeObserver())}applyTheme(){let e=(this.getAttribute("data-theme")??"auto").toLowerCase(),r;if(e==="light"||e==="dark")r=e;else{let o=document.documentElement.getAttribute("data-bs-theme");o==="light"||o==="dark"?r=o:r=typeof window.matchMedia=="function"&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"}this.getAttribute("data-theme-effective")!==r&&this.setAttribute("data-theme-effective",r)}setupThemeObserver(){(this.getAttribute("data-theme")??"auto").toLowerCase()==="auto"&&(this.themeObserver===null&&typeof MutationObserver<"u"&&(this.themeObserver=new MutationObserver(()=>this.applyTheme()),this.themeObserver.observe(document.documentElement,{attributes:!0,attributeFilter:["data-bs-theme","data-theme"]})),this.themePrefersDarkMql===null&&typeof window.matchMedia=="function"&&(this.themePrefersDarkMql=window.matchMedia("(prefers-color-scheme: dark)"),this.themePrefersDarkListener=()=>this.applyTheme(),typeof this.themePrefersDarkMql.addEventListener=="function"&&this.themePrefersDarkMql.addEventListener("change",this.themePrefersDarkListener)))}teardownThemeObserver(){this.themeObserver!==null&&(this.themeObserver.disconnect(),this.themeObserver=null),this.themePrefersDarkMql!==null&&this.themePrefersDarkListener!==null&&(typeof this.themePrefersDarkMql.removeEventListener=="function"&&this.themePrefersDarkMql.removeEventListener("change",this.themePrefersDarkListener),this.themePrefersDarkMql=null,this.themePrefersDarkListener=null)}bootstrap(){this.bootstrapped=!0,this.mode=Je(),this.widgetMode=this.getAttribute("mode")==="page"?"page":"widget",this.saver=it(250),this.shadow=this.attachShadow({mode:"open"}),this.applyModeAttr(),this.i18n=Ze(this);let e=Q(this.i18n.dashboard,"kpi");typeof e.no_value=="string"&&e.no_value!==""&&ze({no_value:e.no_value});let r=document.createElement("style");r.textContent=$e+Ie,this.shadow.appendChild(r),this.launcherEl=document.createElement("button"),this.launcherEl.type="button",this.launcherEl.className="launcher",this.launcherEl.setAttribute("aria-label","Open chatbot"),this.launcherEl.innerHTML='<span aria-hidden="true">\u{1F4AC}</span>',this.launcherEl.addEventListener("click",()=>this.requestState("open")),this.shadow.appendChild(this.launcherEl);let o=document.createElement("section");o.className="panel",o.setAttribute("role","dialog"),o.setAttribute("aria-label",$(this.i18n,"title","Chatbot")),this.panelEl=o;let i=document.createElement("header");i.className="header";let a=document.createElement("slot");a.name="header";let s=document.createElement("h2"),d=$(this.i18n,"title","Chatbot"),l=$(this.i18n,"open_full_page","Open full chat page");if(this.widgetMode==="page")s.textContent=d;else{let h=document.createElement("a");h.className="cb-header-title-link",h.textContent=d,h.setAttribute("aria-label",l),h.title=l;let k=this.getAttribute("data-page-target")??"_blank";h.target=k,h.rel="noopener noreferrer",h.href=this.computePageUrl(),s.appendChild(h),this.headerLink=h}a.appendChild(s),i.appendChild(a);let c=document.createElement("button");c.type="button",c.className="cb-header-new";let u=$(this.i18n,"new_conversation","New conversation");c.setAttribute("aria-label",u),c.title=u,c.textContent="\u270E",c.addEventListener("click",()=>this.startNewConversation());let p=document.createElement("button");p.type="button",p.className="cb-header-fullscreen",p.setAttribute("aria-label","Toggle fullscreen"),p.textContent="\u2922",p.addEventListener("click",()=>{this.requestState(this.machine.state==="fullscreen"?"open":"fullscreen")});let f=document.createElement("button");f.type="button",f.className="cb-header-close",f.setAttribute("aria-label","Close"),f.textContent="\u2715",f.addEventListener("click",()=>this.requestState("closed")),i.append(c,p,f),o.appendChild(i),this.errorBanner=document.createElement("div"),this.errorBanner.className="error-banner",this.errorBanner.hidden=!0,o.appendChild(this.errorBanner),this.bodyEl=document.createElement("div"),this.bodyEl.className="body",o.appendChild(this.bodyEl);let v=document.createElement("form");v.className="composer",v.addEventListener("submit",h=>{h.preventDefault(),this.submit()}),this.inputEl=document.createElement("textarea"),this.inputEl.rows=1,this.inputEl.name="chatbot_message",this.inputEl.placeholder="Type a message\u2026",this.inputEl.addEventListener("keydown",h=>{h.key==="Enter"&&!h.shiftKey&&(h.preventDefault(),this.submit())}),this.inputEl.addEventListener("input",()=>this.persist()),this.sendBtn=document.createElement("button"),this.sendBtn.type="submit",this.sendBtn.className="send",this.sendBtn.textContent="Send",v.append(this.inputEl,this.sendBtn);let g=document.createElement("slot");g.name="footer",o.append(v,g),this.shadow.appendChild(o),this.api.__internal.onOpenRequest(()=>this.requestState("open")),this.api.__internal.onCloseRequest(()=>this.requestState("closed")),this.api.__internal.onToggleRequest(()=>{this.requestState(this.machine.state==="open"||this.machine.state==="fullscreen"?"closed":"open")}),this.api.__internal.onNewChatRequest(()=>this.startNewConversation()),this.machine.onChange(h=>{this.applyStateAttr(h),this.persist()}),this.applyUserGating(this.getAttribute("data-user-id")),this.conversationId=this.getAttribute("data-conversation-id"),this.applyModeLayout();let x=fe();if(Object.keys(x).length>0&&this.api.setPageContext(x),this.mode==="spa"){let h=()=>{if(this.currentStream){try{this.currentStream.abort()}catch{}this.currentStream=null}let k=fe();Object.keys(k).length>0&&this.api.setPageContext(k)};window.addEventListener("inertia:navigate",h),window.addEventListener("livewire:navigated",h),window.addEventListener("popstate",h),this.spaNavListener=()=>{window.removeEventListener("inertia:navigate",h),window.removeEventListener("livewire:navigated",h),window.removeEventListener("popstate",h)}}}applyUserGating(e){let r=typeof e=="string"?e.trim():"",o=rt();if(r!==""){if(o!==null&&o!==r){Ce();let i=Ee();i.conversationId!==null&&le({...i,conversationId:null}),this.conversationId=null,this.saver?.cancel(),this.hasAttribute("data-conversation-id")&&this.removeAttribute("data-conversation-id"),this.sidebar?.setActive(null)}ot(r)}}rehydrate(){let e=Ee(),r=nt(),o=!1,i=r??e.conversationId;return i!==null&&(this.conversationId=i,this.setAttribute("data-conversation-id",String(i)),r===null&&J(i),this.fetchAndRenderConversation(i).then(()=>this.rehydratePendingActions(i))),e.draft!==""&&this.inputEl&&(this.inputEl.value=e.draft,o=!0),e.isOpen&&this.widgetMode!=="page"&&(this.machine.state==="closed"&&this.machine.transition("open"),o=!0),o}applyModeAttr(){this.setAttribute("data-mode",this.widgetMode)}applyModeLayout(){if(this.panelEl)if(this.widgetMode==="page"){let e=this.deriveConversationsEndpoint();this.sidebar?.destroy(),this.sidebar=null,e&&e!==""?(this.panelEl.classList.remove("cb-page-layout-no-sidebar"),this.sidebar=Ye(this.panelEl,{endpoint:e,bearer:this.api.__internal.getBearer(),activeId:this.conversationId,onSelect:r=>this.selectConversation(r),onDeleteActive:()=>this.clearConversation(),onNew:()=>this.startNewConversation()})):this.panelEl.classList.add("cb-page-layout-no-sidebar")}else this.sidebar?.destroy(),this.sidebar=null,this.panelEl.classList.remove("cb-page-layout-no-sidebar")}selectConversation(e){if(!(this.conversationId!==null&&String(this.conversationId)===String(e))){if(this.currentStream){try{this.currentStream.abort()}catch{}this.currentStream=null,this.streaming=!1,this.sendBtn&&(this.sendBtn.disabled=!1)}this.conversationId=e,this.setAttribute("data-conversation-id",String(e)),J(e),this.persist(),this.refreshHeaderLink(),this.fetchAndRenderConversation(e)}}deriveConversationsEndpoint(){let e=this.getAttribute("data-conversations-endpoint");if(e&&e!=="")return e;let r=this.getAttribute("data-endpoint")??"";return r!==""&&r.endsWith("/stream")?r.slice(0,-7)+"/conversations":null}deriveActionsEndpoint(){let e=this.getAttribute("data-actions-endpoint");if(e&&e!=="")return e;let r=this.deriveConversationsEndpoint();if(r!==null){let i=r.match(/^(.*)\/conversations(\?.*)?$/);if(i)return`${i[1]}/actions${i[2]??""}`}let o=this.getAttribute("data-endpoint")??"";return o!==""&&o.endsWith("/stream")?o.slice(0,-7)+"/actions":null}async rehydratePendingActions(e){let r=this.deriveActionsEndpoint();if(r===null)return;let o=r.includes("?")?"&":"?",i=`${r}${o}status=pending&conversation_id=${encodeURIComponent(String(e))}`,a={Accept:"application/json"},s=this.api.__internal.getBearer();s&&s!==""&&(a.Authorization=`Bearer ${s}`);let d;try{d=await fetch(i,{method:"GET",credentials:"same-origin",headers:a})}catch{return}if(!d.ok)return;let l;try{l=await d.json()}catch{return}if(!l||typeof l!="object")return;let c=l.data;if(Array.isArray(c))for(let u of c){if(!u||typeof u!="object")continue;let p=u,f=typeof p.tool=="string"?p.tool:"",v=typeof p.action_id=="string"?p.action_id:"",g=p.confirmation;if(f===""||v===""||g!=="confirm"&&g!=="manual")continue;let x=p.args,h={tool:f,args:x&&typeof x=="object"&&!Array.isArray(x)?x:{},action_id:v,confirmation:g};this.attachConfirmFlow(h)}}async fetchAndRenderConversation(e){if(!this.bodyEl)return;let r=this.deriveConversationsEndpoint();if(r===null){this.messages=[],this.bodyEl.innerHTML="";return}this.messages=[],this.bodyEl.innerHTML="";let o=document.createElement("div");o.className="cb-loading",o.textContent=$(this.i18n,"loading_conversation","Loading conversation\u2026"),this.bodyEl.appendChild(o);let i;try{let l={Accept:"application/json"},c=this.api.__internal.getBearer();c&&c!==""&&(l.Authorization=`Bearer ${c}`),i=await fetch(`${r}/${encodeURIComponent(String(e))}`,{method:"GET",credentials:"same-origin",headers:l})}catch{o.remove(),this.showError($(this.i18n,"failed_to_load_conversation","Failed to load conversation"));return}o.remove();let a=$(this.i18n,"failed_to_load_conversation","Failed to load conversation");if(!i.ok){if(i.status===404){this.clearConversation(),this.sidebar?.refresh();return}this.showError(`${a} (HTTP ${i.status})`);return}let s;try{s=await i.json()}catch{this.showError(a);return}let d=this.extractMessagesArray(s);if(d.length!==0){d.reverse();for(let l of d){let c=this.adaptStoredMessage(l);c!==null&&(this.messages.push(c),this.appendMessageElement(c))}this.bodyEl.scrollTop=this.bodyEl.scrollHeight}}extractMessagesArray(e){if(!e||typeof e!="object")return[];let r=e.messages;if(!r||typeof r!="object")return[];let o=r.data;return Array.isArray(o)?o:[]}maybeEmitSideEffects(e){let r=e.meta;if(!r||typeof r!="object")return;let o=r.side_effects;if(!o||typeof o!="object"||Array.isArray(o))return;let i=o;typeof i.type!="string"||i.type===""||typeof document>"u"||document.dispatchEvent(new CustomEvent("chatbot:dashboard-mutation",{detail:i}))}adaptStoredMessage(e){let r=e.role;if(r!=="user"&&r!=="assistant")return null;let o=e.id,i=typeof o=="number"||typeof o=="string"?String(o):"";if(i==="")return null;let a=Array.isArray(e.content)?e.content:[],s="",d=[];for(let l of a){if(!l||typeof l!="object")continue;let c=l.type;if(c==="text"){let u=l.text;typeof u=="string"&&(s+=u)}else if(typeof c=="string"&&c!==""){let u=l.data,p={type:c,data:u&&typeof u=="object"&&!Array.isArray(u)?u:{}};ue(l,p),d.push(p)}}return{id:r==="user"?`u-${i}`:`a-${i}`,role:r,text:s,blocks:d,pending:!1}}computePageUrl(){let e=this.getAttribute("data-page-url"),r=null;if(e&&e!=="")r=e;else{let a=this.getAttribute("data-endpoint")??"";a!==""&&a.endsWith("/stream")&&(r=a.slice(0,-7))}if(r===null)return"#";let o=this.conversationId;if(o===null||o==="")return r;let i=r.includes("?")?"&":"?";return`${r}${i}conversation_id=${encodeURIComponent(String(o))}`}refreshHeaderLink(){this.headerLink&&(this.headerLink.href=this.computePageUrl())}startNewConversation(){if(this.currentStream){try{this.currentStream.abort()}catch{}this.currentStream=null,this.streaming=!1,this.sendBtn&&(this.sendBtn.disabled=!1)}this.clearConversation(),this.sidebar?.setActive(null),queueMicrotask(()=>this.inputEl?.focus())}clearConversation(){this.conversationId=null,this.removeAttribute("data-conversation-id"),Ce(),this.messages=[],this.bodyEl&&(this.bodyEl.innerHTML=""),this.persist(),this.refreshHeaderLink(),this.renderSuggestedPrompts()}async renderSuggestedPrompts(){if(!this.bodyEl||this.bodyEl.children.length>0)return;let e=this.getSuggestedPromptsEndpoint();if(e==="")return;let r=[];try{let i={Accept:"application/json"},a=this.api.__internal.getBearer();a&&a!==""&&(i.Authorization=`Bearer ${a}`);let s=await fetch(e,{method:"GET",credentials:"same-origin",headers:i});if(!s.ok)return;let d=await s.json(),l=d&&typeof d=="object"&&"data"in d?d.data:[];if(!Array.isArray(l))return;r=l.filter(c=>!!c&&typeof c=="object"&&typeof c.label=="string"&&typeof c.prompt=="string")}catch{return}if(r.length===0||!this.bodyEl||this.bodyEl.children.length>0)return;let o=document.createElement("div");o.className="cb-suggested-prompts",r.forEach(i=>{let a=document.createElement("button");a.type="button",a.textContent=i.label,a.addEventListener("click",()=>{o.remove(),this.inputEl&&(this.inputEl.value=i.prompt),this.send(i.prompt)}),o.appendChild(a)}),this.bodyEl.appendChild(o)}getSuggestedPromptsEndpoint(){let e=this.getAttribute("data-suggested-prompts-endpoint");if(e&&e!=="")return e;let r=this.deriveConversationsEndpoint();if(r!==null)return r.replace(/\/conversations\/?$/,"/suggested-prompts");let o=this.getAttribute("data-endpoint");return o&&o!==""?o.replace(/\/stream\/?$/,"/suggested-prompts"):"/chatbot/suggested-prompts"}persist(){if(!this.saver||!this.inputEl)return;let e={conversationId:this.conversationId,isOpen:this.machine.state==="open"||this.machine.state==="fullscreen",draft:this.inputEl.value};this.saver.save(e)}applyStateAttr(e){this.setAttribute("data-state",e)}requestState(e){e!==this.machine.state&&this.machine.canTransition(e)&&(this.machine.transition(e),(e==="open"||e==="fullscreen")&&queueMicrotask(()=>this.inputEl?.focus()))}submit(){if(this.streaming)return;let e=this.inputEl.value.trim();e!==""&&(this.inputEl.value="",this.persist(),this.send(e))}send(e){let r=this.getAttribute("data-endpoint");if(!r||r===""){this.showError("Missing data-endpoint attribute on <chatbot-widget>");return}let o={id:`u-${Date.now()}`,role:"user",text:e,blocks:[],pending:!1};this.messages.push(o),this.appendMessageElement(o);let i={id:`a-${Date.now()}`,role:"assistant",text:"",blocks:[],pending:!0};this.messages.push(i),this.currentAssistant=i,this.appendMessageElement(i),this.streaming=!0,this.sendBtn.disabled=!0;let a={message:e};this.conversationId!==null&&this.conversationId!==""&&(a.conversation_id=this.conversationId);let s=this.api.__internal.getPageContext();Object.keys(s).length>0&&(a.page_context=s),this.currentStream=De({url:r,body:a,bearer:this.api.__internal.getBearer()},{onFrame:d=>this.handleFrame(d),onError:(d,l)=>{this.showError(`${l??"error"}: ${d}`)},onClose:d=>{this.streaming=!1,this.sendBtn.disabled=!1,this.currentStream=null,this.currentAssistant&&(this.currentAssistant.pending=!1,this.refreshAssistantNode(this.currentAssistant),this.currentAssistant=null)}})}handleFrame(e){switch(e.event){case"text":{let r=typeof e.data.delta=="string"?e.data.delta:"";this.currentAssistant&&(this.currentAssistant.text+=r,this.refreshAssistantNode(this.currentAssistant));return}case"block":{let r=typeof e.data.type=="string"?e.data.type:"",o=e.data.data&&typeof e.data.data=="object"?e.data.data:{};if(this.currentAssistant&&r!==""){let i={type:r,data:o};ue(e.data,i),this.maybeEmitSideEffects(i),this.currentAssistant.blocks.push(i),this.refreshAssistantNode(this.currentAssistant)}return}case"frontend_action":{let r=e.data;if(typeof r.tool!="string"||r.tool==="")return;if(r.tool==="render_block"){let i=typeof r.args?.type=="string"?r.args.type:"",a=r.args?.data,s=a&&typeof a=="object"&&!Array.isArray(a)?a:{};if(this.currentAssistant&&i!==""){let d={type:i,data:s};ue(r.args,d),this.currentAssistant.blocks.push(d),this.refreshAssistantNode(this.currentAssistant)}return}if(r.confirmation!=="auto"){this.attachConfirmFlow(r);return}let o=Ge(r,{hostElement:this,showToast:(i,a)=>this.showToast(i,a)});o.ok===!1&&this.reportAutoActionFailure(r,o);return}case"tool_call":{let r=typeof e.data.name=="string"?e.data.name:"";r!==""&&this.currentAssistant&&this.setToolCallStatus(this.currentAssistant,`Calling ${r}\u2026`);return}case"tool_result":{this.currentAssistant&&this.setToolCallStatus(this.currentAssistant,"");return}case"error":{let r=typeof e.data.message=="string"?e.data.message:"Stream error";console.error("[chatbot] stream error frame:",r);let o=$(this.i18n,"stream_error","Something went wrong. Please try again.");this.showError(o),this.currentAssistant&&(this.currentAssistant.error=o,this.refreshAssistantNode(this.currentAssistant));return}case"done":{let r=e.data.message_id;if(this.currentAssistant&&(typeof r=="number"||typeof r=="string")){let s=`a-${r}`,d=this.bodyEl.querySelector(`[data-msg-id="${CSS.escape(this.currentAssistant.id)}"]`);d&&(d.dataset.msgId=s),this.currentAssistant.id=s}let o=e.data.conversation_id,i=!1;(typeof o=="number"||typeof o=="string")&&o!==""&&String(this.conversationId??"")!==String(o)&&(this.conversationId=o,this.setAttribute("data-conversation-id",String(o)),J(o),this.persist(),this.refreshHeaderLink(),i=!0);let a=e.data.conversation_title;(i||typeof a=="string"&&a!=="")&&this.sidebar?.refresh();return}}}appendMessageElement(e){let r=document.createElement("div");r.className=`msg ${e.role}`,r.dataset.msgId=e.id,e.role==="user"?r.textContent=e.text:this.fillAssistantNode(r,e),this.bodyEl.appendChild(r),this.bodyEl.scrollTop=this.bodyEl.scrollHeight}fillAssistantNode(e,r){let o=Array.from(e.querySelectorAll(":scope > .cb-confirm-banner")),i=Array.from(e.querySelectorAll(":scope > .cb-tool-status"));if(e.innerHTML="",r.text!==""){let a=document.createElement("div");a.innerHTML=Y(r.text),e.appendChild(a)}for(let a of r.blocks){let s=Ue(a,{send:l=>this.send(l)}),d=Xe({block:a,rendered:s,onPin:l=>this.openPinModalForBlock(l),labels:this.pinButtonLabels()});e.appendChild(d)}if(r.error!==void 0&&r.error!==""){let a=document.createElement("div");a.className="cb-block-error",a.setAttribute("role","alert"),a.textContent=r.error,e.appendChild(a),e.classList.add("failed")}else e.classList.remove("failed");i.forEach(a=>e.appendChild(a)),o.forEach(a=>e.appendChild(a)),r.pending?e.classList.add("pending"):e.classList.remove("pending")}refreshAssistantNode(e){let r=this.bodyEl.querySelector(`[data-msg-id="${CSS.escape(e.id)}"]`);if(!r){console.warn("[chatbot] refreshAssistantNode: no DOM node for",e.id);return}this.fillAssistantNode(r,e),this.bodyEl.scrollTop=this.bodyEl.scrollHeight}setToolCallStatus(e,r){let o=this.findAssistantNode(e);if(!o)return;let i=o.querySelector(":scope > .cb-tool-status");if(r===""){i&&i.remove();return}let a=i;a||(a=document.createElement("div"),a.className="cb-tool-status",o.appendChild(a)),a.textContent=r}attachConfirmFlow(e){let o={parent:this.findAssistantNode(this.currentAssistant)??this.bodyEl,showToast:(a,s)=>this.showToast(a,s),executePrimitive:a=>Promise.resolve(se(a,{hostElement:this,showToast:(s,d)=>this.showToast(s,d)})),streamEndpoint:this.getAttribute("data-endpoint")??"",bearer:this.api.__internal.getBearer()},i=Ve(e,o);this.confirmDisposers.add(i),this.bodyEl.scrollTop=this.bodyEl.scrollHeight}reportAutoActionFailure(e,r){if(r.ok!==!1)return;let o=`[chatbot] ${e.tool} failed: ${r.message??r.error}`;this.showToast(o,5e3);let i=this.getAttribute("data-endpoint")??"";if(i===""||!e.action_id)return;let a=ve(i,e.action_id),s={...r},d=this.api.__internal.getBearer();V(a,{accept:!0,result:s},d)}findAssistantNode(e){return e===null?null:this.bodyEl.querySelector(`[data-msg-id="${CSS.escape(e.id)}"]`)}showError(e){this.errorBanner.textContent=e,this.errorBanner.hidden=!1,window.setTimeout(()=>{this.errorBanner.hidden=!0},6e3)}showToast(e,r){let o=document.createElement("div");o.className="toast",o.textContent=e,this.shadow.appendChild(o),window.setTimeout(()=>o.remove(),r)}deriveDashboardsEndpoint(){let e=this.getAttribute("data-dashboards-endpoint");if(e&&e!=="")return e;let r=this.deriveConversationsEndpoint();if(r!==null){let i=r.match(/^(.*)\/conversations(\?.*)?$/);if(i)return`${i[1]}/dashboards${i[2]??""}`}let o=this.getAttribute("data-endpoint")??"";return o!==""&&o.endsWith("/stream")?o.slice(0,-7)+"/dashboards":null}deriveDashboardPageUrl(e){let r=this.getAttribute("data-dashboard-url"),o=null;if(r&&r!=="")o=r;else{let a=this.getAttribute("data-endpoint")??"";a!==""&&a.endsWith("/stream")&&(o=a.slice(0,-7)+"/dashboard")}if(o===null)return null;let i=o.includes("?")?"&":"?";return`${o}${i}dashboard=${encodeURIComponent(e)}`}getOrCreateDashboardApi(){if(this.dashboardApi!==null)return this.dashboardApi;let e=this.deriveDashboardsEndpoint();return e===null?null:(this.dashboardApi=new ce({endpoint:e,bearer:this.api.__internal.getBearer()}),this.dashboardApi)}openPinModalForBlock(e){let r=this.getOrCreateDashboardApi();if(r===null){console.warn("[chatbot] cannot open pin modal: dashboards endpoint not configured.");return}if(this.pinModalCloser){try{this.pinModalCloser()}catch{}this.pinModalCloser=null}let o=Qe(this.shadow,{block:e,api:r,pageContext:this.api.__internal.getPageContext(),labels:this.pinModalLabels(),onSuccess:({dashboardSlug:i,dashboardName:a})=>{this.pinModalCloser=null,this.showPinSuccessToast(a,i)},onClose:()=>{this.pinModalCloser=null}});this.pinModalCloser=o.close}pinButtonLabels(){let e=Q(this.i18n.dashboard,"pin"),r={};return typeof e.cta=="string"&&e.cta!==""&&(r.cta=e.cta),typeof e.tooltip=="string"&&e.tooltip!==""&&(r.tooltip=e.tooltip),r}pinModalLabels(){let e=Q(this.i18n.dashboard,"pin"),r=["modal_title","modal_select_label","modal_create_inline","modal_create_name","modal_title_label","modal_title_placeholder","submit","cancel","error_dashboard_full","error_tool_unpinnable","error_tool_missing","error_generic"],o={};for(let i of r){let a=e[i];typeof a=="string"&&a!==""&&(o[i]=a)}return o}showPinSuccessToast(e,r){let o=document.createElement("div");o.className="toast";let i=Q(this.i18n.dashboard,"pin"),a=$(i,"toast_added","Added to :dashboard"),s=$(i,"toast_view","View dashboard"),d=document.createElement("span");d.textContent=a.includes(":dashboard")?a.replace(":dashboard",e):`${a} ${e}`,o.appendChild(d);let l=this.deriveDashboardPageUrl(r);if(l!==null){let c=document.createElement("span");c.textContent=" \xB7 ",c.style.opacity="0.7",o.appendChild(c);let u=document.createElement("a");u.href=l,u.target="_blank",u.rel="noopener noreferrer",u.textContent=s,u.style.color="inherit",u.style.textDecoration="underline",o.appendChild(u)}this.shadow.appendChild(o),window.setTimeout(()=>o.remove(),5e3)}disconnectedCallback(){if(this.currentStream?.abort(),this.saver?.flush(),this.spaNavListener&&(this.spaNavListener(),this.spaNavListener=null),this.confirmDisposers.forEach(e=>{try{e()}catch{}}),this.confirmDisposers.clear(),this.sidebar?.destroy(),this.sidebar=null,this.pinModalCloser){try{this.pinModalCloser()}catch{}this.pinModalCloser=null}this.teardownThemeObserver()}},_e=!1;function at(){if(!_e&&!(typeof customElements>"u")){if(customElements.get("chatbot-widget")){_e=!0;return}customElements.define("chatbot-widget",Ae),_e=!0}}var lt=["DOMContentLoaded","inertia:navigate","livewire:navigated"],st=!1,ct=!1;function En(){if(typeof document>"u")return!1;let n=document.querySelector('meta[name="chatbot:options"]');if(!n)return!1;let t=n.getAttribute("content");if(t===null||t==="")return!1;try{return JSON.parse(t)?.backpack?.dt_row_decoration===!0}catch{return!1}}function Cn(){if(typeof document>"u")return!1;let n=document.querySelector('meta[name="chatbot:options"]');if(!n)return!1;let t=n.getAttribute("content");if(t===null||t==="")return!1;try{return JSON.parse(t)?.backpack?.dt_selected_sync===!0}catch{return!1}}function _n(n){let t=n.querySelectorAll('a[href*="/show"], a[href*="/edit"]');for(let e of Array.from(t)){let r=e.getAttribute("href");if(typeof r!="string"||r==="")continue;let o=r.match(/\/(\d+)\/(?:show|edit)(?:\?|#|$)/);if(o&&o[1])return o[1]}return null}function Se(n=document){n.querySelectorAll("table#crudTable tbody tr").forEach(e=>{if(!(e instanceof HTMLElement)||e.hasAttribute("data-chatbot-row-id"))return;let r=_n(e);r!==null&&e.setAttribute("data-chatbot-row-id",r)})}function dt(){if(st||typeof window>"u"||!En())return;st=!0,Se();let n=()=>{let t=window.jQuery;if(typeof t=="function")try{t("table#crudTable").on("draw.dt",()=>{Se()})}catch{}};n(),lt.forEach(t=>{window.addEventListener(t,()=>{Se(),n()})})}function An(n=document){let t=n.querySelectorAll("table#crudTable input.crud_bulk_actions_line_checkbox:checked"),e=[];return t.forEach(r=>{let o=r.dataset.primaryKeyValue??r.getAttribute("data-primary-key-value");typeof o=="string"&&o!==""&&e.push(o)}),e}function ut(){if(ct||typeof window>"u"||typeof document>"u"||!Cn())return;ct=!0;let n=()=>{let t=window.Chatbot;if(typeof t?.setPageContext!="function")return;let r=(t.__internal?.getPageContext?.()??{}).crud,o={...r&&typeof r=="object"&&!Array.isArray(r)?r:{},selected_ids:An()};t.setPageContext({crud:o})};document.addEventListener("change",t=>{let e=t.target;!e||typeof e.matches!="function"||e.matches("table#crudTable input.crud_bulk_actions_line_checkbox, table#crudTable input.crud_bulk_actions_main_checkbox")&&n()}),n(),lt.forEach(t=>{window.addEventListener(t,n)})}ne();at();dt();ut();Ne();})();
//# sourceMappingURL=chatbot-widget.js.map
