<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Rnkr69\LaraChatbot\Models\Dashboard;
use Rnkr69\LaraChatbot\Models\DashboardWidget;
use Rnkr69\LaraChatbot\Models\WidgetRefreshPolicy;
use Rnkr69\LaraChatbot\Models\WidgetRefreshStatus;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;

beforeEach(function () {
    $this->artisan('migrate')->run();
});

function makeUser(int $id, string $name = 'Tester'): TestUser
{
    $user = new TestUser(['id' => $id, 'name' => $name]);
    $user->setRawAttributes(['id' => $id, 'name' => $name], sync: true);

    return $user;
}

function makeDashboard(TestUser $user, array $overrides = []): Dashboard
{
    return Dashboard::create(array_merge([
        'user_type'      => $user->getMorphClass(),
        'user_id'        => $user->getKey(),
        'name'           => 'Mi panel',
        'slug'           => 'mi-panel',
        'is_default'     => false,
        'layout_version' => 1,
        'metadata'       => null,
    ], $overrides));
}

it('persists a dashboard with the expected casts', function () {
    $u = makeUser(1);

    $d = makeDashboard($u, [
        'is_default' => true,
        'metadata'   => ['theme' => 'dark', 'accent' => '#0af'],
    ]);

    $reloaded = Dashboard::find($d->id);

    expect($reloaded->is_default)->toBeTrue();
    expect($reloaded->layout_version)->toBe(1);
    expect($reloaded->metadata)->toBe(['theme' => 'dark', 'accent' => '#0af']);
    expect($reloaded->deleted_at)->toBeNull();
});

it('scopeForUser filtra cross-user', function () {
    $alice = makeUser(1, 'Alice');
    $bob   = makeUser(2, 'Bob');

    makeDashboard($alice, ['slug' => 'alice-1']);
    makeDashboard($alice, ['slug' => 'alice-2']);
    makeDashboard($bob,   ['slug' => 'bob-1']);

    $aliceDashboards = Dashboard::forUser($alice)->get();
    $bobDashboards   = Dashboard::forUser($bob)->get();

    expect($aliceDashboards)->toHaveCount(2);
    expect($bobDashboards)->toHaveCount(1);
    expect($aliceDashboards->pluck('slug')->all())->toEqualCanonicalizing(['alice-1', 'alice-2']);
});

it('scopeDefault filtra al is_default=true', function () {
    $u = makeUser(1);

    makeDashboard($u, ['slug' => 'panel-a', 'is_default' => false]);
    $b = makeDashboard($u, ['slug' => 'panel-b', 'is_default' => true]);

    $found = Dashboard::forUser($u)->default()->first();

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($b->id);
});

it('hook saving auto-demote del resto del mismo usuario al setear is_default=true', function () {
    $u = makeUser(1);

    $a = makeDashboard($u, ['slug' => 'panel-a', 'is_default' => true]);
    $b = makeDashboard($u, ['slug' => 'panel-b', 'is_default' => true]);
    $c = makeDashboard($u, ['slug' => 'panel-c', 'is_default' => true]);

    expect($a->fresh()->is_default)->toBeFalse();
    expect($b->fresh()->is_default)->toBeFalse();
    expect($c->fresh()->is_default)->toBeTrue();
});

it('hook saving no toca dashboards de otros usuarios', function () {
    $alice = makeUser(1);
    $bob   = makeUser(2);

    $aliceDefault = makeDashboard($alice, ['slug' => 'a-default', 'is_default' => true]);
    $bobDefault   = makeDashboard($bob,   ['slug' => 'b-default', 'is_default' => true]);

    expect($aliceDefault->fresh()->is_default)->toBeTrue();
    expect($bobDefault->fresh()->is_default)->toBeTrue();
});

