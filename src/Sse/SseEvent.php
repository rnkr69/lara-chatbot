<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Sse;

/**
 * Evento del stream SSE que el `ChatService` (E08) emite y el endpoint
 * `/chatbot/stream` (E09) serializa al cliente. Catálogo cerrado en
 * ROADMAP §3.4:
 *
 *   - `text`            — chunk de texto (markdown del LLM).
 *   - `block`           — bloque tipado renderizado por el widget (E15).
 *   - `tool_call`       — informativo: el LLM acaba de invocar una backend
 *                          tool. Lleva `name` y `args`.
 *   - `tool_result`     — informativo: la backend tool terminó. Lleva
 *                          `name`, `ok` y un `summary` corto.
 *   - `frontend_action` — el LLM invocó una frontend tool. El widget la
 *                          ejecuta. Lleva `tool`, `args`, `action_id`,
 *                          `confirmation`.
 *   - `error`           — error recuperable o fatal del stream.
 *   - `done`            — fin del turno. Lleva `message_id` (de la tabla
 *                          `chatbot_messages`) y `usage` (tokens).
 *
 * VO inmutable. La serialización al protocolo SSE (`event: ...\ndata: ...\n\n`)
 * la hace el endpoint en E09; aquí sólo se modela el shape estructurado.
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
     * Frame `block` del stream SSE.
     *
     * v2.0 (E1) — el orquestador estampa metadatos opcionales por cada
     * bloque que un backend tool emite en su `ToolResult::blocks[]`:
     *
     *   - `$id`           : UUID estable del bloque (handle para pin/scroll/etc.).
     *   - `$source`       : descriptor `{tool, args, page_context_keys?}` que el
     *                       replay engine (E3) usará para re-ejecutar el tool.
     *   - `$pinnable`     : si `true`, el cliente puede mostrar el botón 📌. Sólo
     *                       se propaga cuando la tool declara `pinnable() === true`
     *                       *y* `confirmation === Auto` (enforcement aguas arriba).
     *   - `$blockOrdinal` : v2.1.2 (#27) — posición 0-based del bloque ENTRE los
     *                       de su mismo `type` dentro del `ToolResult` que lo
     *                       emitió (el N-ésimo `kpi`, el N-ésimo `chart`…). Es
     *                       la pieza estable del descriptor `{block_type,
     *                       ordinal}` con la que el replay vuelve a localizar
     *                       ESTE bloque cuando un tool emite varios — el `id`
     *                       no sirve (es un UUID nuevo por invocación).
     *   - `$meta`         : v2.2.1 (PR-B) — bag opcional `{key: value, …}` que
     *                       el tool author estampa en `ToolResult::blocks[*]['meta']`
     *                       y el orquestador propaga verbatim. Reservado para
     *                       hooks UX que no caben en `data` (renderizable) ni
     *                       en `source` (replay). El caso canónico es
     *                       `meta.side_effects` que las 5 tools de
     *                       `add/edit/delete dashboard|widget` usan para que
     *                       el bundle del dashboard se entere y refresque sin
     *                       F5. Consumers que no conocen una clave la ignoran
     *                       sin error.
     *
     * Todos son opcionales y SÓLO se serializan al payload cuando no son
     * `null`. Consumers v1.x que sólo conocen `{type, data}` ignoran las
     * claves extra y siguen funcionando sin cambios — el JS también lo trata
     * como aditivo (`readV2BlockMetadata` en `widget.ts`).
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
