<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Rnkr69\LaraChatbot\Models\Dashboard;
use Rnkr69\LaraChatbot\Models\DashboardWidget;
use Rnkr69\LaraChatbot\Models\WidgetRefreshPolicy;
use Rnkr69\LaraChatbot\Models\WidgetRefreshStatus;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;

beforeEach(function () {
    $this->artisan('migrate')->run();
});

function pruneMakeUser(int $id = 1): TestUser
{
    $u = new TestUser(['id' => $id, 'name' => 'Tester']);
    $u->setRawAttributes(['id' => $id, 'name' => 'Tester'], sync: true);

    return $u;
}

function pruneMakeDashboard(TestUser $u, string $slug = 'mi-panel'): Dashboard
{
    return Dashboard::create([
        'user_type'      => $u->getMorphClass(),
        'user_id'        => $u->getKey(),
        'name'           => 'Mi panel ' . $slug,
        'slug'           => $slug,
        'is_default'     => false,
        'layout_version' => 1,
        'metadata'       => null,
    ]);
}

function pruneMakeWidget(Dashboard $d, array $overrides = []): DashboardWidget
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

it('rejects the run when no mode flag is provided', function () {
    $this->artisan('chatbot:dashboards:prune')
        ->expectsOutputToContain('Specify at least one of')
        ->assertExitCode(2); // self::INVALID
});

it('lists source-missing widgets in dry-run without deleting them', function () {
    $u   = pruneMakeUser();
    $d   = pruneMakeDashboard($u);
    $old = pruneMakeWidget($d, [
        'last_refresh_status' => WidgetRefreshStatus::SourceMissing,
        'last_refreshed_at'   => now()->subDays(40),
    ]);
    $recent = pruneMakeWidget($d, [
        'last_refresh_status' => WidgetRefreshStatus::SourceMissing,
        'last_refreshed_at'   => now()->subDays(5),
    ]);
    $fresh = pruneMakeWidget($d, [
        'last_refresh_status' => WidgetRefreshStatus::Fresh,
        'last_refreshed_at'   => now()->subDays(40),
    ]);

    $this->artisan('chatbot:dashboards:prune', ['--source-missing' => true])
        ->expectsOutputToContain('source-missing widgets')
        ->expectsOutputToContain('1 widget(s) would be soft-deleted')
        ->expectsOutputToContain('DRY-RUN')
        ->assertExitCode(0);

    expect(DashboardWidget::query()->count())->toBe(3)
        ->and(DashboardWidget::withTrashed()->whereNotNull('deleted_at')->count())->toBe(0);
});

it('actually soft-deletes source-missing widgets when --force is given', function () {
    $u   = pruneMakeUser();
    $d   = pruneMakeDashboard($u);
    $old = pruneMakeWidget($d, [
        'last_refresh_status' => WidgetRefreshStatus::SourceMissing,
        'last_refreshed_at'   => now()->subDays(40),
    ]);
    $fresh = pruneMakeWidget($d, [
        'last_refresh_status' => WidgetRefreshStatus::Fresh,
        'last_refreshed_at'   => now()->subDays(40),
    ]);

    $this->artisan('chatbot:dashboards:prune', [
        '--source-missing' => true,
        '--force'          => true,
    ])
        ->expectsOutputToContain('EXECUTED')
        ->expectsOutputToContain('1 widget(s) soft-deleted')
        ->assertExitCode(0);

    expect(DashboardWidget::query()->count())->toBe(1)
        ->and(DashboardWidget::withTrashed()->find($old->id)->trashed())->toBeTrue()
        ->and(DashboardWidget::find($fresh->id))->not->toBeNull();
});

