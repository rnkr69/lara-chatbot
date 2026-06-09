<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Models\Dashboard;
use Rnkr69\LaraChatbot\Models\DashboardWidget;
use Rnkr69\LaraChatbot\Models\WidgetRefreshPolicy;
use Rnkr69\LaraChatbot\Models\WidgetRefreshStatus;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;
use Rnkr69\LaraChatbot\Tools\Backend\DeleteDashboardTool;
use Rnkr69\LaraChatbot\Tools\Backend\EditDashboardTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;

/**
 * v2.2 / PR-B — `EditDashboardTool` y `DeleteDashboardTool`. Cubre
 * rename + slug regen, set_default + auto-demote (model hook),
 * auto-promote-next-default tras delete, would_create_orphan_default,
 * y política 404-no-403 cross-user.
 */

beforeEach(function () {
    $this->artisan('migrate')->run();
});

function edtMakeUser(int $id = 1): TestUser
{
    $u = new TestUser(['id' => $id]);
    $u->setRawAttributes(['id' => $id], sync: true);

    return $u;
}

function edtMakeDashboard(TestUser $u, string $slug, string $name = 'Panel', bool $isDefault = false): Dashboard
{
    return Dashboard::create([
        'user_type'      => $u->getMorphClass(),
        'user_id'        => $u->getKey(),
        'name'           => $name,
        'slug'           => $slug,
        'is_default'     => $isDefault,
        'layout_version' => 1,
    ]);
}

// ──────────────────────────────────────────────────────────────────────────
// EditDashboardTool
// ──────────────────────────────────────────────────────────────────────────

it('renames a dashboard and regenerates the slug', function () {
    $u = edtMakeUser();
    $d = edtMakeDashboard($u, slug: 'old-name', name: 'Old name');

    $tool = app(EditDashboardTool::class);
    $result = $tool->execute([
        'dashboard_slug' => 'old-name',
        'name'           => 'New name',
    ], new ToolContext(user: $u));

    expect($result->isOk())->toBeTrue();
    expect($result->data['new_slug'])->toBe('new-name');
    $d->refresh();
    expect($d->name)->toBe('New name');
    expect($d->slug)->toBe('new-name');
});

it('sets a dashboard as default, auto-demoting the previous default via model hook', function () {
    $u = edtMakeUser();
    $current = edtMakeDashboard($u, slug: 'current', name: 'Current', isDefault: true);
    $other = edtMakeDashboard($u, slug: 'other', name: 'Other', isDefault: false);

    $tool = app(EditDashboardTool::class);
    $result = $tool->execute([
        'dashboard_slug' => 'other',
        'is_default'     => true,
    ], new ToolContext(user: $u));

    expect($result->isOk())->toBeTrue();
    $current->refresh();
    $other->refresh();
    expect($other->is_default)->toBeTrue();
    expect($current->is_default)->toBeFalse();
});

it('returns dashboard_not_found when slug does not match', function () {
    $u = edtMakeUser();

    $tool = app(EditDashboardTool::class);
    $result = $tool->execute([
        'dashboard_slug' => 'ghost',
        'name'           => 'X',
    ], new ToolContext(user: $u));

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('dashboard_not_found');
});

it('returns dashboard_not_found cross-user (404-no-403)', function () {
    $u = edtMakeUser(1);
    $other = edtMakeUser(2);
    edtMakeDashboard($other, slug: 'theirs');

    $tool = app(EditDashboardTool::class);
    $result = $tool->execute([
        'dashboard_slug' => 'theirs',
        'name'           => 'X',
    ], new ToolContext(user: $u));

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('dashboard_not_found');
});

it('returns nothing_to_change when only dashboard_slug is sent', function () {
    $u = edtMakeUser();
    edtMakeDashboard($u, slug: 'p');

    $tool = app(EditDashboardTool::class);
    $result = $tool->execute([
        'dashboard_slug' => 'p',
    ], new ToolContext(user: $u));

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('nothing_to_change');
});

