<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Frontend;

use Rnkr69\LaraChatbot\Tools\BaseFrontendTool;

/**
 * Muestra, oculta o alterna la visibilidad de uno o varios elementos de la
 * página. El widget aplica `display: none` (hide), `display: ''` (show) o
 * inversa (toggle).
 *
 * Confirmation: `auto`. Acción reversible y no destructiva.
 */
class ToggleVisibilityTool extends BaseFrontendTool
{
    public function name(): string
    {
        return 'toggle_visibility';
    }

    public function description(): string
    {
        return 'Show, hide, or toggle the visibility of one or more page elements. Provide a CSS selector that may match a single element or multiple elements. `action` is `show` (force visible), `hide` (force hidden) or `toggle` (flip the current state). Use to reveal advanced filters, hide irrelevant panels, or guide the user through progressive disclosure.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'selector' => ['type' => 'string', 'description' => 'CSS selector targeting one or more elements.'],
                'action'   => ['type' => 'string', 'enum' => ['show', 'hide', 'toggle'], 'description' => 'Visibility action to apply.'],
            ],
            'required' => ['selector', 'action'],
        ];
    }
}
