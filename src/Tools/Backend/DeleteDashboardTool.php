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
 * v2.2 / PR-B — Deletes (soft-delete) a user's dashboard. If it was the
 * `is_default`, `DashboardCrudService::delete()` auto-promotes the next-most-
 * recent one — the response includes `promoted_slug` so the LLM can mention
 * it to the user ("I deleted X; your default is now Y").
 *
 * **Note on confirmation**: like `DeleteWidgetTool`, we keep
 * `confirmation = Auto` in v2.2 because the Confirm flow for backend tools
 * is still pending in the backlog. The `description()` instructs the LLM
 * to confirm verbally before invoking. Soft-delete is the safety net at
 * the DB level.
 *
 * Guard `would_create_orphan_default`: if the dashboard to delete is the
 * user's only one, we return an error instead of leaving them orphaned with
 * no dashboards. It is more useful to ask them to create another before
 * deleting the only one they have.
 */
class DeleteDashboardTool extends BaseBackendTool
{
    public function __construct(
        protected DashboardCrudService $dashboardCrud,
    ) {}

    public function name(): string
    {
        return 'delete_dashboard';
    }

    public function description(): string
    {
        return 'Delete a dashboard the user owns (soft-delete; if it was the default the next-most-recent is auto-promoted). INVOKE when the user asks to remove a dashboard. **Before invoking, CONFIRM verbally with the user** ("Are you sure you want to delete the panel ‘QA Q1’? All its widgets will go with it.") and wait for an explicit yes — there is no UI banner for backend tools in v2.2 (planned for v2.3). Refuses to delete if it is the user\'s only dashboard. Returns `success({slug, was_default, promoted_slug?})` or `error({category, message})`.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'dashboard_slug' => ['type' => 'string'],
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
                (string) __('chatbot::chatbot.delete_dashboard.errors.dashboard_not_found', ['slug' => $slug]),
            );
        }

        // Guard: don't leave the user without any dashboard. Count the user's
        // dashboards (not soft-deleted). If this is the only one, error.
        $totalForUser = Dashboard::query()->forUser($ctx->user)->count();
        if ($totalForUser <= 1) {
            return ToolResult::error(
                'would_create_orphan_default',
                (string) __('chatbot::chatbot.delete_dashboard.errors.would_create_orphan_default'),
            );
        }

        $name = $dashboard->name;
        $wasDefault = (bool) $dashboard->is_default;

        $promotedSlug = $this->dashboardCrud->delete($dashboard);

        $description = $promotedSlug !== null
            ? (string) __('chatbot::chatbot.delete_dashboard.success.card_description_promoted', [
                'name'      => $name,
                'promoted'  => $promotedSlug,
            ])
            : (string) __('chatbot::chatbot.delete_dashboard.success.card_description', ['name' => $name]);

        $data = [
            'slug'        => $slug,
            'was_default' => $wasDefault,
        ];
        if ($promotedSlug !== null) {
            $data['promoted_slug'] = $promotedSlug;
        }

        // v2.2.1 (PR-B) — the dashboard bundle removes the dashboard from the
        // sidebar and, if the deleted one was the active one, jumps to
        // `promoted_slug` or (if there was no promote) to the first available
        // one without an F5.
        $sideEffects = [
            'type'           => 'dashboard_deleted',
            'dashboard_slug' => $slug,
            'was_default'    => $wasDefault,
        ];
        if ($promotedSlug !== null) {
            $sideEffects['promoted_slug'] = $promotedSlug;
        }

        return ToolResult::success(
            data: $data,
            blocks: [[
                'type' => 'card',
                'data' => [
                    'title'       => (string) __('chatbot::chatbot.delete_dashboard.success.card_title'),
                    'description' => $description,
                ],
                'meta' => [
                    'side_effects' => $sideEffects,
                ],
            ]],
        );
    }
}
