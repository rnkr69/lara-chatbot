/**
 * v2.0 / E7 — renderer Chart.js por defecto para el block `chart` cuando vive
 * en el bundle del dashboard.
 *
 * El widget flotante NO embarca Chart.js (cap 80 KB gzip lo prohíbe); el
 * dashboard sí lo embarca (cap 150 KB gzip). `chart.js/auto` registra los 8
 * controllers + escalas + plugins → cualquier `type` válido sale gratis sin
 * cambios futuros aquí.
 *
 * El renderer es PURE: recibe `data` y devuelve un HTMLElement. No conoce
 * lifecycle del widget-card; el WeakMap interno destruye la Chart instance
 * anterior cuando el mismo canvas se re-renderiza (E3 replay actualiza el
 * snapshot → widget-card.ts re-llama renderBlock → si el wrapper existe y la
 * Chart anterior sigue viva, hay que destruirla para evitar leaks).
 *
 * Sanity:
 *   - `type` debe ser uno de los 4 soportados; cualquier otro → fallback al
 *     placeholder de blocks.ts.
 *   - `labels` array de strings; `datasets` array no-vacío de objetos con
 *     `data` array de numbers; o aliases `series/points/values` para
 *     datasets[0].data y `categories` para labels.
 *
 * Aliases LLM-friendly:
 *   - `kind` → `type` (v2.1.1 / #25).
 *   - `categories` → `labels`.
 *   - `series` | `points` | `values` → `datasets[0].data` (con label opcional
 *     del block.data.title || block.type).
 *
 * Override por host: si el host hace `window.Chatbot.registerBlockRenderer('chart', fn)`
 * ANTES de que el bundle del dashboard intente registrar el built-in, gana el
 * host. La lógica vive en `index.ts`; este módulo sólo expone el renderer.
 */

// Importamos Chart.js/auto (registra TODO automáticamente). El bundle del
// dashboard absorbe ~60 KB gzip; el bundle del widget no toca este módulo.
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
  // Passthrough opaco de claves Chart.js que no normalizamos (fill, tension, …).
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

  // labels (con alias categories).
  const labelsCandidate = data['labels'] !== undefined ? data['labels'] : data['categories'];
  const labels = asStringArray(labelsCandidate);
  if (labels === null) return null;

  // datasets — primero el shape explícito; si no, aliases series/points/values.
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

  // En 'pie'/'doughnut'/'polarArea', labels.length DEBE coincidir con
  // datasets[0].data.length; si no, Chart.js renderiza pero con leyenda rota.
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
  // Title plugin merge: respect user options.plugins.title si existe; si no y
  // el block trae `title`, lo proyectamos al plugin.
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
 * Renderiza un block tipo `chart` usando Chart.js. Si la `data` no pasa las
 * sanity checks, delega al placeholder built-in (`renderChartBlock`) para
 * mantener UX coherente con el widget flotante (los hosts ya vieron ese
 * placeholder en v1.x).
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
