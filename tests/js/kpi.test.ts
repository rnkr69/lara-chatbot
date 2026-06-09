import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import { renderKpiBlock, setKpiLabels, resetKpiLabels } from '../../resources/js/kpi.js';
import { renderBlock, BUILTIN_BLOCK_RENDERERS } from '../../resources/js/blocks.js';

const NOOP_HOST = { send: () => undefined };

beforeEach(() => {
  // Reset html[lang] so locale-cascade tests start from a known state.
  document.documentElement.lang = '';
});

afterEach(() => {
  resetKpiLabels();
});

describe('renderKpiBlock — happy paths', () => {
  it('renders label + numeric value with locale-aware grouping (no format)', () => {
    const node = renderKpiBlock({ label: 'Active users', value: 1234, locale: 'en-US' }, NOOP_HOST);
    expect(node.querySelector('.cb-kpi-label')?.textContent).toBe('Active users');
    expect(node.querySelector('.cb-kpi-value')?.textContent).toBe('1,234');
    expect(node.classList.contains('block-kpi')).toBe(true);
    expect(node.classList.contains('cb-kpi')).toBe(true);
  });

  it('renders currency format with USD as default when neither currency nor unit ISO is given', () => {
    const node = renderKpiBlock({ label: 'Revenue', value: 1200, format: 'currency', locale: 'en-US' }, NOOP_HOST);
    expect(node.querySelector('.cb-kpi-value')?.textContent).toBe('$1,200.00');
    // Unit is not rendered as a sibling because the currency symbol is already in the value.
    expect(node.querySelector('.cb-kpi-unit')).toBeNull();
  });

  it('renders percent format treating value as fraction (0.42 → "42%")', () => {
    const node = renderKpiBlock({ label: 'Conversion', value: 0.42, format: 'percent', locale: 'en-US' }, NOOP_HOST);
    expect(node.querySelector('.cb-kpi-value')?.textContent).toBe('42%');
  });

  it('applies compact notation when abs(value) >= 100_000', () => {
    const big = renderKpiBlock({ label: 'Views', value: 1_234_567, format: 'number', locale: 'en-US' }, NOOP_HOST);
    expect(big.querySelector('.cb-kpi-value')?.textContent).toMatch(/1\.23M/);

    const small = renderKpiBlock({ label: 'Views', value: 99_999, format: 'number', locale: 'en-US' }, NOOP_HOST);
    expect(small.querySelector('.cb-kpi-value')?.textContent).toBe('99,999');
  });

  it('renders unit sibling when format is not currency', () => {
    const node = renderKpiBlock({ label: 'Latency', value: 420, unit: 'ms', locale: 'en-US' }, NOOP_HOST);
    expect(node.querySelector('.cb-kpi-value')?.textContent).toBe('420');
    expect(node.querySelector('.cb-kpi-unit')?.textContent).toBe('ms');
  });

  it('renders optional caption when present', () => {
    const node = renderKpiBlock({ label: 'Users', value: 100, caption: 'vs. last week', locale: 'en-US' }, NOOP_HOST);
    expect(node.querySelector('.cb-kpi-caption')?.textContent).toBe('vs. last week');
  });
});

describe('renderKpiBlock — string values', () => {
  it('renders pre-formatted strings as-is when no format is set', () => {
    const node = renderKpiBlock({ label: 'Revenue', value: '$1.2B' }, NOOP_HOST);
    expect(node.querySelector('.cb-kpi-value')?.textContent).toBe('$1.2B');
  });

  it('coerces numeric strings when format is set', () => {
    const node = renderKpiBlock({ label: 'Revenue', value: '1200', format: 'currency', locale: 'en-US' }, NOOP_HOST);
    expect(node.querySelector('.cb-kpi-value')?.textContent).toBe('$1,200.00');
  });

  it('renders raw when coercion fails and format is set', () => {
    const node = renderKpiBlock({ label: 'Revenue', value: 'N/A', format: 'currency', locale: 'en-US' }, NOOP_HOST);
    expect(node.querySelector('.cb-kpi-value')?.textContent).toBe('N/A');
  });
});

