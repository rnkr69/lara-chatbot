<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Rnkr69\LaraChatbot\Models\Dashboard;
use Rnkr69\LaraChatbot\Models\DashboardWidget;
use Rnkr69\LaraChatbot\Models\WidgetRefreshPolicy;
use Rnkr69\LaraChatbot\Models\WidgetRefreshStatus;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;

beforeEach(function () {
    $this->artisan('migrate')->run();
});

function makeUserW(int $id, string $name = 'Tester'): TestUser
{
    $user = new TestUser(['id' => $id, 'name' => $name]);
    $user->setRawAttributes(['id' => $id, 'name' => $name], sync: true);

    return $user;
}

function makeDashboardW(TestUser $user, string $slug = 'mi-panel'): Dashboard
{
    return Dashboard::create([
        'user_type'      => $user->getMorphClass(),
        'user_id'        => $user->getKey(),
        'name'           => 'Mi panel',
        'slug'           => $slug,
        'is_default'     => false,
        'layout_version' => 1,
        'metadata'       => null,
    ]);
}

function makeWidget(Dashboard $d, array $overrides = []): DashboardWidget
{
    return DashboardWidget::create(array_merge([
        'dashboard_id'        => $d->id,
        'position'            => ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
        'block_type'          => 'table',
        'title'               => null,
        'snapshot'            => ['data' => ['rows' => []], 'captured_at' => now()->toIso8601String(), 'byte_size' => 32],
        'source'              => ['tool' => 'list_invoices', 'args' => ['period' => 'last_month']],
        'source_signature'    => str_repeat('a', 64),
        'refresh_policy'      => WidgetRefreshPolicy::OnOpen,
        'last_refreshed_at'   => now(),
        'last_refresh_status' => WidgetRefreshStatus::Fresh,
        'last_refresh_error'  => null,
        'order_index'         => 0,
    ], $overrides));
}

it('persiste un widget con casts correctos', function () {
    $u = makeUserW(1);
    $d = makeDashboardW($u);

    $w = makeWidget($d, [
        'position' => ['x' => 2, 'y' => 1, 'w' => 6, 'h' => 4],
        'source'   => ['tool' => 'list_invoices', 'args' => ['period' => 'q1']],
    ]);

    $reloaded = DashboardWidget::find($w->id);

    expect($reloaded->position)->toBe(['x' => 2, 'y' => 1, 'w' => 6, 'h' => 4]);
    expect($reloaded->source)->toBe(['tool' => 'list_invoices', 'args' => ['period' => 'q1']]);
    expect($reloaded->refresh_policy)->toBe(WidgetRefreshPolicy::OnOpen);
    expect($reloaded->last_refresh_status)->toBe(WidgetRefreshStatus::Fresh);
    expect($reloaded->last_refreshed_at)->toBeInstanceOf(Carbon::class);
});

it('enums roundtrip a través de los casts', function () {
    $u = makeUserW(1);
    $d = makeDashboardW($u);

    $w = makeWidget($d, [
        'refresh_policy'      => WidgetRefreshPolicy::Manual,
        'last_refresh_status' => WidgetRefreshStatus::Unauthorized,
        'last_refresh_error'  => ['category' => 'auth', 'message' => 'permission revoked', 'captured_at' => '2026-05-13T12:00:00Z'],
    ]);

    $reloaded = DashboardWidget::find($w->id);

    expect($reloaded->refresh_policy)->toBe(WidgetRefreshPolicy::Manual);
    expect($reloaded->last_refresh_status)->toBe(WidgetRefreshStatus::Unauthorized);
    expect($reloaded->last_refresh_error)->toBe([
        'category'    => 'auth',
        'message'     => 'permission revoked',
        'captured_at' => '2026-05-13T12:00:00Z',
    ]);
});

it('relación belongsTo Dashboard', function () {
    $u = makeUserW(1);
    $d = makeDashboardW($u);
    $w = makeWidget($d);

    expect($w->dashboard->is($d))->toBeTrue();
});

it('scope staleAfter incluye nunca-refrescados y los anteriores al threshold', function () {
    $u = makeUserW(1);
    $d = makeDashboardW($u);

    $fresh   = makeWidget($d, ['last_refreshed_at' => now()]);
    $stale   = makeWidget($d, ['last_refreshed_at' => now()->subHour()]);
    $unkn    = makeWidget($d, ['last_refreshed_at' => null]);

    $threshold = now()->subMinutes(5);
    $stales = DashboardWidget::staleAfter($threshold)->get();

    expect($stales->pluck('id')->all())->toEqualCanonicalizing([$stale->id, $unkn->id]);
    expect($stales->contains('id', $fresh->id))->toBeFalse();
});

it('soft-delete excluye widgets en queries por defecto', function () {
    $u = makeUserW(1);
    $d = makeDashboardW($u);

    $w = makeWidget($d);
    $w->delete();

    expect(DashboardWidget::count())->toBe(0);
    expect(DashboardWidget::withTrashed()->count())->toBe(1);
});

it('FK cascade del Dashboard borra widgets hard-deletados', function () {
    // SQLite no aplica FK constraints por defecto; activamos PRAGMA para
    // este test concreto. Los hosts MySQL/Postgres lo aplican siempre.
    DB::statement('PRAGMA foreign_keys = ON');

    $u = makeUserW(1);
    $d = makeDashboardW($u);

    makeWidget($d);
    makeWidget($d, ['source_signature' => str_repeat('b', 64)]);

    $d->forceDelete();

    expect(DashboardWidget::withTrashed()->count())->toBe(0);
})->skip(
    fn () => DB::connection()->getDriverName() !== 'sqlite',
    'Test diseñado para validar la migración en la connection sqlite del Testbench.'
);

it('source_signature null permitido cuando el block no procede de tool', function () {
    $u = makeUserW(1);
    $d = makeDashboardW($u);

    $w = makeWidget($d, [
        'source'           => null,
        'source_signature' => null,
    ]);

    expect($w->fresh()->source)->toBeNull();
    expect($w->fresh()->source_signature)->toBeNull();
});
