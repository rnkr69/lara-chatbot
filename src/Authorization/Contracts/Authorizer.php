<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Authorization\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contrato del componente que decide si un usuario tiene los permisos
 * declarados por una tool. El paquete provee tres implementaciones:
 *   - `GateAuthorizer`   — fallback default (Gate::forUser->allows).
 *   - `SpatieAuthorizer` — usa $user->can() de Spatie\Permission.
 *   - `CustomAuthorizer` — el host inyecta su propia clase vía config.
 *
 * La cascada concreta la decide el ServiceProvider en función de
 * `chatbot.authorization.resolver` ('spatie' | 'gate' | 'custom').
 */
interface Authorizer
{
    /**
     * Devuelve true sólo si el usuario tiene TODOS los permisos. Una lista
     * de permisos vacía debe interpretarse como "tool pública" → true.
     *
     * @param  array<int, string>  $permissions
     */
    public function check(Authenticatable $user, array $permissions): bool;
}
