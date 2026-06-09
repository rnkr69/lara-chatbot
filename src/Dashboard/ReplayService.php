<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Rnkr69\LaraChatbot\Events\ToolInvoked;
use Rnkr69\LaraChatbot\Models\Dashboard;
use Rnkr69\LaraChatbot\Models\DashboardWidget;
use Rnkr69\LaraChatbot\Models\WidgetRefreshStatus;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\Contracts\BackendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;
use Rnkr69\LaraChatbot\Tools\ToolResult;
use Throwable;

/**
 * Motor de replay del Personal Dashboard (v2.0 / E3, plan §4.6).
 *
 * Re-ejecuta el tool de origen de un widget respetando la MISMA cascada de
 * autorización que aplica el chat (`permission → scope → tenant → ownership`)
 * y mapea el resultado a un `WidgetRefreshStatus` que el frontend pinta como
 * badge en el header del widget.
 *
 * Por qué la cascada vive "gratis": las tools que extienden `BaseBackendTool`
 * tienen un método `execute()` que aplica validate→permission→tenant→handle
 * antes de delegar a `handle()` (donde el tool aplica el `ScopeResolver` y
 * el ownership filter vía `accessibleQuery()`). Replay reusa ese punto de
 * entrada exactamente como `ChatService::executeTool()` (línea ~503): si la
 * tool no tiene `execute()` (caso de `McpBackendTool`), cae a `handle()` sin
 * cascada — coherente con el comportamiento del chat para esas tools.
 *
 * Diferencias con la invocación del chat:
 *   - `ToolContext.conversation = null` (un widget del dashboard no está
 *     atado a ninguna conversación; lo escribimos para que un listener de
 *     audit lo distinga de un tool call dentro del chat).
 *   - `ToolContext.pageContext = source.page_context_snapshot` (el snapshot
 *     capturado al pinear; plan §4.9). La página `/chatbot/dashboard` no
 *     tiene contexto propio.
 *   - Confirmación: siempre `Auto` por contrato (el pin sólo se permite si
 *     `pinnable() && confirmation === Auto`; lo validamos defensivamente
 *     antes de ejecutar por si el tool author bajó el flag post-pin).
 *
 * Mapeo `ToolResult` → `WidgetRefreshStatus` (plan §4.6 paso 6):
 *
 *   tool no registrado                         → SourceMissing
 *   pinnable=false / confirmation != Auto      → Error (category='not_pinnable')
 *   error('unauthorized' | 'out_of_scope' |    → Unauthorized
 *         'not_owner')
 *   error(otro)  / Throwable                   → Error
 *   ok + ningún block del tipo del widget      → Stale (snapshot conservado)
 *   ok + hay blocks del tipo pero no el nº N   → Stale (snapshot conservado)
 *   ok + existe el nº N del tipo del widget    → Fresh (snapshot reemplazado)
 *
 * v2.1.2 (#27) — selección de bloque por DESCRIPTOR, no por `blocks[0]`. Un
 * tool `pinnable()` puede emitir varios bloques (el caso canónico del
 * dashboard: KPIs + gráfica). El widget guarda en `source.block_ordinal` la
 * posición 0-based del bloque ENTRE los de su tipo en la salida del tool;
 * `mapResult()` re-selecciona el N-ésimo bloque de `widget.block_type`. Si
 * el tool cambió su salida y ya no existe ese bloque → `Stale` con mensaje
 * claro — JAMÁS se persiste otro bloque como si fuera el pineado (eso era
 * el bug #27: `blocks[0]` con datos de otro KPI marcado `Fresh`). Widgets
 * pineados antes de 2.1.2 no tienen `block_ordinal` → caen a ordinal 0
 * (primer bloque de su tipo), sin migración y sin empeorar respecto a 2.1.1.
 *
 * `replayBulk()` ejecuta hasta `chatbot.dashboard.replay.concurrency`
 * widgets en paralelo (default 8) usando
 * `Concurrency::driver(config('chatbot.dashboard.replay.driver'))`. El
 * driver lo elige el PAQUETE, no el `concurrency.default` del host: el
 * default de Laravel 11+ es `process`, que hace `proc_open()` de un
 * subproceso `artisan` y revienta en Windows/WAMP, shared hosting sin
 * `pcntl` y contenedores sin `proc_open`. Por eso el paquete fija su
 * propio default `sync` (ejecución secuencial en el mismo proceso, sin
 * serialización ni subproceso — viable en cualquier entorno). Un host con
 * infra adecuada sube a `process`/`fork` vía
 * `chatbot.dashboard.replay.driver`; en tests el default `sync` se deja.
 *
 * IMPORTANTE — los tasks de `replayBulk()` son closures STATIC: no pueden
 * capturar `$this`. Los drivers `process`/`fork` serializan cada task con
 * `laravel/serializable-closure`; una closure no-static bindea `$this`, y
 * serializar `$this` arrastra el grafo entero del `ReplayService`
 * (`ToolRegistry`, `Dispatcher`, el container) → 128 MB agotados → 500.
 * El task re-resuelve `ReplayService` desde el container, así el payload
 * serializado se reduce a `$widget` + `$user` — los drivers que sí
 * serializan (`process`/`fork`) quedan seguros. Ver `docs/deployment.md`
 * §7.5.
 */
