<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Authorization\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Rnkr69\LaraChatbot\Authorization\AccessScope;

/**
 * Contrato del componente que mapea un `AccessScope` (self/team/all) a la
 * lista de IDs de usuario cuyas filas son visibles para la tool. El host
 * lo implementa una sola vez en su proyecto siguiendo la jerarquía propia
 * (manager → equipo, tenant → users, etc.).
 *
 * El paquete provee `NullScopeResolver` como default: sabe responder
 * `Self` con `[user.id]` y lanza `ScopeResolverNotConfiguredException`
 * para `Team`/`All`, forzando al host a implementar el suyo si quiere
 * usar esos scopes.
 */
interface ScopeResolver
{
    /**
     * @return array<int, int|string>  IDs de los usuarios cuya data el invocador puede ver.
     */
    public function resolveAccessibleUserIds(Authenticatable $user, AccessScope $scope): array;
}
