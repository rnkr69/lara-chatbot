export type WidgetState = 'closed' | 'minimized' | 'open' | 'fullscreen';

/**
 * E17 — Web Component mode:
 *   - 'widget' (default): previous behavior, floating FAB with state machine.
 *   - 'page'  : fullscreen layout + conversations sidebar; always "open".
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
 * v2.0 — the shape of a typed block as it travels over SSE and as the widget
 * persists it in `ChatMessage.blocks[]`.
 *
 * The `id`, `source` and `pinnable` fields are added in v2.0 as part of the
 * personal dashboard's foundation (E1):
 *
 *   - `id` — UUID stamped by the backend SSE orchestrator when it emits the
 *     frame. Stable across the turn; the client uses it as a handle (pin
 *     button, scroll-to, etc.). If the block arrives without an id (old host
 *     renderer, LLM-driven RenderBlockTool), it stays undefined and the
 *     client treats it as anonymous.
 *
 *   - `source` — descriptor of which tool produced the block and with which
 *     args, including the page context keys active at that moment. It is the
 *     piece that enables "replay" of the block from the dashboard (E3). Only
 *     present for blocks emitted by backend tools that declare
 *     `pinnable() === true`; the tool author never declares it — the
 *     orchestrator always stamps it.
 *
 *   - `pinnable` — the 📌 button only appears in the chat if this is `true`.
 *     It is only `true` when the tool declares `pinnable()` *and* its
 *     `confirmation === Auto` (enforcement upstream in the backend; the
 *     client treats it as an opaque flag).
 *
 * Back-compat: all three fields are optional. v1.x renderers that only read
 * `type` + `data` keep working unchanged; persistence in `sessionStorage`
 * with the old shape hydrates without warnings.
 */
export interface BlockSource {
  /** Canonical tool name (snake_case, registry id). */
  tool: string;
  /** Args the tool was invoked with on this turn. */
  args: Record<string, unknown>;
  /** Top-level page context keys active when the tool ran. Just the names —
   *  the value snapshot is captured later when pinning. */
  page_context_keys?: string[];
}

export interface BlockPayload {
  type: string;
  data: Record<string, unknown>;
  /** Stable per-block UUID, stamped by the SSE orchestrator. */
  id?: string;
  /** Descriptor for replay from the dashboard. */
  source?: BlockSource;
  /** If `true`, the 📌 button can appear on hover over this block. */
  pinnable?: boolean;
  /**
   * v2.1.2 (#27) — 0-based position of the block among those of its same
   * `type` in the `ToolResult` that emitted it (the Nth `kpi`, the Nth
   * `chart`…). Travels when pinning as `source.block_ordinal`; the replay
   * re-locates THIS block with that descriptor in multi-block tools, instead
   * of taking `blocks[0]`. Absent in old v2.1.x blocks → the replay falls
   * back to 0.
   */
  blockOrdinal?: number;
  /**
   * v2.2.1 (PR-B) — optional bag with out-of-band metadata. The backend
   * stamps it on `ToolResult::blocks[*]['meta']`; the orchestrator propagates
   * it verbatim as `meta` in the `block` frame. The keys are client↔server
   * conventions; consumers that don't know a key ignore it without error.
   * Current canonical channels:
   *   - `meta.side_effects` (object `{type, ...}`): the conversational
   *     dashboard's 5 tools use it so the dashboard bundle refreshes the UI
   *     without F5 (`chatbot:dashboard-mutation` CustomEvent).
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
