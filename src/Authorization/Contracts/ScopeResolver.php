<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Authorization\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Rnkr69\LaraChatbot\Authorization\AccessScope;

/**
 * Contract for the component that maps an `AccessScope` (self/team/all) to
 * the list of user IDs whose rows are visible to the tool. The host
 * implements it once in its project following its own hierarchy
 * (manager → team, tenant → users, etc.).
 *
 * The package provides `NullScopeResolver` as the default: it knows how to
 * answer `Self` with `[user.id]` and throws
 * `ScopeResolverNotConfiguredException` for `Team`/`All`, forcing the host
 * to implement its own if it wants to use those scopes.
 */
interface ScopeResolver
{
    /**
     * @return array<int, int|string>  IDs of the users whose data the invoker can see.
     */
    public function resolveAccessibleUserIds(Authenticatable $user, AccessScope $scope): array;
}
