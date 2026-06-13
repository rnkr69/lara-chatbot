<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Backend;

use Rnkr69\LaraChatbot\Dashboard\PinException;
use Rnkr69\LaraChatbot\Dashboard\PinService;
use Rnkr69\LaraChatbot\Models\Dashboard;
use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\Contracts\BackendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;
use Rnkr69\LaraChatbot\Tools\ToolResult;
use Throwable;

/**
 * v2.2 — Conversational tool that pins a block to the user's dashboard
 * WITHOUT having to go through the manual flow (chat → render → hover → 📌 →
 * modal → pin). The LLM invokes it when the user asks something like
 * "add my KPIs to the dashboard" or "pin the missions list".
 *
 * Orchestrates three steps:
 *
 *   1. Resolves the source tool (`source_tool`) and the target dashboard
 *      (slug or the user's default).
 *   2. Executes the source tool — the `BaseBackendTool::execute()` cascade
 *      (permission → scope → tenant → validation) applies to the source_tool;
 *      this wrapper does not open doors the LLM did not already have.
 *   3. Selects the indicated block (`block_type` + `block_ordinal`) from the
 *      result and delegates to `PinService::pin` to persist it.
 *
 * Errors are returned as `ToolResult::error(category, message)` with the
 * same category that doc 2.1.3 promises (`tool_not_found`, `not_pinnable`,
 * `unauthorized`, `out_of_scope`, `dashboard_not_found`, `no_dashboard`,
 * `cap_reached`, `source_args_invalid`, `source_runtime`, `no_block`,
 * `ordinal_out_of_range`). The messages come from `chatbot::chatbot.add_to_dashboard.errors.*`
 * and the host can translate them by publishing lang.
 *
 * Emits no confirmation banner: the action "add X" is itself the consent
 * (`confirmation = Auto`); asking for an extra banner would be redundant (same
 * principle as the v2.1.1 #L2 fix from a test host).
 *
 * `pinnable = false` here: this tool's output is a confirmation card, not
 * data-driven content that would make sense to pin (recursion!).
 */
class AddToDashboardTool extends BaseBackendTool
{
    public function __construct(
        protected ToolRegistry $registry,
        protected PinService $pinService,
    ) {}

    public function name(): string
    {
        return 'add_to_dashboard';
    }

