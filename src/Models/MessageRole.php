<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Models;

/**
 * Possible roles of a message in a conversation, mirroring the enum of the
 * `chatbot_messages.role` column. The `Message` model uses it as a cast.
 *
 * - User      — message written by the human user.
 * - Assistant — LLM response (includes tool_calls when it invokes tools).
 * - Tool      — result of an executed tool (only populated in tool_results).
 * - System    — system message (rarely persisted; the system prompt is
 *               rendered each turn from the Blade view).
 */
enum MessageRole: string
{
    case User      = 'user';
    case Assistant = 'assistant';
    case Tool      = 'tool';
    case System    = 'system';
}
