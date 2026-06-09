/**
 * v2.0 / E5 — styles injectados al document.head al bootstrap.
 *
 * Combina:
 *   1. `gridstack/dist/gridstack.min.css` + `gridstack-extra.min.css` —
 *      importados como `text` via el loader de esbuild (configurado en
 *      `scripts/build.mjs`). Sin esto, los `.grid-stack-item` no se
 *      posicionan ni los handles de resize aparecen.
 *   2. Nuestras propias clases (`.cb-dashboard-*`) para sidebar, header,
 *      card y status pills. Mantiene el bundle como UN ÚNICO archivo JS;
 *      no hay `<link rel="stylesheet">` adicional que la blade tenga que
 *      cargar.
 *
 * El archivo NO es importado por `app.ts`/`grid.ts`/`widget-card.ts`/
 * `sidebar.ts`. Sólo lo carga `index.ts` (entry de producción). De ese
 * modo los tests Vitest no chocan con el resolver de CSS (el grid se
 * mockea, las cards renderizan en DOM plano, los assertions van contra
 * clases y atributos).
 */

// @ts-expect-error — los .css resuelven como string via el loader 'text' que
// `scripts/build.mjs` configura en esbuild. Para `tsc --noEmit` (typecheck)
// no hay declaración; el expect-error la oculta sin afectar al runtime.
import gridstackCss from 'gridstack/dist/gridstack.min.css';
// @ts-expect-error — ditto.
import gridstackExtraCss from 'gridstack/dist/gridstack-extra.min.css';
import { BLOCK_STYLES } from '../block-styles.js';

const STYLE_ID = 'chatbot-dashboard-styles';

