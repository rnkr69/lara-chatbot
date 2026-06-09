<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Authorization\AccessScope;
use Rnkr69\LaraChatbot\Authorization\Exceptions\ScopeResolverNotConfiguredException;
use Rnkr69\LaraChatbot\Authorization\NullScopeResolver;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\FakeUser;

/*
|--------------------------------------------------------------------------
| NullScopeResolver — E20 hueco
|--------------------------------------------------------------------------
|
| Default cuando el host no declara scope_resolver. Sólo sabe responder
| `Self`; `Team`/`All` lanzan `ScopeResolverNotConfiguredException` para
| forzar al host a implementar el suyo. Antes de E20 sólo se verificaba la
| instancia en boot, no su comportamiento.
*/

it('returns the user id wrapped in an array for Self scope', function () {
    $user = new FakeUser(id: 7);

    expect((new NullScopeResolver)->resolveAccessibleUserIds($user, AccessScope::Self))
        ->toBe([7]);
});

it('throws ScopeResolverNotConfigured for the Team scope', function () {
    expect(fn () => (new NullScopeResolver)->resolveAccessibleUserIds(new FakeUser, AccessScope::Team))
        ->toThrow(ScopeResolverNotConfiguredException::class);
});

it('throws ScopeResolverNotConfigured for the All scope', function () {
    expect(fn () => (new NullScopeResolver)->resolveAccessibleUserIds(new FakeUser, AccessScope::All))
        ->toThrow(ScopeResolverNotConfiguredException::class);
});

it('preserves string identifiers for Self when the user uses non-numeric ids', function () {
    $user = new FakeUser(id: 'usr_abc');

    expect((new NullScopeResolver)->resolveAccessibleUserIds($user, AccessScope::Self))
        ->toBe(['usr_abc']);
});
