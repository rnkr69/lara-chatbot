<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Rnkr69\LaraChatbot\Authorization\AccessScope;
use Rnkr69\LaraChatbot\Authorization\Contracts\ScopeResolver;

/**
 * `ScopeResolver` controllable from the test: returns the preset list
 * for each scope. Used to validate the integration of `accessibleQuery()`
 * and `accessibleUserIds()` without coupling to the real `User` model.
 */
class FixedScopeResolver implements ScopeResolver
{
    /**
     * @param  array<string, array<int, int|string>>  $idsByScope
     */
    public function __construct(
        public array $idsByScope = [
            'self' => [42],
            'team' => [42, 99, 100],
            'all'  => [1, 2, 3, 42, 99, 100],
        ],
    ) {}

    public function resolveAccessibleUserIds(Authenticatable $user, AccessScope $scope): array
    {
        return $this->idsByScope[$scope->value] ?? [$user->getAuthIdentifier()];
    }
}