describe('renderKpiBlock — fallback when value is missing or invalid', () => {
  it('renders label + "—" when value is missing', () => {
    const node = renderKpiBlock({ label: 'Active users' }, NOOP_HOST);
    expect(node.classList.contains('cb-kpi-empty')).toBe(true);
    expect(node.querySelector('.cb-kpi-label')?.textContent).toBe('Active users');
    expect(node.querySelector('.cb-kpi-value')?.textContent).toBe('—');
  });

  it('renders minimal "—" when both label and value are missing', () => {
    const node = renderKpiBlock({}, NOOP_HOST);
    expect(node.classList.contains('cb-kpi-empty')).toBe(true);
    expect(node.querySelector('.cb-kpi-label')).toBeNull();
    expect(node.querySelector('.cb-kpi-value')?.textContent).toBe('—');
  });

  it('renders fallback when value is null / object / Symbol', () => {
    const a = renderKpiBlock({ label: 'X', value: null }, NOOP_HOST);
    expect(a.querySelector('.cb-kpi-value')?.textContent).toBe('—');
    const b = renderKpiBlock({ label: 'X', value: { evil: true } }, NOOP_HOST);
    expect(b.querySelector('.cb-kpi-value')?.textContent).toBe('—');
    const c = renderKpiBlock({ label: 'X', value: Symbol('s') as unknown as string }, NOOP_HOST);
    expect(c.querySelector('.cb-kpi-value')?.textContent).toBe('—');
  });

  it('treats NaN / Infinity as invalid', () => {
    const a = renderKpiBlock({ label: 'X', value: Number.NaN }, NOOP_HOST);
    expect(a.querySelector('.cb-kpi-value')?.textContent).toBe('—');
    const b = renderKpiBlock({ label: 'X', value: Number.POSITIVE_INFINITY }, NOOP_HOST);
    expect(b.querySelector('.cb-kpi-value')?.textContent).toBe('—');
  });
});

describe('renderKpiBlock — delta and trend', () => {
  it('auto-derives trend from numeric delta sign and prefixes positive with +', () => {
    const up = renderKpiBlock({ label: 'X', value: 100, delta: 12.5, locale: 'en-US' }, NOOP_HOST);
    expect(up.querySelector('.cb-kpi-delta')?.classList.contains('cb-kpi-trend-up')).toBe(true);
    expect(up.querySelector('.cb-kpi-trend-arrow')?.textContent).toBe('↑');
    expect(up.querySelector('.cb-kpi-delta-value')?.textContent).toBe('+12.5');

    const down = renderKpiBlock({ label: 'X', value: 100, delta: -3, locale: 'en-US' }, NOOP_HOST);
    expect(down.querySelector('.cb-kpi-delta')?.classList.contains('cb-kpi-trend-down')).toBe(true);
    expect(down.querySelector('.cb-kpi-trend-arrow')?.textContent).toBe('↓');
    expect(down.querySelector('.cb-kpi-delta-value')?.textContent).toBe('-3');

    const flat = renderKpiBlock({ label: 'X', value: 100, delta: 0, locale: 'en-US' }, NOOP_HOST);
    expect(flat.querySelector('.cb-kpi-delta')?.classList.contains('cb-kpi-trend-flat')).toBe(true);
    expect(flat.querySelector('.cb-kpi-trend-arrow')?.textContent).toBe('→');
  });

  it('explicit trend overrides auto-derived sign', () => {
    const node = renderKpiBlock({ label: 'X', value: 100, delta: -10, trend: 'up', locale: 'en-US' }, NOOP_HOST);
    expect(node.querySelector('.cb-kpi-delta')?.classList.contains('cb-kpi-trend-up')).toBe(true);
    expect(node.querySelector('.cb-kpi-delta-value')?.textContent).toBe('-10');
  });

  it('renders string delta as-is without prefix', () => {
    const node = renderKpiBlock({ label: 'X', value: 100, delta: '12% YoY', locale: 'en-US' }, NOOP_HOST);
    expect(node.querySelector('.cb-kpi-delta-value')?.textContent).toBe('12% YoY');
    // No auto-derived trend for string deltas; without explicit trend the arrow is omitted.
    expect(node.querySelector('.cb-kpi-trend-arrow')).toBeNull();
  });

  it('omits the delta row entirely when no delta and no trend are given', () => {
    const node = renderKpiBlock({ label: 'X', value: 100 }, NOOP_HOST);
    expect(node.querySelector('.cb-kpi-delta')).toBeNull();
  });

  it('formats numeric delta with the same family as value (currency)', () => {
    const node = renderKpiBlock({ label: 'X', value: 1000, delta: 250, format: 'currency', locale: 'en-US' }, NOOP_HOST);
    expect(node.querySelector('.cb-kpi-delta-value')?.textContent).toBe('+$250.00');
  });
});

