import type { BlockHost, BlockPayload, BlockRenderer } from './types.js';
import { renderMarkdown } from './markdown.js';
import { cloneAndBind, findTemplate } from './slot-templates.js';
import { renderKpiBlock } from './kpi.js';
import { renderChartBlockChartjs } from './chart-default.js';

function asString(value: unknown, fallback = ''): string {
  return typeof value === 'string' ? value : fallback;
}

/**
 * v1.1 (findings #5): pick the first non-empty string under any of `keys`.
 * The LLM often picks a near-synonym (`header`/`name` instead of `title`,
 * `items`/`data` instead of `rows`); silently failing made the block render
 * empty with no signal to the host operator. We accept the alias AND warn
 * (in dev console) so the prompt can be tightened over time.
 */
function pickAlias(
  data: Record<string, unknown>,
  required: string,
  aliases: readonly string[],
  blockType: string,
): string {
  const direct = asString(data[required]);
  if (direct !== '') return direct;
  for (const alias of aliases) {
    const v = asString(data[alias]);
    if (v !== '') {
      console.warn(
        `[chatbot:block:${blockType}] expected key "${required}" missing; using alias "${alias}". `
        + `Tighten the LLM prompt or block contract.`,
      );
      return v;
    }
  }
  return '';
}

function pickArrayAlias(
  data: Record<string, unknown>,
  required: string,
  aliases: readonly string[],
  blockType: string,
): unknown[] {
  if (Array.isArray(data[required])) return data[required] as unknown[];
  for (const alias of aliases) {
    if (Array.isArray(data[alias])) {
      console.warn(
        `[chatbot:block:${blockType}] expected key "${required}" missing; using alias "${alias}". `
        + `Tighten the LLM prompt or block contract.`,
      );
      return data[alias] as unknown[];
    }
  }
  return [];
}

function renderMissingKeyPlaceholder(blockType: string, key: string, available: string[]): HTMLElement {
  const el = document.createElement('div');
  el.className = `block block-${blockType} cb-block-invalid`;
  el.setAttribute('role', 'note');
  el.textContent = available.length > 0
    ? `[${blockType}: invalid — missing required "${key}" (got: ${available.join(', ')})]`
    : `[${blockType}: invalid — missing required "${key}"]`;
  return el;
}

export function renderTextBlock(data: Record<string, unknown>, _host: BlockHost): HTMLElement {
  const wrapper = document.createElement('div');
  wrapper.className = 'block block-text';
  wrapper.innerHTML = renderMarkdown(asString(data['content']));
  return wrapper;
}

interface ActionItem {
  label: string;
  prompt?: string;
  tool?: string;
  args?: Record<string, unknown>;
}

function normalizeActions(raw: unknown): ActionItem[] {
  if (!Array.isArray(raw)) return [];
  const out: ActionItem[] = [];
  for (const entry of raw) {
    if (!entry || typeof entry !== 'object') continue;
    const e = entry as Record<string, unknown>;
    const label = asString(e['label']);
    if (label === '') continue;
    const item: ActionItem = { label };
    const prompt = asString(e['prompt']);
    if (prompt !== '') item.prompt = prompt;
    const tool = asString(e['tool']);
    if (tool !== '') item.tool = tool;
    if (e['args'] && typeof e['args'] === 'object' && !Array.isArray(e['args'])) {
      item.args = e['args'] as Record<string, unknown>;
    }
    out.push(item);
  }
  return out;
}

export function renderActionsBlock(data: Record<string, unknown>, host: BlockHost): HTMLElement {
  const wrapper = document.createElement('div');
  wrapper.className = 'block block-actions actions';
  const items = normalizeActions(data['actions']);
  for (const item of items) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = item.label;
    btn.addEventListener('click', () => {
      if (item.prompt) {
        host.send(item.prompt);
        return;
      }
      if (item.tool) {
        const handler = window.Chatbot?.__internal.getTool(item.tool);
        if (handler) {
          void handler(item.args ?? {}, { actionId: `block-${Date.now()}`, confirmation: 'auto' });
        }
      }
    });
    wrapper.appendChild(btn);
  }
  return wrapper;
}

