<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Rnkr69\LaraChatbot\Authorization\Contracts\Authorizer;
use RuntimeException;

/**
 * Implementación que delega en `spatie/laravel-permission`.
 *
 * El paquete **no** declara Spatie como dependencia (ROADMAP §2.1). Esta
 * clase sólo se instancia si el ServiceProvider detecta que el trait
 * `Spatie\Permission\Traits\HasRoles` está cargado. Si se intenta
 * instanciar sin Spatie, lanza un `RuntimeException` claro que guía al
 * integrador a `composer require spatie/laravel-permission` o cambiar a
 * `chatbot.authorization.resolver = gate`.
 *
 * Itera los permisos y llama `$user->can($permission)` — método que
 * Spatie inyecta vía `HasPermissions`. Todos deben pasar (AND).
 */
class SpatieAuthorizer implements Authorizer
{
    public function __construct()
    {
        if (! class_exists(\Spatie\Permission\PermissionServiceProvider::class)) {
            throw new RuntimeException(
                'chatbot.authorization.resolver=spatie pero el paquete '
                . 'spatie/laravel-permission no está instalado. Ejecuta '
                . '`composer require spatie/laravel-permission` o cambia a '
                . 'resolver=gate / resolver=custom.'
            );
        }
    }

    public function check(Authenticatable $user, array $permissions): bool
    {
        if ($permissions === []) {
            return true;
        }

        if (! method_exists($user, 'can')) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (! $user->can($permission)) {
                return false;
            }
        }

        return true;
    }
}
