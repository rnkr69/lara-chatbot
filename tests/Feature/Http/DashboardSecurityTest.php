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
 * E10 — Tests defensivos de seguridad para el Personal Dashboard (v2.0).
 *
 * Esta suite complementa la sección §11 de `docs/dashboard.md`: cubre los
 * huecos que la auditoría detectó sobre el resto de la batería (E2-E8),
 * sin duplicar lo que tests previos ya cubren (404-no-403, caps, page
 * context filtering — todos en `ApiDashboard{,Widget}ControllerTest`).
 *
 * Cubre:
 *   - CSRF: las rutas `/chatbot/dashboards*` heredan el middleware `web`
 *     del grupo (config `chatbot.route.middleware`), que incluye
 *     `VerifyCsrfToken`. Laravel testing auto-bypassa CSRF en
 *     `$this->post()` para no romper tests, así que verificamos la
 *     propiedad inspeccionando el stack del Router en vez de simular
 *     un POST sin token.
 *   - XSS persistence: `dashboard.name` con payload `<script>` se
 *     persiste y se devuelve raw — el cliente (`sidebar.ts:181`) usa
 *     `textContent`, no `innerHTML`, así que el server-side no necesita
 *     escapar. Verificación: idempotencia binaria entre input y output.
 *   - Replay tolerante a args maliciosos: si un cliente pinea con args
 *     válidos al pinear pero el tool tira al refresh (caso "argumento
 *     edge no contemplado en el JSON Schema del tool"), el endpoint
 *     refresh devuelve 200 con `last_refresh_status='error'`, NO 500.
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

    // El show endpoint también devuelve el name sin tocarlo — el cliente
    // usa `textContent` (sidebar.ts:181 + widget-card title) y nunca
    // `innerHTML`, así que el escape es responsabilidad del DOM API,
    // no del JSON.
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
