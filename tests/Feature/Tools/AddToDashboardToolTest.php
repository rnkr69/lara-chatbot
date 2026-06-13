<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Rnkr69\LaraChatbot\Models\Dashboard;
use Rnkr69\LaraChatbot\Models\DashboardWidget;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\EchoBackendTool;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\PermissionedTool;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\StrictArgsTool;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;
use Rnkr69\LaraChatbot\Tools\Backend\AddToDashboardTool;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;

/**
 * v2.2 / PR-A — `AddToDashboardTool`. Pins from the chat without going
 * through the manual modal. Covers the full cascade: source tool resolution,
 * pinnable enforcement, dashboard target (slug or default), widget cap,
 * source tool execution + mapping of its error, block selection
 * (`block_type` + `block_ordinal`) and persistence via `PinService`.
 */

beforeEach(function () {
    $this->artisan('migrate')->run();
    app(ToolRegistry::class)->clear();
});

function atdMakeUser(int $id = 1, string $name = 'Tester'): TestUser
{
    $user = new TestUser(['id' => $id, 'name' => $name]);
    $user->setRawAttributes(['id' => $id, 'name' => $name], sync: true);

    return $user;
}

function atdMakeDashboard(TestUser $user, string $slug = 'panel', bool $isDefault = true, string $name = 'Panel'): Dashboard
{
    return Dashboard::create([
        'user_type'      => $user->getMorphClass(),
        'user_id'        => $user->getKey(),
        'name'           => $name,
        'slug'           => $slug,
        'is_default'     => $isDefault,
        'layout_version' => 1,
        'metadata'       => null,
    ]);
}

function atdRegisterEcho(array $emitBlocks = [], ConfirmationLevel $confirmation = ConfirmationLevel::Auto, bool $pinnable = true): EchoBackendTool
{
    $tool = new EchoBackendTool();
    $tool->pinnableOverride = $pinnable;
    $tool->confirmationOverride = $confirmation;
    $tool->emitBlocks = $emitBlocks;
    app(ToolRegistry::class)->register($tool);

    return $tool;
}

function atdInvoke(TestUser $user, array $args): \Rnkr69\LaraChatbot\Tools\ToolResult
{
    /** @var AddToDashboardTool $tool */
    $tool = app(AddToDashboardTool::class);
    $ctx = new ToolContext(user: $user, pageContext: ['route' => '/chatbot/dashboard']);

    return $tool->execute($args, $ctx);
}

// ──────────────────────────────────────────────────────────────────────────
// Happy path
// ──────────────────────────────────────────────────────────────────────────

it('pins a block from a pinnable source tool to the user\'s default dashboard', function () {
    atdRegisterEcho([['type' => 'kpi', 'data' => ['label' => 'Latency', 'value' => 420]]]);
    $u = atdMakeUser();
    $d = atdMakeDashboard($u);

    $result = atdInvoke($u, [
        'source_tool' => 'echo_tool',
        'source_args' => ['message' => 'hi'],
    ]);

    expect($result->isOk())->toBeTrue();
    expect($result->data['dashboard_slug'])->toBe($d->slug);

    $widget = DashboardWidget::query()->where('dashboard_id', $d->id)->firstOrFail();
    expect($widget->block_type)->toBe('kpi');
    expect($widget->source['tool'])->toBe('echo_tool');
    expect($widget->source['args'])->toBe(['message' => 'hi']);
    expect($widget->source['block_ordinal'])->toBe(0);

    // The success card the LLM relays.
    expect($result->blocks)->toHaveCount(1);
    expect($result->blocks[0]['type'])->toBe('card');
});

it('emits dashboard_url with ?dashboard= so the DashboardController deep-link resolves to the pinned dashboard', function () {
    // v2.2.1 — DashboardController::resolveDefaultSlug reads
    // $request->query('dashboard'). Before the fix the tool emitted
    // `?slug=`, which the controller silently ignored — the "Open
    // dashboard" card link landed on the user's default panel instead
    // of the one the LLM just pinned to.
    atdRegisterEcho([['type' => 'kpi', 'data' => []]]);
    $u = atdMakeUser();
    atdMakeDashboard($u, slug: 'default-one', isDefault: true);
    atdMakeDashboard($u, slug: 'qa-panel', isDefault: false, name: 'QA');

    $result = atdInvoke($u, [
        'source_tool'    => 'echo_tool',
        'source_args'    => ['message' => 'hi'],
        'dashboard_slug' => 'qa-panel',
    ]);

    expect($result->isOk())->toBeTrue();
    expect($result->data['dashboard_url'])->toContain('?dashboard=qa-panel');
    expect($result->data['dashboard_url'])->not->toContain('?slug=');
});

