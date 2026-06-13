<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Sse;

/**
 * SSE stream event that `ChatService` (E08) emits and the
 * `/chatbot/stream` (E09) endpoint serializes to the client. Closed catalog in
 * ROADMAP §3.4:
 *
 *   - `text`            — text chunk (LLM markdown).
 *   - `block`           — typed block rendered by the widget (E15).
 *   - `tool_call`       — informational: the LLM just invoked a backend
 *                          tool. Carries `name` and `args`.
 *   - `tool_result`     — informational: the backend tool finished. Carries
 *                          `name`, `ok` and a short `summary`.
 *   - `frontend_action` — the LLM invoked a frontend tool. The widget
 *                          executes it. Carries `tool`, `args`, `action_id`,
 *                          `confirmation`.
 *   - `error`           — recoverable or fatal stream error.
 *   - `done`            — end of turn. Carries `message_id` (from the
 *                          `chatbot_messages` table) and `usage` (tokens).
 *
 * Immutable VO. Serialization to the SSE protocol (`event: ...\ndata: ...\n\n`)
 * is done by the endpoint in E09; here we only model the structured shape.
 */
final class SseEvent
{
    public const EVENT_TEXT             = 'text';
    public const EVENT_BLOCK            = 'block';
    public const EVENT_TOOL_CALL        = 'tool_call';
    public const EVENT_TOOL_RESULT      = 'tool_result';
    public const EVENT_FRONTEND_ACTION  = 'frontend_action';
    public const EVENT_ERROR            = 'error';
    public const EVENT_DONE             = 'done';

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $event,
        public readonly array $data,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function text(string $delta): self
    {
        return new self(self::EVENT_TEXT, ['delta' => $delta]);
    }

    /**
     * `block` frame of the SSE stream.
     *
     * v2.0 (E1) — the orchestrator stamps optional metadata on each
     * block that a backend tool emits in its `ToolResult::blocks[]`:
     *
     *   - `$id`           : stable block UUID (handle for pin/scroll/etc.).
     *   - `$source`       : `{tool, args, page_context_keys?}` descriptor that the
     *                       replay engine (E3) will use to re-execute the tool.
     *   - `$pinnable`     : if `true`, the client may show the 📌 button. Only
     *                       propagated when the tool declares `pinnable() === true`
     *                       *and* `confirmation === Auto` (enforcement upstream).
     *   - `$blockOrdinal` : v2.1.2 (#27) — 0-based position of the block AMONG those
     *                       of its same `type` within the `ToolResult` that
     *                       emitted it (the Nth `kpi`, the Nth `chart`…). It is
     *                       the stable piece of the `{block_type,
     *                       ordinal}` descriptor with which the replay relocates
     *                       THIS block when a tool emits several — the `id`
     *                       is useless (it is a new UUID per invocation).
     *   - `$meta`         : v2.2.1 (PR-B) — optional `{key: value, …}` bag that
     *                       the tool author stamps in `ToolResult::blocks[*]['meta']`
     *                       and the orchestrator propagates verbatim. Reserved for
     *                       UX hooks that fit neither in `data` (renderable) nor
     *                       in `source` (replay). The canonical case is
     *                       `meta.side_effects` that the 5
     *                       `add/edit/delete dashboard|widget` tools use so that
     *                       the dashboard bundle finds out and refreshes without
     *                       F5. Consumers that do not know a key ignore it
     *                       without error.
     *
     * They are all optional and are ONLY serialized to the payload when they are not
     * `null`. v1.x consumers that only know `{type, data}` ignore the
     * extra keys and keep working without changes — the JS also treats it
     * as additive (`readV2BlockMetadata` in `widget.ts`).
     *
     * @param  array<string, mixed>  $data
     * @param  array{tool: string, args: array<string, mixed>, page_context_keys?: array<int, string>}|null  $source
     * @param  array<string, mixed>|null  $meta
     */
    public static function block(
        string $type,
        array $data,
        ?string $id = null,
        ?array $source = null,
        ?bool $pinnable = null,
        ?int $blockOrdinal = null,
        ?array $meta = null,
    ): self {
        $payload = ['type' => $type, 'data' => $data];

        if ($id !== null && $id !== '') {
            $payload['id'] = $id;
        }
        if ($source !== null) {
            $payload['source'] = $source;
        }
        if ($pinnable === true) {
            $payload['pinnable'] = true;
        }
        if ($blockOrdinal !== null) {
            $payload['block_ordinal'] = $blockOrdinal;
        }
        if ($meta !== null && $meta !== []) {
            $payload['meta'] = $meta;
        }

        return new self(self::EVENT_BLOCK, $payload);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public static function toolCall(string $name, array $args): self
    {
        return new self(self::EVENT_TOOL_CALL, ['name' => $name, 'args' => $args]);
    }

    public static function toolResult(string $name, bool $ok, string $summary = ''): self
    {
        return new self(self::EVENT_TOOL_RESULT, [
            'name'    => $name,
            'ok'      => $ok,
            'summary' => $summary,
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public static function frontendAction(
        string $tool,
        array $args,
        string $actionId,
        string $confirmation,
    ): self {
        return new self(self::EVENT_FRONTEND_ACTION, [
            'tool'         => $tool,
            'args'         => $args,
            'action_id'    => $actionId,
            'confirmation' => $confirmation,
        ]);
    }

    public static function error(string $message, string $code = 'unknown'): self
    {
        return new self(self::EVENT_ERROR, ['message' => $message, 'code' => $code]);
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    public static function done(
        int|string|null $messageId,
        array $usage = [],
        int|string|null $conversationId = null,
        ?string $conversationTitle = null,
    ): self {
        $payload = [
            'message_id' => $messageId,
            'usage'      => $usage,
        ];
        if ($conversationId !== null) {
            $payload['conversation_id'] = $conversationId;
        }
        if ($conversationTitle !== null) {
            $payload['conversation_title'] = $conversationTitle;
        }
        return new self(self::EVENT_DONE, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'data'  => $this->data,
        ];
    }
}
