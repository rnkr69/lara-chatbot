/**
 * v2.1 (E13) — shared CSS for the typed-block primitives (`card`, `table`,
 * `list`) emitted by the renderers in `blocks.ts`.
 *
 * Why a shared module: the renderers are imported by BOTH the widget bundle
 * and the dashboard bundle, but until v2.1 the CSS lived only in the
 * widget's `SHADOW_CSS`. A `table`/`card`/`list` pinned to the dashboard
 * rendered as naked user-agent HTML (finding #16). This module is the
 * single source of truth for block presentation; both bundles inject it.
 *
 * Tokens: every color resolves through a `--cb-*` custom property with an
 * inline fallback, so the CSS is correct even where no token set is
 * defined. The widget defines the full set in `:host`; the dashboard
 * defines it on `.cb-dashboard-root` (see the respective `styles.ts`).
 *
 * Scope: `card`/`table`/`list` only. `chart` keeps its own CSS — it renders
 * differently per bundle (a JSON dump in the widget, a Chart.js canvas in
 * the dashboard). `kpi` keeps its own CSS too — it already shipped rules in
 * both bundles. Dashboard-specific polish (striped rows, hover, font scale)
 * layers on top of this base, scoped to `.cb-dashboard-card-body`.
 */
export const BLOCK_STYLES = `
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
`;
