/**
 * Default Chart.js renderer for the `chart` block (core module since v0.4.4).
 *
 * Previously this lived in `dashboard/` and only the dashboard bundle pulled it
 * in, so the floating widget and the `/chatbot` page rendered a placeholder
 * instead of a chart. It now lives in core and is wired as the built-in `chart`
 * renderer in `blocks.ts`, so ALL bundles (widget, page, dashboard) include
 * Chart.js and render charts identically. The trade-off is the widget bundle
 * grows (~+50 KB gzip); accepted in exchange for consistency.
 *
 * Host override: a host that prefers another library can still win the cascade
 * with `window.Chatbot.registerBlockRenderer('chart', fn)` (see `renderBlock`).
 *
 * The renderer is PURE: it receives `data` and returns an HTMLElement. The
 * internal WeakMap destroys the previous Chart instance when the same canvas is
 * re-rendered (E3 replay → widget-card.ts re-calls renderBlock → the previous
 * Chart must be destroyed to avoid leaks).
 *
 * LLM-friendly aliases (handled in normalize()):
 *   - `kind` → `type` (#25).
 *   - `categories` → `labels`.
 *   - `series` | `points` | `values` → `datasets[0].data`.
 */

// chart.js/auto registers EVERYTHING (controllers + scales + plugins) so any
// supported `type` works with no further changes here.
import Chart from 'chart.js/auto';
import type { BlockHost, BlockRenderer, BlockRendererMeta } from './types.js';
import { renderChartBlock as renderChartBlockPlaceholder } from './chart-placeholder.js';

const ALLOWED_TYPES = ['line', 'bar', 'pie', 'doughnut', 'radar', 'polarArea', 'bubble', 'scatter'] as const;
type AllowedType = typeof ALLOWED_TYPES[number];

interface NormalizedDataset {
  label?: string;
  data: number[];
  backgroundColor?: string | string[];
  borderColor?: string | string[];
  [key: string]: unknown;
}

interface NormalizedShape {
  type: AllowedType;
  title?: string;
  labels: string[];
  datasets: NormalizedDataset[];
  options?: Record<string, unknown>;
}

const liveCharts: WeakMap<HTMLCanvasElement, Chart> = new WeakMap();

function isAllowedType(value: unknown): value is AllowedType {
  return typeof value === 'string' && (ALLOWED_TYPES as readonly string[]).includes(value);
}

function asStringArray(value: unknown): string[] | null {
  if (!Array.isArray(value)) return null;
  const out: string[] = [];
  for (const item of value) {
    if (typeof item === 'string') out.push(item);
    else if (typeof item === 'number' && Number.isFinite(item)) out.push(String(item));
    else return null;
  }
  return out;
}

function asNumberArray(value: unknown): number[] | null {
  if (!Array.isArray(value)) return null;
  const out: number[] = [];
  for (const item of value) {
    if (typeof item === 'number' && Number.isFinite(item)) out.push(item);
    else if (typeof item === 'string' && item.trim() !== '' && Number.isFinite(Number(item))) out.push(Number(item));
    else return null;
  }
  return out;
}

function normalizeDataset(raw: unknown, fallbackLabel: string): NormalizedDataset | null {
  if (raw === null || typeof raw !== 'object') return null;
  const obj = raw as Record<string, unknown>;
  const data = asNumberArray(obj['data']);
  if (data === null || data.length === 0) return null;
  const out: NormalizedDataset = { data };
  if (typeof obj['label'] === 'string') out.label = obj['label'];
  else out.label = fallbackLabel;
  if (typeof obj['backgroundColor'] === 'string') out.backgroundColor = obj['backgroundColor'];
  else if (Array.isArray(obj['backgroundColor']) && obj['backgroundColor'].every((c) => typeof c === 'string')) {
    out.backgroundColor = obj['backgroundColor'] as string[];
  }
  if (typeof obj['borderColor'] === 'string') out.borderColor = obj['borderColor'];
  else if (Array.isArray(obj['borderColor']) && obj['borderColor'].every((c) => typeof c === 'string')) {
    out.borderColor = obj['borderColor'] as string[];
  }
  // Opaque passthrough of Chart.js keys we don't normalize (fill, tension, …).
  for (const key of Object.keys(obj)) {
    if (key in out) continue;
    if (key === 'data') continue;
    out[key] = obj[key];
  }
  return out;
}

