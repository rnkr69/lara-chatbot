<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Backend;

use Rnkr69\LaraChatbot\Dashboard\WidgetCrudService;
use Rnkr69\LaraChatbot\Models\DashboardWidget;
use Rnkr69\LaraChatbot\Models\WidgetRefreshPolicy;
use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

/**
 * v2.2 / PR-B — Edits properties of a widget on the user's dashboard
 * (move, resize, retitle, change refresh_policy). The LLM invokes it when
 * the user asks something like "move the KPIs widget to the left and make
 * it bigger" or "rename the chart to Ventas Q1".
 *
 * By convention the tool is only offered on `/chatbot/dashboard` — the LLM
 * resolves the `widget_id` from a readable title against the
 * `page_context.dashboard.widgets` auto-injected on that page (E7 of
 * v2.2). Outside the dashboard the tool stays active but the LLM has no
 * way to name widgets without an id.
 *
 * No banner: `confirmation = Auto`. Non-destructive edit (move/resize/
 * rename are easily reversible from the gridstack UI itself). If it is
 * later promoted to `Confirm`, the BE-confirm flow in the orchestrator is
 * needed (backlog).
 */
class EditWidgetTool extends BaseBackendTool
{
    public function __construct(
        protected WidgetCrudService $widgetCrud,
    ) {}

    public function name(): string
    {
        return 'edit_widget';
    }

    public function description(): string
    {
        return 'Edit one or more properties of a dashboard widget the user owns. INVOKE when the user asks to move, resize, rename, or change the refresh policy of a widget on their dashboard. The user usually identifies the widget by its title or block type ("the KPI widget", "the chart"). Resolve the `widget_id` from the `page_context.dashboard.widgets` list (which the package auto-injects when the chat is mounted on /chatbot/dashboard). Arguments: `widget_id` (required, integer); `position` (optional `{x, y, w, h}` — pass only the fields you want to change, server fills the rest from current); `title` (optional string up to 180 chars, or null to clear); `refresh_policy` (optional enum: on_open|manual|never). All optional args can be combined in a single invocation. Returns `success({widget_id, applied_changes})` on success, or `error({category, message})`.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'widget_id'      => ['type' => 'integer'],
                'position'       => [
                    'type'       => 'object',
                    'properties' => [
                        'x' => ['type' => 'integer'],
                        'y' => ['type' => 'integer'],
                        'w' => ['type' => 'integer'],
                        'h' => ['type' => 'integer'],
                    ],
                ],
                // `title` accepts string OR null (clears the title). JSON
                // Schema enforced via the tool's own validation in handle()
                // because `JsonSchemaToRules` doesn't translate union types.
                'title'          => ['description' => 'Widget title, up to 180 chars, or null to clear it.'],
                'refresh_policy' => ['type' => 'string', 'enum' => ['on_open', 'manual', 'never']],
            ],
            'required' => ['widget_id'],
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
        $widgetId = isset($args['widget_id']) && is_int($args['widget_id']) ? $args['widget_id'] : null;
        if ($widgetId === null) {
            return ToolResult::error(
                'validation',
                'widget_id is required.',
            );
        }

        // 404-not-403 policy: scope the query to widgets of the user's
        // dashboards. Cross-user resolves to "widget_not_found", not to
        // "unauthorized" (same pattern as the HTTP controller).
        $widget = $this->findOwnedWidget($ctx, $widgetId);
        if ($widget === null) {
            return ToolResult::error(
                'widget_not_found',
                (string) __('chatbot::chatbot.edit_widget.errors.widget_not_found'),
            );
        }

        $changes = [];
        if (array_key_exists('position', $args)) {
            $position = $args['position'];
            if (! is_array($position)) {
                return ToolResult::error(
                    'validation',
                    (string) __('chatbot::chatbot.edit_widget.errors.validation', ['detail' => 'position must be an object.']),
                );
            }
            if (($detail = $this->validatePosition($position)) !== null) {
                return ToolResult::error(
                    'validation',
                    (string) __('chatbot::chatbot.edit_widget.errors.validation', ['detail' => $detail]),
                );
            }
            // Merge into current position so the LLM can pass only the keys
            // it wants to change (e.g. just {x: 4}). Without merge, missing
            // keys would default via the normalizer and silently move the
            // widget.
            $current = is_array($widget->position) ? $widget->position : [];
            $changes['position'] = array_merge($current, $position);
        }