const OWN_CSS = `
.cb-dashboard-root {
  /* v2.1 (E13) — token set the shared block-styles module resolves against.
     The widget defines the equivalent set in its shadow :host; here the
     dashboard owns it so .cb-table / .cb-card / .cb-list are no longer naked
     UA HTML outside the widget (finding #16). */
  --cb-border: rgba(0,0,0,0.1);
  --cb-bg: #ffffff;
  --cb-fg: #1f2933;
  --cb-muted: #6b7280;
  --cb-accent: #3b82f6;
  --cb-block-head-bg: #f3f4f6;

  display: grid;
  grid-template-columns: 240px 1fr;
  height: 100%;
  font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  color: #1f2933;
  background: #f5f5f5;
}
.cb-dashboard-root.cb-theme-dark {
  background: #1f2933; color: #e4e7eb;
  --cb-border: rgba(255,255,255,0.12);
  --cb-bg: #2c333b;
  --cb-fg: #e4e7eb;
  --cb-muted: #9aa5b1;
  --cb-block-head-bg: #353d47;
}

.cb-dashboard-sidebar-host {
  border-right: 1px solid rgba(0,0,0,0.08);
  background: #fff;
  overflow-y: auto;
}
.cb-theme-dark .cb-dashboard-sidebar-host {
  background: #2c333b; border-right-color: rgba(255,255,255,0.08);
}
.cb-dashboard-sidebar { padding: 12px; }
.cb-dashboard-sidebar-new {
  display: flex; gap: 6px; margin-bottom: 12px;
}
.cb-dashboard-sidebar-new-input {
  flex: 1; min-width: 0; padding: 6px 8px; font-size: 13px;
  border: 1px solid rgba(0,0,0,0.15); border-radius: 4px; background: inherit; color: inherit;
}
.cb-dashboard-sidebar-new-btn {
  padding: 6px 10px; font-size: 13px; border: 0; border-radius: 4px;
  background: #3b82f6; color: #fff; cursor: pointer;
}
.cb-dashboard-sidebar-new-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.cb-dashboard-sidebar-list { list-style: none; padding: 0; margin: 0; }
.cb-dashboard-sidebar-item {
  display: flex; align-items: center; gap: 4px;
  padding: 4px 6px; border-radius: 4px;
}
.cb-dashboard-sidebar-item:hover { background: rgba(0,0,0,0.04); }
.cb-theme-dark .cb-dashboard-sidebar-item:hover { background: rgba(255,255,255,0.04); }
.cb-dashboard-sidebar-item-active { background: rgba(59,130,246,0.12) !important; }
.cb-dashboard-sidebar-item-main {
  flex: 1; min-width: 0; display: flex; align-items: center; gap: 6px;
  background: transparent; border: 0; padding: 6px 4px; cursor: pointer;
  color: inherit; text-align: left; font-size: 13px;
}
.cb-dashboard-sidebar-item-title {
  flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.cb-dashboard-rename-input {
  flex: 1; padding: 2px 4px; border: 1px solid rgba(0,0,0,0.2); border-radius: 3px;
  background: inherit; color: inherit; font-size: 13px;
}
.cb-dashboard-sidebar-item-default {
  font-size: 10px; text-transform: uppercase; opacity: 0.6;
}
.cb-dashboard-sidebar-item-count {
  font-size: 11px; opacity: 0.6; padding: 1px 6px; border-radius: 999px;
  background: rgba(0,0,0,0.06);
}
.cb-dashboard-sidebar-item-action {
  padding: 2px 6px; background: transparent; border: 0; cursor: pointer;
  font-size: 12px; color: inherit; opacity: 0.5;
}
.cb-dashboard-sidebar-item:hover .cb-dashboard-sidebar-item-action { opacity: 1; }
.cb-dashboard-sidebar-empty {
  padding: 24px 8px; text-align: center; opacity: 0.7; font-size: 13px;
}
.cb-dashboard-sidebar-empty strong { display: block; margin-bottom: 4px; }
.cb-dashboard-sidebar-error {
  margin-top: 8px; padding: 8px; background: #fef2f2; color: #991b1b;
  border-radius: 4px; font-size: 12px;
}

.cb-dashboard-main { display: flex; flex-direction: column; min-width: 0; }
.cb-dashboard-header {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 16px; border-bottom: 1px solid rgba(0,0,0,0.08);
  background: #fff;
}
.cb-theme-dark .cb-dashboard-header { background: #2c333b; border-bottom-color: rgba(255,255,255,0.08); }
.cb-dashboard-title { flex: 1; min-width: 0; margin: 0; font-size: 16px; font-weight: 600; }
.cb-dashboard-refresh-all {
  padding: 6px 10px; font-size: 14px; border: 0; border-radius: 4px;
  background: rgba(0,0,0,0.06); color: inherit; cursor: pointer;
}
.cb-dashboard-refresh-all:disabled { opacity: 0.5; cursor: not-allowed; }
.cb-dashboard-grid-host {
  flex: 1; padding: 12px; overflow: auto;
}
.cb-dashboard-main-empty {
  flex: 1; display: flex; align-items: center; justify-content: center; padding: 24px;
}
.cb-dashboard-main-empty[hidden] { display: none; }
.cb-dashboard-main-empty-inner h2 { margin: 0 0 4px; font-size: 18px; }
.cb-dashboard-main-empty-inner p { margin: 0; opacity: 0.7; }

.cb-dashboard-card {
  display: flex; flex-direction: column; height: 100%;
  background: #fff; border-radius: 6px; border: 1px solid rgba(0,0,0,0.08);
  box-shadow: 0 1px 2px rgba(0,0,0,0.04);
  overflow: hidden;
}
.cb-theme-dark .cb-dashboard-card { background: #2c333b; border-color: rgba(255,255,255,0.08); }
.cb-dashboard-card-header {
  display: flex; align-items: center; gap: 8px;
  padding: 6px 10px; border-bottom: 1px solid rgba(0,0,0,0.06);
  cursor: grab;
}
.cb-dashboard-card-header:active { cursor: grabbing; }
.cb-dashboard-card-title {
  /* v2.1.3 (#32): floor the title at ~4rem so flex cannot shrink it to a
     single letter on narrow cards. Combined with the header cleanup of #33
     (no more "just now" + 👁 button), even a gs-w:3 (~190 px) card has
     enough room to show several characters and the existing
     text-overflow:ellipsis finally degrades to "Aver…" instead of "A". */
  flex: 1 1 auto; min-width: 4rem; margin: 0; font-size: 13px; font-weight: 600;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  cursor: text;
}
.cb-dashboard-card-status {
  font-size: 10px; text-transform: uppercase; padding: 2px 6px; border-radius: 999px;
  background: rgba(0,0,0,0.08);
}
.cb-status-error .cb-dashboard-card-status,
.cb-status-unauthorized .cb-dashboard-card-status,
.cb-status-source-missing .cb-dashboard-card-status { background: #fef2f2; color: #991b1b; }
.cb-status-stale .cb-dashboard-card-status { background: #fef3c7; color: #92400e; }
.cb-dashboard-card-menu { display: flex; gap: 2px; }
.cb-dashboard-card-btn {
  padding: 2px 6px; border: 0; background: transparent; color: inherit;
  font-size: 13px; cursor: pointer; opacity: 0.6; border-radius: 3px;
}
.cb-dashboard-card-btn:hover { opacity: 1; background: rgba(0,0,0,0.06); }
.cb-dashboard-card-btn:disabled { opacity: 0.3; cursor: not-allowed; }
/* v2.1.3 — the .cb-dashboard-card-refreshed ("just now" label) and the
   .cb-dashboard-card-source-panel / -pre (👁 debug expander) rules used
   to live here; both DOM nodes are gone now, see widget-card.ts. */
.cb-dashboard-card-body {
  flex: 1; min-height: 0; overflow: auto; padding: 8px 10px;
  /* v2.1 (E13) — anchor the typographic scale. Without this the block
     content inherited the UA's 16px instead of the dashboard's 13px
     (finding #16). Stays in the always-injected shell CSS — it's a density
     decision, not block-primitive styling. */
  font-size: 13px;
}
/* The dashboard polish for the block primitives (sticky header, striping,
   margin/chrome resets) lives in BLOCK_DASHBOARD_CSS below — injected only
   when the host's Bootstrap is NOT in play (E14 / #19). */
.cb-dashboard-card-error {
  padding: 6px 10px; font-size: 11px; background: #fef2f2; color: #991b1b;
  border-top: 1px solid #fecaca;
}
.cb-dashboard-card-empty { opacity: 0.5; font-size: 12px; }
.cb-dashboard-card-refreshing .cb-dashboard-card-header { opacity: 0.8; }
.cb-dashboard-card-refreshing .cb-dashboard-card-refresh {
  animation: cb-spin 0.8s linear infinite;
}
@keyframes cb-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

.cb-dashboard-fatal {
  padding: 16px; margin: 16px; background: #fef2f2; color: #991b1b;
  border-radius: 4px; font-size: 13px;
}

/* v2.0 / E7 — chart block with Chart.js default renderer.
 *
 * The canvas needs an ancestor with a *definite* size for Chart.js responsive
 * sizing to work (it reads offsetWidth/offsetHeight from the parent). The card
 * body is already flex:1 + min-height:0 inside the card column, so the
 * canvas-wrap with height:100% gets a concrete pixel size.
 */
.cb-dashboard-card-body .block-chart { display: flex; flex-direction: column; height: 100%; min-height: 0; }
.cb-chart-title { margin: 0 0 6px; font-size: 12px; font-weight: 600; opacity: 0.8; }
.cb-chart-canvas-wrap {
  position: relative; flex: 1; min-height: 180px;
}
.cb-chart-canvas-wrap canvas { display: block !important; max-width: 100%; }
.cb-chart-note {
  padding: 8px; font-size: 12px; opacity: 0.7;
  background: rgba(0,0,0,0.04); border-radius: 4px;
}
.cb-theme-dark .cb-chart-note { background: rgba(255,255,255,0.05); }

/* v2.0 / E8 — kpi block when rendered inside a dashboard card.
 *
 * The renderer (resources/js/kpi.ts) is registered in BUILTIN_BLOCK_RENDERERS
 * so it works in both bundles. Here we override the widget-style border/padding
 * because the dashboard card already provides them, and we tune the value size
 * for the typical 2x1 / 3x2 grid cell. clamp() lets it scale gracefully when
 * the user resizes the widget bigger.
 */
.cb-dashboard-card-body .block-kpi {
  margin: 0; padding: 0; border: 0; background: transparent;
  display: flex; flex-direction: column; gap: 6px;
  height: 100%; min-height: 0;
}
.cb-dashboard-card-body .block-kpi .cb-kpi-label {
  font-size: 11px; font-weight: 600;
  text-transform: uppercase; letter-spacing: 0.04em;
  opacity: 0.6;
}
.cb-dashboard-card-body .block-kpi .cb-kpi-value-row {
  display: flex; align-items: baseline; gap: 6px;
  flex-wrap: wrap; min-width: 0;
}
.cb-dashboard-card-body .block-kpi .cb-kpi-value {
  /* v2.1.3 (#33): nowrap + ellipsis so "$123.6K" no longer breaks intra-token
     ("$123." / "6K") on narrow cards. The clamp() font-size still scales the
     value when the user resizes the widget bigger; the floor (22 px) is
     readable even on a gs-w:3 default-sized kpi card. */
  font-size: clamp(22px, 4vw, 38px); font-weight: 700;
  line-height: 1.1;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  min-width: 0;
}
.cb-dashboard-card-body .block-kpi .cb-kpi-unit {
  font-size: 13px; opacity: 0.65; font-weight: 500;
}
.cb-dashboard-card-body .block-kpi .cb-kpi-delta {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 12px; font-weight: 600;
}
.cb-dashboard-card-body .block-kpi .cb-kpi-trend-up    { color: #16a34a; }
.cb-dashboard-card-body .block-kpi .cb-kpi-trend-down  { color: #dc2626; }
.cb-dashboard-card-body .block-kpi .cb-kpi-trend-flat  { opacity: 0.65; }
.cb-theme-dark .cb-dashboard-card-body .block-kpi .cb-kpi-trend-up   { color: #4ade80; }
.cb-theme-dark .cb-dashboard-card-body .block-kpi .cb-kpi-trend-down { color: #f87171; }
.cb-dashboard-card-body .block-kpi .cb-kpi-trend-arrow { font-size: 13px; line-height: 1; }
.cb-dashboard-card-body .block-kpi .cb-kpi-caption {
  font-size: 11px; opacity: 0.65; line-height: 1.3;
}
.cb-dashboard-card-body .block-kpi.cb-kpi-empty .cb-kpi-value { opacity: 0.5; }

@media (max-width: 720px) {
  .cb-dashboard-root { grid-template-columns: 1fr; }
  .cb-dashboard-sidebar-host { display: none; }
}
`;

