<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Cache;
use Rnkr69\LaraChatbot\Dashboard\SourceSignature;
use Rnkr69\LaraChatbot\Models\Dashboard;
use Rnkr69\LaraChatbot\Models\DashboardWidget;
use Rnkr69\LaraChatbot\Models\WidgetRefreshPolicy;
use Rnkr69\LaraChatbot\Models\WidgetRefreshStatus;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\EchoBackendTool;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;

/**
 * E4 — JSON API + SSE bulk refresh for dashboard widgets.
 *
 * Covers:
 *   - store (pin): pinnable+Auto tool validation, source_signature,
 *     truncated snapshot, filtered page_context, widget cap, default position.
 *   - update (move/resize/retitle/refresh_policy).
 *   - single refresh (delegates to ReplayService) + rate limit.
 *   - refreshAll SSE (emits widget_refreshed per widget + done).
 *   - destroy (soft-delete unpin).
 *   - 404-not-403 policy on every cross-user op.
 */

beforeEach(function () {
    $this->artisan('migrate')->run();
    $this->withoutMiddleware(VerifyCsrfToken::class);
    Cache::flush();

    // Bulk SSE tests (same as E3): `sync` driver so that
    // Concurrency::run runs inline in the same PHP process.
    config(['concurrency.default' => 'sync']);

    app(ToolRegistry::class)->clear();
});

function adwMakeUser(int $id = 1, string $name = 'Tester'): TestUser
{
    $user = new TestUser(['id' => $id, 'name' => $name]);
    $user->setRawAttributes(['id' => $id, 'name' => $name], sync: true);

    return $user;
}

function adwMakeDashboard(TestUser $user, string $slug = 'panel'): Dashboard
{
    return Dashboard::create([
        'user_type'      => $user->getMorphClass(),
        'user_id'        => $user->getKey(),
        'name'           => 'Panel',
        'slug'           => $slug,
        'is_default'     => false,
        'layout_version' => 1,
        'metadata'       => null,
    ]);
}

function adwMakeWidget(Dashboard $d, array $overrides = []): DashboardWidget
{
    return DashboardWidget::create(array_merge([
        'dashboard_id'        => $d->id,
        'position'            => ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
        'block_type'          => 'table',
        'title'               => null,
        'snapshot'            => ['data' => ['rows' => []], 'captured_at' => '2026-05-13T00:00:00Z', 'byte_size' => 18],
        'source'              => ['tool' => 'echo_tool', 'args' => ['message' => 'hi']],
        'source_signature'    => SourceSignature::for('echo_tool', ['message' => 'hi']),
        'refresh_policy'      => WidgetRefreshPolicy::OnOpen,
        'last_refreshed_at'   => null,
        'last_refresh_status' => WidgetRefreshStatus::Fresh,
        'last_refresh_error'  => null,
        'order_index'         => 0,
    ], $overrides));
}

function adwRegisterPinnableTool(array $emitBlocks = [['type' => 'table', 'data' => ['rows' => [['id' => 1]]]]]): EchoBackendTool
{
    $tool = new EchoBackendTool();
    $tool->pinnableOverride = true;
    $tool->emitBlocks = $emitBlocks;
    app(ToolRegistry::class)->register($tool);

    return $tool;
}

// ──────────────────────────────────────────────────────────────────────────
// store (pin)
// ──────────────────────────────────────────────────────────────────────────

