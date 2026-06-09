<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Rnkr69\LaraChatbot\Authorization\Contracts\ScopeResolver;
use Rnkr69\LaraChatbot\Authorization\Exceptions\ScopeResolverNotConfiguredException;

/**
 * Implementación por defecto del `ScopeResolver`. Sólo sabe responder
 * `Self` con el id del invocador. Para `Team` o `All` lanza
 * `ScopeResolverNotConfiguredException`, forzando al host a implementar
 * el suyo si quiere usar esos scopes superiores.
 *
 * El paquete enlaza esta clase al contenedor cuando
 * `chatbot.authorization.scope_resolver` es `null`.
 */
class NullScopeResolver implements ScopeResolver
{
    public function resolveAccessibleUserIds(Authenticatable $user, AccessScope $scope): array
    {
        return match ($scope) {
            AccessScope::Self => [$user->getAuthIdentifier()],
            AccessScope::Team, AccessScope::All
                => throw new ScopeResolverNotConfiguredException($scope),
        };
    }
}
