import type { PageContext } from './types.js';

/**
 * Page Context API runtime helpers (E14 ROADMAP §5/E14).
 *
 * The widget exposes two ways for the host to declare the current page
 * context:
 *
 *   1. Declarative — `<meta name="chatbot:context" content='{"route":"orders.index"}'>`.
 *      The widget reads it on bootstrap (in MPA) and on every detected SPA
 *      navigation.
 *   2. Imperative — `window.Chatbot.setPageContext({ route: 'orders.index' })`.
 *      Merges into the current context one level deep (top-level keys
 *      overwrite; when both the previous and incoming values at a key are
 *      plain objects, their sub-keys are merged) and emits the
 *      `chatbot:context-changed` window event. Arrays and primitives replace
 *      wholesale. The one-level merge was introduced in v1.1.8 (#34) so that
 *      partial sync calls like `setPageContext({ crud: { selected_ids: [] }})`
 *      stop blowing away server-emitted `crud.form` / `crud.filters` /
 *      `crud.entity`. See `api.ts:setPageContext`.
 *
 * Listeners (e.g. host integrations like Backpack) can subscribe with:
 *   `window.addEventListener('chatbot:context-changed', (e) => e.detail)`.
 *
 * E14 D14: the event is emitted in BOTH paths — every setPageContext()/
 * clearPageContext() call AND every SPA navigation that re-reads the meta
 * tag. Hosts get a single signal regardless of how the context changed.
 */

export const META_NAME = 'chatbot:context';
export const META_FORM_NAME = 'chatbot:context-form';
export const CONTEXT_CHANGED_EVENT = 'chatbot:context-changed';

/**
 * Reads the `<meta name="chatbot:context">` tag, if present, and parses its
 * content. JSON-looking content is parsed; anything else (or invalid JSON)
 * yields `{}` so the caller can rely on a stable shape.
 *
 * v1.1.1 (finding #13.a): ALSO merges the first
 * `<meta name="chatbot:context-form">` tag emitted by the `@chatbotForm`
 * Blade directive into `context.form`. Multiple form metas in the same
 * page are not supported declaratively — hosts that need to expose
 * several forms should disambiguate via `setPageContext`.
 */
export function readMetaContext(doc: Document = document): PageContext {
  const base: PageContext = {};

  if (typeof doc.querySelector !== 'function') return base;

  const tag = doc.querySelector(`meta[name="${META_NAME}"]`);
  if (tag) {
    const raw = tag.getAttribute('content');
    if (raw) {
      const trimmed = raw.trim();
      if (trimmed !== '' && (trimmed[0] === '{' || trimmed[0] === '[')) {
        try {
          const parsed = JSON.parse(trimmed);
          if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
            Object.assign(base, parsed as PageContext);
          }
        } catch { /* ignore bad JSON: surfacing to console is the host's job */ }
      }
    }
  }

  // v1.1.1: merge form schema emitted by @chatbotForm directive. Only the
  // first one wins under `form` — multiple forms on the same page should
  // declare via setPageContext to disambiguate.
  if (typeof doc.querySelectorAll === 'function' && (base as Record<string, unknown>)['form'] === undefined) {
    const formTags = doc.querySelectorAll(`meta[name="${META_FORM_NAME}"]`);
    if (formTags.length > 0) {
      const firstRaw = formTags[0]?.getAttribute('content');
      if (firstRaw) {
        try {
          const parsed = JSON.parse(firstRaw);
          if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
            const obj = parsed as Record<string, unknown>;
            if (obj['form'] !== undefined) {
              (base as Record<string, unknown>)['form'] = obj['form'];
            }
          }
        } catch { /* ignore */ }
      }
    }
  }

  return base;
}

/**
 * Dispatches `chatbot:context-changed` on `window` with the *effective*
 * context as `event.detail`. No-op when window is unavailable (e.g. SSR
 * pre-render passes that load this module).
 */
export function emitContextChanged(detail: PageContext, target: Window = window): void {
  if (typeof target === 'undefined' || typeof target.dispatchEvent !== 'function') return;
  target.dispatchEvent(new CustomEvent(CONTEXT_CHANGED_EVENT, { detail }));
}
