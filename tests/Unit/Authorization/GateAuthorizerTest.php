<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Rnkr69\LaraChatbot\Authorization\GateAuthorizer;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\FakeUser;

/*
|--------------------------------------------------------------------------
| GateAuthorizer — E20 hueco
|--------------------------------------------------------------------------
|
| El algoritmo de iteración (AND, short-circuit en `false`) y el
| short-circuit `[]` se ejercitaban indirectamente por BaseBackendToolTest.
| Aquí cubrimos su contrato directo.
*/

it('returns true on an empty permissions list (short-circuit)', function () {
    expect(app(GateAuthorizer::class)->check(new FakeUser, []))->toBeTrue();
});

it('returns true when every permission is granted by the Gate', function () {
    Gate::define('orders.read', fn () => true);
    Gate::define('orders.write', fn () => true);

    expect(app(GateAuthorizer::class)->check(new FakeUser, ['orders.read', 'orders.write']))
        ->toBeTrue();
});

it('returns false as soon as one permission is denied (AND semantics)', function () {
    Gate::define('orders.read', fn () => true);
    Gate::define('orders.write', fn () => false);

    expect(app(GateAuthorizer::class)->check(new FakeUser, ['orders.read', 'orders.write']))
        ->toBeFalse();
});

it('returns false when a permission is undefined by the Gate', function () {
    expect(app(GateAuthorizer::class)->check(new FakeUser, ['totally.unknown']))->toBeFalse();
});

it('passes the user to the Gate so it sees the same authenticatable', function () {
    $seen = null;
    Gate::define('inspect', function ($user) use (&$seen) {
        $seen = $user;

        return true;
    });

    $user = new FakeUser(id: 99);

    app(GateAuthorizer::class)->check($user, ['inspect']);

    expect($seen)->toBe($user);
});
