<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Rnkr69\LaraChatbot\Models\Dashboard;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;

/**
 * E4 — `GET /chatbot/dashboard` (página dedicada del Personal Dashboard).
 *
 * Cubre el shape público de la vista HTML:
 *   - autenticación heredada del middleware del grupo
 *   - render standalone vs `@extends($layout)` (mismo split que E17 / D16)
 *   - fallback con log warning si el layout configurado no existe
 *   - desactivación vía `chatbot.dashboard.enabled = false`
 *   - resolución del `defaultSlug` (query string > is_default > null)
 */

beforeEach(function () {
    $this->artisan('migrate')->run();
});

function makeDashboardPageUser(int $id = 1): TestUser
{
    $user = new TestUser(['id' => $id, 'name' => "User-{$id}"]);
    $user->setRawAttributes(['id' => $id, 'name' => "User-{$id}"], sync: true);

    return $user;
}

function makeDashboardForUser(TestUser $user, string $slug = 'mi-panel', bool $isDefault = false): Dashboard
{
    return Dashboard::create([
        'user_type'      => $user->getMorphClass(),
        'user_id'        => $user->getKey(),
        'name'           => 'Mi panel',
        'slug'           => $slug,
        'is_default'     => $isDefault,
        'layout_version' => 1,
        'metadata'       => null,
    ]);
}

it('renders the dashboard page with status 200 for authenticated users', function () {
    $user = makeDashboardPageUser();

    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');

    $response->assertOk();
});

it('mounts the dashboard root with the dashboards API endpoint', function () {
    $user = makeDashboardPageUser();

    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');

    $response->assertOk();
    $body = $response->getContent();
    expect($body)->toContain('id="chatbot-dashboard-root"');
    expect($body)->toContain('data-dashboards-endpoint="' . url('/chatbot/dashboards') . '"');
});

it('injects data-chart-renderer from chatbot.dashboard.chart_renderer config', function () {
    $user = makeDashboardPageUser();

    config()->set('chatbot.dashboard.chart_renderer', 'chartjs');
    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');
    expect($response->getContent())->toContain('data-chart-renderer="chartjs"');

    config()->set('chatbot.dashboard.chart_renderer', 'none');
    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');
    expect($response->getContent())->toContain('data-chart-renderer="none"');

    config()->set('chatbot.dashboard.chart_renderer', 'unknown-value-coerced-back');
    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');
    expect($response->getContent())->toContain('data-chart-renderer="chartjs"');
});

it('emits data-use-bootstrap="0" by default (auto, no Backpack installed)', function () {
    // chatbot.backpack.use_bootstrap defaults to 'auto'. In the test env
    // Backpack is not installed, so auto resolves to false regardless of
    // layout mode — there is no host Bootstrap to ride on.
    $user = makeDashboardPageUser();

    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');

    expect($response->getContent())->toContain('data-use-bootstrap="0"');
});

it('emits data-use-bootstrap="1" when chatbot.backpack.use_bootstrap is forced true', function () {
    config()->set('chatbot.backpack.use_bootstrap', true);
    $user = makeDashboardPageUser();

    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');
    expect($response->getContent())->toContain('data-use-bootstrap="1"');

    // string form is accepted too
    config()->set('chatbot.backpack.use_bootstrap', 'true');
    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');
    expect($response->getContent())->toContain('data-use-bootstrap="1"');
});

it('emits data-use-bootstrap="0" when chatbot.backpack.use_bootstrap is forced false', function () {
    config()->set('chatbot.backpack.use_bootstrap', false);
    $user = makeDashboardPageUser();

    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');
    expect($response->getContent())->toContain('data-use-bootstrap="0"');
});

it('emits data-debug reflecting app.debug — gates the "View source" button (#17)', function () {
    $user = makeDashboardPageUser();

    config()->set('app.debug', true);
    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');
    expect($response->getContent())->toContain('data-debug="1"');

    config()->set('app.debug', false);
    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');
    expect($response->getContent())->toContain('data-debug="0"');
});

it('exposes the configured dashboard bundle asset URL', function () {
    config()->set('chatbot.dashboard.asset_path', 'vendor/chatbot/chatbot-dashboard.js');
    $user = makeDashboardPageUser();

    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');

    $body = $response->getContent();
    expect($body)->toContain('src="' . url('vendor/chatbot/chatbot-dashboard.js') . '"');
});