it('stamps meta.side_effects on the success card so the dashboard bundle can refresh', function () {
    atdRegisterEcho([['type' => 'kpi', 'data' => []]]);
    $u = atdMakeUser();
    $d = atdMakeDashboard($u);

    $result = atdInvoke($u, [
        'source_tool' => 'echo_tool',
        'source_args' => ['message' => 'hi'],
    ]);

    expect($result->isOk())->toBeTrue();
    $widget = DashboardWidget::query()->where('dashboard_id', $d->id)->firstOrFail();

    $meta = $result->blocks[0]['meta'] ?? null;
    expect($meta)->toBeArray();
    expect($meta['side_effects'])->toEqual([
        'type'           => 'widget_added',
        'dashboard_slug' => $d->slug,
        'widget_id'      => $widget->id,
    ]);
});

it('uses suggested title when provided', function () {
    atdRegisterEcho([['type' => 'kpi', 'data' => ['label' => 'Latency']]]);
    $u = atdMakeUser();
    atdMakeDashboard($u);

    $result = atdInvoke($u, [
        'source_tool' => 'echo_tool',
        'source_args' => ['message' => 'hi'],
        'title'       => 'My latency widget',
    ]);

    expect($result->isOk())->toBeTrue();
    $widget = DashboardWidget::query()->firstOrFail();
    expect($widget->title)->toBe('My latency widget');
});

it('pins to a named dashboard when dashboard_slug is provided', function () {
    atdRegisterEcho([['type' => 'kpi', 'data' => []]]);
    $u = atdMakeUser();
    atdMakeDashboard($u, slug: 'default-one', isDefault: true);
    $target = atdMakeDashboard($u, slug: 'qa-panel', isDefault: false, name: 'QA');

    $result = atdInvoke($u, [
        'source_tool'    => 'echo_tool',
        'source_args'    => ['message' => 'hi'],
        'dashboard_slug' => 'qa-panel',
    ]);

    expect($result->isOk())->toBeTrue();
    expect($result->data['dashboard_slug'])->toBe('qa-panel');
    expect(DashboardWidget::query()->where('dashboard_id', $target->id)->count())->toBe(1);
});

// ──────────────────────────────────────────────────────────────────────────
// Error path: tool resolution
// ──────────────────────────────────────────────────────────────────────────

it('returns tool_not_found error when source_tool is not registered, with list of alternatives', function () {
    atdRegisterEcho([['type' => 'kpi', 'data' => []]]);
    $u = atdMakeUser();
    atdMakeDashboard($u);

    $result = atdInvoke($u, ['source_tool' => 'ghost_tool']);

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('tool_not_found');
    // Lists available pinnable+Auto tools, so the LLM can self-correct.
    expect($result->errorMessage)->toContain('echo_tool');
});

it('returns not_pinnable when the source tool has pinnable=false', function () {
    atdRegisterEcho([['type' => 'kpi', 'data' => []]], pinnable: false);
    $u = atdMakeUser();
    atdMakeDashboard($u);

    $result = atdInvoke($u, ['source_tool' => 'echo_tool']);

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('not_pinnable');
});

it('returns not_pinnable when the source tool requires Confirm (mutating)', function () {
    atdRegisterEcho([['type' => 'kpi', 'data' => []]], confirmation: ConfirmationLevel::Confirm);
    $u = atdMakeUser();
    atdMakeDashboard($u);

    $result = atdInvoke($u, ['source_tool' => 'echo_tool']);

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('not_pinnable');
});

// ──────────────────────────────────────────────────────────────────────────
// Error path: dashboard resolution
// ──────────────────────────────────────────────────────────────────────────

it('returns no_dashboard error when the user has no dashboards', function () {
    atdRegisterEcho([['type' => 'kpi', 'data' => []]]);
    $u = atdMakeUser();

    $result = atdInvoke($u, ['source_tool' => 'echo_tool']);

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('no_dashboard');
    expect($result->errorMessage)->toContain('/chatbot/dashboard');
});

it('returns dashboard_not_found when slug does not match, with list of user dashboards', function () {
    atdRegisterEcho([['type' => 'kpi', 'data' => []]]);
    $u = atdMakeUser();
    atdMakeDashboard($u, slug: 'panel-a');
    atdMakeDashboard($u, slug: 'panel-b', isDefault: false);

    $result = atdInvoke($u, [
        'source_tool'    => 'echo_tool',
        'dashboard_slug' => 'unknown',
    ]);

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('dashboard_not_found');
    expect($result->errorMessage)->toContain("'panel-a'");
});

