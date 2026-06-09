<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Rnkr69\LaraChatbot\Models\Conversation;

/**
 * Contexto de invocación que recibe `BackendTool::handle()`. Inmutable.
 *
 * - `user`         — usuario autenticado del host. Las tools lo usan para
 *                    resolver scopes y filtrar queries.
 * - `pageContext`  — payload sanitizado de `Page Context API` (E14). Incluye
 *                    `route`, `entity`, filtros y selección actuales.
 *                    Diccionario libre, ya truncado por el sanitizador.
 * - `conversation` — conversación activa si existe (E08 lo provee). Puede
 *                    ser `null` en sandboxes (`chatbot:test-connection`,
 *                    tests aislados). Las tools no deberían depender de su
 *                    presencia salvo para escribir audit log o leer
 *                    `metadata`.
 * - `locale`       — locale efectivo del invocador (`User->locale` →
 *                    `app()->getLocale()` → 'en' como fallback). Útil para
 *                    formatear fechas/números en la respuesta.
 *
 * Pensado para construirse en E08 (`ChatService`) por cada tool call y
 * pasarse al `BaseBackendTool::execute()` que decora.
 */
final class ToolContext
{
    /**
     * @param  array<string, mixed>  $pageContext
     */
    public function __construct(
        public readonly Authenticatable $user,
        public readonly array $pageContext = [],
        public readonly ?Conversation $conversation = null,
        public readonly ?string $locale = null,
    ) {}
}