class ReplayService
{
    public function __construct(
        protected ToolRegistry $registry,
        protected Dispatcher $events,
    ) {}

    /**
     * Replay de un único widget. Persiste `last_refreshed_at`,
     * `last_refresh_status`, `last_refresh_error` siempre; el `snapshot`
     * sólo cuando el resultado es `Fresh`.
     */
    public function replay(DashboardWidget $widget, Authenticatable $user): RefreshResult
    {
        $at               = CarbonImmutable::now();
        $previousSnapshot = is_array($widget->snapshot) ? $widget->snapshot : [];
        $source           = is_array($widget->source) ? $widget->source : null;

        $toolName = is_array($source) && is_string($source['tool'] ?? null)
            ? $source['tool']
            : null;

        if ($toolName === null) {
            return $this->persist($widget, RefreshResult::sourceMissing($previousSnapshot, '(missing)', $at));
        }

        $tool = $this->registry->get($toolName);

        if ($tool === null) {
            return $this->persist($widget, RefreshResult::sourceMissing($previousSnapshot, $toolName, $at));
        }

        // Defensiva: el orquestador SSE sólo propaga `pinnable: true` cuando
        // `pinnable() && confirmation === Auto`. Si llegamos aquí con un
        // widget cuya tool ya no cumple, el author cambió el contrato post-
        // pin; lo marcamos como Error (no Unauthorized: no es un fallo de
        // permisos, sino de configuración del tool).
        if (! $tool->pinnable() || $tool->confirmation() !== ConfirmationLevel::Auto) {
            return $this->persist($widget, RefreshResult::error(
                $previousSnapshot,
                'not_pinnable',
                sprintf('La tool `%s` ya no es pinnable o cambió su nivel de confirmación.', $toolName),
                $at,
            ));
        }

        $args = is_array($source['args'] ?? null) ? $source['args'] : [];
        $pageContext = is_array($source['page_context_snapshot'] ?? null)
            ? $source['page_context_snapshot']
            : [];

        // `set_time_limit` es best-effort: hosts con `disable_functions` lo
        // tienen no-op (devuelve false) y los tools confían en sus propios
        // timeouts (Prism HTTP, queries DB). Lo aplicamos para el caso fácil
        // (PHP-FPM standard) y documentamos la key como advisory.
        $timeout = (int) config('chatbot.dashboard.replay.timeout_seconds', 15);

        if ($timeout > 0) {
            @set_time_limit($timeout);
        }

        $ctx = new ToolContext(
            user: $user,
            pageContext: $pageContext,
            conversation: null,
            locale: null,
        );

        $start      = microtime(true);
        $toolResult = $this->executeTool($tool, $args, $ctx);
        $durationMs = (microtime(true) - $start) * 1000.0;

        // Audit/PII (paridad con ChatService.onToolCall, línea ~247): el
        // host puede enganchar listeners de `ToolInvoked` para trazar
        // replays como cualquier otra invocación. `conversation=null` lo
        // distingue de un tool call del chat.
        $this->events->dispatch(new ToolInvoked(
            user: $user,
            tool: $tool,
            args: $args,
            result: $toolResult,
            durationMs: $durationMs,
            conversation: null,
        ));

        $result = $this->mapResult($toolResult, $widget, $previousSnapshot, $at);

        return $this->persist($widget, $result);
    }