it('pins a new widget with the snapshot, source, and source_signature', function () {
    adwRegisterPinnableTool();
    $u = adwMakeUser();
    $d = adwMakeDashboard($u);

    $response = $this->actingAs($u, 'web')->postJson("/chatbot/dashboards/{$d->slug}/widgets", [
        'block_id'        => '00000000-0000-0000-0000-000000000001',
        'block_type'      => 'table',
        'snapshot'        => ['data' => ['rows' => [['id' => 1, 'total' => 50]]]],
        'source'          => ['tool' => 'echo_tool', 'args' => ['message' => 'hi']],
        'suggested_title' => 'Mis facturas',
    ]);

    $response->assertStatus(201);
    expect($response->json('data.block_type'))->toBe('table');
    expect($response->json('data.title'))->toBe('Mis facturas');
    expect($response->json('data.source.tool'))->toBe('echo_tool');
    expect($response->json('data.source.block_id'))->toBe('00000000-0000-0000-0000-000000000001');
    expect($response->json('data.source_signature'))->toBe(SourceSignature::for('echo_tool', ['message' => 'hi']));
    expect($response->json('data.last_refresh_status'))->toBe('fresh');
    expect($response->json('data.snapshot.data.rows.0.id'))->toBe(1);

    expect(DashboardWidget::query()->where('dashboard_id', $d->id)->count())->toBe(1);
});

it('persists block_ordinal into source when the client sends it (#27)', function () {
    // #27 — the stable half of the replay descriptor. The controller copies
    // `block_ordinal` into the persisted `source` so `ReplayService` can
    // re-select the exact pinned block of a multi-block tool.
    adwRegisterPinnableTool();
    $u = adwMakeUser();
    $d = adwMakeDashboard($u);

    $response = $this->actingAs($u, 'web')->postJson("/chatbot/dashboards/{$d->slug}/widgets", [
        'block_type'    => 'kpi',
        'block_ordinal' => 2,
        'snapshot'      => ['data' => ['label' => 'Fuel', 'value' => 12]],
        'source'        => ['tool' => 'echo_tool', 'args' => []],
    ]);

    $response->assertStatus(201);
    expect($response->json('data.source.block_ordinal'))->toBe(2);

    $widget = DashboardWidget::query()->where('dashboard_id', $d->id)->firstOrFail();
    expect($widget->source['block_ordinal'])->toBe(2);
});

it('omits block_ordinal from source when the client does not send it (v2.1.1 client back-compat) (#27)', function () {
    // A v2.1.1 bundle never sends `block_ordinal`. The widget persists
    // without the key; the replay falls back to ordinal 0.
    adwRegisterPinnableTool();
    $u = adwMakeUser();
    $d = adwMakeDashboard($u);

    $response = $this->actingAs($u, 'web')->postJson("/chatbot/dashboards/{$d->slug}/widgets", [
        'block_type' => 'table',
        'snapshot'   => ['data' => ['rows' => []]],
        'source'     => ['tool' => 'echo_tool', 'args' => []],
    ]);

    $response->assertStatus(201);

    $widget = DashboardWidget::query()->where('dashboard_id', $d->id)->firstOrFail();
    expect($widget->source)->not->toHaveKey('block_ordinal');
});

it('rejects a negative or non-integer block_ordinal with 422 (#27)', function () {
    adwRegisterPinnableTool();
    $u = adwMakeUser();
    $d = adwMakeDashboard($u);

    $this->actingAs($u, 'web')->postJson("/chatbot/dashboards/{$d->slug}/widgets", [
        'block_type'    => 'table',
        'block_ordinal' => -1,
        'snapshot'      => ['data' => ['rows' => []]],
        'source'        => ['tool' => 'echo_tool', 'args' => []],
    ])->assertStatus(422)->assertJsonValidationErrors(['block_ordinal']);

    $this->actingAs($u, 'web')->postJson("/chatbot/dashboards/{$d->slug}/widgets", [
        'block_type'    => 'table',
        'block_ordinal' => 'not-an-int',
        'snapshot'      => ['data' => ['rows' => []]],
        'source'        => ['tool' => 'echo_tool', 'args' => []],
    ])->assertStatus(422)->assertJsonValidationErrors(['block_ordinal']);
});

