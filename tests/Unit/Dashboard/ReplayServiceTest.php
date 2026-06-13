<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Rnkr69\LaraChatbot\Authorization\Contracts\TenantResolver;
use Rnkr69\LaraChatbot\Dashboard\ReplayService;
use Rnkr69\LaraChatbot\Events\ToolInvoked;
use Rnkr69\LaraChatbot\Models\Dashboard;
use Rnkr69\LaraChatbot\Models\DashboardWidget;
use Rnkr69\LaraChatbot\Models\WidgetRefreshPolicy;
use Rnkr69\LaraChatbot\Models\WidgetRefreshStatus;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\EchoBackendTool;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\FixedTenantResolver;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;
use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;
use Rnkr69\LaraChatbot\Tools\ToolResult;

beforeEach(function () {
    $this->artisan('migrate')->run();

    // E3 closure decision: tests pin `concurrency.default = sync` in
    // beforeEach so that `Concurrency::run()` runs inline without spawning
    // workers. The service's production branch is unaware of the environment.
    config(['concurrency.default' => 'sync']);

    app(ToolRegistry::class)->clear();
});

function rsMakeUser(int $id = 1): TestUser
{
    $user = new TestUser(['id' => $id, 'name' => 'Tester']);
    $user->setRawAttributes(['id' => $id, 'name' => 'Tester'], sync: true);

    return $user;
}

function rsMakeDashboard(TestUser $user, string $slug = 'mi-panel'): Dashboard
{
    return Dashboard::create([
        'user_type'      => $user->getMorphClass(),
        'user_id'        => $user->getKey(),
        'name'           => 'Mi panel',
        'slug'           => $slug,
        'is_default'     => false,
        'layout_version' => 1,
        'metadata'       => null,
    ]);
}

/**
 * @param  array<string, mixed>  $source
 */
function rsMakeWidget(
    Dashboard $d,
    array $source,
    string $blockType = 'table',
    ?array $snapshotOverride = null,
): DashboardWidget {
    return DashboardWidget::create([
        'dashboard_id'        => $d->id,
        'position'            => ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
        'block_type'          => $blockType,
        'title'               => null,
        'snapshot'            => $snapshotOverride ?? [
            'data'        => ['rows' => [['id' => 1]]],
            'captured_at' => '2026-05-13T00:00:00Z',
            'byte_size'   => 32,
        ],
        'source'              => $source,
        'source_signature'    => str_repeat('a', 64),
        'refresh_policy'      => WidgetRefreshPolicy::OnOpen,
        'last_refreshed_at'   => null,
        'last_refresh_status' => WidgetRefreshStatus::Fresh,
        'last_refresh_error'  => null,
        'order_index'         => 0,
    ]);
}

it('marks SourceMissing when the tool is not in the registry', function () {
    $u = rsMakeUser();
    $d = rsMakeDashboard($u);
    $w = rsMakeWidget($d, ['tool' => 'ghost_tool', 'args' => []]);

    $r = app(ReplayService::class)->replay($w, $u);

    expect($r->status)->toBe(WidgetRefreshStatus::SourceMissing);
    expect($r->error)->toMatchArray(['category' => 'source_missing']);
    expect($r->error['message'])->toContain('ghost_tool');

    $reloaded = $w->fresh();
    expect($reloaded->last_refresh_status)->toBe(WidgetRefreshStatus::SourceMissing);
    expect($reloaded->snapshot)->toMatchArray(['data' => ['rows' => [['id' => 1]]]]);
    expect($reloaded->last_refreshed_at)->not->toBeNull();
});

