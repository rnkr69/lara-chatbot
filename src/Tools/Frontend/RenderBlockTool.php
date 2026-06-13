<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Frontend;

use Rnkr69\LaraChatbot\Tools\BaseFrontendTool;

/**
 * Inserts a typed block within the chat thread (unlike
 * `OpenModalTool`, which presents it as an overlay above the page).
 *
 * It is the canonical primitive for the LLM to respond with rich content
 * — order tables, product cards, lists — instead of plain
 * markdown. The concrete renderers are registered by the widget in E15
 * (`text`, `card`, `table`, `list`, `actions`, `chart`).
 *
 * Confirmation: `auto`. It only renders content in the chat.
 *
 * Architectural note: ROADMAP §5/E15 indicates two possible sources for the
 * blocks: the LLM emitting `<block type="...">{...}</block>` parsed by
 * `ChatService`, or this tool emitting the block through the SSE as
 * `event: frontend_action`. Both coexist; `RenderBlockTool` is useful
 * when the LLM explicitly reasons about when to emit a block (e.g.
 * "show this table IF the user asked for a listing") and prefers a
 * named tool over an inline span.
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
