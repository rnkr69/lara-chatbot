<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Authorization\AccessScope;
use Rnkr69\LaraChatbot\Authorization\Exceptions\ScopeResolverNotConfiguredException;
use Rnkr69\LaraChatbot\Authorization\NullScopeResolver;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\FakeUser;

/*
|--------------------------------------------------------------------------
| NullScopeResolver — E20 gap
|--------------------------------------------------------------------------
|
| Default when the host does not declare scope_resolver. It only knows how
| to answer `Self`; `Team`/`All` throw `ScopeResolverNotConfiguredException`
| to force the host to implement its own. Before E20 only the instance was
| verified at boot, not its behavior.
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