it('renders standalone HTML when chatbot.dashboard.layout is null', function () {
    config()->set('chatbot.dashboard.layout', null);
    $user = makeDashboardPageUser();

    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');

    $body = $response->getContent();
    expect($body)->toContain('<!DOCTYPE html>');
    expect($body)->toContain('<title>');
});

it('extends the host layout when chatbot.dashboard.layout points to an existing view', function () {
    View::addNamespace('chatbottest', __DIR__ . '/../../Stubs/views');
    @mkdir(__DIR__ . '/../../Stubs/views', 0775, recursive: true);
    file_put_contents(
        __DIR__ . '/../../Stubs/views/host_layout_dashboard.blade.php',
        '<html><head><title>DASHBOARD HOST</title></head><body>@yield(\'content\')</body></html>',
    );

    config()->set('chatbot.dashboard.layout', 'chatbottest::host_layout_dashboard');
    config()->set('chatbot.dashboard.section', 'content');

    $user = makeDashboardPageUser();
    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');

    $body = $response->getContent();
    expect($body)->toContain('DASHBOARD HOST');
    expect($body)->toContain('id="chatbot-dashboard-root"');

    @unlink(__DIR__ . '/../../Stubs/views/host_layout_dashboard.blade.php');
});

it('falls back to standalone with a log warning when the layout view does not exist', function () {
    config()->set('chatbot.dashboard.layout', 'non.existent.dashboard.layout');

    Log::spy();

    $user = makeDashboardPageUser();
    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');

    $response->assertOk();
    expect($response->getContent())->toContain('<!DOCTYPE html>');

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($msg) => str_contains((string) $msg, 'non.existent.dashboard.layout'));
});

it('exposes the default dashboard slug from is_default=true row', function () {
    $user = makeDashboardPageUser();
    makeDashboardForUser($user, 'analytics');
    makeDashboardForUser($user, 'ops', isDefault: true);

    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');

    $body = $response->getContent();
    expect($body)->toContain('data-default-slug="ops"');
});

it('honors ?dashboard={slug} when it belongs to the user', function () {
    $user = makeDashboardPageUser();
    makeDashboardForUser($user, 'ops', isDefault: true);
    makeDashboardForUser($user, 'analytics');

    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard?dashboard=analytics');

    $body = $response->getContent();
    expect($body)->toContain('data-default-slug="analytics"');
});

it('falls back to default slug when ?dashboard= references a foreign dashboard', function () {
    $self    = makeDashboardPageUser(1);
    $foreign = makeDashboardPageUser(99);

    makeDashboardForUser($foreign, 'theirs');
    makeDashboardForUser($self, 'mine', isDefault: true);

    $response = $this->actingAs($self, 'web')->get('/chatbot/dashboard?dashboard=theirs');

    $body = $response->getContent();
    expect($body)->toContain('data-default-slug="mine"');
    expect($body)->not->toContain('data-default-slug="theirs"');
});

it('omits the default-slug attribute when the user has no dashboards', function () {
    $user = makeDashboardPageUser();

    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');

    $body = $response->getContent();
    expect($body)->not->toContain('data-default-slug=');
});

it('emits a data-i18n payload with the dashboard.* subtree on the root', function () {
    $user = makeDashboardPageUser();
    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');

    $body = $response->getContent();
    expect($body)->toContain('data-i18n=');

    // Decode the attribute to assert structure without escaping noise.
    preg_match('/data-i18n="([^"]*)"/', $body, $m);
    expect($m)->toHaveCount(2);
    $payload = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);
    expect($payload)->toBeArray();
    expect($payload['dashboard'] ?? null)->toBeArray();
    expect($payload['dashboard']['sidebar'] ?? null)->toBeArray();
    expect($payload['dashboard']['pin'] ?? null)->toBeArray();
    expect($payload['dashboard']['kpi'] ?? null)->toBeArray();
    // The English default for `dashboard.pin.cta` is "Pin to dashboard".
    expect($payload['dashboard']['pin']['cta'] ?? null)->toBe('Pin to dashboard');
});

it('reflects the active locale in the data-i18n payload', function () {
    app()->setLocale('es');
    $user = makeDashboardPageUser();
    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');

    $body = $response->getContent();
    preg_match('/data-i18n="([^"]*)"/', $body, $m);
    $payload = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);
    expect($payload['dashboard']['pin']['cta'] ?? null)->toBeString()->not->toBe('Pin to dashboard');
    app()->setLocale('en');
});

