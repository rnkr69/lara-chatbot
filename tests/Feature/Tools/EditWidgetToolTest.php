<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Dashboard\SourceSignature;
use Rnkr69\LaraChatbot\Models\Dashboard;
use Rnkr69\LaraChatbot\Models\DashboardWidget;
use Rnkr69\LaraChatbot\Models\WidgetRefreshPolicy;
use Rnkr69\LaraChatbot\Models\WidgetRefreshStatus;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;
use Rnkr69\LaraChatbot\Tools\Backend\EditWidgetTool;
use Rnkr69\LaraChatbot\Tools\Backend\DeleteWidgetTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;

/**
 * v2.2 / PR-B — `EditWidgetTool` and `DeleteWidgetTool`.
 *
 * Covers: happy path with each subset of args (single field, multiple
 * combined), widget_not_found cross-user (404-no-403 policy), validation
 * ranges (x/y/w/h, title length, refresh_policy enum), nothing_to_change
 * when the LLM passes no optional args, and `delete_widget` with confirmed
 * soft-delete.
 */

beforeEach(function () {
    $this->artisan('migrate')->run();
});

function ewtMakeUser(int $id = 1): TestUser
{
    $user = new TestUser(['id' => $id]);
    $user->setRawAttributes(['id' => $id], sync: true);

    return $user;
}

function ewtMakeDashboard(TestUser $user, string $slug = 'panel'): Dashboard
{
    return Dashboard::create([
        'user_type'      => $user->getMorphClass(),
        'user_id'        => $user->getKey(),
        'name'           => 'Panel',
        'slug'           => $slug,
        'is_default'     => true,
        'layout_version' => 1,
    ]);
}

function ewtMakeWidget(Dashboard $dashboard, array $overrides = []): DashboardWidget
{
    return DashboardWidget::create(array_merge([
        'dashboard_id'        => $dashboard->id,
        'position'            => ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
        'block_type'          => 'kpi',
        'title'               => 'KPI widget',
        'snapshot'            => ['data' => []],
        'source'              => ['tool' => 'echo_tool', 'args' => []],
        'source_signature'    => SourceSignature::for('echo_tool', []),
        'refresh_policy'      => WidgetRefreshPolicy::OnOpen,
        'last_refreshed_at'   => null,
        'last_refresh_status' => WidgetRefreshStatus::Fresh,
        'order_index'         => 0,
    ], $overrides));
}

// ──────────────────────────────────────────────────────────────────────────
// EditWidgetTool — happy path
// ──────────────────────────────────────────────────────────────────────────

it('moves a widget when position.x is changed', function () {
    $u = ewtMakeUser();
    $d = ewtMakeDashboard($u);
    $w = ewtMakeWidget($d, ['position' => ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 3]]);

    $tool = app(EditWidgetTool::class);
    $result = $tool->execute([
        'widget_id' => $w->id,
        'position'  => ['x' => 6],
    ], new ToolContext(user: $u));

    expect($result->isOk())->toBeTrue();
    $w->refresh();
    expect($w->position['x'])->toBe(6);
    // The other dims are preserved (merge with current).
    expect($w->position['y'])->toBe(0);
    expect($w->position['w'])->toBe(4);
    expect($w->position['h'])->toBe(3);
});

it('resizes + repositions in a single invocation', function () {
    $u = ewtMakeUser();
    $d = ewtMakeDashboard($u);
    $w = ewtMakeWidget($d);

    $tool = app(EditWidgetTool::class);
    $result = $tool->execute([
        'widget_id' => $w->id,
        'position'  => ['x' => 3, 'y' => 2, 'w' => 6, 'h' => 4],
    ], new ToolContext(user: $u));

    expect($result->isOk())->toBeTrue();
    $w->refresh();
    expect($w->position)->toBe(['x' => 3, 'y' => 2, 'w' => 6, 'h' => 4]);
});