export function renderCardBlock(data: Record<string, unknown>, host: BlockHost): HTMLElement {
  // v1.1 (findings #5): `title` is the documented required key, but the LLM
  // often emits `header` or `name`. Accept aliases (with a console.warn) and
  // fall back to a visible placeholder when none are present so the host
  // operator sees something instead of an empty card body.
  const title = pickAlias(data, 'title', ['header', 'name', 'label'], 'card');
  const subtitle = asString(data['subtitle']);
  const description = asString(data['description']);
  const fields = data['fields'];
  const actions = data['actions'];

  const hasAnyContent = title !== ''
    || subtitle !== ''
    || description !== ''
    || (Array.isArray(fields) && fields.length > 0)
    || (Array.isArray(actions) && actions.length > 0);

  if (!hasAnyContent) {
    console.warn('[chatbot:block:card] no recognised content keys; rendering placeholder.', {
      received: Object.keys(data),
    });
    return renderMissingKeyPlaceholder('card', 'title', Object.keys(data));
  }

  const wrapper = document.createElement('div');
  // v2.1 (E14 / #19) — Bootstrap classes (`card`, `card-body`, `card-title`…)
  // ride alongside the `.cb-*` classes. Where Bootstrap is absent (the
  // widget's shadow DOM, the standalone dashboard) they match nothing and
  // the package's own CSS styles the block; where the host's Bootstrap is
  // loaded (dashboard in layout mode) it owns the look and the package skips
  // injecting its block CSS — no specificity fight. `.cb-card` is the chrome
  // (border/radius/bg), `.cb-card-body` the content padding box.
  wrapper.className = 'block block-card cb-card card';
  const body = document.createElement('div');
  body.className = 'cb-card-body card-body';

  if (title !== '') {
    const h = document.createElement('h3');
    h.className = 'cb-card-title card-title';
    h.textContent = title;
    body.appendChild(h);
  }

  if (subtitle !== '') {
    const s = document.createElement('div');
    s.className = 'cb-card-subtitle card-subtitle';
    s.textContent = subtitle;
    body.appendChild(s);
  }

  if (description !== '') {
    const p = document.createElement('p');
    p.className = 'cb-card-description card-text';
    // Markdown so the LLM can include `**bold**`, `code`, links inside the
    // card description. The renderer escapes HTML; safe by construction.
    p.innerHTML = renderMarkdown(description);
    body.appendChild(p);
  }

  if (Array.isArray(fields) && fields.length > 0) {
    const dl = document.createElement('dl');
    dl.className = 'cb-card-fields';
    for (const entry of fields) {
      if (!entry || typeof entry !== 'object') continue;
      const f = entry as Record<string, unknown>;
      const label = asString(f['label']);
      if (label === '') continue;
      const dt = document.createElement('dt');
      dt.textContent = label;
      const dd = document.createElement('dd');
      const value = f['value'];
      dd.textContent = value === null || value === undefined ? '' : String(value);
      dl.append(dt, dd);
    }
    if (dl.children.length > 0) body.appendChild(dl);
  }

  if (Array.isArray(actions) && actions.length > 0) {
    const actionsEl = renderActionsBlock({ actions }, host);
    body.appendChild(actionsEl);
  }

  wrapper.appendChild(body);
  return wrapper;
}

interface TableColumn {
  key: string;
  label: string;
}

function normalizeColumns(raw: unknown): TableColumn[] {
  if (!Array.isArray(raw)) return [];
  const out: TableColumn[] = [];
  for (const entry of raw) {
    if (typeof entry === 'string' && entry !== '') {
      out.push({ key: entry, label: entry });
      continue;
    }
    if (!entry || typeof entry !== 'object') continue;
    const e = entry as Record<string, unknown>;
    const key = asString(e['key']);
    if (key === '') continue;
    const label = asString(e['label'], key);
    out.push({ key, label });
  }
  return out;
}

function cellText(value: unknown): string {
  if (value === null || value === undefined) return '';
  if (typeof value === 'string') return value;
  if (typeof value === 'number' || typeof value === 'boolean') return String(value);
  try {
    return JSON.stringify(value);
  } catch {
    return '';
  }
}

