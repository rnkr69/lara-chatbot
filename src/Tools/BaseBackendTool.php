<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Validator;
use Rnkr69\LaraChatbot\Authorization\AccessScope;
use Rnkr69\LaraChatbot\Authorization\Concerns\AuthorizesToolAccess;
use Rnkr69\LaraChatbot\Tools\Contracts\BackendTool;
use Rnkr69\LaraChatbot\Tools\Support\JsonSchemaToRules;

/**
 * Clase base para implementar `BackendTool` con la cascada de autorización
 * ya cableada (ROADMAP §5/E06):
 *
 *   1. Validar args contra `parameters()` (JSON Schema → Laravel Validator).
 *      Si fallan: `ToolResult::error('validation', ...)` SIN invocar `handle()`.
 *   2. `Authorizer::check()` con `permissions()`. Si falla:
 *      `ToolResult::error('unauthorized', ...)`.
 *   3. (Opcional) si `tenantScope()=true`, resolver tenant ids; si `[]`
 *      explícito → `error('out_of_scope', ...)`. Si `null` (bypass) o
 *      lista no vacía, continúa.
 *   4. Llamar `handle()` con args ya validados.
 *
 * El tool concreto sólo implementa `name()`, `description()`, `parameters()`,
 * `permissions()` (opcional) y `handle()` — la base se ocupa del resto.
 *
 * Ownership puntual (ROADMAP §2.3) NO se aplica aquí: depende de qué
 * registro toca cada tool, así que cada `handle()` lo verifica en su
 * propia query (helper `accessibleQuery()` lo facilita).
 *
 * Helpers expuestos (vía `AuthorizesToolAccess`):
 *   - `accessibleUserIds()`   — lista de user_ids resueltos por
 *                               `ScopeResolver` para el scope efectivo.
 *   - `accessibleTenantIds()` — lista (o null) por `TenantResolver`.
 *   - `accessibleQuery()`     — aplica `whereIn` sobre el campo de usuario
 *                               y opcionalmente sobre el campo de tenant.
 */
abstract class BaseBackendTool implements BackendTool
{
    use AuthorizesToolAccess;

    /**
     * Nombre del campo en BD que asocia un registro con un usuario. Las
     * tools que filtran por `accessibleQuery()` pueden override esto.
     */
    protected string $ownerColumn = 'user_id';

    public function permissions(): array
    {
        return [];
    }

    public function defaultScope(): AccessScope
    {
        $configured = config('chatbot.authorization.default_scope', 'self');

        return AccessScope::tryFrom(is_string($configured) ? $configured : 'self')
            ?? AccessScope::Self;
    }

    /**
     * Hook opcional (v1.1, findings #7) para que una tool ajuste su scope al
     * usuario que la invoca — útil cuando una misma tool debe correr como
     * `Self` para un rol y `Team`/`All` para otro sin duplicar la
     * implementación. Devolver `null` cae al `defaultScope()` estático.
     *
     * Diseño: añadido en `BaseBackendTool` (no en `BackendTool`) para no
     * romper implementaciones existentes que no extiendan la base
     * (e.g. `McpBackendTool`). El consumo vive en `resolveDefaultScope()`,
     * que `accessibleQuery()` y `accessibleUserIdsForCtx()` invocan en lugar
     * de `defaultScope()` directamente.
     */
    public function defaultScopeFor(Authenticatable $user): ?AccessScope
    {
        return null;
    }

    /**
     * Devuelve el scope efectivo para `$user`: intenta `defaultScopeFor()`
     * primero (hook user-aware); si retorna null, cae al `defaultScope()`
     * clásico (compatible con tools v1.0).
     */
    public function resolveDefaultScope(Authenticatable $user): AccessScope
    {
        return $this->defaultScopeFor($user) ?? $this->defaultScope();
    }

    public function confirmation(): ConfirmationLevel
    {
        return ConfirmationLevel::Auto;
    }

    public function tenantScope(): bool
    {
        return false;
    }

    /**
     * v2.0 (E1) — default `false`. Una tool concreta declara opt-in
     * override con `return true;` cuando sus blocks pueden vivir en el
     * dashboard personal y ser replayados. El enforcement final lo aplica
     * el orquestador SSE: aunque devolvamos `true` aquí, si
     * `confirmation() !== Auto` el flag se ignora silenciosamente al
     * emitir el block (no se propaga `pinnable: true` al cliente).
     */
    public function pinnable(): bool
    {
        return false;
    }

    /**
     * Punto de entrada que invoca el orquestador (E08). Aplica la cascada
     * y delega en `handle()` cuando todos los pasos pasan.
     *
     * @param  array<string, mixed>  $args
     */
    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $validation = $this->validateArgs($args);

        if ($validation !== null) {
            return $validation;
        }

        if (! $this->checkPermissions($ctx->user, $this->permissions())) {
            return ToolResult::error('unauthorized', 'Acceso denegado.');
        }

        if ($this->tenantScope()) {
            $tenantIds = $this->accessibleTenantIds($ctx->user, $this, $ctx->pageContext);

            if ($tenantIds === []) {
                return ToolResult::error('out_of_scope', 'Sin acceso a ningún tenant.');
            }
        }

        return $this->handle($args, $ctx);
    }

    /**
     * Hook de validación. Override para lógica más rica que el subset JSON
     * Schema soportado por `JsonSchemaToRules`. Devolver `null` cuando los
     * args son válidos.
     *
     * @param  array<string, mixed>  $args
     */
    protected function validateArgs(array $args): ?ToolResult
    {
        $rules = JsonSchemaToRules::convert($this->parameters());

        if ($rules === []) {
            return null;
        }

        $validator = Validator::make($args, $rules);

        if ($validator->fails()) {
            $first = $validator->errors()->first();

            return ToolResult::error('validation', $first ?: 'Parámetros inválidos.');
        }

        return null;
    }

    /**
     * Aplica la cascada de scope/tenant a una query Eloquent o Query
     * Builder. Devuelve el mismo builder para chaining.
     *
     * Uso típico:
     *
     *   public function handle(array $args, ToolContext $ctx): ToolResult
     *   {
     *       $orders = $this->accessibleQuery(
     *           Order::query(),
     *           $ctx,
     *       )->get();
     *       ...
     *   }
     *
     * @template T of EloquentBuilder|QueryBuilder
     * @param  T  $query
     * @return T
     */
    protected function accessibleQuery(
        EloquentBuilder|QueryBuilder $query,
        ToolContext $ctx,
        ?string $tenantColumn = null,
    ): EloquentBuilder|QueryBuilder {
        $scope = $this->resolveDefaultScope($ctx->user);

        $userIds = $this->accessibleUserIds($ctx->user, $scope);
        $query->whereIn($this->ownerColumn, $userIds);

        if ($this->tenantScope() && $tenantColumn !== null) {
            $tenantIds = $this->accessibleTenantIds($ctx->user, $this, $ctx->pageContext);

            if ($tenantIds !== null) {
                $query->whereIn($tenantColumn, $tenantIds);
            }
        }

        return $query;
    }

    /**
     * Atajo para tools que sólo necesitan los IDs (sin acceso al builder).
     *
     * @return array<int, int|string>
     */
    protected function accessibleUserIdsForCtx(ToolContext $ctx): array
    {
        return $this->accessibleUserIds($ctx->user, $this->resolveDefaultScope($ctx->user));
    }

    /**
     * Para que las clases hijas puedan refrescar el user del ctx sin
     * obligar a importarlo. (Ergonomía.)
     */
    protected function user(ToolContext $ctx): Authenticatable
    {
        return $ctx->user;
    }
}