// ──────────────────────────────────────────────────────────────────────────
// Error path: cap_reached
// ──────────────────────────────────────────────────────────────────────────

it('returns cap_reached when the dashboard already has max_widgets_per_dashboard widgets', function () {
    config(['chatbot.dashboard.max_widgets_per_dashboard' => 2]);
    atdRegisterEcho([['type' => 'kpi', 'data' => []]]);
    $u = atdMakeUser();
    $d = atdMakeDashboard($u);

    // Pre-fill to the cap.
    DashboardWidget::create([
        'dashboard_id'        => $d->id,
        'position'            => ['x' => 0, 'y' => 0, 'w' => 3, 'h' => 2],
        'block_type'          => 'kpi',
        'snapshot'            => ['data' => []],
        'source'              => ['tool' => 'echo_tool', 'args' => []],
        'source_signature'    => 'sig1',
        'refresh_policy'      => \Rnkr69\LaraChatbot\Models\WidgetRefreshPolicy::OnOpen,
        'last_refresh_status' => \Rnkr69\LaraChatbot\Models\WidgetRefreshStatus::Fresh,
        'order_index'         => 0,
    ]);
    DashboardWidget::create([
        'dashboard_id'        => $d->id,
        'position'            => ['x' => 3, 'y' => 0, 'w' => 3, 'h' => 2],
        'block_type'          => 'kpi',
        'snapshot'            => ['data' => []],
        'source'              => ['tool' => 'echo_tool', 'args' => []],
        'source_signature'    => 'sig2',
        'refresh_policy'      => \Rnkr69\LaraChatbot\Models\WidgetRefreshPolicy::OnOpen,
        'last_refresh_status' => \Rnkr69\LaraChatbot\Models\WidgetRefreshStatus::Fresh,
        'order_index'         => 1,
    ]);

    $result = atdInvoke($u, ['source_tool' => 'echo_tool']);

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('cap_reached');
    expect($result->errorMessage)->toContain('2/2');
});

// ──────────────────────────────────────────────────────────────────────────
// Error path: source tool execution
// ──────────────────────────────────────────────────────────────────────────

it('maps source tool validation error to source_args_invalid', function () {
    $tool = new StrictArgsTool();
    app(ToolRegistry::class)->register($tool);
    // StrictArgsTool only has pinnable=false by default; we need to nudge it
    // to pinnable+Auto so the pinnable check passes. The doc admits this is
    // a contrived combination — StrictArgsTool is read-only-ish.
    // Since we cannot easily override its pinnable() externally, use an Echo
    // tool with required arg that we leave out.
    app(ToolRegistry::class)->clear();
    atdRegisterEcho([['type' => 'kpi', 'data' => []]]);
    $u = atdMakeUser();
    atdMakeDashboard($u);

    // EchoBackendTool has `message` required in parameters(). Omit it.
    $result = atdInvoke($u, [
        'source_tool' => 'echo_tool',
        'source_args' => [],
    ]);

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('source_args_invalid');
});

it('maps source tool runtime error to source_runtime', function () {
    $tool = atdRegisterEcho([['type' => 'kpi', 'data' => []]]);
    $tool->shouldFail = true;
    $u = atdMakeUser();
    atdMakeDashboard($u);

    $result = atdInvoke($u, [
        'source_tool' => 'echo_tool',
        'source_args' => ['message' => 'hi'],
    ]);

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('source_runtime');
});

it('maps source tool thrown exception to source_runtime', function () {
    $tool = atdRegisterEcho([['type' => 'kpi', 'data' => []]]);
    $tool->shouldThrow = new RuntimeException('boom');
    $u = atdMakeUser();
    atdMakeDashboard($u);

    $result = atdInvoke($u, [
        'source_tool' => 'echo_tool',
        'source_args' => ['message' => 'hi'],
    ]);

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('source_runtime');
});

it('maps source tool unauthorized to unauthorized', function () {
    // PermissionedTool requires `orders.read`. With the GateAuthorizer
    // default and no `Gate::define`, the check fails → unauthorized.
    // Force its pinnable+Auto via subclass to bypass not_pinnable.
    $tool = new class extends PermissionedTool {
        public function pinnable(): bool { return true; }
        public function handle(array $args, ToolContext $ctx): \Rnkr69\LaraChatbot\Tools\ToolResult
        {
            return \Rnkr69\LaraChatbot\Tools\ToolResult::success(data: [], blocks: [['type' => 'kpi', 'data' => []]]);
        }
    };
    app(ToolRegistry::class)->register($tool);
    $u = atdMakeUser();
    atdMakeDashboard($u);

    $result = atdInvoke($u, ['source_tool' => 'permissioned_tool']);

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('unauthorized');
});