it('renames a widget', function () {
    $u = ewtMakeUser();
    $d = ewtMakeDashboard($u);
    $w = ewtMakeWidget($d, ['title' => 'Old title']);

    $tool = app(EditWidgetTool::class);
    $result = $tool->execute([
        'widget_id' => $w->id,
        'title'     => 'New title',
    ], new ToolContext(user: $u));

    expect($result->isOk())->toBeTrue();
    $w->refresh();
    expect($w->title)->toBe('New title');
});

it('changes refresh policy via enum', function () {
    $u = ewtMakeUser();
    $d = ewtMakeDashboard($u);
    $w = ewtMakeWidget($d);

    $tool = app(EditWidgetTool::class);
    $result = $tool->execute([
        'widget_id'      => $w->id,
        'refresh_policy' => 'manual',
    ], new ToolContext(user: $u));

    expect($result->isOk())->toBeTrue();
    $w->refresh();
    expect($w->refresh_policy)->toBe(WidgetRefreshPolicy::Manual);
});

it('applies multiple changes in a single invocation', function () {
    $u = ewtMakeUser();
    $d = ewtMakeDashboard($u);
    $w = ewtMakeWidget($d, ['title' => 'A', 'refresh_policy' => WidgetRefreshPolicy::OnOpen]);

    $tool = app(EditWidgetTool::class);
    $result = $tool->execute([
        'widget_id'      => $w->id,
        'position'       => ['x' => 4, 'w' => 8],
        'title'          => 'B',
        'refresh_policy' => 'never',
    ], new ToolContext(user: $u));

    expect($result->isOk())->toBeTrue();
    $w->refresh();
    expect($w->position['x'])->toBe(4);
    expect($w->position['w'])->toBe(8);
    expect($w->title)->toBe('B');
    expect($w->refresh_policy)->toBe(WidgetRefreshPolicy::Never);
});

it('stamps meta.side_effects with the touched keys on the success card', function () {
    $u = ewtMakeUser();
    $d = ewtMakeDashboard($u);
    $w = ewtMakeWidget($d, ['title' => 'Old']);

    $tool = app(EditWidgetTool::class);
    $result = $tool->execute([
        'widget_id' => $w->id,
        'title'     => 'New',
        'position'  => ['x' => 2],
    ], new ToolContext(user: $u));

    expect($result->isOk())->toBeTrue();
    $meta = $result->blocks[0]['meta'] ?? null;
    expect($meta)->toBeArray();
    expect($meta['side_effects']['type'])->toBe('widget_updated');
    expect($meta['side_effects']['dashboard_slug'])->toBe($d->slug);
    expect($meta['side_effects']['widget_id'])->toBe($w->id);
    expect($meta['side_effects']['changes'])->toEqualCanonicalizing(['title', 'position']);
});

// ──────────────────────────────────────────────────────────────────────────
// EditWidgetTool — error paths
// ──────────────────────────────────────────────────────────────────────────

it('returns widget_not_found when the id does not exist', function () {
    $u = ewtMakeUser();
    ewtMakeDashboard($u);

    $tool = app(EditWidgetTool::class);
    $result = $tool->execute([
        'widget_id' => 9999,
        'title'     => 'X',
    ], new ToolContext(user: $u));

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('widget_not_found');
});

it('returns widget_not_found when the widget belongs to another user (404-no-403)', function () {
    $u = ewtMakeUser(1);
    $other = ewtMakeUser(2);
    $otherDashboard = ewtMakeDashboard($other, slug: 'other');
    $w = ewtMakeWidget($otherDashboard);

    $tool = app(EditWidgetTool::class);
    $result = $tool->execute([
        'widget_id' => $w->id,
        'title'     => 'X',
    ], new ToolContext(user: $u));

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('widget_not_found');
});

it('returns nothing_to_change when only widget_id is sent', function () {
    $u = ewtMakeUser();
    $d = ewtMakeDashboard($u);
    $w = ewtMakeWidget($d);

    $tool = app(EditWidgetTool::class);
    $result = $tool->execute([
        'widget_id' => $w->id,
    ], new ToolContext(user: $u));

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('nothing_to_change');
});

