<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Contracts;

use Rnkr69\LaraChatbot\Authorization\AccessScope;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

/**
 * Contrato completo de una tool de backend. Lo que el LLM ve y el
 * orquestador (`ChatService`, E08) ejecuta.
 *
 * E04 introdujo una versión mínima (sólo `name()` + `permissions()`) porque
 * la firma de `TenantResolver` necesitaba referenciar la interfaz. E06
 * expande esta misma interfaz con el resto del contrato (NO la mueve ni la
 * renombra). La forma natural de implementarla es vía `BaseBackendTool`,
 * que aporta validación de args, cascada de autorización (permission →
 * scope → tenant) y helpers de query.
 *
 * Convenciones:
 *   - `name()` snake_case, único en todo el registro (`list_my_invoices`).
 *   - `description()` la lee el LLM y decide si invocar la tool. Debe ser
 *     una sola frase orientada a "para qué sirve" en lenguaje natural.
 *   - `parameters()` JSON Schema mínimo (type/properties/required/enum).
 *     Se mapea a Laravel Validator vía `JsonSchemaToRules` para validar
 *     args antes de invocar `handle()`.
 *   - `permissions()` lista AND. Lista vacía = tool pública.
 *   - `defaultScope()` el scope que asume la tool si su lógica de query no
 *     declara otro. Tools de admin pueden devolver `All`.
 *   - `confirmation()` en backend tools v1 debe ser `Auto`; otros niveles
 *     quedan en backlog v2.
 *   - `tenantScope()` opt-in del gap cross-host E04 (hosts multi-tenant). Si una
 *     tool devuelve `true`, el `ToolRegistry` exige al boot que el host
 *     haya bind un `TenantResolver`; al ejecutarse, el filtro tenant entra
 *     en la cascada de autorización.
 */
interface BackendTool
{
    /**
     * Identificador único de la tool. Convención snake_case.
     */
    public function name(): string;

    /**
     * Frase corta que el LLM lee para decidir si invocarla.
     */
    public function description(): string;

    /**
     * JSON Schema mínimo del shape de los args.
     *
     * Estructura esperada:
     *   [
     *     'type' => 'object',
     *     'properties' => [
     *       'order_id' => ['type' => 'integer', 'description' => '...'],
     *       'status'   => ['type' => 'string', 'enum' => ['paid', 'pending']],
     *     ],
     *     'required' => ['order_id'],
     *   ]
     *
     * Las claves no top-level (anidadas, oneOf, etc.) son ignoradas por el
     * validador interno; si necesitas validación más rica, override
     * `BaseBackendTool::validateArgs()`.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * Permisos que el usuario debe tener para invocar esta tool. Lista AND.
     *
     * @return array<int, string>
     */
    public function permissions(): array;

    /**
     * Scope por defecto para la cascada de autorización ROADMAP §2.4.
     */
    public function defaultScope(): AccessScope;

    /**
     * Nivel de confirmación. En backend tools v1 sólo `Auto` está soportado
     * end-to-end por el orquestador; otros valores los rechaza E08 hasta v2.
     */
    public function confirmation(): ConfirmationLevel;

    /**
     * Si `true`, el `ToolRegistry` exige al boot que el host haya bind un
     * `TenantResolver`. La cascada pasa a permission → scope → tenant →
     * ownership. Default `false` — sólo opt-in para tools del gap cross-host.
     */
    public function tenantScope(): bool;

    /**
     * v2.0 (E1) — declara si los blocks que esta tool emita pueden ser
     * fijados al dashboard personal y replayados periódicamente. Sólo se
     * considera efectivo cuando coincide con `confirmation() === Auto`:
     * tools que requieren confirmación o ejecución manual son siempre tools
     * que mutan, así que aunque devuelvan `true` aquí el orquestador no
     * emitirá `pinnable: true` en los blocks (enforcement aguas arriba).
     *
     * Default `false` en `BaseBackendTool` — todas las tools existentes
     * siguen igual sin cambios. Una tool quita el opt-in subclasificando
     * y devolviendo `true` explícitamente (e.g. listados read-only).
     *
     * `FrontendTool` hereda esta firma vía `extends BackendTool`; la
     * implementación por defecto en `BaseBackendTool` (de la que
     * `BaseFrontendTool` también hereda) devuelve `false`, así que el
     * universo "frontend tools pinables" es vacío por construcción a
     * menos que un host lo active explícitamente (lo cual sólo tiene
     * sentido cuando una FE tool produce datos replayables, no efectos UI).
     */
    public function pinnable(): bool;

    /**
     * Ejecuta la lógica de la tool. La validación de args y la cascada de
     * autorización deben haberse aplicado antes (lo hace `BaseBackendTool`).
     *
     * @param  array<string, mixed>  $args
     */
    public function handle(array $args, ToolContext $ctx): ToolResult;
}