it('marks Error when pinnable() === false (snapshot preserved, tool not invoked)', function () {
    $tool = new EchoBackendTool();
    // pinnableOverride = null → BaseBackendTool default = false. Degraded
    // state: the tool was pinnable at pin time but the author flipped the
    // flag to false post-pin.
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);
    $w = rsMakeWidget($d, ['tool' => 'echo_tool', 'args' => ['message' => 'hi']]);

    $r = app(ReplayService::class)->replay($w, $u);

    expect($r->status)->toBe(WidgetRefreshStatus::Error);
    expect($r->error['category'])->toBe('not_pinnable');
    expect($tool->invocations)->toBe(0);
});

it('marks Error when confirmation() !== Auto (defensive post-pin)', function () {
    $tool = new EchoBackendTool();
    $tool->pinnableOverride = true;
    $tool->confirmationOverride = ConfirmationLevel::Confirm;
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);
    $w = rsMakeWidget($d, ['tool' => 'echo_tool', 'args' => ['message' => 'hi']]);

    $r = app(ReplayService::class)->replay($w, $u);

    expect($r->status)->toBe(WidgetRefreshStatus::Error);
    expect($r->error['category'])->toBe('not_pinnable');
    expect($tool->invocations)->toBe(0);
});

it('marks Unauthorized when the Authorizer rejects permissions (cascade step 1)', function () {
    // Explicit Gate denying — the `GateAuthorizer` (default in tests)
    // queries it via Gate::forUser($user)->allows('orders.read').
    Gate::define('orders.read', fn () => false);

    $tool = new class extends EchoBackendTool {
        public function permissions(): array
        {
            return ['orders.read'];
        }
    };
    $tool->pinnableOverride = true;
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);
    $w = rsMakeWidget($d, ['tool' => 'echo_tool', 'args' => ['message' => 'hi']]);

    $r = app(ReplayService::class)->replay($w, $u);

    expect($r->status)->toBe(WidgetRefreshStatus::Unauthorized);
    expect($r->error['category'])->toBe('unauthorized');
    expect($tool->invocations)->toBe(0);  // cascade short-circuited before handle()

    // Previous snapshot preserved: never unauthorized fresh data.
    expect($w->fresh()->snapshot)->toMatchArray(['data' => ['rows' => [['id' => 1]]]]);
});

it('marks Unauthorized when the TenantResolver returns [] (cascade step 3 — out_of_scope)', function () {
    app()->instance(TenantResolver::class, new FixedTenantResolver(tenantIds: []));

    $tool = new class extends EchoBackendTool {
        public function tenantScope(): bool
        {
            return true;
        }
    };
    $tool->pinnableOverride = true;
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);
    $w = rsMakeWidget($d, ['tool' => 'echo_tool', 'args' => ['message' => 'hi']]);

    $r = app(ReplayService::class)->replay($w, $u);

    expect($r->status)->toBe(WidgetRefreshStatus::Unauthorized);
    expect($r->error['category'])->toBe('out_of_scope');
    expect($tool->invocations)->toBe(0);
});

it('marks Unauthorized when handle() returns not_owner (scope+ownership layer)', function () {
    // In production the scope layer manifests as an empty dataset
    // (accessibleQuery() applies whereIn(user_id, [])), not as an explicit
    // rejection. When the tool wants to report loss of ownership it does so
    // by returning ToolResult::error('not_owner', ...) — we test that path,
    // which plan §4.6 maps to Unauthorized.
    $tool = new class extends BaseBackendTool {
        public int $handleInvocations = 0;

        public function name(): string
        {
            return 'owned_tool';
        }

        public function description(): string
        {
            return 'Tool that detects ownership loss and returns not_owner.';
        }

        public function parameters(): array
        {
            return ['type' => 'object', 'properties' => [], 'required' => []];
        }

        public function pinnable(): bool
        {
            return true;
        }

        public function handle(array $args, ToolContext $ctx): ToolResult
        {
            $this->handleInvocations++;

            return ToolResult::error('not_owner', 'El recurso ya no pertenece al usuario.');
        }
    };
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);
    $w = rsMakeWidget($d, ['tool' => 'owned_tool', 'args' => []]);

    $r = app(ReplayService::class)->replay($w, $u);

    expect($r->status)->toBe(WidgetRefreshStatus::Unauthorized);
    expect($r->error['category'])->toBe('not_owner');
    expect($tool->handleInvocations)->toBe(1);  // handle ran (auth+tenant OK)
});

