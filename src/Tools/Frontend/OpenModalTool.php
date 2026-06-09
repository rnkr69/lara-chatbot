<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Frontend;

use Rnkr69\LaraChatbot\Tools\BaseFrontendTool;

/**
 * Abre un modal con un bloque tipado dentro y, opcionalmente, una lista de
 * acciones (botones) que disparan otras tools cuando el usuario las pulsa.
 *
 * El `block` sigue el shape de `RenderBlockTool` (E15): `{type: 'card'|'table'|...,
 * data: {...}}`. `actions[]` permite componer flujos en los que el modal
 * presenta información rica + opciones (ej. "Confirmar", "Editar",
 * "Cancelar") asociadas a tools concretas.
 *
 * Confirmation: `auto` por defecto. ROADMAP §5/E11 nota que cuando un modal
 * dispara acciones destructivas (tipo "Borrar") el host puede subclase para
 * devolver `confirm`. Detectarlo automáticamente desde los args es frágil
 * (depende de la nomenclatura del host); preferimos default permisivo +
 * receta de override en la doc.
 */
class OpenModalTool extends BaseFrontendTool
{
    public function name(): string
    {
        return 'open_modal';
    }

    public function description(): string
    {
        return 'Open a modal dialog overlaying the page with a typed block (card, table, list, etc.) and optional action buttons. Use when you need to display rich, focused content above the chat — confirmation summaries, drill-down details, or a small flow with explicit choices. `actions[]` are buttons; each binds a label to a tool name (and args) that runs when clicked. Prefer this over `render_block` when the user needs to act on the content.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title'   => ['type' => 'string', 'description' => 'Modal title shown in the header.'],
                'block'   => ['type' => 'object', 'description' => 'A typed block payload: `{type: "card"|"table"|..., data: {...}}`.'],
                'actions' => [
                    'type'        => 'array',
                    'description' => 'Optional list of `{label, tool, args?}` entries that render as action buttons.',
                ],
            ],
            'required' => ['title', 'block'],
        ];
    }
}