describe('renderKpiBlock — locale and currency cascades', () => {
  it('uses data.locale when provided', () => {
    document.documentElement.lang = 'en-US';
    const node = renderKpiBlock({ label: 'X', value: 1234, locale: 'de-DE' }, NOOP_HOST);
    // de-DE uses "." as thousand separator.
    expect(node.querySelector('.cb-kpi-value')?.textContent).toBe('1.234');
  });

  it('falls back to document.documentElement.lang', () => {
    document.documentElement.lang = 'de-DE';
    const node = renderKpiBlock({ label: 'X', value: 1234 }, NOOP_HOST);
    expect(node.querySelector('.cb-kpi-value')?.textContent).toBe('1.234');
  });

  it('uses data.currency when provided', () => {
    const node = renderKpiBlock({ label: 'X', value: 100, format: 'currency', currency: 'EUR', locale: 'en-US' }, NOOP_HOST);
    expect(node.querySelector('.cb-kpi-value')?.textContent).toMatch(/€/);
  });

  it('uses unit as currency when it looks like an ISO code', () => {
    const node = renderKpiBlock({ label: 'X', value: 100, format: 'currency', unit: 'GBP', locale: 'en-US' }, NOOP_HOST);
    expect(node.querySelector('.cb-kpi-value')?.textContent).toMatch(/£/);
  });
});

describe('renderKpiBlock — label aliases and malicious inputs', () => {
  it('accepts title and name as label aliases', () => {
    const a = renderKpiBlock({ title: 'From title', value: 1 }, NOOP_HOST);
    expect(a.querySelector('.cb-kpi-label')?.textContent).toBe('From title');
    const b = renderKpiBlock({ name: 'From name', value: 1 }, NOOP_HOST);
    expect(b.querySelector('.cb-kpi-label')?.textContent).toBe('From name');
  });

  it('survives __proto__ poisoning and Symbol fields', () => {
    const payload: Record<string, unknown> = Object.create(null);
    payload['label'] = 'Safe';
    payload['value'] = 42;
    Object.defineProperty(payload, '__proto__', {
      value: { evil: true },
      enumerable: true,
      configurable: true,
    });
    (payload as Record<string | symbol, unknown>)[Symbol('s')] = 'ignored';
    const node = renderKpiBlock(payload, NOOP_HOST);
    expect(node.querySelector('.cb-kpi-label')?.textContent).toBe('Safe');
    expect(node.querySelector('.cb-kpi-value')?.textContent).toBe('42');
  });
});

describe('setKpiLabels — i18n bridge', () => {
  it('replaces the fallback dash in renderEmpty when no value resolves', () => {
    setKpiLabels({ no_value: 'sin datos' });
    const node = renderKpiBlock({ label: 'Latency' }, NOOP_HOST);
    expect(node.querySelector('.cb-kpi-value')?.textContent).toBe('sin datos');
    expect(node.classList.contains('cb-kpi-empty')).toBe(true);
  });

  it('partial calls only override the keys given', () => {
    setKpiLabels({});
    const node = renderKpiBlock({ label: 'Latency' }, NOOP_HOST);
    // Empty partial leaves default in place.
    expect(node.querySelector('.cb-kpi-value')?.textContent).toBe('—');
  });

  it('resetKpiLabels restores the inline defaults', () => {
    setKpiLabels({ no_value: 'sin datos' });
    resetKpiLabels();
    const node = renderKpiBlock({ label: 'Latency' }, NOOP_HOST);
    expect(node.querySelector('.cb-kpi-value')?.textContent).toBe('—');
  });
});

describe('renderKpiBlock — wired into BUILTIN_BLOCK_RENDERERS', () => {
  it('is registered as the kpi built-in', () => {
    expect(BUILTIN_BLOCK_RENDERERS['kpi']).toBe(renderKpiBlock);
  });

  it('renders via renderBlock() cascade', () => {
    const node = renderBlock(
      { type: 'kpi', data: { label: 'Latency', value: 420, unit: 'ms', locale: 'en-US' } },
      NOOP_HOST,
    );
    expect(node.querySelector('.cb-kpi-label')?.textContent).toBe('Latency');
    expect(node.querySelector('.cb-kpi-value')?.textContent).toBe('420');
    expect(node.querySelector('.cb-kpi-unit')?.textContent).toBe('ms');
  });
});
