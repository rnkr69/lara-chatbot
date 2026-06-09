<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Frontend;

use Rnkr69\LaraChatbot\Tools\BaseFrontendTool;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;

/**
 * "Escape hatch": invoca una acción JavaScript registrada por el host vía
 * `window.Chatbot.registerAction(name, fn)` (E12). Se usa cuando el host
 * quiere exponer una acción específica de su SPA (refrescar una grid,
 * disparar un modal propio, llamar a un evento Livewire) que las primitivas
 * estándar no cubren.
 *
 * Confirmation: `auto` por defecto (desde v1.1.3, finding #19). La mayoría
 * de los host actions registrados en hosts reales son reversibles
 * (refreshGrid, exportCsv, printManifest) y exigir "Mark as done" del
 * usuario degrada la UX. Los host actions destructivos deben exponerse
 * como backend tools dedicadas con `confirm`/`manual` propios; el escape
 * hatch frontend asume "el host sabe lo que está exponiendo".
 *
 * Hosts que registren acciones mutativas pueden subclasear para devolver
 * `confirm` o `manual`.
 */
class InvokeHostActionTool extends BaseFrontendTool
{
    public function name(): string
    {
        return 'invoke_host_action';
    }

    public function description(): string
    {
        return 'Invoke a host-registered custom action (escape hatch). Use ONLY when the standard primitives (navigate, fill_form, render_block, etc.) cannot express what the user needs and the host has explicitly registered a named action via `window.Chatbot.registerAction`. Provide `action_name` (the registered identifier) and `args` (the action\'s expected payload). Executes automatically (no banner); hosts that register destructive actions should expose them as backend tools with explicit confirmation instead.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action_name' => ['type' => 'string', 'description' => 'Identifier registered by the host.'],
                'args'        => ['type' => 'object', 'description' => 'Payload forwarded to the host action.'],
            ],
            'required' => ['action_name'],
        ];
    }

    public function confirmation(): ConfirmationLevel
    {
        return ConfirmationLevel::Auto;
    }
}
