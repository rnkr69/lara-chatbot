<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Dashboard;

use Rnkr69\LaraChatbot\Models\DashboardWidget;
use Rnkr69\LaraChatbot\Models\WidgetRefreshPolicy;

/**
 * v2.2 — Widget edit/delete layer shared between two callers:
 *
 *   1. **HTTP controller** (`PATCH/DELETE /chatbot/dashboards/{slug}/widgets/{id}`).
 *      The gridstack JS client sends atomic changes (move, resize,
 *      retitle, change refresh policy) in a single request.
 *   2. **`EditWidgetTool` / `DeleteWidgetTool`** (conversational editing v2.2).
 *      The LLM resolves `widget_id` from the page_context auto-injected on
 *      opening `/chatbot/dashboard` and applies the changes requested in natural
 *      language ("move KPIs to the left and make them bigger").
 *
 * The service does NOT validate ownership or authorize — the caller (the controller
 * thanks to `findOwnedOr404` + auth middleware, or the tool thanks to its
 * scope cascade) has already confirmed that the widget belongs to the user.
 * Here we only apply changes and persist.
 *
 * No HTTP contract changes: `update()` produces exactly the same
 * selective `fill()` pattern that lived inline in the controller; the shape
 * of the Resource emitted to the client is identical to v2.1.x.
 */
class WidgetCrudService
{
    /**
     * Applies selective changes to the widget. Only the keys PRESENT in
     * `$changes` are touched; absent ones are preserved (PATCH semantics).
     *
     * `position` is normalized with `WidgetPositionNormalizer`. `title` accepts
     * `null` to clear it. `refresh_policy` is silently ignored if
     * it is not a valid enum value (same historical behavior —
     * `tryFrom` returns null → it is not assigned).
     *
     * @param  array{position?: array<string,mixed>|null, title?: string|null, refresh_policy?: string} $changes
     * @return array<string, mixed> $appliedChanges  diff summary ("what changed") to return to the LLM.
     */
    public function update(DashboardWidget $widget, array $changes): array
    {
        $applied = [];

        if (array_key_exists('position', $changes)) {
            $rawPosition = $changes['position'];
            $normalized = WidgetPositionNormalizer::normalize(
                is_array($rawPosition) ? $rawPosition : null,
            );
            $widget->position = $normalized;
            $applied['position'] = $normalized;
        }

        if (array_key_exists('title', $changes)) {
            $widget->title = $changes['title'];
            $applied['title'] = $changes['title'];
        }

        if (array_key_exists('refresh_policy', $changes)) {
            $policy = WidgetRefreshPolicy::tryFrom((string) $changes['refresh_policy']);
            if ($policy !== null) {
                $widget->refresh_policy = $policy;
                $applied['refresh_policy'] = $policy->value;
            }
        }

        if ($applied === []) {
            return [];
        }

        $widget->save();

        return $applied;
    }

    /**
     * Soft-delete of the widget. The dashboard is "touched" so the sidebar
     * reflects the recent edit.
     */
    public function delete(DashboardWidget $widget): void
    {
        $dashboard = $widget->dashboard;
        $widget->delete();

        if ($dashboard !== null) {
            $dashboard->touch();
        }
    }
}