export function renderTableBlock(data: Record<string, unknown>, _host: BlockHost): HTMLElement {
  const wrapper = document.createElement('div');
  // v2.1 (E14 / #19) — Bootstrap classes ride alongside the `.cb-*` ones; see
  // renderCardBlock for the rationale (host-native theming in layout mode,
  // package CSS fallback everywhere else, no specificity fight).
  wrapper.className = 'block block-table cb-table-wrapper table-responsive';

  const caption = asString(data['caption']);
  const columns = normalizeColumns(data['columns']);
  // v1.1 (findings #5): `rows` is the documented required key, but the LLM
  // often emits `items`, `data`, or `records`. Accept aliases (with a warn)
  // so the block renders something useful instead of a silent empty table.
  const rowsRaw = pickArrayAlias(data, 'rows', ['items', 'data', 'records'], 'table');

  // Auto-derive columns from the first row when none were declared. This is
  // the LLM-friendly path: the model can emit `rows: [{id, name}, ...]` and
  // get a sane header without restating the schema.
  let cols = columns;
  if (cols.length === 0 && rowsRaw.length > 0) {
    const first = rowsRaw[0];
    if (first && typeof first === 'object' && !Array.isArray(first)) {
      cols = Object.keys(first as Record<string, unknown>).map((key) => ({ key, label: key }));
    }
  }

  const table = document.createElement('table');
  table.className = 'cb-table table table-sm table-striped table-hover';

  if (caption !== '') {
    const cap = document.createElement('caption');
    cap.textContent = caption;
    table.appendChild(cap);
  }

  if (cols.length > 0) {
    const thead = document.createElement('thead');
    const trh = document.createElement('tr');
    for (const col of cols) {
      const th = document.createElement('th');
      th.scope = 'col';
      th.textContent = col.label;
      trh.appendChild(th);
    }
    thead.appendChild(trh);
    table.appendChild(thead);
  }

  const tbody = document.createElement('tbody');
  for (const row of rowsRaw) {
    const tr = document.createElement('tr');
    if (cols.length === 0) {
      // No columns and no first-row schema (e.g. rows are scalars/arrays).
      const td = document.createElement('td');
      td.textContent = cellText(row);
      tr.appendChild(td);
    } else if (Array.isArray(row)) {
      for (let i = 0; i < cols.length; i++) {
        const td = document.createElement('td');
        td.textContent = cellText(row[i]);
        tr.appendChild(td);
      }
    } else if (row && typeof row === 'object') {
      const obj = row as Record<string, unknown>;
      for (const col of cols) {
        const td = document.createElement('td');
        td.textContent = cellText(obj[col.key]);
        tr.appendChild(td);
      }
    } else {
      const td = document.createElement('td');
      td.colSpan = Math.max(1, cols.length);
      td.textContent = cellText(row);
      tr.appendChild(td);
    }
    tbody.appendChild(tr);
  }
  table.appendChild(tbody);

  if (rowsRaw.length === 0) {
    const empty = document.createElement('div');
    empty.className = 'cb-table-empty';
    empty.textContent = asString(data['empty_text'], 'No rows.');
    wrapper.appendChild(empty);
  }

  wrapper.appendChild(table);
  return wrapper;
}

interface ListItem {
  text: string;
  prompt?: string;
  tool?: string;
  args?: Record<string, unknown>;
}

function normalizeListItems(raw: unknown): ListItem[] {
  if (!Array.isArray(raw)) return [];
  const out: ListItem[] = [];
  for (const entry of raw) {
    if (typeof entry === 'string') {
      if (entry !== '') out.push({ text: entry });
      continue;
    }
    if (!entry || typeof entry !== 'object') continue;
    const e = entry as Record<string, unknown>;
    // Accept either `text` or `label` for the visible label — both are
    // common in LLM output and we don't want to fight the model.
    const text = asString(e['text']) || asString(e['label']);
    if (text === '') continue;
    const item: ListItem = { text };
    const prompt = asString(e['prompt']);
    if (prompt !== '') item.prompt = prompt;
    const tool = asString(e['tool']);
    if (tool !== '') item.tool = tool;
    if (e['args'] && typeof e['args'] === 'object' && !Array.isArray(e['args'])) {
      item.args = e['args'] as Record<string, unknown>;
    }
    out.push(item);
  }
  return out;
}

