export type WidgetState = 'closed' | 'minimized' | 'open' | 'fullscreen';

/**
 * E17 — modo del Web Component:
 *   - 'widget' (default): comportamiento anterior, FAB flotante con state machine.
 *   - 'page'  : layout fullscreen + sidebar de conversaciones; siempre "open".
 */
export type WidgetMode = 'widget' | 'page';

export type SseEventName =
  | 'text'
  | 'block'
  | 'tool_call'
  | 'tool_result'
  | 'frontend_action'
  | 'error'
  | 'done';

export interface SseFrame {
  event: SseEventName;
  data: Record<string, unknown>;
}

export type ConfirmationLevel = 'auto' | 'confirm' | 'manual';

export interface FrontendActionPayload {
  tool: string;
  args: Record<string, unknown>;
  action_id: string;
  confirmation: ConfirmationLevel;
}

export interface DonePayload {
  message_id: number | string | null;
  usage: Record<string, unknown>;
}

/**
 * v2.0 — el shape de un bloque tipado tal y como viaja por SSE y como
 * lo persiste el widget en `ChatMessage.blocks[]`.
 *
 * Los campos `id`, `source` y `pinnable` se añaden en v2.0 como parte del
 * cimiento (E1) del personal dashboard:
 *
 *   - `id` — UUID estampado por el orquestador SSE en backend cuando emite
 *     el frame. Estable a lo largo del turno; el cliente lo usa como
 *     handle (pin button, scroll-to, etc.). Si el block llega sin id
 *     (renderer host antiguo, RenderBlockTool LLM-driven), se queda
 *     undefined y el cliente lo trata como anónimo.
 *
 *   - `source` — descriptor de qué tool produjo el bloque y con qué args,
 *     incluyendo las claves del page context activas en ese momento. Es
 *     la pieza que permite "replay" del bloque desde el dashboard
 *     (E3). Solo presente para blocks emitidos por backend tools que
 *     declaran `pinnable() === true`; nunca lo declara el tool author —
 *     siempre lo estampa el orquestador.
 *
 *   - `pinnable` — el botón 📌 sólo aparece en el chat si esto es `true`.
 *     Sólo es `true` cuando el tool declara `pinnable()` *y* su
 *     `confirmation === Auto` (enforcement aguas arriba en backend; el
 *     cliente lo trata como flag opaco).
 *
 * Back-compat: los tres campos son opcionales. Renderers v1.x que sólo
 * leen `type` + `data` siguen funcionando sin cambios; persistencia en
 * `sessionStorage` con el shape antiguo se hidrata sin warnings.
 */
export interface BlockSource {
  /** Nombre canónico de la tool (snake_case, registry id). */
  tool: string;
  /** Args con los que la tool fue invocada en este turno. */
  args: Record<string, unknown>;
  /** Top-level keys del page context activas al ejecutar la tool. Sólo
   *  los nombres — el snapshot del valor se captura más tarde al pinear. */
  page_context_keys?: string[];
}

export interface BlockPayload {
  type: string;
  data: Record<string, unknown>;
  /** UUID estable por bloque, estampado por el orquestador SSE. */
  id?: string;
  /** Descriptor para replay desde el dashboard. */
  source?: BlockSource;
  /** Si `true`, el botón 📌 puede aparecer en hover sobre este block. */
  pinnable?: boolean;
  /**
   * v2.1.2 (#27) — posición 0-based del bloque entre los de su mismo `type`
   * en el `ToolResult` que lo emitió (el N-ésimo `kpi`, el N-ésimo `chart`…).
   * Viaja al pinear como `source.block_ordinal`; el replay re-localiza ESTE
   * bloque con ese descriptor en tools multi-bloque, en vez de coger
   * `blocks[0]`. Ausente en blocks v2.1.x antiguos → el replay cae a 0.
   */
  blockOrdinal?: number;
  /**
   * v2.2.1 (PR-B) — bag opcional con metadata fuera de banda. El backend
   * lo estampa en `ToolResult::blocks[*]['meta']`; el orquestador lo propaga
   * verbatim como `meta` en el frame `block`. Las claves son convenciones
   * cliente↔servidor; consumers que no conocen una clave la ignoran sin
   * error. Carriles canónicos actuales:
   *   - `meta.side_effects` (objeto `{type, ...}`): las 5 tools del dashboard
   *     conversacional la usan para que el bundle del dashboard refresque la
   *     UI sin F5 (`chatbot:dashboard-mutation` CustomEvent).
   */
  meta?: Record<string, unknown>;
}

