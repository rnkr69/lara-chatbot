<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Authorization;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Rnkr69\LaraChatbot\Authorization\Contracts\Authorizer;

/**
 * Implementación default del `Authorizer`. Itera la lista de permisos y
 * usa `Gate::forUser($user)->allows($permission)` — todos deben pasar.
 *
 * No requiere ningún paquete adicional. Funciona contra Gates y Policies
 * que el host registre con `Gate::define(...)` o policies de Eloquent.
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
