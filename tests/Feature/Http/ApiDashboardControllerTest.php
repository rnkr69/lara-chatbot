<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Cache;
use Rnkr69\LaraChatbot\Models\Dashboard;
use Rnkr69\LaraChatbot\Models\DashboardWidget;
use Rnkr69\LaraChatbot\Models\WidgetRefreshPolicy;
use Rnkr69\LaraChatbot\Models\WidgetRefreshStatus;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;

/**
 * E4 — JSON CRUD `/chatbot/dashboards*`.
 *
 * Covers:
 *   - index: pagination + widget count + forUser scope
 *   - store: server-side slug derivation + max_dashboards_per_user cap
 *   - show: inline widgets + 404 for foreign / soft-deleted
 *   - update: rename re-derives slug, setting is_default auto-demotes the rest
 *   - destroy: soft-delete + auto-promote of the next one if it was default
 *
 * 404-not-403 policy on every operation over foreign slugs.
 */

beforeEach(function () {
    $this->artisan('migrate')->run();
    $this->withoutMiddleware(VerifyCsrfToken::class);
    Cache::flush();
});

function adcMakeUser(int $id = 1, string $name = 'Tester'): TestUser
{
    $user = new TestUser(['id' => $id, 'name' => $name]);
    $user->setRawAttributes(['id' => $id, 'name' => $name], sync: true);

    return $user;
}

function adcMakeDashboard(TestUser $user, array $overrides = []): Dashboard
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

function adcMakeWidget(Dashboard $d, array $overrides = []): DashboardWidget
{
    return DashboardWidget::create(array_merge([
        'dashboard_id'        => $d->id,
        'position'            => ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
        'block_type'          => 'table',
        'title'               => null,
        'snapshot'            => ['data' => ['rows' => []], 'captured_at' => '2026-05-13T00:00:00Z', 'byte_size' => 18],
        'source'              => ['tool' => 'echo_tool', 'args' => []],
        'source_signature'    => str_repeat('a', 64),
        'refresh_policy'      => WidgetRefreshPolicy::OnOpen,
        'last_refreshed_at'   => null,
        'last_refresh_status' => WidgetRefreshStatus::Fresh,
        'last_refresh_error'  => null,
        'order_index'         => 0,
    ], $overrides));
}

// ──────────────────────────────────────────────────────────────────────────
// index
// ──────────────────────────────────────────────────────────────────────────

it('returns only the authenticated user dashboards in index', function () {
    $self    = adcMakeUser(1, 'Alice');
    $foreign = adcMakeUser(99, 'Bob');

    adcMakeDashboard($self, ['slug' => 'mine-a', 'name' => 'Mine A']);
    adcMakeDashboard($self, ['slug' => 'mine-b', 'name' => 'Mine B']);
    adcMakeDashboard($foreign, ['slug' => 'theirs', 'name' => 'Theirs']);

    $response = $this->actingAs($self, 'web')->getJson('/chatbot/dashboards');

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toHaveCount(2)
        ->and($names)->toContain('Mine A')
        ->and($names)->toContain('Mine B')
        ->and($names)->not->toContain('Theirs');
});

it('exposes widget_count in the index payload', function () {
    $u = adcMakeUser();
    $a = adcMakeDashboard($u, ['slug' => 'a']);
    adcMakeDashboard($u, ['slug' => 'b']);
    adcMakeWidget($a);
    adcMakeWidget($a);

    $response = $this->actingAs($u, 'web')->getJson('/chatbot/dashboards');

    $payload = collect($response->json('data'))->keyBy('slug');
    expect((int) $payload['a']['widget_count'])->toBe(2)
        ->and((int) $payload['b']['widget_count'])->toBe(0);
});

it('places the is_default dashboard first in index', function () {
    $u = adcMakeUser();
    adcMakeDashboard($u, ['slug' => 'analytics']);
    adcMakeDashboard($u, ['slug' => 'ops', 'is_default' => true]);
    adcMakeDashboard($u, ['slug' => 'reports']);

    $response = $this->actingAs($u, 'web')->getJson('/chatbot/dashboards');

    $slugs = collect($response->json('data'))->pluck('slug')->all();
    expect($slugs[0])->toBe('ops');
});

it('excludes soft-deleted dashboards from index', function () {
    $u = adcMakeUser();
    adcMakeDashboard($u, ['slug' => 'live']);
    $gone = adcMakeDashboard($u, ['slug' => 'gone']);
    $gone->delete();

    $response = $this->actingAs($u, 'web')->getJson('/chatbot/dashboards');

    $slugs = collect($response->json('data'))->pluck('slug')->all();
    expect($slugs)->toBe(['live']);
});

// ──────────────────────────────────────────────────────────────────────────
// store
// ──────────────────────────────────────────────────────────────────────────

