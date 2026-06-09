/**
 * v2.0 / E8 — built-in renderer para el block tipo `kpi`.
 *
 * Vive en `resources/js/` (no en `dashboard/`) porque tanto el widget flotante
 * como el bundle del dashboard heredan el cascade `host > template > builtin`
 * vía `BUILTIN_BLOCK_RENDERERS` en `blocks.ts`. Coste ~3 KB gzip; el widget
 * pasa de 24 → ~27 KB / cap 80 KB.
 *
 * Shape (laxo, normalizado in-place — coherente con E7 chart-default.ts):
 *
 *   {
 *     label:    string,                                  // recomendado (LLM aliases: title|name)
 *     value:    number | string,                          // requerido para mostrar algo útil
 *     unit?:    string,                                   // p. ej. "ms", "USD", "users"
 *     delta?:   number | string,                          // diff vs período anterior
 *     trend?:   'up' | 'down' | 'flat',                   // override; si falta y delta es numérico → auto
 *     format?:  'number' | 'currency' | 'percent',        // si falta → render numérico locale-aware
 *     caption?: string,                                   // texto pequeño bajo la tarjeta
 *     locale?:  string,                                   // override; default: html[lang] || 'en-US'
 *     currency?:string,                                   // ISO 4217; default: unit-if-ISO || 'USD'
 *   }
 *
 * Reglas clave:
 *   - `value` numérico SIN `format` → render locale-aware con grouping; compact
 *     notation cuando |value| ≥ 100_000.
 *   - `value` STRING — escape hatch para LLMs que pre-formatean ("$1.2B"). Sólo
 *     intentamos coercion `Number()` si `format` está SET; si la coerción falla
 *     (o no hay format), renderizamos el string tal cual.
 *   - `format: 'percent'` espera fracción (0.42 → "42%"). Quien emita 42 sin
 *     ajustar la escala usa `format: 'number' + unit: '%'`.
 *   - `delta` numérica → formatea con `signDisplay: 'exceptZero'` así los
 *     positivos llevan "+" auto; negativos ya llevan "−"/"-". Strings as-is.
 *   - `trend` explícita gana sobre auto-derivada de `delta`.
 *   - Sin `value` válido y sin `label` → render minimal "—" (en lugar de devolver
 *     placeholder genérico de `[unknown block type]`).
 */

import type { BlockHost, BlockRenderer } from './types.js';

type KpiFormat = 'number' | 'currency' | 'percent';
type KpiTrend = 'up' | 'down' | 'flat';

const TREND_ARROWS: Record<KpiTrend, string> = {
  up: '↑',
  down: '↓',
  flat: '→',
};

/**
 * v2.0 / E9 — module-level i18n state for the KPI built-in renderer.
 *
 * The renderer is registered in `BUILTIN_BLOCK_RENDERERS` (`blocks.ts`), so it
 * has no `opts.labels` channel like the dashboard mounters do — `blocks.ts`
 * invokes it with `(data, host)` only. The widget bundle (`widget.ts`) and the
 * dashboard bundle (`dashboard/index.ts`) both call `setKpiLabels` at boot
 * with the value of `i18n.dashboard.kpi`, draining the PHP translation.
 *
 * `Partial` merge: passing `setKpiLabels({})` is a no-op; passing
 * `setKpiLabels({ no_value: '—' })` replaces only that key. Bundles that don't
 * receive a payload (no `data-i18n` on root) leave the defaults intact.
 */
export interface KpiLabels {
  no_value: string;
}

const DEFAULT_KPI_LABELS: KpiLabels = {
  no_value: '—',
};

let currentLabels: KpiLabels = { ...DEFAULT_KPI_LABELS };

export function setKpiLabels(partial: Partial<KpiLabels>): void {
  currentLabels = { ...currentLabels, ...partial };
}

/** Exposed for tests; resets to inline defaults. */
export function resetKpiLabels(): void {
  currentLabels = { ...DEFAULT_KPI_LABELS };
}

function asNonEmptyString(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null;
}

function isAllowedFormat(value: unknown): value is KpiFormat {
  return value === 'number' || value === 'currency' || value === 'percent';
}