// ──────────────────────────────────────────────────────────────────────────
// Error path: block selection
// ──────────────────────────────────────────────────────────────────────────

it('returns no_block when the source tool emits zero blocks matching block_type', function () {
    atdRegisterEcho([['type' => 'kpi', 'data' => []]]);
    $u = atdMakeUser();
    atdMakeDashboard($u);

    $result = atdInvoke($u, [
        'source_tool' => 'echo_tool',
        'source_args' => ['message' => 'hi'],
        'block_type'  => 'chart',
    ]);

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('no_block');
});

it('returns no_block when the source tool emits zero blocks at all', function () {
    atdRegisterEcho(emitBlocks: []);
    $u = atdMakeUser();
    atdMakeDashboard($u);

    $result = atdInvoke($u, [
        'source_tool' => 'echo_tool',
        'source_args' => ['message' => 'hi'],
    ]);

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('no_block');
});

it('returns ordinal_out_of_range when block_ordinal exceeds the candidate count', function () {
    atdRegisterEcho([
        ['type' => 'kpi', 'data' => ['v' => 1]],
        ['type' => 'kpi', 'data' => ['v' => 2]],
    ]);
    $u = atdMakeUser();
    atdMakeDashboard($u);

    $result = atdInvoke($u, [
        'source_tool'   => 'echo_tool',
        'source_args'   => ['message' => 'hi'],
        'block_type'    => 'kpi',
        'block_ordinal' => 99,
    ]);

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('ordinal_out_of_range');
});

// ──────────────────────────────────────────────────────────────────────────
// Block selection: type + ordinal
// ──────────────────────────────────────────────────────────────────────────

it('filters by block_type when the source tool emits multiple types', function () {
    // Mimic a real fleet_kpis-like output: kpi + chart.
    atdRegisterEcho([
        ['type' => 'kpi',   'data' => ['v' => 1]],
        ['type' => 'kpi',   'data' => ['v' => 2]],
        ['type' => 'chart', 'data' => ['series' => []]],
    ]);
    $u = atdMakeUser();
    atdMakeDashboard($u);

    $result = atdInvoke($u, [
        'source_tool' => 'echo_tool',
        'source_args' => ['message' => 'hi'],
        'block_type'  => 'chart',
    ]);

    expect($result->isOk())->toBeTrue();
    $widget = DashboardWidget::query()->firstOrFail();
    expect($widget->block_type)->toBe('chart');
});

it('selects the N-th block of the given type via block_ordinal', function () {
    atdRegisterEcho([
        ['type' => 'kpi', 'data' => ['label' => 'first']],
        ['type' => 'kpi', 'data' => ['label' => 'second']],
        ['type' => 'kpi', 'data' => ['label' => 'third']],
    ]);
    $u = atdMakeUser();
    atdMakeDashboard($u);

    $result = atdInvoke($u, [
        'source_tool'   => 'echo_tool',
        'source_args'   => ['message' => 'hi'],
        'block_type'    => 'kpi',
        'block_ordinal' => 2,
    ]);

    expect($result->isOk())->toBeTrue();
    $widget = DashboardWidget::query()->firstOrFail();
    expect($widget->snapshot['data']['label'])->toBe('third');
    expect($widget->source['block_ordinal'])->toBe(2);
});

it('rejects a negative block_ordinal via JSON Schema validation', function () {
    atdRegisterEcho([['type' => 'kpi', 'data' => []]]);
    $u = atdMakeUser();
    atdMakeDashboard($u);

    // BaseBackendTool::validateArgs converts the JSON Schema to Laravel
    // rules. integer type + handle()'s `>= 0` guard treat -1 as ordinal 0.
    $result = atdInvoke($u, [
        'source_tool'   => 'echo_tool',
        'source_args'   => ['message' => 'hi'],
        'block_ordinal' => -1,
    ]);

    // Falls back to ordinal 0 (the guard).
    expect($result->isOk())->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────
// JSON Schema validation
// ──────────────────────────────────────────────────────────────────────────

it('returns validation error when source_tool is missing', function () {
    $u = atdMakeUser();
    atdMakeDashboard($u);

    $result = atdInvoke($u, []);

    expect($result->isError())->toBeTrue();
    expect($result->errorCategory)->toBe('validation');
});