it('marks Error when the tool throws a Throwable (caught, log + ToolResult error)', function () {
    $tool = new EchoBackendTool();
    $tool->pinnableOverride = true;
    $tool->shouldThrow = new RuntimeException('boom');
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);
    $w = rsMakeWidget($d, ['tool' => 'echo_tool', 'args' => ['message' => 'hi']]);

    $r = app(ReplayService::class)->replay($w, $u);

    expect($r->status)->toBe(WidgetRefreshStatus::Error);
    expect($r->error['category'])->toBe('runtime');
    // Previous snapshot preserved.
    expect($w->fresh()->snapshot)->toMatchArray(['data' => ['rows' => [['id' => 1]]]]);
});

it('marks Stale when the tool returns no block of the widget type (snapshot preserved)', function () {
    $tool = new EchoBackendTool();
    $tool->pinnableOverride = true;
    $tool->emitBlocks = [['type' => 'text', 'data' => ['text' => 'source changed']]];
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);
    $w = rsMakeWidget($d, ['tool' => 'echo_tool', 'args' => ['message' => 'hi']], blockType: 'table');

    $r = app(ReplayService::class)->replay($w, $u);

    expect($r->status)->toBe(WidgetRefreshStatus::Stale);
    expect($r->error['category'])->toBe('stale');
    expect($r->error['message'])->toContain('table');

    // Previous snapshot intact.
    expect($w->fresh()->snapshot)->toMatchArray(['data' => ['rows' => [['id' => 1]]]]);
});

it('marks Stale when the tool returns no block at all', function () {
    $tool = new EchoBackendTool();
    $tool->pinnableOverride = true;
    $tool->emitBlocks = [];  // ok but without blocks
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);
    $w = rsMakeWidget($d, ['tool' => 'echo_tool', 'args' => ['message' => 'hi']], blockType: 'table');

    $r = app(ReplayService::class)->replay($w, $u);

    expect($r->status)->toBe(WidgetRefreshStatus::Stale);
});

it('marks Fresh and replaces the snapshot when the block type matches', function () {
    $tool = new EchoBackendTool();
    $tool->pinnableOverride = true;
    $tool->emitBlocks = [['type' => 'table', 'data' => ['rows' => [['id' => 7], ['id' => 8]]]]];
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);
    $w = rsMakeWidget($d, ['tool' => 'echo_tool', 'args' => ['message' => 'hi']], blockType: 'table');

    $r = app(ReplayService::class)->replay($w, $u);

    expect($r->status)->toBe(WidgetRefreshStatus::Fresh);
    expect($r->error)->toBeNull();
    expect($r->snapshot['data'])->toBe(['rows' => [['id' => 7], ['id' => 8]]]);
    expect($r->snapshot)->toHaveKey('captured_at');
    expect($r->snapshot['byte_size'])->toBeGreaterThan(0);

    $reloaded = $w->fresh();
    expect($reloaded->last_refresh_status)->toBe(WidgetRefreshStatus::Fresh);
    expect($reloaded->snapshot['data'])->toBe(['rows' => [['id' => 7], ['id' => 8]]]);
    expect($reloaded->last_refreshed_at)->not->toBeNull();
});

// ── #27 — block selection by descriptor {block_type, ordinal} ──────────────
//
// A `pinnable()` tool may emit several blocks (KPIs + chart — the canonical
// dashboard case). v2.1.1 ALWAYS took `blocks[0]`: silent corruption if the
// pinned block matched the type of blocks[0], perpetual `Stale` if not.
// v2.1.2 re-selects the N-th block of the widget's type via
// `source.block_ordinal`.