it('honours --source-missing-days CLI override', function () {
    $u   = pruneMakeUser();
    $d   = pruneMakeDashboard($u);
    $ten = pruneMakeWidget($d, [
        'last_refresh_status' => WidgetRefreshStatus::SourceMissing,
        'last_refreshed_at'   => now()->subDays(10),
    ]);
    $forty = pruneMakeWidget($d, [
        'last_refresh_status' => WidgetRefreshStatus::SourceMissing,
        'last_refreshed_at'   => now()->subDays(40),
    ]);

    $this->artisan('chatbot:dashboards:prune', [
        '--source-missing'      => true,
        '--source-missing-days' => '7',
        '--force'               => true,
    ])
        ->expectsOutputToContain('2 widget(s) soft-deleted')
        ->assertExitCode(0);

    expect(DashboardWidget::query()->count())->toBe(0);
});

it('soft-deletes stale widgets (no recent refresh) but skips source-missing', function () {
    $u = pruneMakeUser();
    $d = pruneMakeDashboard($u);

    $staleError = pruneMakeWidget($d, [
        'last_refresh_status' => WidgetRefreshStatus::Error,
        'last_refreshed_at'   => now()->subDays(120),
    ]);
    $staleNever = pruneMakeWidget($d, [
        'last_refresh_status' => WidgetRefreshStatus::Fresh,
        'last_refreshed_at'   => null,
    ]);
    $freshOk = pruneMakeWidget($d, [
        'last_refresh_status' => WidgetRefreshStatus::Fresh,
        'last_refreshed_at'   => now()->subDays(10),
    ]);
    $sourceMissingOld = pruneMakeWidget($d, [
        'last_refresh_status' => WidgetRefreshStatus::SourceMissing,
        'last_refreshed_at'   => now()->subDays(200),
    ]);

    $this->artisan('chatbot:dashboards:prune', [
        '--stale' => true,
        '--force' => true,
    ])
        ->expectsOutputToContain('stale widgets')
        ->expectsOutputToContain('2 widget(s) soft-deleted')
        ->assertExitCode(0);

    expect(DashboardWidget::withTrashed()->find($staleError->id)->trashed())->toBeTrue()
        ->and(DashboardWidget::withTrashed()->find($staleNever->id)->trashed())->toBeTrue()
        ->and(DashboardWidget::find($freshOk->id))->not->toBeNull()
        ->and(DashboardWidget::find($sourceMissingOld->id))->not->toBeNull();
});

it('soft-deletes old empty dashboards but keeps populated and recent ones', function () {
    $u = pruneMakeUser();

    $oldEmpty = pruneMakeDashboard($u, 'old-empty');
    Dashboard::where('id', $oldEmpty->id)->update(['created_at' => now()->subDays(200)]);

    $oldWithWidget = pruneMakeDashboard($u, 'old-active');
    Dashboard::where('id', $oldWithWidget->id)->update(['created_at' => now()->subDays(200)]);
    pruneMakeWidget($oldWithWidget);

    $recentEmpty = pruneMakeDashboard($u, 'recent-empty');

    $this->artisan('chatbot:dashboards:prune', [
        '--empty-dashboards' => true,
        '--force'            => true,
    ])
        ->expectsOutputToContain('empty dashboards')
        ->expectsOutputToContain('1 dashboard(s) soft-deleted')
        ->assertExitCode(0);

    expect(Dashboard::withTrashed()->find($oldEmpty->id)->trashed())->toBeTrue()
        ->and(Dashboard::find($oldWithWidget->id))->not->toBeNull()
        ->and(Dashboard::find($recentEmpty->id))->not->toBeNull();
});

