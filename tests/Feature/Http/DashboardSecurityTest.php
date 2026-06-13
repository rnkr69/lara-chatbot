<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Rnkr69\LaraChatbot\Dashboard\SourceSignature;
use Rnkr69\LaraChatbot\Models\Dashboard;
use Rnkr69\LaraChatbot\Models\DashboardWidget;
use Rnkr69\LaraChatbot\Models\WidgetRefreshPolicy;
use Rnkr69\LaraChatbot\Models\WidgetRefreshStatus;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\EchoBackendTool;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;

/**
 * E10 — Defensive security tests for the Personal Dashboard (v2.0).
 *
 * This suite complements §11 of `docs/dashboard.md`: it covers the gaps the
 * audit detected across the rest of the battery (E2-E8), without duplicating
 * what previous tests already cover (404-not-403, caps, page context
 * filtering — all in `ApiDashboard{,Widget}ControllerTest`).
 *
 * Covers:
 *   - CSRF: the `/chatbot/dashboards*` routes inherit the group's `web`
 *     middleware (config `chatbot.route.middleware`), which includes
 *     `VerifyCsrfToken`. Laravel testing auto-bypasses CSRF on
 *     `$this->post()` so as not to break tests, so we verify the property
 *     by inspecting the Router stack instead of simulating a POST without a
 *     token.
 *   - XSS persistence: `dashboard.name` with a `<script>` payload is
 *     persisted and returned raw — the client (`sidebar.ts:181`) uses
 *     `textContent`, not `innerHTML`, so the server-side does not need to
 *     escape it. Verification: binary idempotence between input and output.
 *   - Replay tolerant to malicious args: if a client pins with valid args
 *     but the tool throws on refresh (the "edge argument not contemplated in
 *     the tool's JSON Schema" case), the refresh endpoint returns 200 with
 *     `last_refresh_status='error'`, NOT 500.
 */

beforeEach(function () {
    $this->artisan('migrate')->run();
    Cache::flush();
    config(['concurrency.default' => 'sync']);
    app(ToolRegistry::class)->clear();
});

function secMakeUser(int $id = 1): TestUser
{
    $u = new TestUser(['id' => $id, 'name' => 'Tester']);
    $u->setRawAttributes(['id' => $id, 'name' => 'Tester'], sync: true);

    return $u;
}

function secMakeDashboard(TestUser $u, string $slug = 'panel', string $name = 'Panel'): Dashboard
{
    return Dashboard::create([
        'user_type'      => $u->getMorphClass(),
        'user_id'        => $u->getKey(),
        'name'           => $name,
        'slug'           => $slug,
        'is_default'     => false,
        'layout_version' => 1,
        'metadata'       => null,
    ]);
}

it('registers dashboard CRUD routes under the web middleware (CSRF stack)', function () {
    $names = [
        'chatbot.dashboards.index',
        'chatbot.dashboards.store',
        'chatbot.dashboards.show',
        'chatbot.dashboards.update',
        'chatbot.dashboards.destroy',
        'chatbot.dashboards.widgets.store',
        'chatbot.dashboards.widgets.update',
        'chatbot.dashboards.widgets.refresh',
        'chatbot.dashboards.widgets.destroy',
        'chatbot.dashboards.refresh',
    ];

    foreach ($names as $name) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain('web');
    }
});

it('persists dashboard name verbatim even with HTML-like payload (no server-side escape)', function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);

    $u       = secMakeUser();
    $hostile = '<script>alert("xss")</script>';

    $response = $this->actingAs($u, 'web')->postJson('/chatbot/dashboards', [
        'name' => $hostile,
    ]);

    $response->assertCreated();
    expect($response->json('data.name'))->toBe($hostile);

    $persisted = Dashboard::query()->forUser($u)->firstOrFail();
    expect($persisted->name)->toBe($hostile);

    // The show endpoint also returns the name untouched — the client uses
    // `textContent` (sidebar.ts:181 + widget-card title) and never
    // `innerHTML`, so escaping is the DOM API's responsibility, not the
    // JSON's.
    $show = $this->actingAs($u, 'web')
        ->getJson("/chatbot/dashboards/{$persisted->slug}");
    $show->assertOk();
    expect($show->json('data.name'))->toBe($hostile);
});

it('refresh marks the widget as error (not 500) when the tool throws on the persisted args', function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);

    // Tool registered as pinnable initially (lets us seed the widget via
    // ::create directly — we don't go through the pin endpoint to avoid
    // re-validating args twice). At refresh time the same tool is
    // configured to throw — simulating an attacker who pinned with args
    // that the underlying tool no longer accepts (or never did, but
    // slipped past validation because the schema is loose).
    $tool                   = new EchoBackendTool();
    $tool->pinnableOverride = true;
    app(ToolRegistry::class)->register($tool);

    $u = secMakeUser(11);
    $d = secMakeDashboard($u, 'sec-panel');

    $w = DashboardWidget::create([
        'dashboard_id'        => $d->id,
        'position'            => ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
        'block_type'          => 'table',
        'title'               => null,
        'snapshot'            => ['data' => ['rows' => []], 'captured_at' => '2026-05-13T00:00:00Z', 'byte_size' => 18],
        'source'              => ['tool' => 'echo_tool', 'args' => ['message' => 'persisted-but-soon-rejected']],
        'source_signature'    => SourceSignature::for('echo_tool', ['message' => 'persisted-but-soon-rejected']),
        'refresh_policy'      => WidgetRefreshPolicy::OnOpen,
        'last_refreshed_at'   => null,
        'last_refresh_status' => WidgetRefreshStatus::Fresh,
        'last_refresh_error'  => null,
        'order_index'         => 0,
    ]);

    $tool->shouldThrow = new RuntimeException('args no longer accepted by tool');

    $response = $this->actingAs($u, 'web')->postJson(
        "/chatbot/dashboards/{$d->slug}/widgets/{$w->id}/refresh"
    );

    $response->assertOk();
    // Flat WidgetRefreshedFrame shape — same contract as the bulk SSE frames.
    expect($response->json('data.status'))->toBe('error')
        ->and($response->json('data.error.category'))->not->toBeNull();

    $reloaded = DashboardWidget::find($w->id);
    expect($reloaded->last_refresh_status)->toBe(WidgetRefreshStatus::Error);
});
