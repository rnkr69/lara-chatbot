<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Authorization\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Rnkr69\LaraChatbot\Authorization\AccessScope;
use Rnkr69\LaraChatbot\Authorization\Contracts\Authorizer;
use Rnkr69\LaraChatbot\Authorization\Contracts\ScopeResolver;
use Rnkr69\LaraChatbot\Authorization\Contracts\TenantResolver;
use Rnkr69\LaraChatbot\Tools\Contracts\BackendTool;

/**
 * Trait reusable by the tool base classes (`BaseBackendTool`,
 * `BaseFrontendTool` — defined in E06/E11) to compose the ROADMAP §2.4
 * authorization cascade without duplicating the container logic.
 *
 * The methods resolve their dependencies from the Laravel container every
 * time they're called, which is cheap (singletons) and avoids having to
 * inject 3-4 services into every tool's constructor.
 */
trait AuthorizesToolAccess
{
    /**
     * Cascade step 1 — permission check.
     *
     * @param  array<int, string>  $permissions
     */
    protected function checkPermissions(Authenticatable $user, array $permissions): bool
    {
        return app(Authorizer::class)->check($user, $permissions);
    }

    /**
     * Cascade step 2 — data scope.
     *
     * @return array<int, int|string>
     */
    protected function accessibleUserIds(Authenticatable $user, AccessScope $scope): array
    {
        return app(ScopeResolver::class)->resolveAccessibleUserIds($user, $scope);
    }

    /**
     * Step 3 (optional, cross-host gap) — tenant scope. Returns null if the
     * host has not registered a `TenantResolver` (semantically: "no tenant
     * restriction"). Tools that require tenant scope must declare
     * `tenantScope=true` so E06 validates at boot that the resolver exists.
     *
     * @param  array<string, mixed>  $pageContext
     * @return array<int, int|string>|null
     */
    protected function accessibleTenantIds(
        Authenticatable $user,
        BackendTool $tool,
        array $pageContext,
    ): ?array {
        if (! app()->bound(TenantResolver::class)) {
            return null;
        }

        return app(TenantResolver::class)->resolveAccessibleTenantIds($user, $tool, $pageContext);
    }
}