        if (array_key_exists('title', $args)) {
            $title = $args['title'];
            if ($title !== null && (! is_string($title) || strlen($title) > 180)) {
                return ToolResult::error(
                    'validation',
                    (string) __('chatbot::chatbot.edit_widget.errors.validation', ['detail' => 'title must be a string up to 180 characters, or null.']),
                );
            }
            $changes['title'] = $title;
        }

        if (array_key_exists('refresh_policy', $args)) {
            $policy = $args['refresh_policy'];
            if (! is_string($policy) || WidgetRefreshPolicy::tryFrom($policy) === null) {
                return ToolResult::error(
                    'validation',
                    (string) __('chatbot::chatbot.edit_widget.errors.validation', ['detail' => 'refresh_policy must be one of: on_open, manual, never.']),
                );
            }
            $changes['refresh_policy'] = $policy;
        }

        if ($changes === []) {
            return ToolResult::error(
                'nothing_to_change',
                (string) __('chatbot::chatbot.edit_widget.errors.nothing_to_change'),
            );
        }

        $applied = $this->widgetCrud->update($widget, $changes);

        $summary = $this->summariseChanges($applied);
        $widgetTitle = $widget->title ?? $widget->block_type;
        $dashboardSlug = $widget->dashboard?->slug;

        return ToolResult::success(
            data: [
                'widget_id'       => $widget->id,
                'applied_changes' => $applied,
            ],
            blocks: [[
                'type' => 'card',
                'data' => [
                    'title'       => (string) __('chatbot::chatbot.edit_widget.success.card_title'),
                    'description' => (string) __('chatbot::chatbot.edit_widget.success.card_description', [
                        'title'   => $widgetTitle,
                        'summary' => $summary,
                    ]),
                ],
                // v2.2.1 (PR-B) — `changes` carries the KEYS touched (title /
                // position / refresh_policy), not the new values — the bundle
                // re-fetches the active dashboard if the mutation affects the
                // one the user has open, avoiding leaking merge logic to the
                // client. `dashboard_slug` may be null if the relation's eager
                // load failed for some odd reason; the listener treats it
                // as "refresh whatever you have open" anyway.
                'meta' => [
                    'side_effects' => array_filter([
                        'type'           => 'widget_updated',
                        'dashboard_slug' => $dashboardSlug,
                        'widget_id'      => (int) $widget->id,
                        'changes'        => array_keys($applied),
                    ], static fn ($v) => $v !== null),
                ],
            ]],
        );
    }

    /**
     * Find a widget by id, scoped to dashboards of the calling user.
     * Cross-user lookup returns `null` — 404-not-403 policy.
     */
    protected function findOwnedWidget(ToolContext $ctx, int $widgetId): ?DashboardWidget
    {
        return DashboardWidget::query()
            ->whereHas('dashboard', function ($q) use ($ctx): void {
                $q->where('user_type', $ctx->user->getMorphClass())
                  ->where('user_id', $ctx->user->getKey());
            })
            ->whereKey($widgetId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $position
     */
    protected function validatePosition(array $position): ?string
    {
        if (isset($position['x']) && (! is_int($position['x']) || $position['x'] < 0 || $position['x'] > 11)) {
            return 'position.x must be an integer between 0 and 11.';
        }
        if (isset($position['y']) && (! is_int($position['y']) || $position['y'] < 0)) {
            return 'position.y must be an integer ≥ 0.';
        }
        if (isset($position['w']) && (! is_int($position['w']) || $position['w'] < 1 || $position['w'] > 12)) {
            return 'position.w must be an integer between 1 and 12.';
        }
        if (isset($position['h']) && (! is_int($position['h']) || $position['h'] < 1 || $position['h'] > 60)) {
            return 'position.h must be an integer between 1 and 60.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $applied
     */
    protected function summariseChanges(array $applied): string
    {
        $parts = [];
        if (isset($applied['position'])) {
            $p = $applied['position'];
            $parts[] = sprintf('position (x:%d, y:%d, w:%d, h:%d)', $p['x'] ?? 0, $p['y'] ?? 0, $p['w'] ?? 0, $p['h'] ?? 0);
        }
        if (array_key_exists('title', $applied)) {
            $parts[] = $applied['title'] === null ? 'title cleared' : sprintf("title → '%s'", (string) $applied['title']);
        }
        if (isset($applied['refresh_policy'])) {
            $parts[] = sprintf("refresh → '%s'", (string) $applied['refresh_policy']);
        }

        return $parts === [] ? '(no changes)' : implode(', ', $parts);
    }
}
