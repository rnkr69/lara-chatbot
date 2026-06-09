<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Tools\Contracts\BackendTool;
use Rnkr69\LaraChatbot\Tools\ToolResult;

/**
 * Evento que el `ChatService` (E08) dispara tras CADA invocación de tool
 * (backend, frontend o MCP), independientemente de su éxito o fallo. Es el
 * gancho oficial del paquete para audit/PII redaction/telemetría/bulk
 * partial-success — el host engancha listeners desde su `EventServiceProvider`
 * sin tocar el paquete.
 *
 * Gap cross-host (audit log + PII redaction): los hosts piden poder
 * trazar todas las invocaciones de tools sin parchear el orquestador. Este
 * evento cumple ese contrato.
 *
 * Convenciones:
 *
 *   - Se dispara una vez por tool call, INCLUYENDO los rechazos por
 *     autorización (`ToolResult::error('unauthorized', ...)` o
 *     `error('out_of_scope', ...)`). El listener puede distinguir
 *     `result->isOk()` vs `isError()`.
 *   - `args` es el array tal como llegó del LLM (lo que tu listener verá si
 *     loguea bruto). Si necesitas redaction, hazlo en el listener leyendo
 *     `tool->parameters()` para saber qué claves son sensibles.
 *   - `result` es la `ToolResult` final (post-cascada). Para tools bulk,
 *     contiene los counts de partial-success en `data` (ver
 *     `docs/backend-tools.md` patrón bulk).
 *   - `durationMs` mide el wall-clock de la invocación (incluye validación,
 *     autorización y `handle()`). Útil para detectar tools lentas.
 *   - `conversation` puede ser `null` en sandboxes (`chatbot:test-connection`).
 */
final class ToolInvoked
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function __construct(
        public readonly Authenticatable $user,
        public readonly BackendTool $tool,
        public readonly array $args,
        public readonly ToolResult $result,
        public readonly float $durationMs,
        public readonly ?Conversation $conversation = null,
    ) {}
}