// v2.0 / E8 — defensive coverage: `block_type: 'kpi'` is a 3-char string and
// already passes `max:32` in PinWidgetRequest. We pin a kpi-shaped block and
// verify the request flows through the existing validation + persistence
// without per-type plumbing.
it('pins a kpi block — block_type validation accepts 3-char string and snapshot persists kpi shape', function () {
    adwRegisterPinnableTool([['type' => 'kpi', 'data' => ['label' => 'Latency', 'value' => 420]]]);
    $u = adwMakeUser();
    $d = adwMakeDashboard($u);

    $response = $this->actingAs($u, 'web')->postJson("/chatbot/dashboards/{$d->slug}/widgets", [
        'block_type' => 'kpi',
        'snapshot'   => ['data' => [
            'label'  => 'p99 latency',
            'value'  => 420,
            'unit'   => 'ms',
            'delta'  => -12,
            'format' => 'number',
        ]],
        'source' => ['tool' => 'echo_tool', 'args' => []],
    ]);

    $response->assertStatus(201);
    expect($response->json('data.block_type'))->toBe('kpi');
    expect($response->json('data.snapshot.data.label'))->toBe('p99 latency');
    expect($response->json('data.snapshot.data.value'))->toBe(420);
    expect($response->json('data.snapshot.data.unit'))->toBe('ms');
    expect($response->json('data.snapshot.data.delta'))->toBe(-12);
});

