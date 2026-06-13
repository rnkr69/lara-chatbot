<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Dashboard;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Rnkr69\LaraChatbot\Models\Dashboard;
use Rnkr69\LaraChatbot\Models\DashboardWidget;
use Rnkr69\LaraChatbot\Models\WidgetRefreshPolicy;
use Rnkr69\LaraChatbot\Models\WidgetRefreshStatus;
use Rnkr69\LaraChatbot\Services\PageContextSanitizer;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\Contracts\BackendTool;

/**
 * v2.2 — Pins a block as a `DashboardWidget`. Extracts all the orchestration
 * logic that lived inline in `ApiDashboardWidgetController::store`:
 * pinnable defense, widget cap, snapshot truncation, sanitization +
 * filtering of the page_context to the keys captured at pin time, default
 * position by `block_type`, source signature, persist + touch of the dashboard.
 *
 * Two callers share the service:
 *
 *   1. **HTTP controller** (`POST /chatbot/dashboards/{slug}/widgets`).
 *      Historical path — the user clicks 📌 on a block in the chat;
 *      the JS client already has the snapshot and sends `{block_id, snapshot,
 *      source, ...}` to the server. The controller resolves the tool + dashboard
 *      and calls this service.
 *   2. **`AddToDashboardTool`** (auto-pin from the chat, v2.2 PR-A). The LLM
 *      invokes the tool with `{source_tool, source_args, dashboard_slug?, ...}`;
 *      the tool executes the source_tool, selects the appropriate block and calls
 *      this service.
 *
 * Domain errors propagate as `PinException` with a category
 * (`cap_reached`, `not_pinnable`). Each caller maps it to its own shape:
 * controller → JSON 422, tool → `ToolResult::error(...)`.
 *
 * No HTTP contract changes: the persisted widget shape (including the
 * `source` descriptor) is identical to v2.1.x to preserve replay
 * compatibility.
 */
class PinService
{
    public function __construct(
        protected PageContextSanitizer $sanitizer,
    ) {}

    /**
     * @param array<string, mixed> $sourceArgs
     * @param array{type:string, data?: array<string, mixed>, id?: string, ordinal?: int} $block
     *                                                                             the selected block to persist.
     *                                                                             `data` is the snapshot body; `id`/`ordinal`
     *                                                                             are optional (audit + replay matching).
     * @param array<string, mixed>|null $pageContext     RAW page_context from the request (unsanitized, unfiltered);
     *                                                   this service applies both steps.
     * @param array<int, string>|null   $pageContextKeys page_context keys captured at pin time — only this subset
     *                                                   of the page_context is persisted in `source.page_context_snapshot`.
     * @param array<string, mixed>|null $position        client-provided position (clamped); `null` = default position
     *                                                   `(x:0, y:9999)` with `w/h` heuristics by block_type.
     *
     * @throws PinException cap_reached | not_pinnable
     */
    public function pin(
        Dashboard $dashboard,
        BackendTool $sourceTool,
        array $sourceArgs,
        array $block,
        ?string $suggestedTitle = null,
        ?array $pageContext = null,
        ?array $pageContextKeys = null,
        ?array $position = null,
    ): DashboardWidget {
        // 1. Defense-in-depth (parity with controller l.103–113). Even though the
        //    caller usually pre-checks, the service does not trust it.
        if (! $sourceTool->pinnable() || $sourceTool->confirmation() !== ConfirmationLevel::Auto) {
            throw PinException::notPinnable($sourceTool->name());
        }

        // 2. Widget cap (parity with controller l.70–83).
        $cap = (int) config('chatbot.dashboard.max_widgets_per_dashboard', 50);
        $current = $dashboard->widgets()->count();

        if ($cap > 0 && $current >= $cap) {
            throw PinException::capReached($cap, $current);
        }

        // 3. Derived attributes.
        $blockType = (string) ($block['type'] ?? '');
        $blockData = is_array($block['data'] ?? null) ? $block['data'] : [];
        $snapshot  = $this->prepareSnapshot(['data' => $blockData]);

        $keys = is_array($pageContextKeys) ? $pageContextKeys : [];
        $pageContextSnapshot = $this->capturePageContextSnapshot(
            is_array($pageContext) ? $pageContext : [],
            $keys,
        );

        $toolName = $sourceTool->name();
        $persistedSource = [
            'tool'                  => $toolName,
            'args'                  => $sourceArgs,
            'page_context_keys'     => array_values(array_filter($keys, 'is_string')),
            'page_context_snapshot' => $pageContextSnapshot,
        ];

        $blockId = $block['id'] ?? null;
        if (is_string($blockId) && $blockId !== '') {
            $persistedSource['block_id'] = $blockId;
        }

        $blockOrdinal = $block['ordinal'] ?? null;
        if (is_int($blockOrdinal) && $blockOrdinal >= 0) {
            $persistedSource['block_ordinal'] = $blockOrdinal;
        }

        $resolvedPosition = WidgetPositionNormalizer::normalize($position, $blockType);
        $orderIndex      = ((int) $dashboard->widgets()->max('order_index')) + 1;
        $defaultPolicy   = (string) config('chatbot.dashboard.default_refresh_policy', 'on_open');
        $policy          = WidgetRefreshPolicy::tryFrom($defaultPolicy) ?? WidgetRefreshPolicy::OnOpen;

        $widget = DashboardWidget::create([
            'dashboard_id'        => $dashboard->id,
            'position'            => $resolvedPosition,
            'block_type'          => $blockType,
            'title'               => $suggestedTitle,
            'snapshot'            => $snapshot,
            'source'              => $persistedSource,
            'source_signature'    => SourceSignature::for($toolName, $sourceArgs),
            'refresh_policy'      => $policy,
            'last_refreshed_at'   => Carbon::now(),
            'last_refresh_status' => WidgetRefreshStatus::Fresh,
            'last_refresh_error'  => null,
            'order_index'         => $orderIndex,
        ]);

        // Touch the dashboard so `updated_at` reflects "last pin time" — the
        // sidebar uses this ordering for "recently used" dashboards.
        $dashboard->touch();

        return $widget;
    }

