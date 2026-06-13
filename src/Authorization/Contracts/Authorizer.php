<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Authorization\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contract for the component that decides whether a user has the
 * permissions declared by a tool. The package provides three
 * implementations:
 *   - `GateAuthorizer`   — default fallback (Gate::forUser->allows).
 *   - `SpatieAuthorizer` — uses $user->can() from Spatie\Permission.
 *   - `CustomAuthorizer` — the host injects its own class via config.
 *
 * The concrete cascade is decided by the ServiceProvider based on
 * `chatbot.authorization.resolver` ('spatie' | 'gate' | 'custom').
 */
interface Authorizer
{
    /**
     * Returns true only if the user has ALL the permissions. An empty
     * permission list should be interpreted as "public tool" → true.
     *
     * @param  array<int, string>  $permissions
     */
    public function check(Authenticatable $user, array $permissions): bool;
}
