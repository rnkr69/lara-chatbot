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
 * Trait reutilizable por las clases base de tools (`BaseBackendTool`,
 * `BaseFrontendTool` — definidas en E06/E11) para componer la cascada de
 * autorización ROADMAP §2.4 sin duplicar la lógica del contenedor.
 *
 * Los métodos resuelven sus dependencias del container Laravel cada vez
 * que se llaman, lo cual es barato (singletons) y evita tener que inyectar
 * 3-4 servicios en el constructor de cada tool.
 */
trait AuthorizesToolAccess
{
    /**
     * Paso 1 de la cascada — permission check.
     *
     * @param  array<int, string>  $permissions
     */
    protected function checkPermissions(Authenticatable $user, array $permissions): bool
    {
        return app(Authorizer::class)->check($user, $permissions);
    }

    /**
     * Paso 2 de la cascada — scope de datos.
     *
     * @return array<int, int|string>
     */
    protected function accessibleUserIds(Authenticatable $user, AccessScope $scope): array
    {
        return app(ScopeResolver::class)->resolveAccessibleUserIds($user, $scope);
    }

    /**
     * Paso 3 (opcional, gap cross-host) — tenant scope. Devuelve null si
     * el host no ha registrado un `TenantResolver` (semánticamente:
     * "sin restricción tenant"). Las tools que requieren tenant scope deben
     * declarar `tenantScope=true` para que E06 valide al boot que el
     * resolver existe.
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