it('returns validation when name is empty', function () {
    $u = edtMakeUser();
    edtMakeDashboard($u, slug: 'p');

    $tool = app(EditDashboardTool::class);
    $result = $tool->execute([
        'dashboard_slug' => 'p',
        'name'           => '',
    ], new ToolContext(user: $u));

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('validation');
});

it('returns validation when name exceeds 120 chars', function () {
    $u = edtMakeUser();
    edtMakeDashboard($u, slug: 'p');

    $tool = app(EditDashboardTool::class);
    $result = $tool->execute([
        'dashboard_slug' => 'p',
        'name'           => str_repeat('x', 121),
    ], new ToolContext(user: $u));

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('validation');
});

it('does not return a new_slug when rename yields the same slug', function () {
    $u = edtMakeUser();
    edtMakeDashboard($u, slug: 'same-name', name: 'Same name');

    $tool = app(EditDashboardTool::class);
    $result = $tool->execute([
        'dashboard_slug' => 'same-name',
        'name'           => 'Same name', // identical
    ], new ToolContext(user: $u));

    expect($result->isOk())->toBeTrue();
    expect($result->data)->not->toHaveKey('new_slug');
});

it('stamps meta.side_effects on rename with new_slug + new_name', function () {
    $u = edtMakeUser();
    edtMakeDashboard($u, slug: 'old', name: 'Old');

    $tool = app(EditDashboardTool::class);
    $result = $tool->execute([
        'dashboard_slug' => 'old',
        'name'           => 'Fresh',
    ], new ToolContext(user: $u));

    expect($result->isOk())->toBeTrue();
    $meta = $result->blocks[0]['meta'] ?? null;
    expect($meta)->toBeArray();
    expect($meta['side_effects']['type'])->toBe('dashboard_updated');
    expect($meta['side_effects']['dashboard_slug'])->toBe('old');
    expect($meta['side_effects']['new_slug'])->toBe('fresh');
    expect($meta['side_effects']['new_name'])->toBe('Fresh');
    expect($meta['side_effects']['changes'])->toContain('name');
});

it('stamps meta.side_effects on set_default WITHOUT new_slug', function () {
    $u = edtMakeUser();
    edtMakeDashboard($u, slug: 'a', isDefault: true);
    edtMakeDashboard($u, slug: 'b', isDefault: false);

    $tool = app(EditDashboardTool::class);
    $result = $tool->execute([
        'dashboard_slug' => 'b',
        'is_default'     => true,
    ], new ToolContext(user: $u));

    expect($result->isOk())->toBeTrue();
    $meta = $result->blocks[0]['meta'] ?? null;
    expect($meta)->toBeArray();
    expect($meta['side_effects'])->not->toHaveKey('new_slug');
    expect($meta['side_effects']['changes'])->toContain('is_default');
});

// ──────────────────────────────────────────────────────────────────────────
// DeleteDashboardTool
// ──────────────────────────────────────────────────────────────────────────

it('soft-deletes a non-default dashboard without promoting anything', function () {
    $u = edtMakeUser();
    $default = edtMakeDashboard($u, slug: 'default', name: 'Default', isDefault: true);
    $extra = edtMakeDashboard($u, slug: 'extra', name: 'Extra');

    $tool = app(DeleteDashboardTool::class);
    $result = $tool->execute([
        'dashboard_slug' => 'extra',
    ], new ToolContext(user: $u));

    expect($result->isOk())->toBeTrue();
    expect($result->data['was_default'])->toBeFalse();
    expect($result->data)->not->toHaveKey('promoted_slug');

    expect(Dashboard::query()->where('id', $extra->id)->count())->toBe(0);
    expect(Dashboard::withTrashed()->where('id', $extra->id)->count())->toBe(1);
    $default->refresh();
    expect($default->is_default)->toBeTrue();
});