export interface ChatMessage {
  id: string;
  role: 'user' | 'assistant';
  text: string;
  blocks: BlockPayload[];
  pending: boolean;
  /**
   * v2.1 (#3) — set when the stream emits an `event: error` frame. Without
   * it the assistant message rendered completely empty (no text, no blocks,
   * no feedback). `fillAssistantNode` renders it as a `.cb-block-error`.
   */
  error?: string;
}

export type ToolHandler = (args: Record<string, unknown>, ctx: ToolContext) => void | Promise<void>;
/**
 * Block renderer signature.
 *
 *   - `data` — payload from the SSE `block` / `frontend_action` frame.
 *   - `host` — small object exposing utilities (`host.send(prompt)` to enqueue
 *              a follow-up user message). It is **not** the DOM container —
 *              the renderer must RETURN the HTMLElement and the widget
 *              appends it.
 *   - `meta` (optional, v1.1) — runtime metadata. The cascade in
 *              `renderBlock()` populates `meta.customError` when a previously
 *              registered host renderer threw, so the built-in fallback can
 *              distinguish "no host renderer registered" from "host renderer
 *              threw" (findings #6).
 */
export interface BlockRendererMeta {
  /** Set when a previously registered host renderer threw and we fell back
   *  to the built-in renderer for the same type. */
  customError?: unknown;
  /** v2.1.1 (#25) — set when a *registered* renderer fell back to the
   *  built-in placeholder because the block data failed normalization (not
   *  because no renderer exists). Lets the chart placeholder say "invalid
   *  data" instead of the false "renderer not registered". */
  invalidData?: boolean;
}
export type BlockRenderer = (
  data: Record<string, unknown>,
  host: BlockHost,
  meta?: BlockRendererMeta,
) => HTMLElement;
export type NavigatorFn = (url: string, options?: { replace?: boolean }) => void;

export interface ToolContext {
  actionId: string;
  confirmation: ConfirmationLevel;
}

export interface BlockHost {
  send(prompt: string): void;
}

export interface PageContext {
  [key: string]: unknown;
}

export interface ChatbotApi {
  open(): void;
  close(): void;
  toggle(): void;
  setPageContext(ctx: PageContext): void;
  clearPageContext(): void;
  registerTool(name: string, handler: ToolHandler): void;
  registerBlockRenderer(type: string, renderer: BlockRenderer): void;
  registerNavigator(fn: NavigatorFn): void;
  setUser(token: string | null): void;
  /** Start a fresh conversation: aborts any in-flight stream, drops the
   *  active id and visible history, and refocuses the input. */
  newChat(): void;
  /**
   * v1.1 — invoke `cb` once the chatbot bundle is fully initialized. Solves
   * the script-order race in defer-loaded setups (`getting-started.md`):
   * if the host listener for the `chatbot:ready` event registers AFTER the
   * event has already fired, it would never run. `whenReady` does the
   * double-check internally:
   *
   *   - If the bundle is already initialized when called: invokes `cb`
   *     synchronously on the next microtask.
   *   - Otherwise: subscribes to `document` `chatbot:ready` with `{once:true}`.
   *
   * Hosts that load their script BEFORE the bundle should still feature-detect
   * `window.Chatbot` first (the API itself does not exist until the bundle
   * runs). Once it exists, `whenReady` is the no-race entry point.
   */
  whenReady(cb: () => void): void;
  /** @internal accessors used by the custom element. */
  __internal: ChatbotInternal;
}

export interface ChatbotInternal {
  getTool(name: string): ToolHandler | undefined;
  getBlockRenderer(type: string): BlockRenderer | undefined;
  getNavigator(): NavigatorFn | null;
  getPageContext(): PageContext;
  getBearer(): string | null;
  emitOpen(): void;
  emitClose(): void;
  emitToggle(): void;
  emitNewChat(): void;
  onOpenRequest(cb: () => void): void;
  onCloseRequest(cb: () => void): void;
  onToggleRequest(cb: () => void): void;
  onNewChatRequest(cb: () => void): void;
}