it('registers the route under the chatbot.dashboard name', function () {
    $route = app('router')->getRoutes()->getByName('chatbot.dashboard');

    expect($route)->not->toBeNull();
    expect($route->uri())->toBe('chatbot/dashboard');
    expect($route->methods())->toContain('GET');
});

it('uses the configured section name when extending a host layout', function () {
    @mkdir(__DIR__ . '/../../Stubs/views', 0775, recursive: true);
    file_put_contents(
        __DIR__ . '/../../Stubs/views/host_layout_dashboard_section.blade.php',
        '<html><body>OPEN @yield(\'cb_dashboard\') CLOSE</body></html>',
    );

    View::addNamespace('chatbottest', __DIR__ . '/../../Stubs/views');
    config()->set('chatbot.dashboard.layout', 'chatbottest::host_layout_dashboard_section');
    config()->set('chatbot.dashboard.section', 'cb_dashboard');

    $user = makeDashboardPageUser();
    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');

    $body = $response->getContent();
    expect($body)->toContain('OPEN');
    expect($body)->toContain('id="chatbot-dashboard-root"');
    expect($body)->toContain('CLOSE');

    @unlink(__DIR__ . '/../../Stubs/views/host_layout_dashboard_section.blade.php');
});

// ──────────────────────────────────────────────────────────────────────────
// v2.1.1 (#26) — floating widget in layout mode + standalone "back to app"
// ──────────────────────────────────────────────────────────────────────────

it('mounts the floating widget in layout mode when mount_widget is true (#26)', function () {
    @mkdir(__DIR__ . '/../../Stubs/views', 0775, recursive: true);
    // The host layout must expose `@stack('after_scripts')` — Backpack layouts
    // (the documented `chatbot.dashboard.layout` target) do.
    file_put_contents(
        __DIR__ . '/../../Stubs/views/host_layout_after_scripts.blade.php',
        '<html><body>@yield(\'content\')@stack(\'after_scripts\')</body></html>',
    );
    View::addNamespace('chatbottest', __DIR__ . '/../../Stubs/views');
    config()->set('chatbot.dashboard.layout', 'chatbottest::host_layout_after_scripts');
    config()->set('chatbot.dashboard.section', 'content');
    config()->set('chatbot.dashboard.mount_widget', true);

    $user = makeDashboardPageUser();
    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');

    $body = $response->getContent();
    expect($body)->toContain('<chatbot-widget');
    expect($body)->toContain('data-endpoint="' . url('/chatbot/stream') . '"');
    expect($body)->toContain('src="' . url('vendor/chatbot/chatbot-widget.js') . '"');

    @unlink(__DIR__ . '/../../Stubs/views/host_layout_after_scripts.blade.php');
});

it('omits the floating widget in layout mode when mount_widget is false (#26)', function () {
    @mkdir(__DIR__ . '/../../Stubs/views', 0775, recursive: true);
    file_put_contents(
        __DIR__ . '/../../Stubs/views/host_layout_after_scripts.blade.php',
        '<html><body>@yield(\'content\')@stack(\'after_scripts\')</body></html>',
    );
    View::addNamespace('chatbottest', __DIR__ . '/../../Stubs/views');
    config()->set('chatbot.dashboard.layout', 'chatbottest::host_layout_after_scripts');
    config()->set('chatbot.dashboard.section', 'content');
    config()->set('chatbot.dashboard.mount_widget', false);

    $user = makeDashboardPageUser();
    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');

    expect($response->getContent())->not->toContain('<chatbot-widget');

    @unlink(__DIR__ . '/../../Stubs/views/host_layout_after_scripts.blade.php');
});

it('renders a "back to app" link in standalone mode when back_url is set (#26)', function () {
    config()->set('chatbot.dashboard.layout', null);
    config()->set('chatbot.dashboard.back_url', '/admin');

    $user = makeDashboardPageUser();
    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');

    $body = $response->getContent();
    expect($body)->toContain('<nav class="cb-standalone-bar">');
    expect($body)->toContain('href="/admin"');
    expect($body)->toContain('Back to app');
});