export function renderListBlock(data: Record<string, unknown>, host: BlockHost): HTMLElement {
  const wrapper = document.createElement('div');
  wrapper.className = 'block block-list cb-list';

  const title = asString(data['title']);
  if (title !== '') {
    const h = document.createElement('h3');
    h.className = 'cb-list-title';
    h.textContent = title;
    wrapper.appendChild(h);
  }

  const ordered = data['ordered'] === true;
  const list = document.createElement(ordered ? 'ol' : 'ul');
  // v2.1 (E14 / #19) — `list-group list-group-flush` rides alongside
  // `cb-list-items`; see renderCardBlock for the rationale.
  list.className = 'cb-list-items list-group list-group-flush';

  const items = normalizeListItems(data['items']);
  for (const item of items) {
    const li = document.createElement('li');
    li.className = 'cb-list-item list-group-item';
    if (item.prompt || item.tool) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'cb-list-item-action';
      btn.textContent = item.text;
      btn.addEventListener('click', () => {
        if (item.prompt) {
          host.send(item.prompt);
          return;
        }
        if (item.tool) {
          const handler = window.Chatbot?.__internal.getTool(item.tool);
          if (handler) {
            void handler(item.args ?? {}, { actionId: `list-${Date.now()}`, confirmation: 'auto' });
          }
        }
      });
      li.appendChild(btn);
    } else {
      li.textContent = item.text;
    }
    list.appendChild(li);
  }
  wrapper.appendChild(list);
  return wrapper;
}

// Chart placeholder + i18n live in their own module so `blocks.ts` can import
// the Chart.js renderer below without a `blocks → chart-default → blocks`
// cycle. Re-exported here for back-compat: existing call sites (and tests) can
// keep importing `renderChartBlock` / `setChartLabels` from `./blocks.js`.
export { renderChartBlock, setChartLabels, resetChartLabels } from './chart-placeholder.js';
export type { ChartLabels } from './chart-placeholder.js';

export const BUILTIN_BLOCK_RENDERERS: Record<string, BlockRenderer> = {
  text: renderTextBlock,
  actions: renderActionsBlock,
  card: renderCardBlock,
  table: renderTableBlock,
  list: renderListBlock,
  // Since v0.4.4 the built-in chart renderer is the real Chart.js one, so
  // charts render identically in the widget, the /chatbot page and the
  // dashboard. The placeholder (chart-placeholder.ts) is reached only for
  // invalid data or when a host override throws. Hosts can still swap in
  // another library via registerBlockRenderer('chart', fn).
  chart: renderChartBlockChartjs,
  kpi: renderKpiBlock,
};

export function renderBlock(payload: BlockPayload, host: BlockHost): HTMLElement {
  // Cascade (E15 ROADMAP §5/E15):
  //   1. Host-registered JS renderer — wins outright.
  //   2. Host-declared <template data-chatbot-block-template="<type>"> — clone + bind.
  //   3. Built-in renderer for the type.
  //   4. Muted placeholder so unknown types don't crash the thread.
  let customError: unknown = null;
  const custom = window.Chatbot?.__internal.getBlockRenderer(payload.type);
  if (custom) {
    try {
      return custom(payload.data, host);
    } catch (err) {
      // A misbehaving host renderer should not poison the thread — fall back
      // to the cascade so the user still sees *something*. v1.1 (findings #6):
      // capture the error so the built-in fallback can distinguish "no host
      // renderer registered" from "host renderer threw".
      customError = err;
      console.error(`[chatbot] block renderer for "${payload.type}" threw`, err);
    }
  }

  const template = findTemplate(payload.type);
  if (template) {
    try {
      const node = cloneAndBind(template, payload.data);
      if (!node.classList.contains(`block-${payload.type}`)) {
        node.classList.add('block', `block-${payload.type}`);
      }
      return node;
    } catch (err) {
      console.error(`[chatbot] template binding for "${payload.type}" failed`, err);
    }
  }

  const builtin = BUILTIN_BLOCK_RENDERERS[payload.type];
  if (builtin) return builtin(payload.data, host, customError !== null ? { customError } : undefined);

  const fallback = document.createElement('div');
  fallback.className = 'block block-unknown';
  fallback.textContent = `[unsupported block: ${payload.type}]`;
  return fallback;
}
