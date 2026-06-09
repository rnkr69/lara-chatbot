<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Backend;

use Rnkr69\LaraChatbot\Dashboard\WidgetCrudService;
use Rnkr69\LaraChatbot\Models\DashboardWidget;
use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

/**
 * v2.2 / PR-B — Borra (soft-delete) un widget del dashboard del usuario.
 *
 * **Nota sobre confirmación**: el plan original de PR-B (chatbot_package_
 * 2.1.3_upstream_prs.md) proponía `confirmation = Confirm` para emitir el
 * banner del orquestador antes de aplicar. En v2.2.0 mantenemos
 * `confirmation = Auto` porque el flow de Confirm para backend tools
 * (filtro `ChatService:578` + banner SSE específico para BE + endpoint
 * `POST /actions/{id}/confirm` para BE) está pendiente del backlog v2.x.
 * La acción es soft-delete (recuperable a nivel BD), pero igualmente la
 * `description()` instruye al LLM a confirmar verbalmente con el usuario
 * antes de invocar — el safety net es lingüístico, no UI.
 *
 * Resolución del `widget_id`: el LLM lo obtiene del `page_context.dashboard
 * .widgets` auto-inyectado en `/chatbot/dashboard`.
 */
class DeleteWidgetTool extends BaseBackendTool
{
    public function __construct(
        protected WidgetCrudService $widgetCrud,
    ) {}

    public function name(): string
    {
        return 'delete_widget';
    }

    public function description(): string
    {
        return 'Remove a widget from the user\'s dashboard (soft-delete; the user cannot undo from the UI today). INVOKE when the user asks to delete/remove/unpin a widget. **Before invoking, CONFIRM verbally with the user** ("Are you sure you want to remove the KPI widget?") and wait for an explicit yes — there is no UI banner for backend tools in v2.2 (planned for v2.3). Resolve the `widget_id` from `page_context.dashboard.widgets`. Returns `success({widget_id, dashboard_slug})` or `error({category, message})`.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'widget_id' => ['type' => 'integer'],
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
            return ToolResult::error('validation', 'widget_id is required.');
        }

        $widget = DashboardWidget::query()
            ->whereHas('dashboard', function ($q) use ($ctx): void {
                $q->where('user_type', $ctx->user->getMorphClass())
                  ->where('user_id', $ctx->user->getKey());
            })
            ->whereKey($widgetId)
            ->first();

        if ($widget === null) {
            return ToolResult::error(
                'widget_not_found',
                (string) __('chatbot::chatbot.delete_widget.errors.widget_not_found'),
            );
        }

        $widgetTitle = $widget->title ?? $widget->block_type;
        $dashboardSlug = $widget->dashboard?->slug;

        $this->widgetCrud->delete($widget);

        return ToolResult::success(
            data: [
                'widget_id'      => $widget->id,
                'dashboard_slug' => $dashboardSlug,
            ],
            blocks: [[
                'type' => 'card',
                'data' => [
                    'title'       => (string) __('chatbot::chatbot.delete_widget.success.card_title'),
                    'description' => (string) __('chatbot::chatbot.delete_widget.success.card_description', [
                        'title' => $widgetTitle,
                    ]),
                ],
                // v2.2.1 (PR-B) — el bundle del dashboard quita la card sin F5.
                'meta' => [
                    'side_effects' => array_filter([
                        'type'           => 'widget_deleted',
                        'dashboard_slug' => $dashboardSlug,
                        'widget_id'      => (int) $widget->id,
                    ], static fn ($v) => $v !== null),
                ],
            ]],
        );
    }
}