it('omits the "back to app" link in standalone mode when back_url is null (#26)', function () {
    config()->set('chatbot.dashboard.layout', null);
    config()->set('chatbot.dashboard.back_url', null);

    $user = makeDashboardPageUser();
    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');

    // The `.cb-standalone-bar` CSS rule is always in the <style> block; assert
    // on the <nav> element, which only renders when back_url is set.
    expect($response->getContent())->not->toContain('<nav class="cb-standalone-bar">');
});

// ──────────────────────────────────────────────────────────────────────────
// v2.1.3 (#34) — `chatbot.dashboard.extras_view` replaces the broken
// `@stack('chatbot_dashboard_extras')` hook of v2.1.2 (#31). The stack lived
// inside an already-captured `@section`, so a `@push` from the host's
// `$layout` view (the usage documented in v2.1.2) never landed. The new
// mechanism is a synchronous `@include`: pushes inside the included view DO
// reach the host layout's stacks (e.g. `after_scripts`).
// ──────────────────────────────────────────────────────────────────────────

it('includes the host extras view inside the dashboard section when extras_view is set (#34)', function () {
    @mkdir(__DIR__ . '/../../Stubs/views', 0775, recursive: true);
    file_put_contents(
        __DIR__ . '/../../Stubs/views/host_layout_extras.blade.php',
        '<html><body>@yield(\'content\')@stack(\'after_scripts\')</body></html>',
    );
    file_put_contents(
        __DIR__ . '/../../Stubs/views/chatbot_extras.blade.php',
        '<div class="host-extras-sentinel">HOST EXTRAS SENTINEL</div>'
        . '@push(\'after_scripts\')<script id="host-extras-script"></script>@endpush',
    );
    View::addNamespace('chatbottest', __DIR__ . '/../../Stubs/views');
    config()->set('chatbot.dashboard.layout', 'chatbottest::host_layout_extras');
    config()->set('chatbot.dashboard.section', 'content');
    config()->set('chatbot.dashboard.mount_widget', false);
    config()->set('chatbot.dashboard.extras_view', 'chatbottest::chatbot_extras');

    $user = makeDashboardPageUser();
    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');

    $body = $response->getContent();
    // Markup the host extras view emits lands inside the dashboard section.
    expect($body)->toContain('HOST EXTRAS SENTINEL');
    expect($body)->toContain('class="host-extras-sentinel"');
    // And — the regression that #34 fixes — a `@push('after_scripts')` from
    // within the included view DOES reach the host layout's stack. The
    // pre-2.1.3 `@stack('chatbot_dashboard_extras')` hook could never deliver
    // this because the captured section had already been buffered.
    expect($body)->toContain('id="host-extras-script"');

    @unlink(__DIR__ . '/../../Stubs/views/host_layout_extras.blade.php');
    @unlink(__DIR__ . '/../../Stubs/views/chatbot_extras.blade.php');
});

it('renders without extras when extras_view is null (#34)', function () {
    @mkdir(__DIR__ . '/../../Stubs/views', 0775, recursive: true);
    file_put_contents(
        __DIR__ . '/../../Stubs/views/host_layout_extras.blade.php',
        '<html><body>@yield(\'content\')@stack(\'after_scripts\')</body></html>',
    );
    View::addNamespace('chatbottest', __DIR__ . '/../../Stubs/views');
    config()->set('chatbot.dashboard.layout', 'chatbottest::host_layout_extras');
    config()->set('chatbot.dashboard.section', 'content');
    config()->set('chatbot.dashboard.extras_view', null);

    $user = makeDashboardPageUser();
    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');

    // The page renders; the dead `@stack('chatbot_dashboard_extras')` literal
    // is gone — only the documented include path remains.
    expect($response->getContent())->not->toContain('HOST EXTRAS SENTINEL');
    expect($response->getContent())->not->toContain('chatbot_dashboard_extras');

    @unlink(__DIR__ . '/../../Stubs/views/host_layout_extras.blade.php');
});

// ──────────────────────────────────────────────────────────────────────────
// v2.2 — data-dashboard-context auto-inject (PR-B sub-feature)
// ──────────────────────────────────────────────────────────────────────────

it('emits data-dashboard-context as JSON "[]" when the user has no dashboards', function () {
    $user = makeDashboardPageUser();

    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');

    $response->assertOk();
    expect($response->getContent())->toContain('data-dashboard-context="[]"');
});

