import type { BlockPayload, BlockSource } from './types.js';

/**
 * v2.0 (E1) — copia los metadatos opcionales `id`, `source`, `pinnable` y
 * `block_ordinal` (v2.1.2, #27) que el orquestador SSE backend estampa en
 * los frames `block` (y en los args de `frontend_action` con
 * `tool=render_block`) hacia el `BlockPayload` que el widget guarda en
 * `ChatMessage.blocks[]`.
 *
 * Se extrae a un módulo propio porque la lógica se aplica en tres sitios
 * (live `block` frame, intercepción de `render_block`, hidratación desde
 * sessionStorage) y porque queremos testearla aislada de la state machine
 * del custom element en `widget.ts` — que es demasiado pesada para un
 * unit test simple.
 *
 * Diseño: muta el target en lugar de devolver un objeto nuevo para que los
 * call sites mantengan literales `{ type, data }` claros y sólo se añadan
 * los campos cuando estén presentes (back-compat: blocks v1 no llevan
 * ninguno, así que el target queda exactamente igual).
 *
 * Inputs malformados (id no-string, source sin tool, args no-object) se
 * ignoran silenciosamente — coincide con el contrato general del widget
 * de "frames raros no rompen el render, sólo se degradan".
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

  // v2.1.2 (#27) — ordinal 0-based del bloque entre los de su tipo. Sólo
  // se acepta un entero >= 0; cualquier otra cosa se ignora (back-compat:
  // blocks v2.1.x antiguos no lo llevan → el replay cae a 0).
  const blockOrdinal = raw['block_ordinal'];
  if (typeof blockOrdinal === 'number' && Number.isInteger(blockOrdinal) && blockOrdinal >= 0) {
    target.blockOrdinal = blockOrdinal;
  }

  // v2.2.1 (PR-B) — bag de metadata fuera de banda. Lo conserva el persist a
  // sessionStorage (hidratación de history reabsorbe valores sin reactivar
  // efectos UX) y lo lee la pipeline `block` del widget para emitir hooks
  // hacia el resto de bundles (ver `chatbot:dashboard-mutation` en widget.ts).
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
