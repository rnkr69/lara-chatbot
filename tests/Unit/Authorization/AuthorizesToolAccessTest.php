<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Authorization\Concerns\AuthorizesToolAccess;
use Rnkr69\LaraChatbot\Authorization\Contracts\TenantResolver;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\FakeUser;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\FixedTenantResolver;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\TenantScopedTool;

/*
|--------------------------------------------------------------------------
| AuthorizesToolAccess trait — E20 hueco
|--------------------------------------------------------------------------
|
| El trait forma la cascada de autorización (permission → scope → tenant).
| Ya está cubierto indirectamente por BaseBackendToolTest. Aquí asertamos
| explícitamente el contrato de la rama "sin TenantResolver bound": el
| paquete debe interpretarlo como "sin restricción de tenant" devolviendo
| `null` (semántica documentada en src/Authorization/Concerns/...).
*/

/**
 * Exposer del trait para verificar `accessibleTenantIds` directamente. El
 * trait declara el método como `protected`, así que envolvemos en una
 * subclase que lo expone como `public`.
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
