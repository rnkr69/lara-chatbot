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
 * Personal Dashboard replay engine (v2.0 / E3, plan §4.6).
 *
 * Re-executes a widget's source tool respecting the SAME authorization
 * cascade that the chat applies (`permission → scope → tenant → ownership`)
 * and maps the result to a `WidgetRefreshStatus` that the frontend paints as
 * a badge in the widget header.
 *
 * Why the cascade comes "for free": tools that extend `BaseBackendTool`
 * have an `execute()` method that applies validate→permission→tenant→handle
 * before delegating to `handle()` (where the tool applies the `ScopeResolver` and
 * the ownership filter via `accessibleQuery()`). Replay reuses that entry
 * point exactly like `ChatService::executeTool()` (line ~503): if the
 * tool has no `execute()` (the `McpBackendTool` case), it falls to `handle()` without
 * the cascade — consistent with the chat's behavior for those tools.
 *
 * Differences from the chat invocation:
 *   - `ToolContext.conversation = null` (a dashboard widget is not tied to
 *     any conversation; we set it so that an audit listener can
 *     distinguish it from a tool call inside the chat).
 *   - `ToolContext.pageContext = source.page_context_snapshot` (the snapshot
 *     captured at pin time; plan §4.9). The `/chatbot/dashboard` page has no
 *     context of its own.
 *   - Confirmation: always `Auto` by contract (a pin is only allowed if
 *     `pinnable() && confirmation === Auto`; we validate it defensively
 *     before executing in case the tool author lowered the flag post-pin).
 *
 * `ToolResult` → `WidgetRefreshStatus` mapping (plan §4.6 step 6):
 *
 *   tool not registered                        → SourceMissing
 *   pinnable=false / confirmation != Auto      → Error (category='not_pinnable')
 *   error('unauthorized' | 'out_of_scope' |    → Unauthorized
 *         'not_owner')
 *   error(other) / Throwable                   → Error
 *   ok + no block of the widget's type         → Stale (snapshot kept)
 *   ok + blocks of the type but not the Nth    → Stale (snapshot kept)
 *   ok + the Nth of the widget's type exists   → Fresh (snapshot replaced)
 *
 * v2.1.2 (#27) — block selection by DESCRIPTOR, not by `blocks[0]`. A
 * `pinnable()` tool can emit several blocks (the canonical dashboard case:
 * KPIs + chart). The widget stores in `source.block_ordinal` the
 * 0-based position of the block AMONG those of its type in the tool output;
 * `mapResult()` re-selects the Nth block of `widget.block_type`. If
 * the tool changed its output and that block no longer exists → `Stale` with a
 * clear message — another block is NEVER persisted as if it were the pinned one (that was
 * bug #27: `blocks[0]` with data from another KPI marked `Fresh`). Widgets
 * pinned before 2.1.2 have no `block_ordinal` → they fall to ordinal 0
 * (first block of their type), without migration and without regressing from 2.1.1.
 *
 * `replayBulk()` runs up to `chatbot.dashboard.replay.concurrency`
 * widgets in parallel (default 8) using
 * `Concurrency::driver(config('chatbot.dashboard.replay.driver'))`. The
 * driver is chosen by the PACKAGE, not by the host's `concurrency.default`: the
 * Laravel 11+ default is `process`, which does a `proc_open()` of an
 * `artisan` subprocess and blows up on Windows/WAMP, shared hosting without
 * `pcntl` and containers without `proc_open`. That is why the package sets its
 * own `sync` default (sequential execution in the same process, without
 * serialization or subprocess — viable in any environment). A host with
 * adequate infrastructure bumps it to `process`/`fork` via
 * `chatbot.dashboard.replay.driver`; in tests the `sync` default is kept.
 *
 * IMPORTANT — the `replayBulk()` tasks are STATIC closures: they cannot
 * capture `$this`. The `process`/`fork` drivers serialize each task with
 * `laravel/serializable-closure`; a non-static closure binds `$this`, and
 * serializing `$this` drags in the entire `ReplayService` object graph
 * (`ToolRegistry`, `Dispatcher`, the container) → 128 MB exhausted → 500.
 * The task re-resolves `ReplayService` from the container, so the serialized
 * payload is reduced to `$widget` + `$user` — the drivers that do
 * serialize (`process`/`fork`) stay safe. See `docs/deployment.md`
 * §7.5.
 */
class ReplayService
{
    public function __construct(
        protected ToolRegistry $registry,
        protected Dispatcher $events,
    ) {}

    /**
     * Replay of a single widget. Always persists `last_refreshed_at`,
     * `last_refresh_status`, `last_refresh_error`; the `snapshot`
     * only when the result is `Fresh`.
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

        // Defensive: the SSE orchestrator only propagates `pinnable: true` when
        // `pinnable() && confirmation === Auto`. If we get here with a
        // widget whose tool no longer complies, the author changed the contract
        // post-pin; we mark it as Error (not Unauthorized: it is not a
        // permission failure, but a tool configuration one).
        if (! $tool->pinnable() || $tool->confirmation() !== ConfirmationLevel::Auto) {
            return $this->persist($widget, RefreshResult::error(
                $previousSnapshot,
                'not_pinnable',
                sprintf('Tool `%s` is no longer pinnable or changed its confirmation level.', $toolName),
                $at,
            ));
        }

        $args = is_array($source['args'] ?? null) ? $source['args'] : [];
        $pageContext = is_array($source['page_context_snapshot'] ?? null)
            ? $source['page_context_snapshot']
            : [];

        // `set_time_limit` is best-effort: hosts with `disable_functions` have
        // it as a no-op (returns false) and the tools rely on their own
        // timeouts (Prism HTTP, DB queries). We apply it for the easy case
        // (standard PHP-FPM) and document the key as advisory.
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

        // Audit/PII (parity with ChatService.onToolCall, line ~247): the
        // host can hook `ToolInvoked` listeners to trace replays like any
        // other invocation. `conversation=null` distinguishes it from a
        // chat tool call.
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
     * Replay of all of a dashboard's widgets, in chunks of
     * `chatbot.dashboard.replay.concurrency` (default 8). Returns an array
     * `widget_id => RefreshResult` for the caller (E4) to serialize in
     * the bulk-refresh SSE.
     *
     * `Concurrency::driver(...)->run()` has no cap of its own (it launches N closures
     * in parallel); we chunk manually so as not to exceed the configured cap
     * when a dashboard has >8 widgets.
     *
     * The tasks are STATIC closures that re-resolve `ReplayService` from
     * the container — see the class docblock for why (the `process`/`fork`
     * drivers serialize each task; capturing `$this` would exhaust
     * memory).
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

        // Package driver (default `sync`), NOT the host's `concurrency.default`.
        // `sync` runs the replays sequentially in the same
        // process: no serialization, no subprocess, viable in any
        // environment (Windows/WAMP, shared hosting, containers). The host with
        // adequate infrastructure bumps it to `process`/`fork` — see the class docblock.
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

            // `+` preserves integer keys (the `widget->id`). `array_merge`
            // would re-index them — a subtle PHP bug.
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
            // Only backend tools in `Auto` enter the replay (validated above),
            // so `awaiting_user` here indicates a tool that self-declared
            // pinnable but asks the user for confirmation — broken data.
            return RefreshResult::error(
                $previousSnapshot,
                'unexpected_status',
                'The tool returned awaiting_user; not compatible with replay.',
                $at,
            );
        }

        // v2.1.2 (#27) — selection by descriptor `{block_type, ordinal}`.
        // NEVER `blocks[0]`: a multi-block tool would return the wrong
        // block (silent corruption if the type matches, perpetual `Stale`
        // if not). The widget was pinned to the Nth block of its type.
        $source = is_array($widget->source) ? $widget->source : [];

        // Widget pinned before 2.1.2: no `block_ordinal` → ordinal 0
        // (first block of its type). It is no worse than 2.1.1's `blocks[0]`
        // and does not require migrating data.
        $ordinal = isset($source['block_ordinal'])
            && is_int($source['block_ordinal'])
            && $source['block_ordinal'] >= 0
                ? $source['block_ordinal']
                : 0;

        // Result blocks that match the widget's type, in emission order
        // — the index of this array IS the descriptor's ordinal.
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
                    ? 'The tool did not return any block in the last replay.'
                    : sprintf(
                        'The tool no longer emits any `%s` block; the widget is pinned to that type.',
                        $widget->block_type,
                    ),
                $at,
            );
        }

        if (! array_key_exists($ordinal, $matching)) {
            return RefreshResult::stale(
                $previousSnapshot,
                sprintf(
                    'The tool emitted %d `%s` block(s), but the widget is pinned to #%d.',
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
     * Mirror of `ChatService::executeTool()` (line ~496): prioritizes
     * `execute()` (BaseBackendTool) over `handle()` so the
     * validate→permission→tenant cascade applies automatically. Tools that do
     * not extend the base (e.g. `McpBackendTool`) fall to `handle()` directly
     * and the cascade is not applied — same behavior as the chat.
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
