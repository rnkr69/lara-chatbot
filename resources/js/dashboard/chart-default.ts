/**
 * v2.0 / E7 — default Chart.js renderer for the `chart` block when it lives
 * in the dashboard bundle.
 *
 * The floating widget does NOT bundle Chart.js (the 80 KB gzip cap forbids
 * it); the dashboard does bundle it (150 KB gzip cap). `chart.js/auto`
 * registers the 8 controllers + scales + plugins → any valid `type` comes for
 * free with no future changes here.
 *
 * The renderer is PURE: it receives `data` and returns an HTMLElement. It
 * knows nothing about the widget-card lifecycle; the internal WeakMap destroys
 * the previous Chart instance when the same canvas is re-rendered (E3 replay
 * updates the snapshot → widget-card.ts re-calls renderBlock → if the wrapper
 * exists and the previous Chart is still alive, it must be destroyed to avoid
 * leaks).
 *
 * Sanity:
 *   - `type` must be one of the 4 supported ones; anything else → fallback to
 *     the placeholder in blocks.ts.
 *   - `labels` array of strings; `datasets` non-empty array of objects with a
 *     `data` array of numbers; or `series/points/values` aliases for
 *     datasets[0].data and `categories` for labels.
 *
 * LLM-friendly aliases:
 *   - `kind` → `type` (v2.1.1 / #25).
 *   - `categories` → `labels`.
 *   - `series` | `points` | `values` → `datasets[0].data` (with an optional
 *     label from block.data.title || block.type).
 *
 * Host override: if the host calls `window.Chatbot.registerBlockRenderer('chart', fn)`
 * BEFORE the dashboard bundle tries to register the built-in, the host wins.
 * The logic lives in `index.ts`; this module only exposes the renderer.
 */

// We import Chart.js/auto (registers EVERYTHING automatically). The dashboard
// bundle absorbs ~60 KB gzip; the widget bundle does not touch this module.
import Chart from 'chart.js/auto';
import type { BlockHost, BlockRenderer } from '../types.js';
import { renderChartBlock as renderChartBlockPlaceholder } from '../blocks.js';

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
  // v2.1.1 (#25) — accept `kind` as an alias of `type`. An LLM or a backend
  // tool naturally emits `kind: 'bar'`; without this the spec failed
  // normalization and the placeholder falsely claimed no renderer existed.
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
  // datasets[0].data.length; otherwise Chart.js renders but with a broken legend.
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
 * checks, it delegates to the built-in placeholder (`renderChartBlock`) to keep
 * the UX consistent with the floating widget (hosts already saw that
 * placeholder in v1.x).
 */
export const renderChartBlockChartjs: BlockRenderer = (
  data: Record<string, unknown>,
  host: BlockHost,
): HTMLElement => {
  const shape = normalize(data);
  // v2.1.1 (#25) — the renderer IS registered; the data just failed
  // normalization. `invalidData` makes the placeholder say so instead of
  // the false "Chart renderer not registered".
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
  // measures the canvas. Doing it synchronously works too (Chart.js handles
  // detached canvas), but the responsive sizing degrades. queueMicrotask
  // resolves before the next paint so visually it's identical.
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
      console.error('[chatbot:dashboard] Chart.js threw while mounting:', err);
      // v2.1.1 (#25) — `customError` so the placeholder reports the throw,
      // not a false "renderer not registered".
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
