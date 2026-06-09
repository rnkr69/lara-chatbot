<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Frontend;

use Rnkr69\LaraChatbot\Tools\BaseFrontendTool;

/**
 * Inserta un bloque tipado dentro del hilo del chat (a diferencia de
 * `OpenModalTool`, que lo presenta overlay encima de la página).
 *
 * Es la primitiva canónica para que el LLM responda con contenido rico
 * — tablas de pedidos, tarjetas de producto, listas — en lugar de
 * markdown plano. Los renderers concretos los registra el widget en E15
 * (`text`, `card`, `table`, `list`, `actions`, `chart`).
 *
 * Confirmation: `auto`. Sólo pinta contenido en el chat.
 *
 * Nota arquitectural: ROADMAP §5/E15 indica dos fuentes posibles para los
 * bloques: el LLM emitiendo `<block type="...">{...}</block>` parseado por
 * el `ChatService`, o esta tool emitiendo el bloque a través del SSE como
 * `event: frontend_action`. Ambas conviven; `RenderBlockTool` es útil
 * cuando el LLM razona explícitamente sobre cuándo emitir un bloque (ej.
 * "muestra esta tabla SI el usuario pidió un listado") y prefiere una
 * tool nominada antes que un span inline.
 */
class RenderBlockTool extends BaseFrontendTool
{
    public function name(): string
    {
        return 'render_block';
    }

    public function description(): string
    {
        return 'Render a typed content block inline within the chat thread (not as a modal overlay). Use when the user is better served by a structured visual representation — a table of records, a card with key fields, a list of options — than by markdown text. Provide `type` (`card`, `table`, `list`, `actions`, `chart`, or any custom renderer the host registered) and `data` matching that renderer\'s expected shape. Use `open_modal` instead if the content needs focus and explicit user action.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'description' => 'Block type. Built-in: `text`, `card`, `table`, `list`, `actions`, `chart`. Hosts may register their own.'],
                'data' => ['type' => 'object', 'description' => 'Block payload matching the renderer\'s expected shape.'],
            ],
            'required' => ['type', 'data'],
        ];
    }
}