function isAllowedTrend(value: unknown): value is KpiTrend {
  return value === 'up' || value === 'down' || value === 'flat';
}

function resolveLocale(raw: unknown): string {
  const fromData = asNonEmptyString(raw);
  if (fromData !== null) return fromData;
  if (typeof document !== 'undefined') {
    const docLang = asNonEmptyString(document.documentElement.lang);
    if (docLang !== null) return docLang;
  }
  return 'en-US';
}

function looksLikeIsoCurrency(value: string): boolean {
  return /^[A-Za-z]{3}$/.test(value);
}

function resolveCurrency(data: Record<string, unknown>): string {
  const explicit = asNonEmptyString(data['currency']);
  if (explicit !== null) return explicit.toUpperCase();
  const unit = asNonEmptyString(data['unit']);
  if (unit !== null && looksLikeIsoCurrency(unit)) return unit.toUpperCase();
  return 'USD';
}

function deriveTrend(delta: number): KpiTrend {
  if (delta > 0) return 'up';
  if (delta < 0) return 'down';
  return 'flat';
}

interface FormatterOptions {
  format: KpiFormat | undefined;
  locale: string;
  currency: string;
  compact: boolean;
}

function buildValueFormatter(opts: FormatterOptions): Intl.NumberFormat | null {
  try {
    const base: Intl.NumberFormatOptions = { maximumFractionDigits: 2 };
    if (opts.compact) base.notation = 'compact';
    if (opts.format === 'currency') {
      return new Intl.NumberFormat(opts.locale, { ...base, style: 'currency', currency: opts.currency });
    }
    if (opts.format === 'percent') {
      return new Intl.NumberFormat(opts.locale, { ...base, style: 'percent' });
    }
    return new Intl.NumberFormat(opts.locale, base);
  } catch {
    return null;
  }
}

function buildDeltaFormatter(opts: FormatterOptions): Intl.NumberFormat | null {
  try {
    const base: Intl.NumberFormatOptions = {
      maximumFractionDigits: 2,
      signDisplay: 'exceptZero',
    };
    if (opts.compact) base.notation = 'compact';
    if (opts.format === 'currency') {
      return new Intl.NumberFormat(opts.locale, { ...base, style: 'currency', currency: opts.currency });
    }
    if (opts.format === 'percent') {
      return new Intl.NumberFormat(opts.locale, { ...base, style: 'percent' });
    }
    return new Intl.NumberFormat(opts.locale, base);
  } catch {
    return null;
  }
}

function renderEmpty(label: string | null): HTMLElement {
  const wrapper = document.createElement('div');
  wrapper.className = 'block block-kpi cb-kpi cb-kpi-empty';
  if (label !== null) {
    const labelEl = document.createElement('div');
    labelEl.className = 'cb-kpi-label';
    labelEl.textContent = label;
    wrapper.appendChild(labelEl);
  }
  const valueEl = document.createElement('div');
  valueEl.className = 'cb-kpi-value';
  valueEl.textContent = currentLabels.no_value;
  wrapper.appendChild(valueEl);
  return wrapper;
}

