<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Rnkr69\LaraChatbot\Authorization\AccessScope;
use Rnkr69\LaraChatbot\Authorization\Contracts\ScopeResolver;

/**
 * `ScopeResolver` controlable desde el test: devuelve la lista preestablecida
 * para cada scope. Sirve para validar la integración de `accessibleQuery()`
 * y `accessibleUserIds()` sin acoplar al modelo `User` real.
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
