<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Frontend;

use Rnkr69\LaraChatbot\Tools\BaseFrontendTool;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;

/**
 * "Escape hatch": invokes a JavaScript action registered by the host via
 * `window.Chatbot.registerAction(name, fn)` (E12). Used when the host
 * wants to expose a specific action of its SPA (refresh a grid,
 * trigger its own modal, call a Livewire event) that the standard
 * primitives don't cover.
 *
 * Confirmation: `auto` by default (since v1.1.3, finding #19). Most
 * host actions registered in real hosts are reversible
 * (refreshGrid, exportCsv, printManifest) and requiring "Mark as done" from
 * the user degrades the UX. Destructive host actions should be exposed
 * as dedicated backend tools with their own `confirm`/`manual`; the frontend
 * escape hatch assumes "the host knows what it's exposing".
 *
 * Hosts that register mutating actions can subclass to return
 * `confirm` or `manual`.
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