it('creates a dashboard with server-derived slug from the name', function () {
    $u = adcMakeUser();

    $response = $this->actingAs($u, 'web')->postJson('/chatbot/dashboards', [
        'name' => 'My ACME Operations',
    ]);

    $response->assertStatus(201);
    expect($response->json('data.slug'))->toBe('my-acme-operations')
        ->and($response->json('data.name'))->toBe('My ACME Operations')
        // v2.1 (#10) — the user's FIRST dashboard is auto-promoted to default
        // by the model `saving` hook, so the "exactly one is_default per user"
        // invariant holds even when the client doesn't pass is_default.
        ->and($response->json('data.is_default'))->toBeTrue();
});

it('auto-promotes only the first dashboard; later ones default to false (#10)', function () {
    $u = adcMakeUser();

    $first = $this->actingAs($u, 'web')->postJson('/chatbot/dashboards', ['name' => 'First']);
    $second = $this->actingAs($u, 'web')->postJson('/chatbot/dashboards', ['name' => 'Second']);

    expect($first->json('data.is_default'))->toBeTrue()
        ->and($second->json('data.is_default'))->toBeFalse();
});

it('appends a numeric suffix when a slug collides within the user scope', function () {
    $u = adcMakeUser();
    adcMakeDashboard($u, ['name' => 'Operations', 'slug' => 'operations']);
    adcMakeDashboard($u, ['name' => 'Operations', 'slug' => 'operations-2']);

    $response = $this->actingAs($u, 'web')->postJson('/chatbot/dashboards', [
        'name' => 'Operations',
    ]);

    $response->assertStatus(201);
    expect($response->json('data.slug'))->toBe('operations-3');
});

it('skips slugs occupied by soft-deleted dashboards on store (#21)', function () {
    $u = adcMakeUser();
    // A soft-deleted dashboard still occupies its (user, slug) tuple in the
    // DB UNIQUE constraint — `deleted_at` is not part of the index.
    $trashed = adcMakeDashboard($u, ['slug' => 'operations', 'name' => 'Operations']);
    $trashed->delete();

    $response = $this->actingAs($u, 'web')->postJson('/chatbot/dashboards', [
        'name' => 'Operations',
    ]);

    // Before #21: 500 `Duplicate entry` — `slugExists()` did not see the
    // soft-deleted row and proposed `operations`, already taken by the constraint.
    $response->assertStatus(201);
    expect($response->json('data.slug'))->toBe('operations-2');
});

it('does not collide across users (slugs are scoped per user)', function () {
    $alice = adcMakeUser(1);
    $bob   = adcMakeUser(2);

    adcMakeDashboard($alice, ['name' => 'Operations', 'slug' => 'operations']);

    $response = $this->actingAs($bob, 'web')->postJson('/chatbot/dashboards', [
        'name' => 'Operations',
    ]);

    $response->assertStatus(201);
    // Bob can use the canonical slug — alice's collision doesn't affect him.
    expect($response->json('data.slug'))->toBe('operations');
});

it('falls back to "dashboard" base when the name has only symbols', function () {
    $u = adcMakeUser();

    $response = $this->actingAs($u, 'web')->postJson('/chatbot/dashboards', [
        'name' => '!!! ###',
    ]);

    $response->assertStatus(201);
    expect($response->json('data.slug'))->toBe('dashboard');
});

it('persists the is_default flag and auto-demotes others via the model hook', function () {
    $u = adcMakeUser();
    $first = adcMakeDashboard($u, ['slug' => 'first', 'is_default' => true]);

    $response = $this->actingAs($u, 'web')->postJson('/chatbot/dashboards', [
        'name'       => 'New default',
        'is_default' => true,
    ]);

    $response->assertStatus(201);
    expect($first->fresh()->is_default)->toBeFalse();
    expect(Dashboard::find($response->json('data.id'))->is_default)->toBeTrue();
});