it('emits data-dashboard-context with slug/name/is_default/widgets for the default dashboard', function () {
    $user = makeDashboardPageUser();
    $dashboard = makeDashboardForUser($user, slug: 'panel-a', isDefault: true);
    \Rnkr69\LaraChatbot\Models\DashboardWidget::create([
        'dashboard_id'        => $dashboard->id,
        'position'            => ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
        'block_type'          => 'kpi',
        'title'               => 'Latency',
        'snapshot'            => ['data' => []],
        'source'              => ['tool' => 'echo', 'args' => []],
        'source_signature'    => 'sig',
        'refresh_policy'      => \Rnkr69\LaraChatbot\Models\WidgetRefreshPolicy::OnOpen,
        'last_refresh_status' => \Rnkr69\LaraChatbot\Models\WidgetRefreshStatus::Fresh,
        'order_index'         => 0,
    ]);

    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');

    $response->assertOk();
    // Pull the attribute string from the rendered HTML.
    preg_match('/data-dashboard-context="([^"]+)"/', $response->getContent(), $m);
    expect($m)->not->toBeEmpty();
    $context = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);
    expect($context['slug'])->toBe('panel-a');
    expect($context['name'])->toBe('Mi panel');
    expect($context['is_default'])->toBeTrue();
    expect($context['widgets'])->toHaveCount(1);
    expect($context['widgets'][0]['title'])->toBe('Latency');
    expect($context['widgets'][0]['block_type'])->toBe('kpi');
    expect($context['widgets'][0]['refresh_policy'])->toBe('on_open');
});

it('truncates widget context to id+title when the JSON exceeds page_context_kb cap', function () {
    // Force a tiny cap so just a few widgets blow past it.
    config(['chatbot.limits.page_context_kb' => 1]);

    $user = makeDashboardPageUser();
    $dashboard = makeDashboardForUser($user, slug: 'big', isDefault: true);
    for ($i = 0; $i < 10; $i++) {
        \Rnkr69\LaraChatbot\Models\DashboardWidget::create([
            'dashboard_id'        => $dashboard->id,
            'position'            => ['x' => 0, 'y' => $i, 'w' => 4, 'h' => 3],
            'block_type'          => 'kpi',
            'title'               => "Widget number {$i} with a relatively verbose title",
            'snapshot'            => ['data' => []],
            'source'              => ['tool' => 'echo', 'args' => []],
            'source_signature'    => "sig{$i}",
            'refresh_policy'      => \Rnkr69\LaraChatbot\Models\WidgetRefreshPolicy::OnOpen,
            'last_refresh_status' => \Rnkr69\LaraChatbot\Models\WidgetRefreshStatus::Fresh,
            'order_index'         => $i,
        ]);
    }

    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');

    preg_match('/data-dashboard-context="([^"]+)"/', $response->getContent(), $m);
    $context = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);

    expect($context)->toHaveKey('widgets_truncated');
    expect($context['widgets_truncated'])->toBeTrue();
    // Truncated widgets only have id + title — no block_type / position.
    expect($context['widgets'][0])->toHaveKeys(['id', 'title']);
    expect($context['widgets'][0])->not->toHaveKey('block_type');
});

it('degrades to null and logs a warning when extras_view points to a missing view (#34)', function () {
    @mkdir(__DIR__ . '/../../Stubs/views', 0775, recursive: true);
    file_put_contents(
        __DIR__ . '/../../Stubs/views/host_layout_extras.blade.php',
        '<html><body>@yield(\'content\')@stack(\'after_scripts\')</body></html>',
    );
    View::addNamespace('chatbottest', __DIR__ . '/../../Stubs/views');
    config()->set('chatbot.dashboard.layout', 'chatbottest::host_layout_extras');
    config()->set('chatbot.dashboard.section', 'content');
    config()->set('chatbot.dashboard.extras_view', 'chatbottest::missing_extras_view');

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn ($msg) => str_contains((string) $msg, 'missing_extras_view'));

    $user = makeDashboardPageUser();
    $response = $this->actingAs($user, 'web')->get('/chatbot/dashboard');

    // The page still renders — same degrade-gracefully policy as `layout`.
    $response->assertOk();

    @unlink(__DIR__ . '/../../Stubs/views/host_layout_extras.blade.php');
});
