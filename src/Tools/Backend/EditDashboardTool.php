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
 * v2.2 / PR-B — Edita propiedades de un dashboard del usuario (rename,
 * set_default). Lo invoca el LLM cuando el usuario pide "renombra el panel
 * a Operaciones Q1" o "hazlo mi panel por defecto".
 *
 * Slug del dashboard viene en `page_context.dashboard.slug` (auto-inyectado
 * por DashboardController en `/chatbot/dashboard`) o el LLM lo busca via
 * el listado de dashboards si el usuario nombra otro.
 *
 * Al renombrar, `DashboardCrudService` regenera el slug; el response
 * incluye `new_slug` para que el bundle JS pueda
 * `history.replaceState` y la URL actual del user no quede obsoleta.
 *
 * `confirmation = Auto` — el rename / set_default es reversible y no
 * destruye datos. El auto-demote del resto del usuario al marcar
 * is_default=true vive en el hook `saving` del modelo (no aquí).
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

        // v2.2.1 (PR-B) — un rename regenera el slug; el bundle del dashboard
        // hace `history.replaceState` para mantener la URL alineada y actualiza
        // el `<h1>` y la sidebar. Sin `new_slug` la mutación fue sólo
        // `set_default`/`unset_default`; el bundle refresca la sidebar y los
        // badges sin tocar la URL.
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
            $parts[] = sprintf("nombre → '%s'", (string) $applied['name']);
        }
        if (array_key_exists('is_default', $applied)) {
            $parts[] = $applied['is_default'] ? 'marcado como por defecto' : 'desmarcado como por defecto';
        }

        return $parts === [] ? '(sin cambios)' : implode(', ', $parts);
    }
}
