<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Rnkr69\LaraChatbot\Authorization\Contracts\Authorizer;
use RuntimeException;

/**
 * Implementation that delegates to `spatie/laravel-permission`.
 *
 * The package does **not** declare Spatie as a dependency (ROADMAP §2.1).
 * This class is only instantiated if the ServiceProvider detects that the
 * `Spatie\Permission\Traits\HasRoles` trait is loaded. If instantiation is
 * attempted without Spatie, it throws a clear `RuntimeException` that
 * guides the integrator to `composer require spatie/laravel-permission` or
 * to switch to `chatbot.authorization.resolver = gate`.
 *
 * Iterates the permissions and calls `$user->can($permission)` — a method
 * Spatie injects via `HasPermissions`. All must pass (AND).
 */
class SpatieAuthorizer implements Authorizer
{
    public function __construct()
    {
        if (! class_exists(\Spatie\Permission\PermissionServiceProvider::class)) {
            throw new RuntimeException(
                'chatbot.authorization.resolver=spatie but the '
                . 'spatie/laravel-permission package is not installed. Run '
                . '`composer require spatie/laravel-permission` or switch to '
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
