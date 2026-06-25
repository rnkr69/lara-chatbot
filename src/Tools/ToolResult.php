<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools;

/**
 * Result of invoking a tool. Immutable.
 *
 * Three states:
 *
 *  - `ok`             — success. `data` contains the payload that returns to
 *                       the LLM as a `tool_result` (also consumable by the
 *                       host via the `ToolInvoked` event — E08 gap). `blocks`
 *                       optional: typed blocks that the widget renders
 *                       directly (E15) without going through the LLM.
 *  - `error`          — the invocation did not complete. `errorCategory`
 *                       categorizes the failure for the LLM (`validation`,
 *                       `unauthorized`, `out_of_scope`, `not_owner`,
 *                       `runtime`, ...) and `errorMessage` adds safe
 *                       context (without leaking internals). The LLM sees both
 *                       fields and can ask the user for a correction.
 *  - `awaiting_user`  — the tool requires user confirmation before
 *                       actually executing (E16). Applies to frontend tools
 *                       in v1; backend tools stay at `Auto` until v2.
 *                       `pendingActionId` holds the PK of the
 *                       `chatbot_pending_actions` row that E16 creates.
 *
 * Bulk pattern (E06 cross-host gap): for tools that accept `target_ids[]`
 * with partial-success, return `success()` with `data` that includes
 * `succeeded[]` + `failed[]` + counts. Documented in
 * `docs/backend-tools.md`.
 */
final class ToolResult
{
    public const STATUS_OK             = 'ok';
    public const STATUS_ERROR          = 'error';
    public const STATUS_AWAITING_USER  = 'awaiting_user';

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function __construct(
        public readonly string $status,
        public readonly array $data = [],
        public readonly array $blocks = [],
        public readonly ?string $errorCategory = null,
        public readonly ?string $errorMessage = null,
        public readonly ?string $pendingActionId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public static function success(array $data = [], array $blocks = []): self
    {
        return new self(
            status: self::STATUS_OK,
            data: $data,
            blocks: $blocks,
        );
    }

    public static function error(string $category, string $message = ''): self
    {
        return new self(
            status: self::STATUS_ERROR,
            errorCategory: $category,
            errorMessage: $message !== '' ? $message : $category,
        );
    }

    /**
     * Intermediate state for frontend tools with `confirmation=confirm` (E16).
     * Backend tools in v1 should not return this state.
     */
    public static function awaitingUser(string $pendingActionId, string $message = ''): self
    {
        return new self(
            status: self::STATUS_AWAITING_USER,
            errorMessage: $message !== '' ? $message : null,
            pendingActionId: $pendingActionId,
        );
    }

    public function isOk(): bool
    {
        return $this->status === self::STATUS_OK;
    }

    public function isError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    public function isAwaitingUser(): bool
    {
        return $this->status === self::STATUS_AWAITING_USER;
    }

    /**
     * Serializes for the `tool_result` SSE event (ROADMAP §3.4) and for the
     * payload that returns to the LLM. Listeners of the `ToolInvoked` event
     * (E08 gap) receive this same array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return match ($this->status) {
            self::STATUS_OK => [
                'status' => 'ok',
                'data'   => $this->data,
                'blocks' => $this->blocks,
            ],
            self::STATUS_ERROR => [
                'status'  => 'error',
                'error'   => $this->errorCategory,
                'message' => $this->errorMessage,
            ],
            self::STATUS_AWAITING_USER => [
                'status'            => 'awaiting_user',
                'pending_action_id' => $this->pendingActionId,
                'message'           => $this->errorMessage,
            ],
        };
    }

    /**
     * Payload destinado al modelo (LLM). Es idéntico a `toArray()` pero OMITE la
     * clave `blocks`, que es presentación para el widget — nunca razonamiento.
     * Enviar `blocks` al modelo provoca que reproduzca su contenido como
     * tabla/markdown en el texto, duplicando lo que el widget ya pinta por SSE.
     *
     * El host puede forzar el envío con `chatbot.llm.send_blocks_to_model`
     * (`$includeBlocks = true`); por defecto NO se envían. Para `error` y
     * `awaiting_user` el `unset` es no-op (esos branches no llevan `blocks`).
     *
     * @return array<string, mixed>
     */
    public function toModelArray(bool $includeBlocks = false): array
    {
        $payload = $this->toArray();

        if (! $includeBlocks) {
            unset($payload['blocks']);
        }

        return $payload;
    }
}