it('auto-promotes the next-most-recent dashboard when deleting the default', function () {
    $u = edtMakeUser();
    $default = edtMakeDashboard($u, slug: 'default', name: 'Default', isDefault: true);
    // Touch updated_at so $other comes back as "next most recent" cleanly.
    $other = edtMakeDashboard($u, slug: 'other', name: 'Other');

    $tool = app(DeleteDashboardTool::class);
    $result = $tool->execute([
        'dashboard_slug' => 'default',
    ], new ToolContext(user: $u));

    expect($result->isOk())->toBeTrue();
    expect($result->data['was_default'])->toBeTrue();
    expect($result->data['promoted_slug'])->toBe('other');

    $other->refresh();
    expect($other->is_default)->toBeTrue();

    // v2.2.1 (PR-B) — meta.side_effects carries promoted_slug so the
    // dashboard bundle can jump to it without F5.
    $meta = $result->blocks[0]['meta'] ?? null;
    expect($meta)->toBeArray();
    expect($meta['side_effects'])->toEqual([
        'type'           => 'dashboard_deleted',
        'dashboard_slug' => 'default',
        'was_default'    => true,
        'promoted_slug'  => 'other',
    ]);
});

it('stamps meta.side_effects on non-default delete WITHOUT promoted_slug', function () {
    $u = edtMakeUser();
    edtMakeDashboard($u, slug: 'default', isDefault: true);
    edtMakeDashboard($u, slug: 'extra');

    $tool = app(DeleteDashboardTool::class);
    $result = $tool->execute([
        'dashboard_slug' => 'extra',
    ], new ToolContext(user: $u));

    expect($result->isOk())->toBeTrue();
    $meta = $result->blocks[0]['meta'] ?? null;
    expect($meta)->toBeArray();
    expect($meta['side_effects'])->toEqual([
        'type'           => 'dashboard_deleted',
        'dashboard_slug' => 'extra',
        'was_default'    => false,
    ]);
});

it('refuses to delete the only dashboard of the user (would_create_orphan_default)', function () {
    $u = edtMakeUser();
    edtMakeDashboard($u, slug: 'only');

    $tool = app(DeleteDashboardTool::class);
    $result = $tool->execute([
        'dashboard_slug' => 'only',
    ], new ToolContext(user: $u));

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('would_create_orphan_default');

    // Untouched.
    expect(Dashboard::query()->where('slug', 'only')->count())->toBe(1);
});

it('returns dashboard_not_found cross-user delete attempt', function () {
    $u = edtMakeUser(1);
    $other = edtMakeUser(2);
    edtMakeDashboard($other, slug: 'theirs');
    // Give other a second dashboard so the would_create_orphan_default
    // guard doesn't fire — we want to assert 404 vs orphan.
    edtMakeDashboard($other, slug: 'their-second');

    $tool = app(DeleteDashboardTool::class);
    $result = $tool->execute([
        'dashboard_slug' => 'theirs',
    ], new ToolContext(user: $u));

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('dashboard_not_found');
});

it('cascades soft-delete to widgets implicitly (widgets still exist but their dashboard is trashed)', function () {
    $u = edtMakeUser();
    $extra = edtMakeDashboard($u, slug: 'extra');
    edtMakeDashboard($u, slug: 'keep'); // user keeps at least one

    DashboardWidget::create([
        'dashboard_id'        => $extra->id,
        'position'            => ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
        'block_type'          => 'kpi',
        'snapshot'            => ['data' => []],
        'source'              => ['tool' => 'echo', 'args' => []],
        'source_signature'    => 'sig',
        'refresh_policy'      => WidgetRefreshPolicy::OnOpen,
        'last_refresh_status' => WidgetRefreshStatus::Fresh,
        'order_index'         => 0,
    ]);

    $tool = app(DeleteDashboardTool::class);
    $tool->execute(['dashboard_slug' => 'extra'], new ToolContext(user: $u));

    expect(Dashboard::query()->where('slug', 'extra')->count())->toBe(0);
    // Widget row is not soft-deleted by our flow (paridad con controller).
    // It just orphans against a soft-deleted dashboard. The prune command
    // (`chatbot:dashboards:prune --purge-soft-deleted`) eventually cleans
    // both sides.
    expect(DashboardWidget::query()->where('dashboard_id', $extra->id)->count())->toBe(1);
});
