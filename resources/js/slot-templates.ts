/**
 * Host-side HTML template support for typed blocks (E15).
 *
 * The host can place a `<template data-chatbot-block-template="card">` somewhere
 * in the document; when a block of that type arrives the widget clones the
 * template and walks every node carrying `data-bind="path"`, populating
 * `textContent` from the block's `data` payload using a tiny dot-path lookup.
 *
 * Cascade order in `renderBlock` (resources/js/blocks.ts):
 *   1. `window.Chatbot.registerBlockRenderer(type, fn)` — JS renderer wins.
 *   2. `<template data-chatbot-block-template="<type>">` — declarative HTML.
 *   3. The package's builtin renderer for the type, if any.
 *   4. Fallback placeholder.
 *
 * Templates are scanned each render so hosts can mount/unmount them dynamically
 * (Inertia/Livewire SPA navigations rewriting the DOM still work).
 */

const TEMPLATE_ATTR = 'data-chatbot-block-template';
const BIND_ATTR = 'data-bind';

/**
 * Look up a `<template data-chatbot-block-template="<type>">` declared by the
 * host in the *light* DOM. We do not search inside the widget's shadow root —
 * templates belong to the host page so the host can control them via Blade.
 */
export function findTemplate(type: string): HTMLTemplateElement | null {
  if (typeof document === 'undefined') return null;
  if (typeof type !== 'string' || type === '') return null;
  // Use attribute selector with CSS.escape on the type to defend against
  // unusual characters (still server-controlled, but cheap insurance).
  let escaped: string;
  try {
    escaped = typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
      ? CSS.escape(type)
      : type.replace(/"/g, '\\"');
  } catch {
    escaped = type.replace(/"/g, '\\"');
  }
  const selector = `template[${TEMPLATE_ATTR}="${escaped}"]`;
  let node: Element | null;
  try {
    node = document.querySelector(selector);
  } catch {
    return null;
  }
  return node instanceof HTMLTemplateElement ? node : null;
}

/**
 * Resolve a dot-path against an arbitrary value. Returns `undefined` if any
 * intermediate node is missing or not traversable. Numeric-looking segments
 * index into arrays.
 */
export function getPath(source: unknown, path: string): unknown {
  if (path === '') return source;
  const segments = path.split('.');
  let current: unknown = source;
  for (const segment of segments) {
    if (current === null || current === undefined) return undefined;
    if (Array.isArray(current)) {
      const idx = Number(segment);
      if (!Number.isInteger(idx) || idx < 0) return undefined;
      current = current[idx];
      continue;
    }
    if (typeof current !== 'object') return undefined;
    current = (current as Record<string, unknown>)[segment];
  }
  return current;
}

function stringifyValue(value: unknown): string {
  if (value === null || value === undefined) return '';
  if (typeof value === 'string') return value;
  if (typeof value === 'number' || typeof value === 'boolean') return String(value);
  // Objects/arrays: render as compact JSON so the host sees *something* rather
  // than `[object Object]` if they bound a structured node by mistake.
  try {
    return JSON.stringify(value);
  } catch {
    return '';
  }
}

/**
 * Clone the template's content fragment and bind every `[data-bind="path"]`
 * descendant to the matching value from `data`. Returns the cloned root
 * element (or a wrapper div if the template content has multiple top-level
 * nodes).
 */
export function cloneAndBind(template: HTMLTemplateElement, data: Record<string, unknown>): HTMLElement {
  const fragment = template.content.cloneNode(true) as DocumentFragment;
  const bound = fragment.querySelectorAll(`[${BIND_ATTR}]`);
  bound.forEach((node) => {
    if (!(node instanceof HTMLElement)) return;
    const path = node.getAttribute(BIND_ATTR) ?? '';
    const value = getPath(data, path);
    node.textContent = stringifyValue(value);
  });

  const children = Array.from(fragment.children);
  if (children.length === 1 && children[0] instanceof HTMLElement) {
    return children[0];
  }
  // Multiple top-level nodes: wrap in a div so callers always get one element.
  const wrapper = document.createElement('div');
  wrapper.appendChild(fragment);
  return wrapper;
}
