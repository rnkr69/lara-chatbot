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
 * Base class for implementing `BackendTool` with the authorization cascade
 * already wired (ROADMAP §5/E06):
 *
 *   1. Validate args against `parameters()` (JSON Schema → Laravel Validator).
 *      If they fail: `ToolResult::error('validation', ...)` WITHOUT invoking `handle()`.
 *   2. `Authorizer::check()` with `permissions()`. If it fails:
 *      `ToolResult::error('unauthorized', ...)`.
 *   3. (Optional) if `tenantScope()=true`, resolve tenant ids; if `[]`
 *      explicitly → `error('out_of_scope', ...)`. If `null` (bypass) or a
 *      non-empty list, continue.
 *   4. Call `handle()` with the already-validated args.
 *
 * The concrete tool only implements `name()`, `description()`, `parameters()`,
 * `permissions()` (optional) and `handle()` — the base handles the rest.
 *
 * Per-record ownership (ROADMAP §2.3) is NOT applied here: it depends on which
 * record each tool touches, so each `handle()` checks it in its own query
 * (the `accessibleQuery()` helper makes it easier).
 *
 * Exposed helpers (via `AuthorizesToolAccess`):
 *   - `accessibleUserIds()`   — list of user_ids resolved by
 *                               `ScopeResolver` for the effective scope.
 *   - `accessibleTenantIds()` — list (or null) by `TenantResolver`.
 *   - `accessibleQuery()`     — applies `whereIn` over the user field
 *                               and optionally over the tenant field.
 */
abstract class BaseBackendTool implements BackendTool
{
    use AuthorizesToolAccess;

    /**
     * Name of the DB field that associates a record with a user. Tools that
     * filter via `accessibleQuery()` may override this.
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
     * Optional hook (v1.1, findings #7) for a tool to adjust its scope to the
     * user who invokes it — useful when the same tool must run as `Self` for
     * one role and `Team`/`All` for another without duplicating the
     * implementation. Returning `null` falls back to the static `defaultScope()`.
     *
     * Design: added in `BaseBackendTool` (not in `BackendTool`) so as not to
     * break existing implementations that don't extend the base
     * (e.g. `McpBackendTool`). It is consumed in `resolveDefaultScope()`,
     * which `accessibleQuery()` and `accessibleUserIdsForCtx()` invoke instead
     * of `defaultScope()` directly.
     */
    public function defaultScopeFor(Authenticatable $user): ?AccessScope
    {
        return null;
    }

    /**
     * Returns the effective scope for `$user`: tries `defaultScopeFor()`
     * first (user-aware hook); if it returns null, falls back to the classic
     * `defaultScope()` (compatible with v1.0 tools).
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
     * v2.0 (E1) — default `false`. A concrete tool declares opt-in by
     * overriding with `return true;` when its blocks can live on the personal
     * dashboard and be replayed. The final enforcement is applied by the SSE
     * orchestrator: even if we return `true` here, if
     * `confirmation() !== Auto` the flag is silently ignored when emitting
     * the block (`pinnable: true` is not propagated to the client).
     */
    public function pinnable(): bool
    {
        return false;
    }

    /**
     * Entry point invoked by the orchestrator (E08). Applies the cascade
     * and delegates to `handle()` when all steps pass.
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
            return ToolResult::error('unauthorized', 'Access denied.');
        }

        if ($this->tenantScope()) {
            $tenantIds = $this->accessibleTenantIds($ctx->user, $this, $ctx->pageContext);

            if ($tenantIds === []) {
                return ToolResult::error('out_of_scope', 'No access to any tenant.');
            }
        }

        return $this->handle($args, $ctx);
    }

    /**
     * Validation hook. Override for richer logic than the JSON Schema subset
     * supported by `JsonSchemaToRules`. Return `null` when the args are
     * valid.
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

            return ToolResult::error('validation', $first ?: 'Invalid parameters.');
        }

        return null;
    }

    /**
     * Applies the scope/tenant cascade to an Eloquent or Query Builder.
     * Returns the same builder for chaining.
     *
     * Typical usage:
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
     * Shortcut for tools that only need the IDs (without access to the builder).
     *
     * @return array<int, int|string>
     */
    protected function accessibleUserIdsForCtx(ToolContext $ctx): array
    {
        return $this->accessibleUserIds($ctx->user, $this->resolveDefaultScope($ctx->user));
    }

    /**
     * So that child classes can fetch the ctx user without being forced to
     * import it. (Ergonomics.)
     */
    protected function user(ToolContext $ctx): Authenticatable
    {
        return $ctx->user;
    }
}
