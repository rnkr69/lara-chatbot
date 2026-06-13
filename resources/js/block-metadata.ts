import type { BlockPayload, BlockSource } from './types.js';

/**
 * v2.0 (E1) — copies the optional metadata `id`, `source`, `pinnable` and
 * `block_ordinal` (v2.1.2, #27) that the backend SSE orchestrator stamps on
 * the `block` frames (and on the args of `frontend_action` with
 * `tool=render_block`) into the `BlockPayload` the widget stores in
 * `ChatMessage.blocks[]`.
 *
 * Extracted into its own module because the logic is applied in three places
 * (live `block` frame, `render_block` interception, hydration from
 * sessionStorage) and because we want to test it in isolation from the custom
 * element's state machine in `widget.ts` — which is too heavy for a simple
 * unit test.
 *
 * Design: mutates the target instead of returning a new object so the call
 * sites keep clear `{ type, data }` literals and only add the fields when
 * they are present (back-compat: v1 blocks carry none, so the target stays
 * exactly the same).
 *
 * Malformed inputs (non-string id, source without tool, non-object args) are
 * ignored silently — matching the widget's general contract of "odd frames
 * don't break the render, they just degrade".
 */
export function readV2BlockMetadata(
  raw: Record<string, unknown>,
  target: BlockPayload,
): void {
  const id = raw['id'];
  if (typeof id === 'string' && id !== '') {
    target.id = id;
  }

  const pinnable = raw['pinnable'];
  if (pinnable === true) {
    target.pinnable = true;
  }

  // v2.1.2 (#27) — 0-based ordinal of the block among those of its type. Only
  // an integer >= 0 is accepted; anything else is ignored (back-compat: old
  // v2.1.x blocks don't carry it → the replay falls back to 0).
  const blockOrdinal = raw['block_ordinal'];
  if (typeof blockOrdinal === 'number' && Number.isInteger(blockOrdinal) && blockOrdinal >= 0) {
    target.blockOrdinal = blockOrdinal;
  }

  // v2.2.1 (PR-B) — out-of-band metadata bag. The persist to sessionStorage
  // keeps it (history hydration re-absorbs values without re-triggering UX
  // effects) and the widget's `block` pipeline reads it to emit hooks to the
  // rest of the bundles (see `chatbot:dashboard-mutation` in widget.ts).
  const meta = raw['meta'];
  if (meta && typeof meta === 'object' && !Array.isArray(meta)) {
    target.meta = meta as Record<string, unknown>;
  }

  const source = raw['source'];
  if (!source || typeof source !== 'object' || Array.isArray(source)) {
    return;
  }
  const s = source as Record<string, unknown>;
  const tool = s['tool'];
  const args = s['args'];
  if (typeof tool !== 'string' || tool === '') return;
  if (args === undefined || args === null || typeof args !== 'object') return;
  // PHP's json_encode([]) emits `[]`, never `{}` — a pinnable tool invoked
  // with no args (KPIs, unfiltered listings) serializes its empty arg array
  // as `[]`. Normalize that one degenerate case to an empty map. A non-empty
  // array is still genuinely malformed: named tool args always serialize as
  // an object, never a JSON list.
  let argsObj: Record<string, unknown>;
  if (Array.isArray(args)) {
    if (args.length > 0) return;
    argsObj = {};
  } else {
    argsObj = args as Record<string, unknown>;
  }

  const out: BlockSource = {
    tool,
    args: argsObj,
  };
  const keys = s['page_context_keys'];
  if (Array.isArray(keys)) {
    const filtered = keys.filter((k): k is string => typeof k === 'string');
    if (filtered.length > 0) {
      out.page_context_keys = filtered;
    }
  }
  target.source = out;
}
