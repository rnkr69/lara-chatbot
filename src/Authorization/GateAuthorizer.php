<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Authorization;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Rnkr69\LaraChatbot\Authorization\Contracts\Authorizer;

/**
 * Default `Authorizer` implementation. Iterates the permission list and
 * uses `Gate::forUser($user)->allows($permission)` — all must pass.
 *
 * Requires no additional package. Works against Gates and Policies the
 * host registers with `Gate::define(...)` or Eloquent policies.
 */
class GateAuthorizer implements Authorizer
{
    public function __construct(private readonly Gate $gate)
    {
    }

    public function check(Authenticatable $user, array $permissions): bool
    {
        if ($permissions === []) {
            return true;
        }

        $userGate = $this->gate->forUser($user);

        foreach ($permissions as $permission) {
            if (! $userGate->allows($permission)) {
                return false;
            }
        }

        return true;
    }
}
