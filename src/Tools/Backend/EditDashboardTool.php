<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Backend;

use Rnkr69\LaraChatbot\Dashboard\DashboardCrudService;
use Rnkr69\LaraChatbot\Models\Dashboard;
use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

/**
 * v2.2 / PR-B — Edits properties of a user's dashboard (rename,
 * set_default). The LLM invokes it when the user asks "rename the dashboard
 * to Operaciones Q1" or "make it my default dashboard".
 *
 * The dashboard slug comes in `page_context.dashboard.slug` (auto-injected
 * by DashboardController on `/chatbot/dashboard`) or the LLM looks it up via
 * the dashboard listing if the user names another.
 *
 * On rename, `DashboardCrudService` regenerates the slug; the response
 * includes `new_slug` so the JS bundle can `history.replaceState` and the
 * user's current URL does not become stale.
 *
 * `confirmation = Auto` — the rename / set_default is reversible and does
 * not destroy data. The auto-demote of the user's other dashboards when
 * setting is_default=true lives in the model's `saving` hook (not here).
 */
class EditDashboardTool extends BaseBackendTool
{
    public function __construct(
        protected DashboardCrudService $dashboardCrud,
    ) {}

    public function name(): string
    {
        return 'edit_dashboard';
    }

    public function description(): string
    {
        return 'Edit one or more properties of a dashboard the user owns. INVOKE when the user asks to rename a dashboard or set it as the default. The slug comes from `page_context.dashboard.slug` (current dashboard) or from a list lookup if the user names another. Arguments: `dashboard_slug` (required); `name` (optional, 1–120 chars, rename — the slug will regenerate from the new name); `is_default` (optional boolean, true to make this the default and auto-demote the previous). Returns `success({slug, applied_changes, new_slug?})` or `error({category, message})`.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'dashboard_slug' => ['type' => 'string'],
                'name'           => ['type' => 'string'],
                'is_default'     => ['type' => 'boolean'],
            ],
            'required' => ['dashboard_slug'],
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
        $slug = isset($args['dashboard_slug']) && is_string($args['dashboard_slug']) ? trim($args['dashboard_slug']) : '';
        if ($slug === '') {
            return ToolResult::error('validation', 'dashboard_slug is required.');
        }

        $dashboard = Dashboard::query()
            ->forUser($ctx->user)
            ->where('slug', $slug)
            ->first();

        if ($dashboard === null) {
            return ToolResult::error(
                'dashboard_not_found',
                (string) __('chatbot::chatbot.edit_dashboard.errors.dashboard_not_found', ['slug' => $slug]),
            );
        }

        $changes = [];
        if (array_key_exists('name', $args)) {
            $name = $args['name'];
            if (! is_string($name) || strlen($name) < 1 || strlen($name) > 120) {
                return ToolResult::error(
                    'validation',
                    (string) __('chatbot::chatbot.edit_dashboard.errors.validation', ['detail' => 'name must be a string between 1 and 120 characters.']),
                );
            }
            $changes['name'] = $name;
        }

        if (array_key_exists('is_default', $args)) {
            if (! is_bool($args['is_default'])) {
                return ToolResult::error(
                    'validation',
                    (string) __('chatbot::chatbot.edit_dashboard.errors.validation', ['detail' => 'is_default must be a boolean.']),
                );
            }
            $changes['is_default'] = $args['is_default'];
        }

        if ($changes === []) {
            return ToolResult::error(
                'nothing_to_change',
                (string) __('chatbot::chatbot.edit_dashboard.errors.nothing_to_change'),
            );
        }

        // Capture the slug BEFORE update — `DashboardCrudService::update()`
        // mutates `$dashboard->slug` in place on rename, so reading it after
        // would return the regenerated value. The dashboard bundle needs the
        // ORIGINAL slug to look up which row in its sidebar (the one currently
        // loaded by the user) the mutation refers to.
        $originalSlug = $dashboard->slug;

        $result = $this->dashboardCrud->update($dashboard, $changes);
        $applied = $result['applied'];
        $newSlug = $result['new_slug'] ?? null;

        $summary = $this->summariseChanges($applied);

        $data = [
            'slug'            => $dashboard->slug,
            'applied_changes' => $applied,
        ];
        if ($newSlug !== null) {
            $data['new_slug'] = $newSlug;
        }

        // v2.2.1 (PR-B) — a rename regenerates the slug; the dashboard bundle
        // does `history.replaceState` to keep the URL aligned and updates
        // the `<h1>` and the sidebar. Without `new_slug` the mutation was only
        // `set_default`/`unset_default`; the bundle refreshes the sidebar and
        // the badges without touching the URL.
        $sideEffects = [
            'type'           => 'dashboard_updated',
            'dashboard_slug' => $originalSlug,
            'changes'        => array_keys($applied),
        ];
        if ($newSlug !== null) {
            $sideEffects['new_slug'] = $newSlug;
        }
        if (isset($applied['name'])) {
            $sideEffects['new_name'] = (string) $applied['name'];
        }

        return ToolResult::success(
            data: $data,
            blocks: [[
                'type' => 'card',
                'data' => [
                    'title'       => (string) __('chatbot::chatbot.edit_dashboard.success.card_title'),
                    'description' => (string) __('chatbot::chatbot.edit_dashboard.success.card_description', [
                        'name'    => $dashboard->name,
                        'summary' => $summary,
                    ]),
                ],
                'meta' => [
                    'side_effects' => $sideEffects,
                ],
            ]],
        );
    }

    /**
     * @param  array<string, mixed>  $applied
     */
    protected function summariseChanges(array $applied): string
    {
        $parts = [];
        if (isset($applied['name'])) {
            $parts[] = sprintf("name → '%s'", (string) $applied['name']);
        }
        if (array_key_exists('is_default', $applied)) {
            $parts[] = $applied['is_default'] ? 'set as default' : 'unset as default';
        }

        return $parts === [] ? '(no changes)' : implode(', ', $parts);
    }
}
