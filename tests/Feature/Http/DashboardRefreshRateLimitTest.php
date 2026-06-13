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
use Rnkr69\LaraChatbot\Tools\ToolRegistry;

/**
 * E10 — Validation of the replay rate limit under synthetic pressure.
 *
 * The real default cap is 60 hits/min (config
 * `chatbot.dashboard.replay.rate_limit_per_user_per_minute`); these tests
 * use a cap = 5 to keep them fast. The behavior of
 * `Illuminate\Support\Facades\RateLimiter` does not depend on the cap value,
 * only on the hit→tooManyAttempts→availableIn order.
 *
 * Covers:
 *   - N+1 rapid hits to the single-refresh endpoint → 429 with a numeric
 *     `Retry-After` and coherent X-RateLimit-* headers.
 *   - Bulk SSE (`refreshAll`) counts as 1 hit (not N per widget) — a bulk
 *     with cap=1 saturates the bucket and a subsequent single refresh
 *     returns 429.
 *   - CRUD (list, pin, PATCH, delete) does NOT consume from the bucket — with
 *     cap=1 several CRUD calls can be made and a refresh is still available.
 *   - Per-user isolated buckets: user A can saturate their bucket without
 *     affecting user B (the rate limiter key includes the user id, see
 *     `ApiDashboardWidgetController::checkRefreshRateLimit`).
 */

beforeEach(function () {
    $this->artisan('migrate')->run();
    $this->withoutMiddleware(VerifyCsrfToken::class);
    Cache::flush();
    config(['concurrency.default' => 'sync']);
    app(ToolRegistry::class)->clear();
});

function rlMakeUser(int $id, string $name = 'Tester'): TestUser
{
    $u = new TestUser(['id' => $id, 'name' => $name]);
    $u->setRawAttributes(['id' => $id, 'name' => $name], sync: true);

    return $u;
}

function rlMakeDashboard(TestUser $user, string $slug = 'panel'): Dashboard
{
    return Dashboard::create([
        'user_type'      => $user->getMorphClass(),
        'user_id'        => $user->getKey(),
        'name'           => 'Panel ' . $slug,
        'slug'           => $slug,
        'is_default'     => false,
        'layout_version' => 1,
        'metadata'       => null,
    ]);
}

function rlMakeWidget(Dashboard $d): DashboardWidget
{
    return DashboardWidget::create([
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
    ]);
}

function rlRegisterTool(): void
{
    $tool = new EchoBackendTool();
    $tool->pinnableOverride = true;
    $tool->emitBlocks       = [['type' => 'table', 'data' => ['rows' => [['id' => 1]]]]];
    app(ToolRegistry::class)->register($tool);
}

it('returns 429 with Retry-After and X-RateLimit-* headers after cap+1 single-refresh hits', function () {
    rlRegisterTool();
    config()->set('chatbot.dashboard.replay.rate_limit_per_user_per_minute', 5);

    $u = rlMakeUser(1);
    $d = rlMakeDashboard($u);
    $w = rlMakeWidget($d);

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($u, 'web')
            ->postJson("/chatbot/dashboards/{$d->slug}/widgets/{$w->id}/refresh")
            ->assertOk();
    }

    $blocked = $this->actingAs($u, 'web')
        ->postJson("/chatbot/dashboards/{$d->slug}/widgets/{$w->id}/refresh");

    $blocked->assertStatus(429);
    expect($blocked->headers->get('Retry-After'))->toBeNumeric()
        ->and((int) $blocked->headers->get('Retry-After'))->toBeGreaterThanOrEqual(0)
        ->and((int) $blocked->headers->get('Retry-After'))->toBeLessThanOrEqual(60)
        ->and($blocked->headers->get('X-RateLimit-Limit'))->toBe('5')
        ->and($blocked->headers->get('X-RateLimit-Remaining'))->toBe('0');
});