it('hook saving al actualizar un dashboard a is_default no se demote a sí mismo', function () {
    $u = makeUser(1);

    // #10 — `$a` (first) is auto-promoted to default on insert; creating `$b`
    // as default then demotes it. Re-promote `$a` from a FRESH model — the
    // same path the API controller uses (load → mutate → save).
    $a = makeDashboard($u, ['slug' => 'panel-a', 'is_default' => false]);
    $b = makeDashboard($u, ['slug' => 'panel-b', 'is_default' => true]);

    $aFresh = $a->fresh();
    $aFresh->is_default = true;
    $aFresh->save();

    expect($aFresh->fresh()->is_default)->toBeTrue();
    expect($b->fresh()->is_default)->toBeFalse();
});

it('hook saving auto-promueve el primer dashboard del usuario a is_default (#10)', function () {
    $u = makeUser(1);

    // First dashboard — created WITHOUT is_default → auto-promoted, so the
    // "exactly one is_default per user" invariant holds from the start.
    $first = makeDashboard($u, ['slug' => 'panel-a', 'is_default' => false]);
    expect($first->fresh()->is_default)->toBeTrue();

    // Second dashboard — the user already has a default, so it stays false.
    $second = makeDashboard($u, ['slug' => 'panel-b', 'is_default' => false]);
    expect($second->fresh()->is_default)->toBeFalse();
    expect($first->fresh()->is_default)->toBeTrue();
});

it('hook saving es inerte cuando is_default queda en false', function () {
    $u = makeUser(1);

    $a = makeDashboard($u, ['slug' => 'panel-a', 'is_default' => true]);

    makeDashboard($u, ['slug' => 'panel-b', 'is_default' => false]);

    expect($a->fresh()->is_default)->toBeTrue();
});

it('unique (user_type, user_id, slug) rechaza duplicados del mismo usuario', function () {
    $u = makeUser(1);

    makeDashboard($u, ['slug' => 'mi-panel']);

    expect(fn () => makeDashboard($u, ['slug' => 'mi-panel']))
        ->toThrow(QueryException::class);
});

it('mismo slug en distintos usuarios convive sin conflicto', function () {
    $alice = makeUser(1);
    $bob   = makeUser(2);

    makeDashboard($alice, ['slug' => 'operaciones']);
    makeDashboard($bob,   ['slug' => 'operaciones']);

    expect(Dashboard::count())->toBe(2);
});

it('soft-delete excluye en queries por defecto y conserva la fila', function () {
    $u = makeUser(1);

    $d = makeDashboard($u, ['slug' => 'panel-a']);

    $d->delete();

    expect(Dashboard::forUser($u)->count())->toBe(0);
    expect(Dashboard::withTrashed()->forUser($u)->count())->toBe(1);
    expect($d->fresh()->deleted_at)->not->toBeNull();
});

it('relación widgets devuelve los DashboardWidget del dashboard', function () {
    $u = makeUser(1);
    $d = makeDashboard($u);

    DashboardWidget::create([
        'dashboard_id'        => $d->id,
        'position'            => ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
        'block_type'          => 'table',
        'snapshot'            => ['data' => ['rows' => []], 'captured_at' => now()->toIso8601String(), 'byte_size' => 32],
        'source_signature'    => str_repeat('a', 64),
        'refresh_policy'      => WidgetRefreshPolicy::OnOpen,
        'last_refresh_status' => WidgetRefreshStatus::Fresh,
        'last_refreshed_at'   => now(),
    ]);

    DashboardWidget::create([
        'dashboard_id'        => $d->id,
        'position'            => ['x' => 4, 'y' => 0, 'w' => 4, 'h' => 3],
        'block_type'          => 'kpi',
        'snapshot'            => ['data' => ['label' => 'MRR', 'value' => 1234], 'captured_at' => now()->toIso8601String(), 'byte_size' => 48],
        'source_signature'    => str_repeat('b', 64),
        'refresh_policy'      => WidgetRefreshPolicy::OnOpen,
        'last_refresh_status' => WidgetRefreshStatus::Fresh,
        'last_refreshed_at'   => now(),
    ]);

    expect($d->widgets)->toHaveCount(2);
    expect($d->widgets->pluck('block_type')->all())->toEqualCanonicalizing(['table', 'kpi']);
});
