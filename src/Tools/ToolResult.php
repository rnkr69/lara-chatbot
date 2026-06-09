<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools;

/**
 * Resultado de invocar una tool. Inmutable.
 *
 * Tres estados:
 *
 *  - `ok`             — éxito. `data` contiene el payload que vuelve al LLM
 *                       como `tool_result` (también consumible por el host
 *                       vía evento `ToolInvoked` — gap E08). `blocks`
 *                       opcional: bloques tipados que el widget renderiza
 *                       directamente (E15) sin pasar por el LLM.
 *  - `error`          — la invocación no se completó. `errorCategory`
 *                       categoriza el fallo para el LLM (`validation`,
 *                       `unauthorized`, `out_of_scope`, `not_owner`,
 *                       `runtime`, ...) y `errorMessage` añade contexto
 *                       seguro (sin filtrar internals). El LLM ve ambos
 *                       campos y puede pedir corrección al usuario.
 *  - `awaiting_user`  — la tool requiere confirmación del usuario antes de
 *                       ejecutarse de verdad (E16). Aplica a frontend tools
 *                       en v1; backend tools quedan en `Auto` hasta v2.
 *                       `pendingActionId` guarda la PK de
 *                       `chatbot_pending_actions` que E16 crea.
 *
 * Patrón bulk (gap cross-host E06): para tools que aceptan `target_ids[]`
 * con partial-success, devolver `success()` con `data` que incluya
 * `succeeded[]` + `failed[]` + counts. Documentado en
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
     * Estado intermedio para frontend tools `confirmation=confirm` (E16).
     * Backend tools en v1 no deberían devolver este estado.
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
     * Serializa para el evento SSE `tool_result` (ROADMAP §3.4) y para el
     * payload que vuelve al LLM. Los listeners del evento `ToolInvoked`
     * (gap E08) reciben este mismo array.
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
}
