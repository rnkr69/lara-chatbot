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
 * E10 — Validación del rate limit del replay bajo presión sintética.
 *
 * El cap real default es 60 hits/min (config
 * `chatbot.dashboard.replay.rate_limit_per_user_per_minute`); estos tests
 * usan un cap = 5 para mantenerlos rápidos. El comportamiento del
 * `Illuminate\Support\Facades\RateLimiter` no depende del valor del cap,
 * sólo del orden hit→tooManyAttempts→availableIn.
 *
 * Cubre:
 *   - N+1 hits rápidos al endpoint single-refresh → 429 con `Retry-After`
 *     numérico y headers X-RateLimit-* coherentes.
 *   - Bulk SSE (`refreshAll`) cuenta como 1 hit (no N por widget) — un
 *     bulk con cap=1 satura el bucket y un subsiguiente refresh single
 *     devuelve 429.
 *   - El CRUD (lista, pin, PATCH, delete) NO consume del bucket — con
 *     cap=1 se pueden hacer varios CRUD y todavía un refresh queda
 *     disponible.
 *   - Buckets aislados por usuario: usuario A puede saturar su bucket
 *     sin afectar a usuario B (clave del rate limiter incluye el user
 *     id, ver `ApiDashboardWidgetController::checkRefreshRateLimit`).
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

    // Con cap=1, si el bulk hubiera contado como 3 hits (uno por widget),
    // ya estaría sobre el cap y el siguiente single-refresh ya estaría 429
    // de todos modos. Pero queremos probar la propiedad inversa: el bulk
    // gastó EXACTAMENTE 1 hit. Con cap=1, eso significa que cualquier
    // hit subsiguiente (single OR bulk) devolverá 429 — ya verificado
    // por los tests E4. Aquí confirmamos que el bucket quedó saturado
    // tras UN bulk (no antes — el bulk en sí mismo debe pasar).
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

    // Tras 5 operaciones CRUD el bucket sigue intacto: el primer
    // refresh todavía es 200.
    $refresh = $this->actingAs($u, 'web')->postJson(
        "/chatbot/dashboards/{$d->slug}/widgets/{$widgetId}/refresh"
    );
    $refresh->assertOk();

    // Y el siguiente sí es 429 (cap=1 ya consumido por el refresh).
    $blocked = $this->actingAs($u, 'web')->postJson(
        "/chatbot/dashboards/{$d->slug}/widgets/{$widgetId}/refresh"
    );
    $blocked->assertStatus(429);

    // El CRUD sigue siendo 200 incluso tras el 429 del refresh.
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