/**
 * v2.1 (E14 / #19) — dashboard polish for the block primitives (table /
 * card / list). Injected ONLY when the host's Bootstrap is NOT in play; in
 * layout mode with Bootstrap detected the host's `.table` / `.card` /
 * `.list-group` own the look and these rules — plus `BLOCK_STYLES` — are
 * skipped so there is no specificity fight.
 *
 * The chat shows blocks as conversational snippets; the standalone
 * dashboard *exhibits* them — no leading margin (the card body already
 * pads), the card block drops its own chrome (the widget-card provides it),
 * tables get a sticky header, zebra striping and row hover.
 */
const BLOCK_DASHBOARD_CSS = `
.cb-dashboard-card-body .cb-table-wrapper,
.cb-dashboard-card-body .cb-card,
.cb-dashboard-card-body .cb-list { margin-top: 0; }
.cb-dashboard-card-body .cb-card { border: 0; background: transparent; }
.cb-dashboard-card-body .cb-card-body { padding: 0; }
.cb-dashboard-card-body .cb-table th {
  position: sticky; top: 0; z-index: 1;
}
.cb-dashboard-card-body .cb-table tbody tr:nth-child(even) {
  background: rgba(0,0,0,0.025);
}
.cb-theme-dark .cb-dashboard-card-body .cb-table tbody tr:nth-child(even) {
  background: rgba(255,255,255,0.03);
}
.cb-dashboard-card-body .cb-table tbody tr:hover {
  background: rgba(59,130,246,0.08);
}
`;

/**
 * @param useBootstrap — when true the host's Bootstrap (Backpack layout
 *   mode) styles the block primitives, so the package skips its own block
 *   CSS (`BLOCK_STYLES` + `BLOCK_DASHBOARD_CSS`). The dashboard shell CSS
 *   (`OWN_CSS` — sidebar, header, card chrome, grid) is always injected:
 *   Bootstrap does not provide a gridstack dashboard shell.
 */
export function injectStyles(useBootstrap = false): void {
  if (typeof document === 'undefined') return;
  if (document.getElementById(STYLE_ID)) return;
  const style = document.createElement('style');
  style.id = STYLE_ID;
  const blockCss = useBootstrap ? '' : `${BLOCK_STYLES}\n${BLOCK_DASHBOARD_CSS}\n`;
  style.textContent = `${gridstackCss as string}\n${gridstackExtraCss as string}\n${blockCss}${OWN_CSS}`;
  document.head.appendChild(style);
}