it('#27 — a chart pinned from a [kpi,kpi,kpi,chart] tool refreshes Fresh (not Stale due to blocks[0])', function () {
    // The exact 2.1.1 bug: `fleet_kpis` emits [kpi,kpi,kpi,chart]; the
    // chart widget (ordinal 0 among the `chart`s) stayed `Stale` forever
    // because blocks[0] is a `kpi`.
    $tool = new EchoBackendTool();
    $tool->pinnableOverride = true;
    $tool->emitBlocks = [
        ['type' => 'kpi',   'data' => ['label' => 'Total missions', 'value' => 203]],
        ['type' => 'kpi',   'data' => ['label' => 'Average fare', 'value' => 47]],
        ['type' => 'kpi',   'data' => ['label' => 'Fuel', 'value' => 12]],
        ['type' => 'chart', 'data' => ['kind' => 'bar', 'labels' => ['a', 'b']]],
    ];
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);
    $w = rsMakeWidget(
        $d,
        ['tool' => 'echo_tool', 'args' => ['message' => 'hi'], 'block_ordinal' => 0],
        blockType: 'chart',
    );

    $r = app(ReplayService::class)->replay($w, $u);

    expect($r->status)->toBe(WidgetRefreshStatus::Fresh);
    expect($r->snapshot['data'])->toBe(['kind' => 'bar', 'labels' => ['a', 'b']]);
});

it('#27 — a kpi pinned from ordinal 1 refreshes with ITS block, not with kpi[0] (anti-corruption)', function () {
    // The other side of the bug: the "Average fare" kpi widget (2nd kpi,
    // ordinal 1) was marked `Fresh` but with the "Total missions" data (kpi[0]).
    $tool = new EchoBackendTool();
    $tool->pinnableOverride = true;
    $tool->emitBlocks = [
        ['type' => 'kpi', 'data' => ['label' => 'Total missions', 'value' => 203]],
        ['type' => 'kpi', 'data' => ['label' => 'Average fare', 'value' => 47]],
        ['type' => 'kpi', 'data' => ['label' => 'Fuel', 'value' => 12]],
    ];
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);
    $w = rsMakeWidget(
        $d,
        ['tool' => 'echo_tool', 'args' => ['message' => 'hi'], 'block_ordinal' => 1],
        blockType: 'kpi',
    );

    $r = app(ReplayService::class)->replay($w, $u);

    expect($r->status)->toBe(WidgetRefreshStatus::Fresh);
    expect($r->snapshot['data'])->toBe(['label' => 'Average fare', 'value' => 47]);
});

it('#27 — Stale (snapshot preserved) when the tool no longer emits the widget type — NEVER another block', function () {
    // The tool changed its output: it no longer emits any `chart`. The replay
    // should mark `Stale` and keep the snapshot — never persist the `kpi`
    // as if it were the pinned block.
    $tool = new EchoBackendTool();
    $tool->pinnableOverride = true;
    $tool->emitBlocks = [
        ['type' => 'kpi', 'data' => ['label' => 'Total', 'value' => 1]],
    ];
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);
    $w = rsMakeWidget(
        $d,
        ['tool' => 'echo_tool', 'args' => ['message' => 'hi'], 'block_ordinal' => 0],
        blockType: 'chart',
    );

    $r = app(ReplayService::class)->replay($w, $u);

    expect($r->status)->toBe(WidgetRefreshStatus::Stale);
    expect($r->error['message'])->toContain('chart');
    // Previous snapshot intact — the `kpi` was not leaked.
    expect($w->fresh()->snapshot)->toMatchArray(['data' => ['rows' => [['id' => 1]]]]);
});

