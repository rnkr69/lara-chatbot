/**
 * v2.0 / E9 — PHP → JS bridge for the package keys the bundle renders.
 *
 * Pattern: the package's blade (or the host) emits `data-i18n='{json}'` on the
 * root element of each bundle:
 *
 *   - widget: `<chatbot-widget data-i18n="…">`
 *   - dashboard: `<div id="chatbot-dashboard-root" data-i18n="…">`
 *
 * The JSON is the literal translation of `__('chatbot::chatbot')` — a flat
 * object with snake_case keys + a `dashboard.*` subtree with sub-sections
 * (`sidebar`, `card`, `header`, `pin`, `chart`, `kpi`). The bridge limits
 * itself to a parse + shallow sanitization; each bundle applies the
 * snake_case → internal shape mapping in its own place (some existing TS
 * interfaces use camelCase, others snake_case — see E5/E6/E7/E8). We keep the
 * inline defaults in TS as a fallback when a key is missing or the attribute
 * doesn't exist.
 *
 * If the attribute is absent or the JSON doesn't parse, we return `{}` and the
 * bundle keeps working with the inline defaults (zero regression for hosts
 * that don't add `data-i18n`).
 */

/** Expected shape of the JSON injected by PHP. All keys are optional. */
export interface ChatbotI18n {
  // v1 keys consumed by widget/sidebar
  title?: string;
  open_full_page?: string;
  new_conversation?: string;
  new_conversation_aria?: string;
  loading_conversation?: string;
  failed_to_load_conversation?: string;
  untitled_conversation?: string;
  page_title?: string;
  dashboard_title?: string;
  // v2 dashboard subtree
  dashboard?: ChatbotDashboardI18n;
}

export interface ChatbotDashboardI18n {
  sidebar?: Partial<Record<
    'new_cta' | 'new_placeholder' | 'create' | 'rename' | 'delete'
    | 'set_default' | 'default_badge' | 'empty_title' | 'empty_hint'
    | 'error' | 'confirm_delete', string
  >>;
  card?: Partial<Record<
    'refresh' | 'remove' | 'view_source' | 'unauthorized' | 'error'
    | 'stale' | 'source_missing' | 'no_title' | 'refreshing' | 'just_now'
    | 'inert_actions_hint', string
  >>;
  header?: Partial<Record<'refresh_all' | 'empty_main' | 'empty_main_hint', string>>;
  pin?: Partial<Record<
    'cta' | 'tooltip' | 'modal_title' | 'modal_select_label'
    | 'modal_create_inline' | 'modal_create_name' | 'modal_title_label'
    | 'modal_title_placeholder' | 'submit' | 'cancel'
    | 'toast_added' | 'toast_view'
    | 'error_dashboard_full' | 'error_tool_unpinnable'
    | 'error_tool_missing' | 'error_generic', string
  >>;
  chart?: Partial<Record<'invalid_data' | 'empty_dataset', string>>;
  kpi?: Partial<Record<'no_value', string>>;
}

/**
 * Reads `data-i18n` from the element, JSON.parses it, and returns the
 * resulting object cast through `ChatbotI18n`. Returns `{}` when:
 *   - the element is null/undefined,
 *   - the attribute is missing or empty,
 *   - JSON.parse throws,
 *   - the parsed value is not a plain object (array, primitive, null).
 *
 * On parse failure, logs a single `console.warn` with the offending payload
 * truncated so consumers can diagnose without flooding the console.
 */
export function parseI18nFromElement(el: Element | null | undefined): ChatbotI18n {
  if (!el || typeof (el as HTMLElement).getAttribute !== 'function') return {};
  const raw = (el as HTMLElement).getAttribute('data-i18n');
  if (raw === null || raw === '') return {};
  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch (err) {
    const preview = raw.length > 80 ? `${raw.slice(0, 80)}…` : raw;
    console.warn(`[chatbot:i18n] failed to parse data-i18n: ${preview}`, err);
    return {};
  }
  if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
    return {};
  }
  return parsed as ChatbotI18n;
}

/**
 * Picks `obj[key]` only when it's a non-empty string. Otherwise returns the
 * provided default. Used at wiring sites to drain the loose i18n payload
 * into the strict label interfaces of each module.
 */
export function pickString(
  obj: Record<string, unknown> | undefined,
  key: string,
  fallback: string,
): string {
  if (!obj) return fallback;
  const v = obj[key];
  return typeof v === 'string' && v !== '' ? v : fallback;
}

/**
 * Returns `obj[key]` when it's a plain object, otherwise an empty object.
 * Used to traverse nested subtrees safely (e.g. `i18n.dashboard.sidebar`).
 */
export function pickObject(
  obj: Record<string, unknown> | undefined,
  key: string,
): Record<string, unknown> {
  if (!obj) return {};
  const v = obj[key];
  if (v === null || typeof v !== 'object' || Array.isArray(v)) return {};
  return v as Record<string, unknown>;
}