it('rejects creating a dashboard above max_dashboards_per_user with 422', function () {
    config()->set('chatbot.dashboard.max_dashboards_per_user', 2);

    $u = adcMakeUser();
    adcMakeDashboard($u, ['slug' => 'a']);
    adcMakeDashboard($u, ['slug' => 'b']);

    $response = $this->actingAs($u, 'web')->postJson('/chatbot/dashboards', [
        'name' => 'Too many',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['name']);
});

it('returns 422 when name is missing or empty on store', function () {
    $u = adcMakeUser();

    $response = $this->actingAs($u, 'web')->postJson('/chatbot/dashboards', [
        'name' => '',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['name']);
});

it('returns 422 when name exceeds 120 chars on store', function () {
    $u = adcMakeUser();

    $response = $this->actingAs($u, 'web')->postJson('/chatbot/dashboards', [
        'name' => str_repeat('a', 121),
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['name']);
});

it('rejects unauthenticated requests on index/store via the auth middleware', function () {
    expect($this->getJson('/chatbot/dashboards')->status())->toBeIn([401, 302, 419, 403]);
    expect($this->postJson('/chatbot/dashboards', ['name' => 'x'])->status())->toBeIn([401, 302, 419, 403]);
});

// ──────────────────────────────────────────────────────────────────────────
// show
// ──────────────────────────────────────────────────────────────────────────

it('returns the dashboard with widgets inline', function () {
    $u = adcMakeUser();
    $d = adcMakeDashboard($u, ['slug' => 'panel']);
    adcMakeWidget($d, ['order_index' => 1, 'block_type' => 'kpi']);
    adcMakeWidget($d, ['order_index' => 2, 'block_type' => 'table']);

    $response = $this->actingAs($u, 'web')->getJson('/chatbot/dashboards/panel');

    $response->assertOk();
    expect($response->json('data.slug'))->toBe('panel');

    $widgets = $response->json('data.widgets');
    expect($widgets)->toHaveCount(2);
    $types = collect($widgets)->pluck('block_type')->all();
    expect($types)->toBe(['kpi', 'table']); // order_index asc
});

it('orders widgets by order_index, then id', function () {
    $u = adcMakeUser();
    $d = adcMakeDashboard($u, ['slug' => 'panel']);
    $w1 = adcMakeWidget($d, ['order_index' => 5, 'block_type' => 'a']);
    $w2 = adcMakeWidget($d, ['order_index' => 1, 'block_type' => 'b']);
    $w3 = adcMakeWidget($d, ['order_index' => 5, 'block_type' => 'c']);

    $response = $this->actingAs($u, 'web')->getJson('/chatbot/dashboards/panel');

    $ids = collect($response->json('data.widgets'))->pluck('id')->all();
    expect($ids)->toBe([$w2->id, $w1->id, $w3->id]);
});

it('returns 404 when showing a dashboard owned by another user', function () {
    $self    = adcMakeUser(1);
    $foreign = adcMakeUser(99);
    adcMakeDashboard($foreign, ['slug' => 'theirs']);

    $response = $this->actingAs($self, 'web')->getJson('/chatbot/dashboards/theirs');

    $response->assertStatus(404);
});

it('returns 404 when showing a soft-deleted dashboard', function () {
    $u = adcMakeUser();
    $d = adcMakeDashboard($u, ['slug' => 'trashed']);
    $d->delete();

    $response = $this->actingAs($u, 'web')->getJson('/chatbot/dashboards/trashed');

    $response->assertStatus(404);
});

it('returns 404 when showing a non-existent slug', function () {
    $u = adcMakeUser();

    $response = $this->actingAs($u, 'web')->getJson('/chatbot/dashboards/nope');

    $response->assertStatus(404);
});

// ──────────────────────────────────────────────────────────────────────────
// update (PATCH)
// ──────────────────────────────────────────────────────────────────────────

it('renames a dashboard and re-derives the slug', function () {
    $u = adcMakeUser();
    $d = adcMakeDashboard($u, ['slug' => 'old-name', 'name' => 'Old name']);

    $response = $this->actingAs($u, 'web')->patchJson('/chatbot/dashboards/old-name', [
        'name' => 'Brand New Name',
    ]);

    $response->assertOk();
    expect($response->json('data.slug'))->toBe('brand-new-name')
        ->and($response->json('data.name'))->toBe('Brand New Name');
    expect($d->fresh()->slug)->toBe('brand-new-name');
});

it('keeps the same slug when renaming yields a colliding slug (suffix applied)', function () {
    $u = adcMakeUser();
    adcMakeDashboard($u, ['slug' => 'analytics', 'name' => 'Analytics']);
    $renaming = adcMakeDashboard($u, ['slug' => 'ops', 'name' => 'Ops']);

    $response = $this->actingAs($u, 'web')->patchJson('/chatbot/dashboards/ops', [
        'name' => 'Analytics',
    ]);

    $response->assertOk();
    // The new slug must NOT be `analytics` (in use by another dashboard of
    // the same user): a suffix is applied.
    expect($response->json('data.slug'))->toBe('analytics-2');
});

it('skips slugs occupied by soft-deleted dashboards on rename (#21)', function () {
    $u = adcMakeUser();
    // Soft-deleted slug that the UNIQUE constraint still counts.
    $trashed = adcMakeDashboard($u, ['slug' => 'operations-qa', 'name' => 'Operations QA']);
    $trashed->delete();
    adcMakeDashboard($u, ['slug' => 'operations', 'name' => 'Operations']);

    $response = $this->actingAs($u, 'web')->patchJson('/chatbot/dashboards/operations', [
        'name' => 'Operations QA',
    ]);

    // Before #21: 500 `Duplicate entry` when renaming against a slug
    // occupied by a soft-deleted dashboard.
    $response->assertOk();
    expect($response->json('data.slug'))->toBe('operations-qa-2');
});

it('keeps the existing slug when renaming with the same effective slug (excludeId)', function () {
    $u = adcMakeUser();
    $d = adcMakeDashboard($u, ['slug' => 'ops', 'name' => 'Ops']);

    $response = $this->actingAs($u, 'web')->patchJson('/chatbot/dashboards/ops', [
        'name' => 'Ops', // same name → same slug, but excludeId skips self
    ]);

    $response->assertOk();
    expect($response->json('data.slug'))->toBe('ops');
});

it('sets is_default and auto-demotes others through the model hook', function () {
    $u = adcMakeUser();
    $a = adcMakeDashboard($u, ['slug' => 'a', 'is_default' => true]);
    $b = adcMakeDashboard($u, ['slug' => 'b', 'is_default' => false]);

    $response = $this->actingAs($u, 'web')->patchJson('/chatbot/dashboards/b', [
        'is_default' => true,
    ]);

    $response->assertOk();
    expect($a->fresh()->is_default)->toBeFalse();
    expect($b->fresh()->is_default)->toBeTrue();
});

it('updates metadata replacing the whole object', function () {
    $u = adcMakeUser();
    $d = adcMakeDashboard($u, ['slug' => 'ops', 'metadata' => ['theme' => 'dark']]);

    $response = $this->actingAs($u, 'web')->patchJson('/chatbot/dashboards/ops', [
        'metadata' => ['theme' => 'light', 'accent' => '#0af'],
    ]);

    $response->assertOk();
    expect($d->fresh()->metadata)->toBe(['theme' => 'light', 'accent' => '#0af']);
});

it('returns 404 when patching a foreign dashboard', function () {
    $self    = adcMakeUser(1);
    $foreign = adcMakeUser(99);
    adcMakeDashboard($foreign, ['slug' => 'theirs']);

    $response = $this->actingAs($self, 'web')->patchJson('/chatbot/dashboards/theirs', [
        'name' => 'Hijacked',
    ]);

    $response->assertStatus(404);
});

// ──────────────────────────────────────────────────────────────────────────
// destroy
// ──────────────────────────────────────────────────────────────────────────

it('soft-deletes the dashboard and responds 204', function () {
    $u = adcMakeUser();
    $d = adcMakeDashboard($u, ['slug' => 'gone']);

    $response = $this->actingAs($u, 'web')->deleteJson('/chatbot/dashboards/gone');

    $response->assertStatus(204);
    expect($response->getContent())->toBe('');

    $row = Dashboard::withTrashed()->find($d->id);
    expect($row)->not->toBeNull()->and($row->deleted_at)->not->toBeNull();
    expect(Dashboard::find($d->id))->toBeNull();
});

it('auto-promotes the most recent dashboard to default when destroying the current default', function () {
    $u = adcMakeUser();
    $oldDefault = adcMakeDashboard($u, ['slug' => 'old', 'name' => 'Old', 'is_default' => true]);
    // Create another dashboard after the default; more recent updated_at → should be promoted.
    $newer = adcMakeDashboard($u, ['slug' => 'newer', 'name' => 'Newer']);

    $this->actingAs($u, 'web')->deleteJson('/chatbot/dashboards/old')->assertStatus(204);

    expect($newer->fresh()->is_default)->toBeTrue();
});

it('does not promote anyone when destroying a non-default dashboard', function () {
    $u = adcMakeUser();
    $default = adcMakeDashboard($u, ['slug' => 'default', 'is_default' => true]);
    adcMakeDashboard($u, ['slug' => 'extra']);

    $this->actingAs($u, 'web')->deleteJson('/chatbot/dashboards/extra')->assertStatus(204);

    expect($default->fresh()->is_default)->toBeTrue();
});

it('leaves the user with zero dashboards when destroying the only default', function () {
    $u = adcMakeUser();
    $only = adcMakeDashboard($u, ['slug' => 'only', 'is_default' => true]);

    $this->actingAs($u, 'web')->deleteJson('/chatbot/dashboards/only')->assertStatus(204);

    expect(Dashboard::query()->forUser($u)->count())->toBe(0);
});

it('returns 404 when destroying a foreign dashboard', function () {
    $self    = adcMakeUser(1);
    $foreign = adcMakeUser(99);
    $theirs  = adcMakeDashboard($foreign, ['slug' => 'theirs']);

    $response = $this->actingAs($self, 'web')->deleteJson('/chatbot/dashboards/theirs');

    $response->assertStatus(404);
    expect(Dashboard::find($theirs->id))->not->toBeNull();
});