it('hard-deletes soft-deleted widgets and dashboards older than the threshold via --purge-soft-deleted', function () {
    $u = pruneMakeUser();
    $d = pruneMakeDashboard($u);

    $oldDeletedWidget = pruneMakeWidget($d);
    $oldDeletedWidget->delete();
    DashboardWidget::withTrashed()->where('id', $oldDeletedWidget->id)
        ->update(['deleted_at' => now()->subDays(40)]);

    $recentlyDeletedWidget = pruneMakeWidget($d);
    $recentlyDeletedWidget->delete();

    $oldDeletedDashboard = pruneMakeDashboard($u, 'old-deleted');
    $oldDeletedDashboard->delete();
    Dashboard::withTrashed()->where('id', $oldDeletedDashboard->id)
        ->update(['deleted_at' => now()->subDays(40)]);

    $this->artisan('chatbot:dashboards:prune', [
        '--purge-soft-deleted' => true,
        '--force'              => true,
    ])
        ->expectsOutputToContain('purge soft-deleted')
        ->expectsOutputToContain('hard-deleted')
        ->assertExitCode(0);

    expect(DashboardWidget::withTrashed()->find($oldDeletedWidget->id))->toBeNull()
        ->and(DashboardWidget::withTrashed()->find($recentlyDeletedWidget->id))->not->toBeNull()
        ->and(Dashboard::withTrashed()->find($oldDeletedDashboard->id))->toBeNull();
});

it('combines multiple modes in a single run with the same --force flag', function () {
    $u = pruneMakeUser();
    $d = pruneMakeDashboard($u);

    $sourceMissingOld = pruneMakeWidget($d, [
        'last_refresh_status' => WidgetRefreshStatus::SourceMissing,
        'last_refreshed_at'   => now()->subDays(60),
    ]);
    $staleError = pruneMakeWidget($d, [
        'last_refresh_status' => WidgetRefreshStatus::Error,
        'last_refreshed_at'   => now()->subDays(120),
    ]);
    $fresh = pruneMakeWidget($d, [
        'last_refresh_status' => WidgetRefreshStatus::Fresh,
        'last_refreshed_at'   => now()->subHour(),
    ]);

    $this->artisan('chatbot:dashboards:prune', [
        '--source-missing' => true,
        '--stale'          => true,
        '--force'          => true,
    ])
        ->expectsOutputToContain('Soft-deleted: 2')
        ->assertExitCode(0);

    expect(DashboardWidget::query()->count())->toBe(1)
        ->and(DashboardWidget::find($fresh->id))->not->toBeNull();
});

it('rejects negative or non-numeric day overrides with INVALID exit code', function () {
    $this->artisan('chatbot:dashboards:prune', [
        '--source-missing'      => true,
        '--source-missing-days' => 'abc',
    ])
        ->expectsOutputToContain('--source-missing-days must be a non-negative integer')
        ->assertExitCode(2);

    $this->artisan('chatbot:dashboards:prune', [
        '--stale'      => true,
        '--stale-days' => '-3',
    ])
        ->expectsOutputToContain('--stale-days must be a non-negative integer')
        ->assertExitCode(2);
});

it('reports nothing-to-do when the database is clean', function () {
    pruneMakeUser();

    $this->artisan('chatbot:dashboards:prune', [
        '--source-missing'    => true,
        '--stale'             => true,
        '--empty-dashboards'  => true,
        '--purge-soft-deleted'=> true,
    ])
        ->expectsOutputToContain('Nothing to delete')
        ->expectsOutputToContain('Nothing to purge')
        ->expectsOutputToContain('Soft-deleted: 0')
        ->expectsOutputToContain('Hard-deleted: 0')
        ->assertExitCode(0);
});

it('respects chatbot.dashboard.prune config defaults when no CLI override is given', function () {
    config()->set('chatbot.dashboard.prune.source_missing_days', 7);

    $u = pruneMakeUser();
    $d = pruneMakeDashboard($u);

    $tenDaysOld = pruneMakeWidget($d, [
        'last_refresh_status' => WidgetRefreshStatus::SourceMissing,
        'last_refreshed_at'   => now()->subDays(10),
    ]);

    $this->artisan('chatbot:dashboards:prune', [
        '--source-missing' => true,
        '--force'          => true,
    ])
        ->expectsOutputToContain('1 widget(s) soft-deleted')
        ->assertExitCode(0);

    expect(DashboardWidget::withTrashed()->find($tenDaysOld->id)->trashed())->toBeTrue();
});