    public function description(): string
    {
        return 'Add a content block from another tool to the user\'s personal dashboard. INVOKE this when the user asks "add X to my dashboard", "pin Y", "save these KPIs to my dashboard", or any variant — even if the block has not been generated in the conversation yet. Arguments: `source_tool` (required, the name of the backend tool that produces the block to add — must be `pinnable` and `confirmation=Auto`); `source_args` (optional, args for the source tool); `block_type` (optional, when the source tool emits multiple block types — e.g. fleet_kpis emits kpi+chart — pick one); `block_ordinal` (optional, 0-based index among blocks of the same type, useful for multi-block tools); `dashboard_slug` (optional, defaults to the user\'s default dashboard); `title` (optional, suggested widget title). Returns `success({widget_id, dashboard_slug, title})` on success, or `error({category, message})` with a user-readable reason on failure. The LLM MUST relay the error message verbatim — the messages already explain WHY in plain language.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'source_tool'    => ['type' => 'string'],
                'source_args'    => ['type' => 'object'],
                'block_type'     => ['type' => 'string', 'description' => 'For multi-block tools, pick one type (e.g. "kpi" or "chart" for fleet_kpis).'],
                'block_ordinal'  => ['type' => 'integer', 'description' => '0-based index among blocks of the same type. Default 0.'],
                'dashboard_slug' => ['type' => 'string'],
                'title'          => ['type' => 'string'],
            ],
            'required' => ['source_tool'],
        ];
    }

    public function permissions(): array
    {
        return [];
    }

    public function confirmation(): ConfirmationLevel
    {
        return ConfirmationLevel::Auto;
    }

    public function pinnable(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        $toolName = isset($args['source_tool']) && is_string($args['source_tool']) ? trim($args['source_tool']) : '';
        $sourceArgs = is_array($args['source_args'] ?? null) ? $args['source_args'] : [];
        $blockType = isset($args['block_type']) && is_string($args['block_type']) ? trim($args['block_type']) : null;
        $blockOrdinal = isset($args['block_ordinal']) && is_int($args['block_ordinal']) && $args['block_ordinal'] >= 0
            ? $args['block_ordinal']
            : 0;
        $suggestedTitle = isset($args['title']) && is_string($args['title']) && $args['title'] !== ''
            ? $args['title']
            : null;
        $dashboardSlug = isset($args['dashboard_slug']) && is_string($args['dashboard_slug']) && $args['dashboard_slug'] !== ''
            ? $args['dashboard_slug']
            : null;

        // 1. Source tool resolution.
        $sourceTool = $this->registry->get($toolName);
        if ($sourceTool === null) {
            return ToolResult::error(
                'tool_not_found',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.tool_not_found', [
                    'tool' => $toolName,
                    'list' => $this->listPinnableToolsFor($ctx),
                ]),
            );
        }

        // 2. Pinnable enforcement (defense-in-depth — PinService re-checks).
        if (! $sourceTool->pinnable() || $sourceTool->confirmation() !== ConfirmationLevel::Auto) {
            return ToolResult::error(
                'not_pinnable',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.not_pinnable', ['tool' => $toolName]),
            );
        }

        // 3. Dashboard resolution.
        $dashboard = $this->resolveDashboard($ctx, $dashboardSlug);
        if ($dashboard === null) {
            return $dashboardSlug !== null
                ? ToolResult::error(
                    'dashboard_not_found',
                    (string) __('chatbot::chatbot.add_to_dashboard.errors.dashboard_not_found', [
                        'slug' => $dashboardSlug,
                        'list' => $this->listDashboardSlugsFor($ctx),
                    ]),
                )
                : ToolResult::error(
                    'no_dashboard',
                    (string) __('chatbot::chatbot.add_to_dashboard.errors.no_dashboard'),
                );
        }

        // 4. Widget cap pre-check (parity with the controller). PinService
        //    re-checks it to cover the race where "another tab pinned just
        //    before". In this turn we avoid executing the source_tool if we
        //    already know it won't fit.
        $cap = (int) config('chatbot.dashboard.max_widgets_per_dashboard', 50);
        $current = $dashboard->widgets()->count();
        if ($cap > 0 && $current >= $cap) {
            return ToolResult::error(
                'cap_reached',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.cap_reached', [
                    'name'    => $dashboard->name,
                    'current' => $current,
                    'max'     => $cap,
                ]),
            );
        }

        // 5. Execute the source tool. `execute()` applies the package
        //    cascade (validation → permission → scope → tenant → handle).
        try {
            $sourceResult = $sourceTool->execute($sourceArgs, $ctx);
        } catch (Throwable $e) {
            return ToolResult::error(
                'source_runtime',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.source_runtime', [
                    'tool'   => $toolName,
                    'detail' => $e->getMessage(),
                ]),
            );
        }

        if ($sourceResult->isError()) {
            return $this->mapSourceError($sourceResult, $toolName);
        }

        // 6. Block selection. Filter by `block_type` if given; otherwise
        //    consider all blocks. Within candidates, pick by ordinal.
        $candidates = [];
        foreach ($sourceResult->blocks as $i => $block) {
            if (! is_array($block) || ! isset($block['type']) || ! is_string($block['type'])) {
                continue;
            }
            if ($blockType !== null && $block['type'] !== $blockType) {
                continue;
            }
            $candidates[] = $block;
        }

        if ($candidates === []) {
            return ToolResult::error(
                'no_block',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.no_block', ['tool' => $toolName]),
            );
        }

        if (! array_key_exists($blockOrdinal, $candidates)) {
            return ToolResult::error(
                'ordinal_out_of_range',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.ordinal_out_of_range', [
                    'tool'    => $toolName,
                    'count'   => count($candidates),
                    'type'    => $blockType ?? '',
                    'ordinal' => $blockOrdinal + 1,
                ]),
            );
        }

        $selected = $candidates[$blockOrdinal];
        $selectedBlockType = (string) ($selected['type'] ?? '');
        $selectedBlockData = is_array($selected['data'] ?? null) ? $selected['data'] : [];
        $selectedBlockId   = isset($selected['id']) && is_string($selected['id']) ? $selected['id'] : null;

        // 7. Pin via PinService. Same descriptor shape the HTTP path uses —
        //    `block_ordinal` is the position within candidates (the N-th
        //    block of its type from the source tool), which the replay
        //    engine re-localises identically.
        $block = [
            'type'    => $selectedBlockType,
            'data'    => $selectedBlockData,
            'ordinal' => $blockOrdinal,
        ];
        if ($selectedBlockId !== null) {
            $block['id'] = $selectedBlockId;
        }

        try {
            $widget = $this->pinService->pin(
                dashboard: $dashboard,
                sourceTool: $sourceTool,
                sourceArgs: $sourceArgs,
                block: $block,
                suggestedTitle: $suggestedTitle,
                pageContext: $ctx->pageContext,
                pageContextKeys: array_values(array_filter(array_keys($ctx->pageContext), 'is_string')),
            );
        } catch (PinException $e) {
            // Defense-in-depth mapping: the pre-check already covers the
            // normal case, but if a concurrent tab pinned just before and
            // exceeds the cap, we report it with the same wording as the
            // pre-check.
            return $this->mapPinException($e, $dashboard);
        }

        // 8. Success card. The LLM relays it verbatim to the user.
        $url = $this->dashboardUrl($dashboard);
        $widgetTitle = $widget->title ?? ($suggestedTitle ?? $selectedBlockType);

        return ToolResult::success(
            data: [
                'widget_id'      => $widget->id,
                'dashboard_slug' => $dashboard->slug,
                'title'          => $widgetTitle,
                'dashboard_url'  => $url,
            ],
            blocks: [[
                'type' => 'card',
                'data' => [
                    'title'       => (string) __('chatbot::chatbot.add_to_dashboard.success.card_title'),
                    'description' => (string) __('chatbot::chatbot.add_to_dashboard.success.card_description', [
                        'title'     => $widgetTitle,
                        'dashboard' => $dashboard->name,
                        'url'       => $url,
                    ]),
                ],
                // v2.2.1 (PR-B) — the dashboard bundle listens for
                // `chatbot:dashboard-mutation` to refresh without an F5 when the
                // mutation comes from the chat. The orchestrator propagates `meta` verbatim.
                'meta' => [
                    'side_effects' => [
                        'type'           => 'widget_added',
                        'dashboard_slug' => $dashboard->slug,
                        'widget_id'      => (int) $widget->id,
                    ],
                ],
            ]],
        );
    }

    /**
     * Resolves the target dashboard. If the LLM passed `dashboard_slug`, it
     * looks up by slug; otherwise the user's `is_default`. Returns `null` if
     * no suitable one exists — the caller distinguishes `dashboard_not_found`
     * from `no_dashboard` by the presence of the slug.
     */
    protected function resolveDashboard(ToolContext $ctx, ?string $slug): ?Dashboard
    {
        $query = Dashboard::query()->forUser($ctx->user);

        if ($slug !== null) {
            return $query->where('slug', $slug)->first();
        }

        return $query->default()->first();
    }

    /**
     * Lists the slugs of the user's dashboards (for the `dashboard_not_found`
     * message). Truncated to a reasonable maximum so as not to flood the
     * message when the user has many.
     */
    protected function listDashboardSlugsFor(ToolContext $ctx): string
    {
        $slugs = Dashboard::query()
            ->forUser($ctx->user)
            ->orderBy('is_default', 'desc')
            ->orderBy('updated_at', 'desc')
            ->limit(10)
            ->pluck('slug')
            ->all();

        if ($slugs === []) {
            return '—';
        }

        return implode(', ', array_map(fn (string $s): string => "'{$s}'", $slugs));
    }

    /**
     * Lists the names of the pinnable+Auto tools available to the current
     * user (those the ToolRegistry knows the user can invoke and that are
     * pinnable). Used in the `tool_not_found` message — handing the LLM the
     * alternatives saves an iteration of the "doesn't exist" → "call again
     * with another name" loop.
     */
    protected function listPinnableToolsFor(ToolContext $ctx): string
    {
        $available = [];

        foreach ($this->registry->forUser($ctx->user) as $name => $tool) {
            if ($tool->pinnable() && $tool->confirmation() === ConfirmationLevel::Auto) {
                $available[] = $name;
            }
        }

        if ($available === []) {
            return '—';
        }

        sort($available);

        return implode(', ', array_slice($available, 0, 20));
    }

    /**
     * Maps the `ToolResult::error` categories from the source_tool to this
     * tool's wrapper. The names deliberately differ so the LLM can reason
     * about which of the two levels failed (the source tool's cascade vs.
     * the pin itself).
     */
    protected function mapSourceError(ToolResult $sourceResult, string $toolName): ToolResult
    {
        $category = $sourceResult->errorCategory ?? 'source_runtime';
        $detail   = $sourceResult->errorMessage ?? '';

        return match ($category) {
            'validation' => ToolResult::error(
                'source_args_invalid',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.source_args_invalid', [
                    'tool'   => $toolName,
                    'detail' => $detail,
                ]),
            ),
            'unauthorized' => ToolResult::error(
                'unauthorized',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.unauthorized'),
            ),
            'out_of_scope' => ToolResult::error(
                'out_of_scope',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.out_of_scope'),
            ),
            default => ToolResult::error(
                'source_runtime',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.source_runtime', [
                    'tool'   => $toolName,
                    'detail' => $detail,
                ]),
            ),
        };
    }

    /**
     * Reduces a `PinException` thrown by `PinService` to a `ToolResult::error`.
     * The cap pre-check here in the tool covers the normal case; this map
     * covers the concurrent race (another tab pinned just before
     * persisting).
     */
    protected function mapPinException(PinException $e, Dashboard $dashboard): ToolResult
    {
        return match ($e->category) {
            'cap_reached' => ToolResult::error(
                'cap_reached',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.cap_reached', [
                    'name'    => $dashboard->name,
                    'current' => (int) ($e->context['current'] ?? 0),
                    'max'     => (int) ($e->context['cap'] ?? 0),
                ]),
            ),
            'not_pinnable' => ToolResult::error(
                'not_pinnable',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.not_pinnable', [
                    'tool' => (string) ($e->context['tool'] ?? ''),
                ]),
            ),
            default => ToolResult::error('source_runtime', $e->getMessage()),
        };
    }

    /**
     * Dashboard URL. The named route `chatbot.dashboard` does not accept a
     * slug (it is a single view that renders the user's dashboard with the
     * sidebar for switching between dashboards); the slug is included as the
     * `?dashboard=` query param so that `DashboardController::resolveDefaultSlug`
     * reads it and the JS bundle auto-selects that dashboard on load. v2.2.1:
     * we previously emitted `?slug=` by mistake — the controller ignored it
     * and the card's "Open dashboard" always landed on the user's default.
     */
    protected function dashboardUrl(Dashboard $dashboard): string
    {
        try {
            $base = route('chatbot.dashboard');
        } catch (Throwable $e) {
            $prefix = (string) config('chatbot.route.prefix', 'chatbot');

            return '/' . trim($prefix, '/') . '/dashboard';
        }

        return $base . '?dashboard=' . rawurlencode($dashboard->slug);
    }
}