    /**
     * Replay de todos los widgets de un dashboard, en chunks de
     * `chatbot.dashboard.replay.concurrency` (default 8). Devuelve un array
     * `widget_id => RefreshResult` para que el caller (E4) lo serialice en
     * el SSE de bulk-refresh.
     *
     * `Concurrency::driver(...)->run()` no tiene cap propio (lanza N closures
     * en paralelo); chunkeamos manualmente para no rebasar el cap configurado
     * cuando un dashboard tiene >8 widgets.
     *
     * Los tasks son closures STATIC que re-resuelven `ReplayService` desde
     * el container — ver el docblock de la clase para el porqué (los drivers
     * `process`/`fork` serializan cada task; capturar `$this` agotaría la
     * memoria).
     *
     * @return array<int, RefreshResult>  widget_id => RefreshResult
     */
    public function replayBulk(Dashboard $dashboard, Authenticatable $user): array
    {
        $widgets = $dashboard->widgets()->get()->all();

        if ($widgets === []) {
            return [];
        }

        $cap = (int) config('chatbot.dashboard.replay.concurrency', 8);
        if ($cap < 1) {
            $cap = 1;
        }

        // Driver del paquete (default `sync`), NO el `concurrency.default`
        // del host. `sync` corre los replays secuencialmente en el mismo
        // proceso: sin serialización, sin subproceso, viable en cualquier
        // entorno (Windows/WAMP, shared hosting, contenedores). El host con
        // infra adecuada sube a `process`/`fork` — ver el docblock de la clase.
        $driver = (string) config('chatbot.dashboard.replay.driver', 'sync');

        $results = [];

        foreach (array_chunk($widgets, $cap) as $chunk) {
            $tasks = [];

            foreach ($chunk as $widget) {
                /** @var DashboardWidget $widget */
                // STATIC closure — must not capture `$this`. A non-static
                // closure binds `$this`, and the `process`/`fork` drivers
                // serialize the task → the whole `ReplayService` object
                // graph would be dragged through `serializable-closure` →
                // memory exhausted → 500. Re-resolving from the container
                // keeps the serialized payload to `$widget` + `$user`.
                $tasks[$widget->id] = static function () use ($widget, $user): RefreshResult {
                    return app(ReplayService::class)->replay($widget, $user);
                };
            }

            /** @var array<int, RefreshResult> $chunkResults */
            $chunkResults = Concurrency::driver($driver)->run($tasks);

            // `+` preserva claves enteras (los `widget->id`). `array_merge`
            // las re-indexaría — bug subtle de PHP.
            $results = $results + $chunkResults;
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $previousSnapshot
     */
    protected function mapResult(
        ToolResult $toolResult,
        DashboardWidget $widget,
        array $previousSnapshot,
        CarbonImmutable $at,
    ): RefreshResult {
        if ($toolResult->isError()) {
            $category = $toolResult->errorCategory ?? 'runtime';
            $message  = $toolResult->errorMessage ?? $category;

            if (in_array($category, ['unauthorized', 'out_of_scope', 'not_owner'], true)) {
                return RefreshResult::unauthorized($previousSnapshot, $category, $message, $at);
            }

            return RefreshResult::error($previousSnapshot, $category, $message, $at);
        }

        if (! $toolResult->isOk()) {
            // Sólo backend tools en `Auto` entran al replay (validado arriba),
            // así que `awaiting_user` aquí indica un tool que se autodeclaró
            // pinnable pero pide confirmación al user — datos rotos.
            return RefreshResult::error(
                $previousSnapshot,
                'unexpected_status',
                'El tool devolvió awaiting_user; no compatible con replay.',
                $at,
            );
        }

        // v2.1.2 (#27) — selección por descriptor `{block_type, ordinal}`.
        // NUNCA `blocks[0]`: un tool multi-bloque devolvería el bloque
        // equivocado (corrupción silenciosa si casa el tipo, `Stale`
        // perpetuo si no). El widget se fijó al N-ésimo bloque de su tipo.
        $source = is_array($widget->source) ? $widget->source : [];

        // Widget pineado antes de 2.1.2: sin `block_ordinal` → ordinal 0
        // (primer bloque de su tipo). No es peor que el `blocks[0]` de
        // 2.1.1 y no exige migrar datos.
        $ordinal = isset($source['block_ordinal'])
            && is_int($source['block_ordinal'])
            && $source['block_ordinal'] >= 0
                ? $source['block_ordinal']
                : 0;

        // Bloques del resultado que casan el tipo del widget, en orden de
        // emisión — el índice de este array ES el ordinal del descriptor.
        $matching = [];
        foreach ($toolResult->blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === $widget->block_type) {
                $matching[] = $block;
            }
        }

        if ($matching === []) {
            return RefreshResult::stale(
                $previousSnapshot,
                $toolResult->blocks === []
                    ? 'El tool no devolvió ningún block en el último replay.'
                    : sprintf(
                        'El tool ya no emite ningún block `%s`; el widget está fijado a ese tipo.',
                        $widget->block_type,
                    ),
                $at,
            );
        }

        if (! array_key_exists($ordinal, $matching)) {
            return RefreshResult::stale(
                $previousSnapshot,
                sprintf(
                    'El tool emitió %d block(s) `%s`, pero el widget está fijado al nº %d.',
                    count($matching),
                    $widget->block_type,
                    $ordinal + 1,
                ),
                $at,
            );
        }

        $selectedBlock = $matching[$ordinal];
        $blockData = is_array($selectedBlock['data'] ?? null) ? $selectedBlock['data'] : [];
        $encoded   = json_encode($blockData);
        $byteSize  = is_string($encoded) ? strlen($encoded) : 0;

        $newSnapshot = [
            'data'        => $blockData,
            'captured_at' => $at->toIso8601String(),
            'byte_size'   => $byteSize,
        ];

        return RefreshResult::fresh($newSnapshot, $at);
    }

    /**
     * Mirror de `ChatService::executeTool()` (línea ~496): prioriza
     * `execute()` (BaseBackendTool) sobre `handle()` para que la cascada
     * validate→permission→tenant aplique automáticamente. Tools que no
     * extienden la base (e.g. `McpBackendTool`) caen a `handle()` directo
     * y la cascada no se aplica — comportamiento igual al chat.
     *
     * @param  array<string, mixed>  $args
     */
    protected function executeTool(BackendTool $tool, array $args, ToolContext $ctx): ToolResult
    {
        try {
            if (method_exists($tool, 'execute')) {
                /** @var ToolResult $result */
                $result = $tool->execute($args, $ctx);

                return $result;
            }

            return $tool->handle($args, $ctx);
        } catch (Throwable $e) {
            $correlationId = (string) Str::uuid();

            Log::error('[chatbot] dashboard replay tool threw', [
                'tool'           => $tool->name(),
                'correlation_id' => $correlationId,
                'exception'      => $e::class,
                'message'        => $e->getMessage(),
                'file'           => $e->getFile() . ':' . $e->getLine(),
            ]);

            $debug = (bool) config('app.debug', false);

            $visible = $debug
                ? ($e->getMessage() !== '' ? $e->getMessage() : $e::class)
                : "Internal tool error (ref: {$correlationId}).";

            return ToolResult::error('runtime', $visible);
        }
    }

    protected function persist(DashboardWidget $widget, RefreshResult $result): RefreshResult
    {
        $widget->last_refreshed_at   = $result->lastRefreshedAt;
        $widget->last_refresh_status = $result->status;
        $widget->last_refresh_error  = $result->error;

        if ($result->status === WidgetRefreshStatus::Fresh) {
            $widget->snapshot = $result->snapshot;
        }

        $widget->save();

        return $result;
    }
}