    /**
     * Hard cap of the persisted snapshot (`chatbot.dashboard.snapshot_max_bytes`,
     * default 256 KB). If the JSON of `data` exceeds the cap, we keep only
     * `data.head` (first rows if it is a list array — null otherwise) +
     * the `truncated: true` marker. The replay (E3) re-executes the tool on open
     * and replaces the snapshot with complete fresh data (≤ cap as well);
     * the pin truncation only covers the pathological case (huge datasets
     * pre-computed before the first replay).
     *
     * @param  array<string, mixed>  $rawSnapshot
     * @return array<string, mixed>
     */
    protected function prepareSnapshot(array $rawSnapshot): array
    {
        $cap = (int) config('chatbot.dashboard.snapshot_max_bytes', 256 * 1024);
        $data = is_array($rawSnapshot['data'] ?? null) ? $rawSnapshot['data'] : [];

        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $byteSize = is_string($encoded) ? strlen($encoded) : 0;

        if ($cap > 0 && $byteSize > $cap) {
            Log::info(sprintf(
                '[chatbot] dashboard widget snapshot truncated: %d bytes > cap %d. data.head preserved.',
                $byteSize,
                $cap,
            ));

            $head = null;
            if (array_is_list($data) && $data !== []) {
                $head = array_slice($data, 0, 20);
            }

            return [
                'data'        => ['truncated' => true, 'head' => $head, 'original_byte_size' => $byteSize],
                'captured_at' => Carbon::now()->toIso8601String(),
                'byte_size'   => $byteSize,
                'truncated'   => true,
            ];
        }

        return [
            'data'        => $data,
            'captured_at' => Carbon::now()->toIso8601String(),
            'byte_size'   => $byteSize,
        ];
    }

    /**
     * Filters the request's current `page_context` to the keys declared
     * by the tool in `source.page_context_keys`. Applies:
     *
     *   1. `PageContextSanitizer::sanitize()` (drop closures/objects/null/
     *      resources/non-finite floats — the same defense as `/stream`).
     *   2. Filtering by keys: only the listed keys reach the snapshot.
     *   3. Binary cap of `chatbot.limits.page_context_kb` (default 16 KB):
     *      if the resulting JSON exceeds it, the whole thing is discarded + log info.
     *
     * Returns `[]` when there is no context, the keys are empty, or the
     * sanitization purges everything. Consistent with the behavior of
     * `ChatController::sanitizePageContext`.
     *
     * @param  array<string, mixed>  $rawContext
     * @param  array<int, string>    $keys
     * @return array<string, mixed>
     */
    protected function capturePageContextSnapshot(array $rawContext, array $keys): array
    {
        if ($rawContext === [] || $keys === []) {
            return [];
        }

        $sanitized = $this->sanitizer->sanitize($rawContext);
        if ($sanitized === []) {
            return [];
        }

        $stringKeys = array_filter($keys, 'is_string');
        $filtered   = [];
        foreach ($stringKeys as $key) {
            if (array_key_exists($key, $sanitized)) {
                $filtered[$key] = $sanitized[$key];
            }
        }

        if ($filtered === []) {
            return [];
        }

        $limitKb = (int) config('chatbot.limits.page_context_kb', 16);
        $limit   = max(1, $limitKb) * 1024;

        $encoded = json_encode($filtered);
        if (! is_string($encoded) || strlen($encoded) > $limit) {
            Log::info(sprintf(
                '[chatbot] dashboard widget page_context_snapshot dropped for exceeding %d KB after filtering.',
                $limitKb,
            ));

            return [];
        }

        return $filtered;
    }

}
