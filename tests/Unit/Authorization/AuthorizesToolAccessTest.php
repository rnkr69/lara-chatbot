<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Authorization\Concerns\AuthorizesToolAccess;
use Rnkr69\LaraChatbot\Authorization\Contracts\TenantResolver;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\FakeUser;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\FixedTenantResolver;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\TenantScopedTool;

/*
|--------------------------------------------------------------------------
| AuthorizesToolAccess trait — E20 gap
|--------------------------------------------------------------------------
|
| The trait forms the authorization cascade (permission → scope → tenant).
| It is already covered indirectly by BaseBackendToolTest. Here we assert
| explicitly the contract of the "no TenantResolver bound" branch: the
| package should interpret it as "no tenant restriction" by returning
| `null` (semantics documented in src/Authorization/Concerns/...).
*/

/**
 * Trait exposer to verify `accessibleTenantIds` directly. The trait
 * declares the method as `protected`, so we wrap it in a subclass that
 * exposes it as `public`.
 */
function tenantAccessProbe(): object
{
    return new class {
        use AuthorizesToolAccess {
            accessibleTenantIds as public publicAccessibleTenantIds;
        }
    };
}

it('returns null from accessibleTenantIds when no TenantResolver is bound', function () {
    expect(app()->bound(TenantResolver::class))->toBeFalse();

    $probe = tenantAccessProbe();

    $result = $probe->publicAccessibleTenantIds(
        user: new FakeUser,
        tool: new TenantScopedTool,
        pageContext: ['route' => 'orders.index'],
    );

    expect($result)->toBeNull();
});

it('delegates to the registered TenantResolver when one is bound', function () {
    app()->singleton(TenantResolver::class, fn () => new FixedTenantResolver([10, 20]));

    $probe = tenantAccessProbe();

    $result = $probe->publicAccessibleTenantIds(
        user: new FakeUser,
        tool: new TenantScopedTool,
        pageContext: ['route' => 'orders.index'],
    );

    expect($result)->toBe([10, 20]);
});