it('rejects pin when source.tool is not registered with 422', function () {
    $u = adwMakeUser();
    $d = adwMakeDashboard($u);

    $response = $this->actingAs($u, 'web')->postJson("/chatbot/dashboards/{$d->slug}/widgets", [
        'block_type' => 'table',
        'snapshot'   => ['data' => ['rows' => []]],
        'source'     => ['tool' => 'ghost_tool', 'args' => []],
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['source.tool']);
});

it('rejects pin when the tool has pinnable=false with 422', function () {
    // Default: BaseBackendTool::pinnable() = false. EchoBackendTool without override.
    app(ToolRegistry::class)->register(new EchoBackendTool());
    $u = adwMakeUser();
    $d = adwMakeDashboard($u);

    $response = $this->actingAs($u, 'web')->postJson("/chatbot/dashboards/{$d->slug}/widgets", [
        'block_type' => 'table',
        'snapshot'   => ['data' => []],
        'source'     => ['tool' => 'echo_tool', 'args' => []],
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['source.tool']);
});

it('rejects pin when the tool confirmation is not Auto with 422', function () {
    $tool = new EchoBackendTool();
    $tool->pinnableOverride = true;
    $tool->confirmationOverride = ConfirmationLevel::Confirm;
    app(ToolRegistry::class)->register($tool);

    $u = adwMakeUser();
    $d = adwMakeDashboard($u);

    $response = $this->actingAs($u, 'web')->postJson("/chatbot/dashboards/{$d->slug}/widgets", [
        'block_type' => 'table',
        'snapshot'   => ['data' => []],
        'source'     => ['tool' => 'echo_tool', 'args' => []],
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['source.tool']);
});

it('truncates the snapshot when it exceeds chatbot.dashboard.snapshot_max_bytes', function () {
    adwRegisterPinnableTool();
    config()->set('chatbot.dashboard.snapshot_max_bytes', 100);

    $u = adwMakeUser();
    $d = adwMakeDashboard($u);

    $bigRows = array_fill(0, 30, ['id' => 1, 'description' => 'lorem ipsum dolor sit amet']);

    $response = $this->actingAs($u, 'web')->postJson("/chatbot/dashboards/{$d->slug}/widgets", [
        'block_type' => 'table',
        'snapshot'   => ['data' => $bigRows],
        'source'     => ['tool' => 'echo_tool', 'args' => []],
    ]);

    $response->assertStatus(201);
    expect($response->json('data.snapshot.truncated'))->toBeTrue();
    expect($response->json('data.snapshot.data.truncated'))->toBeTrue();
    expect($response->json('data.snapshot.data.head'))->not->toBeNull();
});

it('filters page_context by source.page_context_keys before persisting the snapshot', function () {
    adwRegisterPinnableTool();
    $u = adwMakeUser();
    $d = adwMakeDashboard($u);

    $response = $this->actingAs($u, 'web')->postJson("/chatbot/dashboards/{$d->slug}/widgets", [
        'block_type'   => 'table',
        'snapshot'     => ['data' => []],
        'source'       => [
            'tool'              => 'echo_tool',
            'args'              => [],
            'page_context_keys' => ['entity', 'id'],
        ],
        'page_context' => [
            'entity'    => 'invoice',
            'id'        => 42,
            'sensitive' => 'should-be-dropped',
        ],
    ]);

    $response->assertStatus(201);
    $snapshot = $response->json('data.source.page_context_snapshot');
    expect($snapshot)->toBe(['entity' => 'invoice', 'id' => 42]);
});

it('persists empty page_context_snapshot when page_context is missing or keys empty', function () {
    adwRegisterPinnableTool();
    $u = adwMakeUser();
    $d = adwMakeDashboard($u);

    $response = $this->actingAs($u, 'web')->postJson("/chatbot/dashboards/{$d->slug}/widgets", [
        'block_type' => 'table',
        'snapshot'   => ['data' => []],
        'source'     => ['tool' => 'echo_tool', 'args' => []],
    ]);

    $response->assertStatus(201);
    expect($response->json('data.source.page_context_snapshot'))->toBe([]);
});

it('rejects pin when the dashboard already has max_widgets_per_dashboard widgets', function () {
    adwRegisterPinnableTool();
    config()->set('chatbot.dashboard.max_widgets_per_dashboard', 2);
    $u = adwMakeUser();
    $d = adwMakeDashboard($u);
    adwMakeWidget($d);
    adwMakeWidget($d);

    $response = $this->actingAs($u, 'web')->postJson("/chatbot/dashboards/{$d->slug}/widgets", [
        'block_type' => 'table',
        'snapshot'   => ['data' => []],
        'source'     => ['tool' => 'echo_tool', 'args' => []],
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['dashboard']);
});

it('assigns a default position sized by block_type when the client omits position (#18)', function () {
    adwRegisterPinnableTool();
    $u = adwMakeUser();
    $d = adwMakeDashboard($u);

    // v2.1 (#18) — a `table` needs width; the heuristic gives it {w:8,h:5}
    // instead of the old fixed {w:6,h:4}. `y:9999` still defers placement
    // to gridstack's "lowest free row".
    $response = $this->actingAs($u, 'web')->postJson("/chatbot/dashboards/{$d->slug}/widgets", [
        'block_type' => 'table',
        'snapshot'   => ['data' => []],
        'source'     => ['tool' => 'echo_tool', 'args' => []],
    ]);

    $response->assertStatus(201);
    expect($response->json('data.position.w'))->toBe(8);
    expect($response->json('data.position.h'))->toBe(5);
    expect($response->json('data.position.y'))->toBe(9999);
});

it('sizes the default position per block_type — kpi small, card medium, chart wide (#18)', function () {
    adwRegisterPinnableTool();
    $u = adwMakeUser();
    $d = adwMakeDashboard($u);

    $cases = [
        'kpi'   => ['w' => 3, 'h' => 2],
        'card'  => ['w' => 4, 'h' => 3],
        'list'  => ['w' => 8, 'h' => 5],
        'chart' => ['w' => 6, 'h' => 4],
    ];

    foreach ($cases as $blockType => $expected) {
        $response = $this->actingAs($u, 'web')->postJson("/chatbot/dashboards/{$d->slug}/widgets", [
            'block_type' => $blockType,
            'snapshot'   => ['data' => []],
            'source'     => ['tool' => 'echo_tool', 'args' => []],
        ]);

        $response->assertStatus(201);
        expect($response->json('data.position.w'))->toBe($expected['w'])
            ->and($response->json('data.position.h'))->toBe($expected['h']);
    }
});

it('honors a position sent by the client', function () {
    adwRegisterPinnableTool();
    $u = adwMakeUser();
    $d = adwMakeDashboard($u);

    $response = $this->actingAs($u, 'web')->postJson("/chatbot/dashboards/{$d->slug}/widgets", [
        'block_type' => 'table',
        'snapshot'   => ['data' => []],
        'source'     => ['tool' => 'echo_tool', 'args' => []],
        'position'   => ['x' => 3, 'y' => 4, 'w' => 8, 'h' => 5],
    ]);

    $response->assertStatus(201);
    expect($response->json('data.position'))->toBe(['x' => 3, 'y' => 4, 'w' => 8, 'h' => 5]);
});

it('increments order_index based on existing widgets', function () {
    adwRegisterPinnableTool();
    $u = adwMakeUser();
    $d = adwMakeDashboard($u);
    adwMakeWidget($d, ['order_index' => 5]);

    $response = $this->actingAs($u, 'web')->postJson("/chatbot/dashboards/{$d->slug}/widgets", [
        'block_type' => 'table',
        'snapshot'   => ['data' => []],
        'source'     => ['tool' => 'echo_tool', 'args' => []],
    ]);

    $response->assertStatus(201);
    expect($response->json('data.order_index'))->toBe(6);
});

it('returns 404 when pinning to a dashboard owned by another user', function () {
    adwRegisterPinnableTool();
    $self    = adwMakeUser(1);
    $foreign = adwMakeUser(99);
    $d = adwMakeDashboard($foreign, 'theirs');

    $response = $this->actingAs($self, 'web')->postJson("/chatbot/dashboards/theirs/widgets", [
        'block_type' => 'table',
        'snapshot'   => ['data' => []],
        'source'     => ['tool' => 'echo_tool', 'args' => []],
    ]);

    $response->assertStatus(404);
});

// ──────────────────────────────────────────────────────────────────────────
// update (PATCH widget)
// ──────────────────────────────────────────────────────────────────────────

it('updates the widget position', function () {
    $u = adwMakeUser();
    $d = adwMakeDashboard($u);
    $w = adwMakeWidget($d);

    $response = $this->actingAs($u, 'web')->patchJson("/chatbot/dashboards/{$d->slug}/widgets/{$w->id}", [
        'position' => ['x' => 2, 'y' => 3, 'w' => 10, 'h' => 6],
    ]);

    $response->assertOk();
    expect($response->json('data.position'))->toBe(['x' => 2, 'y' => 3, 'w' => 10, 'h' => 6]);
});

it('updates the widget title (and accepts null to clear it)', function () {
    $u = adwMakeUser();
    $d = adwMakeDashboard($u);
    $w = adwMakeWidget($d, ['title' => 'Old title']);

    $this->actingAs($u, 'web')->patchJson("/chatbot/dashboards/{$d->slug}/widgets/{$w->id}", [
        'title' => 'New title',
    ])->assertOk();
    expect($w->fresh()->title)->toBe('New title');

    $this->actingAs($u, 'web')->patchJson("/chatbot/dashboards/{$d->slug}/widgets/{$w->id}", [
        'title' => null,
    ])->assertOk();
    expect($w->fresh()->title)->toBeNull();
});

it('updates the widget refresh_policy', function () {
    $u = adwMakeUser();
    $d = adwMakeDashboard($u);
    $w = adwMakeWidget($d);

    $response = $this->actingAs($u, 'web')->patchJson("/chatbot/dashboards/{$d->slug}/widgets/{$w->id}", [
        'refresh_policy' => 'manual',
    ]);

    $response->assertOk();
    expect($w->fresh()->refresh_policy)->toBe(WidgetRefreshPolicy::Manual);
});

it('rejects invalid refresh_policy values with 422', function () {
    $u = adwMakeUser();
    $d = adwMakeDashboard($u);
    $w = adwMakeWidget($d);

    $response = $this->actingAs($u, 'web')->patchJson("/chatbot/dashboards/{$d->slug}/widgets/{$w->id}", [
        'refresh_policy' => 'whenever',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['refresh_policy']);
});

it('returns 404 when patching a widget on a foreign dashboard', function () {
    $self    = adwMakeUser(1);
    $foreign = adwMakeUser(99);
    $d = adwMakeDashboard($foreign, 'theirs');
    $w = adwMakeWidget($d);

    $response = $this->actingAs($self, 'web')->patchJson("/chatbot/dashboards/theirs/widgets/{$w->id}", [
        'title' => 'Hijack',
    ]);

    $response->assertStatus(404);
});

// ──────────────────────────────────────────────────────────────────────────
// refresh single
// ──────────────────────────────────────────────────────────────────────────

it('refresh delegates to ReplayService and updates the widget snapshot', function () {
    adwRegisterPinnableTool([
        ['type' => 'table', 'data' => ['rows' => [['id' => 7], ['id' => 8]]]],
    ]);

    $u = adwMakeUser();
    $d = adwMakeDashboard($u);
    $w = adwMakeWidget($d, [
        'source'           => ['tool' => 'echo_tool', 'args' => ['message' => 'hi']],
        'source_signature' => SourceSignature::for('echo_tool', ['message' => 'hi']),
        'block_type'       => 'table',
    ]);

    $response = $this->actingAs($u, 'web')->postJson(
        "/chatbot/dashboards/{$d->slug}/widgets/{$w->id}/refresh"
    );

    $response->assertOk();
    // Flat WidgetRefreshedFrame shape — same contract as the bulk SSE frames.
    expect($response->json('data.widget_id'))->toBe($w->id);
    expect($response->json('data.status'))->toBe('fresh');
    expect($response->json('data.error'))->toBeNull();
    expect($response->json('data.snapshot.data.rows'))->toBe([['id' => 7], ['id' => 8]]);
});

it('refresh returns 429 when above chatbot.dashboard.replay.rate_limit_per_user_per_minute', function () {
    adwRegisterPinnableTool();
    config()->set('chatbot.dashboard.replay.rate_limit_per_user_per_minute', 1);

    $u = adwMakeUser();
    $d = adwMakeDashboard($u);
    $w = adwMakeWidget($d);

    $first = $this->actingAs($u, 'web')->postJson(
        "/chatbot/dashboards/{$d->slug}/widgets/{$w->id}/refresh"
    );
    $first->assertOk();

    $second = $this->actingAs($u, 'web')->postJson(
        "/chatbot/dashboards/{$d->slug}/widgets/{$w->id}/refresh"
    );
    $second->assertStatus(429);
});

it('refresh returns 404 when the widget id does not belong to the dashboard', function () {
    adwRegisterPinnableTool();
    $u = adwMakeUser();
    $a = adwMakeDashboard($u, 'a');
    $b = adwMakeDashboard($u, 'b');
    $w = adwMakeWidget($a);

    // The widget exists but belongs to the other dashboard of the SAME user:
    // it is still 404 — the path scope is `{slug}/widgets/{id}`.
    $response = $this->actingAs($u, 'web')->postJson(
        "/chatbot/dashboards/{$b->slug}/widgets/{$w->id}/refresh"
    );

    $response->assertStatus(404);
});

// ──────────────────────────────────────────────────────────────────────────
// refreshAll (SSE bulk)
// ──────────────────────────────────────────────────────────────────────────

it('refreshAll emits a widget_refreshed SSE frame per widget plus a done frame', function () {
    adwRegisterPinnableTool([['type' => 'table', 'data' => ['rows' => [['id' => 1]]]]]);

    $u = adwMakeUser();
    $d = adwMakeDashboard($u);
    $w1 = adwMakeWidget($d, [
        'source'           => ['tool' => 'echo_tool', 'args' => ['message' => 'a']],
        'source_signature' => SourceSignature::for('echo_tool', ['message' => 'a']),
    ]);
    $w2 = adwMakeWidget($d, [
        'source'           => ['tool' => 'echo_tool', 'args' => ['message' => 'b']],
        'source_signature' => SourceSignature::for('echo_tool', ['message' => 'b']),
    ]);

    $response = $this->actingAs($u, 'web')->post("/chatbot/dashboards/{$d->slug}/refresh");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/event-stream');

    $body = $response->streamedContent();

    // Lax parser: counts events by their `event: ...` header.
    preg_match_all('/^event: (\w+)$/m', $body, $matches);
    $events = $matches[1];

    expect($events)->toContain('widget_refreshed');
    expect($events)->toContain('done');

    $refreshedCount = count(array_filter($events, fn ($e) => $e === 'widget_refreshed'));
    expect($refreshedCount)->toBe(2);

    // The body contains the real widget_ids.
    expect($body)->toContain('"widget_id":' . $w1->id);
    expect($body)->toContain('"widget_id":' . $w2->id);
});

it('refreshAll emits a done frame with widget_count=0 for an empty dashboard', function () {
    $u = adwMakeUser();
    $d = adwMakeDashboard($u);

    $response = $this->actingAs($u, 'web')->post("/chatbot/dashboards/{$d->slug}/refresh");

    $response->assertOk();
    $body = $response->streamedContent();
    expect($body)->toContain('event: done');
    expect($body)->toContain('"widget_count":0');
});

it('refreshAll returns 429 when rate-limited', function () {
    config()->set('chatbot.dashboard.replay.rate_limit_per_user_per_minute', 1);
    $u = adwMakeUser();
    $d = adwMakeDashboard($u);

    $first = $this->actingAs($u, 'web')->post("/chatbot/dashboards/{$d->slug}/refresh");
    $first->assertOk();
    $first->streamedContent(); // consume

    $second = $this->actingAs($u, 'web')->post("/chatbot/dashboards/{$d->slug}/refresh");
    $second->assertStatus(429);
});

it('refreshAll returns 404 for a foreign dashboard', function () {
    $self    = adwMakeUser(1);
    $foreign = adwMakeUser(99);
    adwMakeDashboard($foreign, 'theirs');

    $response = $this->actingAs($self, 'web')->post('/chatbot/dashboards/theirs/refresh');

    $response->assertStatus(404);
});

// ──────────────────────────────────────────────────────────────────────────
// destroy (unpin)
// ──────────────────────────────────────────────────────────────────────────

it('soft-deletes the widget on unpin and responds 204', function () {
    $u = adwMakeUser();
    $d = adwMakeDashboard($u);
    $w = adwMakeWidget($d);

    $response = $this->actingAs($u, 'web')->deleteJson("/chatbot/dashboards/{$d->slug}/widgets/{$w->id}");

    $response->assertStatus(204);
    expect(DashboardWidget::withTrashed()->find($w->id))->not->toBeNull();
    expect(DashboardWidget::find($w->id))->toBeNull();
});

it('returns 404 when unpinning a widget from a foreign dashboard', function () {
    $self    = adwMakeUser(1);
    $foreign = adwMakeUser(99);
    $d = adwMakeDashboard($foreign, 'theirs');
    $w = adwMakeWidget($d);

    $response = $this->actingAs($self, 'web')->deleteJson("/chatbot/dashboards/theirs/widgets/{$w->id}");

    $response->assertStatus(404);
});

it('rejects unauthenticated widget endpoints via the auth middleware', function () {
    // postJson forces Accept: application/json so the `auth` middleware
    // returns 401 instead of attempting a redirect to a `login` route that
    // testbench does not have registered (which would result in 500). Same
    // pattern as the existing suites (ConversationControllerTest, etc.).
    expect($this->postJson('/chatbot/dashboards/nope/widgets', [])->status())->toBeIn([401, 302, 419, 403]);
    expect($this->postJson('/chatbot/dashboards/nope/widgets/1/refresh')->status())->toBeIn([401, 302, 419, 403]);
    expect($this->postJson('/chatbot/dashboards/nope/refresh')->status())->toBeIn([401, 302, 419, 403]);
});
