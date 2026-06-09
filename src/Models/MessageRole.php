<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Models;

/**
 * Roles posibles de un mensaje en una conversación, espejo del enum de la
 * columna `chatbot_messages.role`. El modelo `Message` lo usa como cast.
 *
 * - User      — mensaje escrito por el usuario humano.
 * - Assistant — respuesta del LLM (incluye tool_calls cuando invoca tools).
 * - Tool      — resultado de una tool ejecutada (sólo poblado en tool_results).
 * - System    — mensaje de sistema (raramente persistido; el system prompt
 *               se renderiza en cada turno desde la vista Blade).
 */
enum MessageRole: string
{
    case User      = 'user';
    case Assistant = 'assistant';
    case Tool      = 'tool';
    case System    = 'system';
}
