<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Contracts;

/**
 * Marker que distingue las tools que el LLM "llama" pero que en realidad
 * delegan su ejecución al widget frontend (navegar, abrir modal, rellenar
 * formulario, etc.). El paquete las trata en E08 igual que cualquier
 * `BackendTool` para la cascada de autorización (permission/scope/tenant);
 * la diferencia es exclusivamente el shape del evento SSE que el
 * `ChatService` emite cuando el LLM las invoca:
 *
 *   - Backend tool → `event: tool_call` + ejecución en backend → `event: tool_result`.
 *   - Frontend tool → `event: frontend_action` con `{tool, args, action_id, confirmation}`
 *                     y al LLM se le devuelve "queued" para que pueda continuar.
 *
 * El contrato sigue siendo `BackendTool` puro en E08: `handle()` valida
 * args y autoriza, pero no toca el host (el widget hará el side-effect
 * real). E11 expandirá este contrato con primitivas concretas
 * (`NavigateTool`, `HighlightTool`, ...) y una `BaseFrontendTool` que
 * automatiza el "shim" devolviendo `ToolResult::success(['status' => 'queued'])`.
 *
 * En E08 el orquestador detecta frontend tools por `instanceof FrontendTool`,
 * no por una bandera string en el nombre — la decisión se tomó para que
 * E11 pueda enriquecer la interfaz sin tener que rehacer el branching.
 */
interface FrontendTool extends BackendTool
{
}