it('#27 — Stale when the ordinal is out of range (the tool emits fewer blocks of that type)', function () {
    // The widget was pinned to the 3rd kpi (ordinal 2); the tool now emits only 2.
    $tool = new EchoBackendTool();
    $tool->pinnableOverride = true;
    $tool->emitBlocks = [
        ['type' => 'kpi', 'data' => ['label' => 'A', 'value' => 1]],
        ['type' => 'kpi', 'data' => ['label' => 'B', 'value' => 2]],
    ];
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);
    $w = rsMakeWidget(
        $d,
        ['tool' => 'echo_tool', 'args' => ['message' => 'hi'], 'block_ordinal' => 2],
        blockType: 'kpi',
    );

    $r = app(ReplayService::class)->replay($w, $u);

    expect($r->status)->toBe(WidgetRefreshStatus::Stale);
    expect($r->error['message'])->toContain('kpi');
    expect($w->fresh()->snapshot)->toMatchArray(['data' => ['rows' => [['id' => 1]]]]);
});

it('#27 — legacy widget without block_ordinal falls back to ordinal 0 (first block of its type)', function () {
    // Widget pinned before 2.1.2: `source` without `block_ordinal`. The replay
    // falls back to ordinal 0 — the first block of the widget's type, not `blocks[0]`.
    $tool = new EchoBackendTool();
    $tool->pinnableOverride = true;
    $tool->emitBlocks = [
        ['type' => 'chart', 'data' => ['kind' => 'line']],
        ['type' => 'kpi',   'data' => ['label' => 'First kpi', 'value' => 10]],
        ['type' => 'kpi',   'data' => ['label' => 'Second kpi', 'value' => 20]],
    ];
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);
    // source WITHOUT `block_ordinal` — shape of a widget pinned in 2.1.x.
    $w = rsMakeWidget(
        $d,
        ['tool' => 'echo_tool', 'args' => ['message' => 'hi']],
        blockType: 'kpi',
    );

    $r = app(ReplayService::class)->replay($w, $u);

    expect($r->status)->toBe(WidgetRefreshStatus::Fresh);
    // Ordinal 0 among the `kpi`s → the first kpi, not blocks[0] (which is the chart).
    expect($r->snapshot['data'])->toBe(['label' => 'First kpi', 'value' => 10]);
});

it('applies the stored page_context_snapshot to the ToolContext on replay', function () {
    $captured = ['ctx' => null];

    $tool = new class ($captured) extends BaseBackendTool {
        /** @var array{ctx: ?ToolContext} */
        public array $capture;

        public function __construct(array &$capture)
        {
            $this->capture = &$capture;
        }

        public function name(): string
        {
            return 'ctx_tool';
        }

        public function description(): string
        {
            return '';
        }

        public function parameters(): array
        {
            return ['type' => 'object', 'properties' => [], 'required' => []];
        }

        public function pinnable(): bool
        {
            return true;
        }

        public function handle(array $args, ToolContext $ctx): ToolResult
        {
            $this->capture['ctx'] = $ctx;

            return ToolResult::success([], [['type' => 'table', 'data' => ['rows' => []]]]);
        }
    };
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);
    $w = rsMakeWidget($d, [
        'tool'                   => 'ctx_tool',
        'args'                   => [],
        'page_context_snapshot'  => ['entity' => 'invoice', 'id' => 42],
    ], blockType: 'table');

    app(ReplayService::class)->replay($w, $u);

    expect($captured['ctx'])->toBeInstanceOf(ToolContext::class);
    expect($captured['ctx']->pageContext)->toBe(['entity' => 'invoice', 'id' => 42]);
    expect($captured['ctx']->conversation)->toBeNull();
});

it('dispatches the ToolInvoked event on every replay (audit hook)', function () {
    Event::fake();

    $tool = new EchoBackendTool();
    $tool->pinnableOverride = true;
    $tool->emitBlocks = [['type' => 'table', 'data' => []]];
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);
    $w = rsMakeWidget($d, ['tool' => 'echo_tool', 'args' => ['message' => 'hi']], blockType: 'table');

    app(ReplayService::class)->replay($w, $u);

    Event::assertDispatched(
        ToolInvoked::class,
        fn (ToolInvoked $e): bool => $e->tool === $tool
            && $e->conversation === null
            && $e->result->isOk()
    );
});

