/**
 * v2.0 / E6 — pin button overlay for chat blocks.
 *
 * Mounts a 📌 button on top of any rendered block that came stamped by the
 * SSE orchestrator (E1) with `pinnable === true` and a `source` descriptor.
 * Visible on hover OR keyboard focus; hidden otherwise. Click invokes the
 * `onPin` callback (the widget wires it to open `pin-modal`).
 *
 * Design notes:
 *
 *   - Wrapper-in-the-caller (NOT inside `renderBlock()`): keeps the cascade
 *     of `blocks.ts` (host > template > builtin) untouched. Hosts that
 *     register custom renderers continue receiving a clean nodeful element.
 *
 *   - Stateless: every render of the assistant message can re-mount this
 *     wrapper from scratch (`refreshAssistantNode` re-runs on text deltas).
 *     There is no internal state that would be lost. The modal lives
 *     elsewhere (shadow root) and survives re-mounts of the button.
 *
 *   - Defensive: if the block is not pinnable or has no source, returns
 *     the rendered element AS-IS — no wrapper, no DOM diff, no CSS class.
 *     This guarantees zero regression on v1.x blocks and on blocks emitted
 *     by tools that didn't opt into `pinnable()`.
 *
 *   - The CSS lives in the widget's SHADOW_CSS (resources/js/styles.ts),
 *     not in the dashboard bundle's styles.ts — the wrapper is rendered
 *     inside the widget's shadow root, not on the dashboard page.
 */

import type { BlockPayload } from '../types.js';

export interface PinButtonLabels {
  cta: string;
  tooltip: string;
}

const DEFAULT_LABELS: PinButtonLabels = {
  cta: 'Pin to dashboard',
  tooltip: 'Pin this block to a dashboard',
};

export interface PinButtonOptions {
  /** Block payload as stamped by the SSE orchestrator. */
  block: BlockPayload;
  /** The HTMLElement produced by `renderBlock()`. */
  rendered: HTMLElement;
  /** Override defaults for i18n. */
  labels?: Partial<PinButtonLabels>;
  /** Invoked on click. The widget opens the modal. */
  onPin(block: BlockPayload, rendered: HTMLElement): void;
}

/**
 * Returns the rendered block, wrapped in a `.cb-pin-wrapper` with a
 * `.cb-pin-button` overlay if (and only if) the block is pinnable and has
 * a source descriptor. Otherwise returns `rendered` unchanged.
 */
export function wrapWithPinButton(opts: PinButtonOptions): HTMLElement {
  const { block, rendered } = opts;

  if (block.pinnable !== true) return rendered;
  if (!block.source || typeof block.source.tool !== 'string' || block.source.tool === '') {
    return rendered;
  }

  const labels = { ...DEFAULT_LABELS, ...(opts.labels ?? {}) };

  const wrapper = document.createElement('div');
  wrapper.className = 'cb-pin-wrapper';
  if (typeof block.id === 'string' && block.id !== '') {
    wrapper.dataset['blockId'] = block.id;
  }
  wrapper.appendChild(rendered);

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'cb-pin-button';
  btn.setAttribute('aria-label', labels.cta);
  btn.title = labels.tooltip;
  btn.textContent = '📌';
  btn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    opts.onPin(block, rendered);
  });
  wrapper.appendChild(btn);

  return wrapper;
}
