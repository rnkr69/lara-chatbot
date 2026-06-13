<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Rnkr69\LaraChatbot\Authorization\Contracts\ScopeResolver;
use Rnkr69\LaraChatbot\Authorization\Exceptions\ScopeResolverNotConfiguredException;

/**
 * Default `ScopeResolver` implementation. It only knows how to answer
 * `Self` with the invoker's id. For `Team` or `All` it throws
 * `ScopeResolverNotConfiguredException`, forcing the host to implement its
 * own if it wants to use those higher scopes.
 *
 * The package binds this class to the container when
 * `chatbot.authorization.scope_resolver` is `null`.
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
