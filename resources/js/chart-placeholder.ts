import type { BlockHost, BlockRendererMeta } from './types.js';

/**
 * Chart placeholder + i18n state (core module).
 *
 * Since v0.4.4 the `chart` block renders with Chart.js out of the box in EVERY
 * bundle (widget, page and dashboard) — see `chart-default.ts`. This placeholder
 * is no longer the default renderer; it survives ONLY as the internal fallback
 * the Chart.js renderer delegates to when:
 *   - the data fails normalization     → `meta.invalidData` → "chart data invalid"
 *   - a host-registered renderer threw → `meta.customError`  → "renderer threw …"
 *
 * It must NEVER claim "renderer not registered" anymore: a renderer is always
 * registered (the built-in Chart.js one), so that message would be misleading.
 *
 * Extracted into its own module (instead of living in `blocks.ts`) to break the
 * import cycle: `blocks.ts` now imports the Chart.js renderer from
 * `chart-default.ts`, which in turn delegates here. Keeping the placeholder in
 * `blocks.ts` would make `blocks.ts → chart-default.ts → blocks.ts` circular.
 */

function asString(value: unknown, fallback = ''): string {
  return typeof value === 'string' ? value : fallback;
}

/**
 * v2.1.1 (#25) — module-level i18n state for the chart placeholder.
 *
 * The placeholder has no `opts.labels` channel (it is reached as a built-in
 * fallback), so the dashboard bundle drives the label through `setChartLabels`
 * at boot with `i18n.dashboard.chart`. The widget bundle can do the same; when
 * it doesn't, the inline English default below applies.
 */
export interface ChartLabels {
  invalid_data: string;
}

const DEFAULT_CHART_LABELS: ChartLabels = {
  invalid_data: 'Chart data is invalid or incomplete.',
};

let currentChartLabels: ChartLabels = { ...DEFAULT_CHART_LABELS };

export function setChartLabels(partial: Partial<ChartLabels>): void {
  currentChartLabels = { ...currentChartLabels, ...partial };
}

/** Exposed for tests; resets to inline defaults. */
export function resetChartLabels(): void {
  currentChartLabels = { ...DEFAULT_CHART_LABELS };
}

/**
 * Chart fallback placeholder.
 *
 * Renders a short note describing why the chart could not be drawn, plus the
 * raw payload in an inspectable `<details>` so the host operator still sees the
 * data. Two states (v1.1 #6 / #25):
 *   1. a registered renderer threw            → meta.customError
 *   2. the data failed normalization (or any  → meta.invalidData / no meta
 *      direct call): "chart data is invalid".
 */
export function renderChartBlock(
  data: Record<string, unknown>,
  _host: BlockHost,
  meta?: BlockRendererMeta,
): HTMLElement {
  const wrapper = document.createElement('div');
  wrapper.className = 'block block-chart cb-chart';

  const title = asString(data['title']);
  if (title !== '') {
    const h = document.createElement('h3');
    h.className = 'cb-chart-title';
    h.textContent = title;
    wrapper.appendChild(h);
  }

  const note = document.createElement('div');
  note.className = 'cb-chart-note';
  if (meta?.customError !== undefined && meta.customError !== null) {
    const err = meta.customError as { message?: unknown };
    const msg = typeof err?.message === 'string' && err.message !== '' ? err.message : String(err);
    note.textContent = `Chart renderer threw: ${msg}. Check the browser console for the stack trace.`;
  } else {
    // No renderer-not-registered state anymore: Chart.js is always the built-in
    // renderer, so reaching the placeholder means the data is unusable.
    note.textContent = currentChartLabels.invalid_data;
  }
  wrapper.appendChild(note);

  // Render the points as a small `<details>` so the data is still inspectable.
  const dataset = data['series'] ?? data['points'] ?? data['values'] ?? null;
  if (dataset !== null && dataset !== undefined) {
    const details = document.createElement('details');
    details.className = 'cb-chart-payload';
    const summary = document.createElement('summary');
    summary.textContent = 'Payload';
    details.appendChild(summary);
    const pre = document.createElement('pre');
    try {
      pre.textContent = JSON.stringify(dataset, null, 2);
    } catch {
      pre.textContent = '';
    }
    details.appendChild(pre);
    wrapper.appendChild(details);
  }

  return wrapper;
}