export const renderKpiBlock: BlockRenderer = (
  data: Record<string, unknown>,
  _host: BlockHost,
): HTMLElement => {
  const label = asNonEmptyString(data['label'])
    ?? asNonEmptyString(data['title'])
    ?? asNonEmptyString(data['name']);

  const rawValue = data['value'];
  const formatRaw = data['format'];
  const format = isAllowedFormat(formatRaw) ? formatRaw : undefined;

  // Resolve value to (numeric | raw string | null). When format is SET we try
  // to coerce string→number; when format is undefined, we keep strings raw so
  // pre-formatted values like "$1.2B" survive.
  let numericValue: number | null = null;
  let rawStringValue: string | null = null;
  if (typeof rawValue === 'number' && Number.isFinite(rawValue)) {
    numericValue = rawValue;
  } else if (typeof rawValue === 'string' && rawValue !== '') {
    if (format !== undefined) {
      const n = Number(rawValue);
      if (Number.isFinite(n)) numericValue = n;
      else rawStringValue = rawValue;
    } else {
      rawStringValue = rawValue;
    }
  }

  if (numericValue === null && rawStringValue === null) {
    return renderEmpty(label);
  }

  const locale = resolveLocale(data['locale']);
  const currency = resolveCurrency(data);
  const compactValue = numericValue !== null && Math.abs(numericValue) >= 100_000;
  const valueFormatter = buildValueFormatter({ format, locale, currency, compact: compactValue });

  let valueText: string;
  if (rawStringValue !== null) {
    valueText = rawStringValue;
  } else if (numericValue !== null && valueFormatter !== null) {
    try { valueText = valueFormatter.format(numericValue); }
    catch { valueText = String(numericValue); }
  } else {
    valueText = currentLabels.no_value;
  }

  // ── Build DOM ──────────────────────────────────────────────────────────
  const wrapper = document.createElement('div');
  wrapper.className = 'block block-kpi cb-kpi';

  if (label !== null) {
    const labelEl = document.createElement('div');
    labelEl.className = 'cb-kpi-label';
    labelEl.textContent = label;
    wrapper.appendChild(labelEl);
  }

  const valueRow = document.createElement('div');
  valueRow.className = 'cb-kpi-value-row';

  const valueEl = document.createElement('span');
  valueEl.className = 'cb-kpi-value';
  valueEl.textContent = valueText;
  valueRow.appendChild(valueEl);

  const unitText = asNonEmptyString(data['unit']);
  // Skip rendering `unit` as a sibling when the currency formatter already
  // baked the symbol into `valueText` — avoids "$1,200 USD" duplication.
  const unitAlreadyInValue = format === 'currency' && rawStringValue === null;
  if (unitText !== null && !unitAlreadyInValue) {
    const unitEl = document.createElement('span');
    unitEl.className = 'cb-kpi-unit';
    unitEl.textContent = unitText;
    valueRow.appendChild(unitEl);
  }

  wrapper.appendChild(valueRow);

  // Delta + trend.
  const rawDelta = data['delta'];
  let deltaText: string | null = null;
  let derivedTrend: KpiTrend | null = null;
  if (typeof rawDelta === 'number' && Number.isFinite(rawDelta)) {
    derivedTrend = deriveTrend(rawDelta);
    const deltaFormatter = buildDeltaFormatter({
      format,
      locale,
      currency,
      compact: Math.abs(rawDelta) >= 100_000,
    });
    if (deltaFormatter !== null) {
      try { deltaText = deltaFormatter.format(rawDelta); }
      catch { deltaText = String(rawDelta); }
    } else {
      deltaText = String(rawDelta);
    }
  } else if (typeof rawDelta === 'string' && rawDelta !== '') {
    deltaText = rawDelta;
  }

  const explicitTrend = isAllowedTrend(data['trend']) ? (data['trend'] as KpiTrend) : null;
  const finalTrend = explicitTrend ?? derivedTrend;

  if (deltaText !== null || finalTrend !== null) {
    const deltaRow = document.createElement('div');
    deltaRow.className = 'cb-kpi-delta';
    if (finalTrend !== null) deltaRow.classList.add(`cb-kpi-trend-${finalTrend}`);
    if (finalTrend !== null) {
      const arrow = document.createElement('span');
      arrow.className = 'cb-kpi-trend-arrow';
      arrow.setAttribute('aria-hidden', 'true');
      arrow.textContent = TREND_ARROWS[finalTrend];
      deltaRow.appendChild(arrow);
    }
    if (deltaText !== null) {
      const deltaValueEl = document.createElement('span');
      deltaValueEl.className = 'cb-kpi-delta-value';
      deltaValueEl.textContent = deltaText;
      deltaRow.appendChild(deltaValueEl);
    }
    wrapper.appendChild(deltaRow);
  }

  const caption = asNonEmptyString(data['caption']);
  if (caption !== null) {
    const captionEl = document.createElement('div');
    captionEl.className = 'cb-kpi-caption';
    captionEl.textContent = caption;
    wrapper.appendChild(captionEl);
  }

  return wrapper;
};
