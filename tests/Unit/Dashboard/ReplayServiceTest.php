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

    // Decisión del cierre E3: tests fijan `concurrency.default = sync` en
    // beforeEach para que `Concurrency::run()` corra inline sin spawnear
    // workers. La rama de producción del servicio no se entera del entorno.
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

it('marca SourceMissing cuando la tool no está en el registry', function () {
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

it('marca Error cuando pinnable() === false (snapshot conservado, tool no se invoca)', function () {
    $tool = new EchoBackendTool();
    // pinnableOverride = null → BaseBackendTool default = false. Estado
    // degradado: la tool era pinnable al pinear pero el author flipó el
    // flag a false post-pin.
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);
    $w = rsMakeWidget($d, ['tool' => 'echo_tool', 'args' => ['message' => 'hi']]);

    $r = app(ReplayService::class)->replay($w, $u);

    expect($r->status)->toBe(WidgetRefreshStatus::Error);
    expect($r->error['category'])->toBe('not_pinnable');
    expect($tool->invocations)->toBe(0);
});

it('marca Error cuando confirmation() !== Auto (defensiva post-pin)', function () {
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

it('marca Unauthorized cuando el Authorizer rechaza permisos (cascada paso 1)', function () {
    // Gate explícito denegando — el `GateAuthorizer` (default en tests) lo
    // consulta vía Gate::forUser($user)->allows('orders.read').
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
    expect($tool->invocations)->toBe(0);  // cascada cortó antes de handle()

    // Snapshot anterior preservado: jamás datos frescos no autorizados.
    expect($w->fresh()->snapshot)->toMatchArray(['data' => ['rows' => [['id' => 1]]]]);
});

it('marca Unauthorized cuando el TenantResolver devuelve [] (cascada paso 3 — out_of_scope)', function () {
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

it('marca Unauthorized cuando handle() devuelve not_owner (capa scope+ownership)', function () {
    // En producción la capa de scope se manifiesta como dataset vacío
    // (accessibleQuery() aplica whereIn(user_id, [])), no como rechazo
    // explícito. Cuando el tool quiere reportar pérdida de ownership lo
    // hace devolviendo ToolResult::error('not_owner', ...) — testeamos
    // ese camino, que el plan §4.6 mapea a Unauthorized.
    $tool = new class extends BaseBackendTool {
        public int $handleInvocations = 0;

        public function name(): string
        {
            return 'owned_tool';
        }

        public function description(): string
        {
            return 'Tool que detecta pérdida de ownership y devuelve not_owner.';
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
    expect($tool->handleInvocations)->toBe(1);  // handle se ejecutó (auth+tenant OK)
});

it('marca Error cuando el tool lanza una Throwable (atrapado, log + ToolResult error)', function () {
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
    // Snapshot anterior preservado.
    expect($w->fresh()->snapshot)->toMatchArray(['data' => ['rows' => [['id' => 1]]]]);
});

it('marca Stale cuando el tool no devuelve ningún block del type del widget (snapshot conservado)', function () {
    $tool = new EchoBackendTool();
    $tool->pinnableOverride = true;
    $tool->emitBlocks = [['type' => 'text', 'data' => ['text' => 'cambió la fuente']]];
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);
    $w = rsMakeWidget($d, ['tool' => 'echo_tool', 'args' => ['message' => 'hi']], blockType: 'table');

    $r = app(ReplayService::class)->replay($w, $u);

    expect($r->status)->toBe(WidgetRefreshStatus::Stale);
    expect($r->error['category'])->toBe('stale');
    expect($r->error['message'])->toContain('table');

    // Snapshot anterior intacto.
    expect($w->fresh()->snapshot)->toMatchArray(['data' => ['rows' => [['id' => 1]]]]);
});

it('marca Stale cuando el tool no devuelve ningún block', function () {
    $tool = new EchoBackendTool();
    $tool->pinnableOverride = true;
    $tool->emitBlocks = [];  // ok pero sin blocks
    app(ToolRegistry::class)->register($tool);

    $u = rsMakeUser();
    $d = rsMakeDashboard($u);
    $w = rsMakeWidget($d, ['tool' => 'echo_tool', 'args' => ['message' => 'hi']], blockType: 'table');

    $r = app(ReplayService::class)->replay($w, $u);

    expect($r->status)->toBe(WidgetRefreshStatus::Stale);
});

it('marca Fresh y reemplaza el snapshot cuando el block type coincide', function () {
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

// ── #27 — selección de bloque por descriptor {block_type, ordinal} ──────────
//
// Un tool `pinnable()` puede emitir varios bloques (KPIs + gráfica — el caso
// canónico del dashboard). v2.1.1 cogía SIEMPRE `blocks[0]`: corrupción
// silenciosa si el bloque pineado casaba el tipo de blocks[0], `Stale`
// perpetuo si no. v2.1.2 re-selecciona el N-ésimo bloque del tipo del widget
// vía `source.block_ordinal`.

it('#27 — un chart pineado de un tool [kpi,kpi,kpi,chart] refresca Fresh (no Stale por blocks[0])', function () {
    // El bug exacto de 2.1.1: `fleet_kpis` emite [kpi,kpi,kpi,chart]; el
    // widget chart (ordinal 0 entre los `chart`) quedaba `Stale` para
    // siempre porque blocks[0] es un `kpi`.
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

it('#27 — un kpi pineado desde el ordinal 1 refresca con SU bloque, no con kpi[0] (anti-corrupción)', function () {
    // El otro lado del bug: el widget kpi "Average fare" (2º kpi, ordinal 1)
    // se marcaba `Fresh` pero con los datos de "Total missions" (kpi[0]).
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

it('#27 — Stale (snapshot conservado) cuando el tool ya no emite el tipo del widget — JAMÁS otro bloque', function () {
    // El tool cambió su salida: ya no emite ningún `chart`. El replay debe
    // marcar `Stale` y conservar el snapshot — nunca persistir el `kpi`
    // como si fuera el bloque pineado.
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
    // Snapshot anterior intacto — no se filtró el `kpi`.
    expect($w->fresh()->snapshot)->toMatchArray(['data' => ['rows' => [['id' => 1]]]]);
});

it('#27 — Stale cuando el ordinal queda fuera de rango (el tool emite menos bloques de ese tipo)', function () {
    // El widget se fijó al 3er kpi (ordinal 2); el tool ahora sólo emite 2.
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

it('#27 — widget legacy sin block_ordinal cae a ordinal 0 (primer bloque de su tipo)', function () {
    // Widget pineado antes de 2.1.2: `source` sin `block_ordinal`. El replay
    // cae a ordinal 0 — el primer bloque del tipo del widget, no `blocks[0]`.
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
    // source SIN `block_ordinal` — shape de un widget pineado en 2.1.x.
    $w = rsMakeWidget(
        $d,
        ['tool' => 'echo_tool', 'args' => ['message' => 'hi']],
        blockType: 'kpi',
    );

    $r = app(ReplayService::class)->replay($w, $u);

    expect($r->status)->toBe(WidgetRefreshStatus::Fresh);
    // Ordinal 0 entre los `kpi` → el primer kpi, no blocks[0] (que es el chart).
    expect($r->snapshot['data'])->toBe(['label' => 'First kpi', 'value' => 10]);
});

it('aplica el page_context_snapshot guardado al ToolContext en el replay', function () {
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

it('dispara el evento ToolInvoked en cada replay (audit hook)', function () {
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

it('replayBulk devuelve un RefreshResult por widget (concurrency sync determinista)', function () {
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

it('replayBulk respeta el cap de concurrency chunkeando widgets', function () {
    // Cap = 2; con 5 widgets debe chunkear 2+2+1 = 3 invocaciones a
    // Concurrency::run() pero el resultado final tiene 5 entries.
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
    expect(array_keys($results))->toHaveCount(5);  // todas las claves enteras únicas
});

it('replayBulk sobre dashboard sin widgets devuelve []', function () {
    $u = rsMakeUser();
    $d = rsMakeDashboard($u);

    expect(app(ReplayService::class)->replayBulk($d, $u))->toBe([]);
});

it('replayBulk no depende del concurrency.default del host (#20)', function () {
    // El default de Laravel 11+ es `process`, no viable en todos los hosts
    // (Windows/WAMP, shared hosting sin pcntl, contenedores sin proc_open).
    // Ponemos `concurrency.default` a un driver que reventaría si el servicio
    // lo usara — el paquete debe ignorarlo y resolver su propia clave.
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

it('replayBulk resuelve el driver desde chatbot.dashboard.replay.driver (#20)', function () {
    // Driver inexistente en la clave del paquete → `Concurrency::driver()`
    // lanza. Prueba de que `replayBulk()` lee ESA clave y no el
    // `concurrency.default` del host (que aquí sí es válido).
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