function normalize(data: Record<string, unknown>): NormalizedShape | null {
  // #25 — accept `kind` as an alias of `type`. An LLM or backend tool naturally
  // emits `kind: 'bar'`; without this the spec failed normalization.
  const typeCandidate = data['type'] !== undefined ? data['type'] : data['kind'];
  if (!isAllowedType(typeCandidate)) return null;
  const type = typeCandidate;

  // labels (with categories alias).
  const labelsCandidate = data['labels'] !== undefined ? data['labels'] : data['categories'];
  const labels = asStringArray(labelsCandidate);
  if (labels === null) return null;

  // datasets — first the explicit shape; otherwise series/points/values aliases.
  let datasets: NormalizedDataset[] | null = null;
  const fallbackLabel = typeof data['title'] === 'string' ? data['title'] : 'Series';
  if (Array.isArray(data['datasets'])) {
    const collected: NormalizedDataset[] = [];
    for (const entry of data['datasets']) {
      const ds = normalizeDataset(entry, fallbackLabel);
      if (ds === null) return null;
      collected.push(ds);
    }
    if (collected.length === 0) return null;
    datasets = collected;
  } else {
    const aliasRaw = data['series'] ?? data['points'] ?? data['values'];
    if (aliasRaw !== undefined && aliasRaw !== null) {
      const single = normalizeDataset({ data: aliasRaw, label: fallbackLabel }, fallbackLabel);
      if (single !== null) datasets = [single];
    }
  }
  if (datasets === null || datasets.length === 0) return null;

  // In 'pie'/'doughnut'/'polarArea', labels.length MUST match
  // datasets[0].data.length; otherwise Chart.js renders with a broken legend.
  const arcLike = type === 'pie' || type === 'doughnut' || type === 'polarArea';
  if (arcLike && labels.length !== datasets[0]!.data.length) return null;

  const out: NormalizedShape = { type, labels, datasets };
  if (typeof data['title'] === 'string' && data['title'] !== '') out.title = data['title'];
  if (data['options'] !== null && typeof data['options'] === 'object' && !Array.isArray(data['options'])) {
    out.options = data['options'] as Record<string, unknown>;
  }
  return out;
}

function buildChartOptions(shape: NormalizedShape): Record<string, unknown> {
  const userOptions = shape.options ?? {};
  const merged: Record<string, unknown> = {
    responsive: true,
    maintainAspectRatio: false,
    ...userOptions,
  };
  // Title plugin merge: respect user options.plugins.title if it exists;
  // otherwise, if the block carries `title`, project it onto the plugin.
  const userPlugins = (userOptions['plugins'] && typeof userOptions['plugins'] === 'object')
    ? userOptions['plugins'] as Record<string, unknown>
    : {};
  const userTitle = (userPlugins['title'] && typeof userPlugins['title'] === 'object')
    ? userPlugins['title'] as Record<string, unknown>
    : null;
  if (shape.title !== undefined && userTitle === null) {
    merged['plugins'] = {
      ...userPlugins,
      title: { display: true, text: shape.title },
    };
  } else if (Object.keys(userPlugins).length > 0) {
    merged['plugins'] = userPlugins;
  }
  return merged;
}

/**
 * Renders a `chart`-type block using Chart.js. If the `data` fails the sanity
 * checks, or a host renderer threw upstream (meta.customError), it delegates to
 * the placeholder (`renderChartBlock`) so the UX is consistent everywhere.
 */
export const renderChartBlockChartjs: BlockRenderer = (
  data: Record<string, unknown>,
  host: BlockHost,
  meta?: BlockRendererMeta,
): HTMLElement => {
  // When used as the built-in fallback after a host-registered 'chart' renderer
  // threw, `renderBlock` passes `meta.customError`. Surface that via the
  // placeholder instead of silently re-rendering the same data.
  if (meta?.customError !== undefined && meta.customError !== null) {
    return renderChartBlockPlaceholder(data, host, meta);
  }

  const shape = normalize(data);
  // #25 — the renderer IS registered; the data just failed normalization.
  // `invalidData` makes the placeholder say so.
  if (shape === null) return renderChartBlockPlaceholder(data, host, { invalidData: true });

  const wrapper = document.createElement('div');
  wrapper.className = 'block block-chart cb-chart cb-chart-chartjs';

  if (shape.title !== undefined) {
    const h = document.createElement('h3');
    h.className = 'cb-chart-title';
    h.textContent = shape.title;
    wrapper.appendChild(h);
  }

  const canvasWrap = document.createElement('div');
  canvasWrap.className = 'cb-chart-canvas-wrap';
  const canvas = document.createElement('canvas');
  canvasWrap.appendChild(canvas);
  wrapper.appendChild(canvasWrap);

  // Defer Chart() construction to the next microtask so callers that mount the
  // wrapper into the DOM right after this call can do so before Chart.js
  // measures the canvas (responsive sizing). queueMicrotask resolves before the
  // next paint so visually it's identical.
  const construct = (): void => {
    const prev = liveCharts.get(canvas);
    if (prev) {
      try { prev.destroy(); } catch { /* noop */ }
    }
    try {
      const instance = new Chart(canvas, {
        type: shape.type,
        data: {
          labels: shape.labels,
          datasets: shape.datasets as unknown as Chart['data']['datasets'],
        },
        options: buildChartOptions(shape) as Chart['options'],
      });
      liveCharts.set(canvas, instance);
    } catch (err) {
      console.error('[chatbot:chart] Chart.js threw while mounting:', err);
      // #25 — `customError` so the placeholder reports the throw.
      wrapper.replaceWith(renderChartBlockPlaceholder(data, host, { customError: err }));
    }
  };
  queueMicrotask(construct);

  return wrapper;
};

/** Test/internal helper: lookup the live Chart instance for a canvas. */
export function __getLiveChart(canvas: HTMLCanvasElement): Chart | undefined {
  return liveCharts.get(canvas);
}