it('replayBulk returns one RefreshResult per widget (deterministic sync concurrency)', function () {
    $tool = new EchoBackendTool();
    $tool->pinnableOverride = true;
    $tool->emitBlocks = [['type' => 'table', 'data' => ['rows' => []]]];
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);

    $widgets = [];
    for ($i = 0; $i < 5; $i++) {
        $widgets[] = rsMakeWidget(
            $d,
            ['tool' => 'echo_tool', 'args' => ['message' => "w{$i}"]],
            blockType: 'table',
        );
    }

    $results = app(ReplayService::class)->replayBulk($d, $u);

    expect($results)->toHaveCount(5);

    foreach ($widgets as $w) {
        expect($results)->toHaveKey($w->id);
        expect($results[$w->id]->status)->toBe(WidgetRefreshStatus::Fresh);
    }
});

it('replayBulk respects the concurrency cap by chunking widgets', function () {
    // Cap = 2; with 5 widgets it should chunk into 2+2+1 = 3 invocations of
    // Concurrency::run() but the final result has 5 entries.
    config(['chatbot.dashboard.replay.concurrency' => 2]);

    $tool = new EchoBackendTool();
    $tool->pinnableOverride = true;
    $tool->emitBlocks = [['type' => 'table', 'data' => []]];
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);

    for ($i = 0; $i < 5; $i++) {
        rsMakeWidget($d, ['tool' => 'echo_tool', 'args' => ['message' => "w{$i}"]], blockType: 'table');
    }

    $results = app(ReplayService::class)->replayBulk($d, $u);

    expect($results)->toHaveCount(5);
    expect(array_keys($results))->toHaveCount(5);  // all integer keys unique
});

it('replayBulk on a dashboard without widgets returns []', function () {
    $u = rsMakeUser();
    $d = rsMakeDashboard($u);

    expect(app(ReplayService::class)->replayBulk($d, $u))->toBe([]);
});

it('replayBulk does not depend on the host concurrency.default (#20)', function () {
    // The Laravel 11+ default is `process`, not viable on all hosts
    // (Windows/WAMP, shared hosting without pcntl, containers without proc_open).
    // We set `concurrency.default` to a driver that would blow up if the service
    // used it — the package should ignore it and resolve its own key.
    config(['concurrency.default' => 'this-driver-does-not-exist']);
    config(['chatbot.dashboard.replay.driver' => 'sync']);

    $tool = new EchoBackendTool();
    $tool->pinnableOverride = true;
    $tool->emitBlocks = [['type' => 'table', 'data' => ['rows' => []]]];
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);
    rsMakeWidget($d, ['tool' => 'echo_tool', 'args' => ['message' => 'hi']], blockType: 'table');

    $results = app(ReplayService::class)->replayBulk($d, $u);

    expect($results)->toHaveCount(1);
    expect(array_values($results)[0]->status)->toBe(WidgetRefreshStatus::Fresh);
});

it('replayBulk resolves the driver from chatbot.dashboard.replay.driver (#20)', function () {
    // Nonexistent driver in the package's key → `Concurrency::driver()`
    // throws. Proves that `replayBulk()` reads THAT key and not the host's
    // `concurrency.default` (which here is valid).
    config(['concurrency.default' => 'sync']);
    config(['chatbot.dashboard.replay.driver' => 'no-such-driver']);

    $tool = new EchoBackendTool();
    $tool->pinnableOverride = true;
    $tool->emitBlocks = [['type' => 'table', 'data' => []]];
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);
    rsMakeWidget($d, ['tool' => 'echo_tool', 'args' => []], blockType: 'table');

    expect(fn () => app(ReplayService::class)->replayBulk($d, $u))
        ->toThrow(InvalidArgumentException::class);
});