it('returns validation when position.x is out of range', function () {
    $u = ewtMakeUser();
    $d = ewtMakeDashboard($u);
    $w = ewtMakeWidget($d);

    $tool = app(EditWidgetTool::class);
    $result = $tool->execute([
        'widget_id' => $w->id,
        'position'  => ['x' => 15],
    ], new ToolContext(user: $u));

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('validation');
});

it('returns validation when refresh_policy is invalid via JSON Schema enum', function () {
    $u = ewtMakeUser();
    $d = ewtMakeDashboard($u);
    $w = ewtMakeWidget($d);

    $tool = app(EditWidgetTool::class);
    $result = $tool->execute([
        'widget_id'      => $w->id,
        'refresh_policy' => 'bogus',
    ], new ToolContext(user: $u));

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('validation');
});

it('returns validation when title exceeds 180 chars', function () {
    $u = ewtMakeUser();
    $d = ewtMakeDashboard($u);
    $w = ewtMakeWidget($d);

    $tool = app(EditWidgetTool::class);
    $result = $tool->execute([
        'widget_id' => $w->id,
        'title'     => str_repeat('a', 181),
    ], new ToolContext(user: $u));

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('validation');
});

it('accepts title=null to clear the title', function () {
    $u = ewtMakeUser();
    $d = ewtMakeDashboard($u);
    $w = ewtMakeWidget($d, ['title' => 'Set']);

    $tool = app(EditWidgetTool::class);
    $result = $tool->execute([
        'widget_id' => $w->id,
        'title'     => null,
    ], new ToolContext(user: $u));

    expect($result->isOk())->toBeTrue();
    $w->refresh();
    expect($w->title)->toBeNull();
});

// ──────────────────────────────────────────────────────────────────────────
// DeleteWidgetTool
// ──────────────────────────────────────────────────────────────────────────

it('soft-deletes the widget', function () {
    $u = ewtMakeUser();
    $d = ewtMakeDashboard($u);
    $w = ewtMakeWidget($d);

    $tool = app(DeleteWidgetTool::class);
    $result = $tool->execute([
        'widget_id' => $w->id,
    ], new ToolContext(user: $u));

    expect($result->isOk())->toBeTrue();
    expect($result->data['widget_id'])->toBe($w->id);
    expect($result->data['dashboard_slug'])->toBe($d->slug);

    // Soft-deleted: query without trashed returns 0; with trashed returns 1.
    expect(DashboardWidget::query()->where('id', $w->id)->count())->toBe(0);
    expect(DashboardWidget::withTrashed()->where('id', $w->id)->count())->toBe(1);

    // v2.2.1 (PR-B) — meta.side_effects on the success card.
    $meta = $result->blocks[0]['meta'] ?? null;
    expect($meta)->toBeArray();
    expect($meta['side_effects'])->toEqual([
        'type'           => 'widget_deleted',
        'dashboard_slug' => $d->slug,
        'widget_id'      => $w->id,
    ]);
});

it('returns widget_not_found for cross-user delete attempt', function () {
    $u = ewtMakeUser(1);
    $other = ewtMakeUser(2);
    $otherDashboard = ewtMakeDashboard($other, slug: 'other');
    $w = ewtMakeWidget($otherDashboard);

    $tool = app(DeleteWidgetTool::class);
    $result = $tool->execute([
        'widget_id' => $w->id,
    ], new ToolContext(user: $u));

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('widget_not_found');

    // The widget remains intact.
    expect(DashboardWidget::query()->where('id', $w->id)->count())->toBe(1);
});

it('returns validation when widget_id is missing', function () {
    $u = ewtMakeUser();
    ewtMakeDashboard($u);

    $tool = app(DeleteWidgetTool::class);
    $result = $tool->execute([], new ToolContext(user: $u));

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('validation');
});