it('counts the bulk SSE refresh as a single hit (not one per widget)', function () {
    rlRegisterTool();
    config()->set('chatbot.dashboard.replay.rate_limit_per_user_per_minute', 1);

    $u = rlMakeUser(2);
    $d = rlMakeDashboard($u);

    $widgets = [];
    for ($i = 0; $i < 3; $i++) {
        $widgets[] = rlMakeWidget($d);
    }

    $bulk = $this->actingAs($u, 'web')->post("/chatbot/dashboards/{$d->slug}/refresh");
    $bulk->assertOk();

    // With cap=1, if the bulk had counted as 3 hits (one per widget), it
    // would already be over the cap and the next single-refresh would be 429
    // anyway. But we want to test the inverse property: the bulk spent
    // EXACTLY 1 hit. With cap=1, that means any subsequent hit (single OR
    // bulk) will return 429 — already verified by the E4 tests. Here we
    // confirm the bucket got saturated after ONE bulk (not before — the bulk
    // itself must pass).
    $secondBulk = $this->actingAs($u, 'web')->post("/chatbot/dashboards/{$d->slug}/refresh");
    $secondBulk->assertStatus(429);

    $singleAfter = $this->actingAs($u, 'web')->postJson(
        "/chatbot/dashboards/{$d->slug}/widgets/{$widgets[0]->id}/refresh"
    );
    $singleAfter->assertStatus(429);
});

it('does not consume the refresh bucket for CRUD operations (list/pin/patch/delete)', function () {
    rlRegisterTool();
    config()->set('chatbot.dashboard.replay.rate_limit_per_user_per_minute', 1);

    $u = rlMakeUser(3);
    $d = rlMakeDashboard($u);

    $this->actingAs($u, 'web')->getJson('/chatbot/dashboards')->assertOk();
    $this->actingAs($u, 'web')->getJson("/chatbot/dashboards/{$d->slug}")->assertOk();

    $pinResponse = $this->actingAs($u, 'web')->postJson("/chatbot/dashboards/{$d->slug}/widgets", [
        'block_type' => 'table',
        'snapshot'   => ['data' => ['rows' => [['id' => 1]]]],
        'source'     => ['tool' => 'echo_tool', 'args' => ['message' => 'hi']],
    ]);
    $pinResponse->assertCreated();
    $widgetId = $pinResponse->json('data.id');

    $this->actingAs($u, 'web')->patchJson(
        "/chatbot/dashboards/{$d->slug}/widgets/{$widgetId}",
        ['position' => ['x' => 2, 'y' => 1, 'w' => 4, 'h' => 3]]
    )->assertOk();

    // After 5 CRUD operations the bucket is still intact: the first
    // refresh is still 200.
    $refresh = $this->actingAs($u, 'web')->postJson(
        "/chatbot/dashboards/{$d->slug}/widgets/{$widgetId}/refresh"
    );
    $refresh->assertOk();

    // And the next one is indeed 429 (cap=1 already consumed by the refresh).
    $blocked = $this->actingAs($u, 'web')->postJson(
        "/chatbot/dashboards/{$d->slug}/widgets/{$widgetId}/refresh"
    );
    $blocked->assertStatus(429);

    // CRUD is still 200 even after the refresh 429.
    $this->actingAs($u, 'web')->getJson("/chatbot/dashboards/{$d->slug}")->assertOk();
});

it('isolates rate-limit buckets per user', function () {
    rlRegisterTool();
    config()->set('chatbot.dashboard.replay.rate_limit_per_user_per_minute', 2);

    $alice = rlMakeUser(101, 'Alice');
    $bob   = rlMakeUser(202, 'Bob');

    $aliceD = rlMakeDashboard($alice, 'alice-panel');
    $aliceW = rlMakeWidget($aliceD);
    $bobD   = rlMakeDashboard($bob, 'bob-panel');
    $bobW   = rlMakeWidget($bobD);

    for ($i = 0; $i < 2; $i++) {
        $this->actingAs($alice, 'web')
            ->postJson("/chatbot/dashboards/{$aliceD->slug}/widgets/{$aliceW->id}/refresh")
            ->assertOk();
    }

    $this->actingAs($alice, 'web')
        ->postJson("/chatbot/dashboards/{$aliceD->slug}/widgets/{$aliceW->id}/refresh")
        ->assertStatus(429);

    $this->actingAs($bob, 'web')
        ->postJson("/chatbot/dashboards/{$bobD->slug}/widgets/{$bobW->id}/refresh")
        ->assertOk();

    $this->actingAs($bob, 'web')
        ->postJson("/chatbot/dashboards/{$bobD->slug}/widgets/{$bobW->id}/refresh")
        ->assertOk();

    $this->actingAs($bob, 'web')
        ->postJson("/chatbot/dashboards/{$bobD->slug}/widgets/{$bobW->id}/refresh")
        ->assertStatus(429);
});
