<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Models;

/**
 * Type of confirmation a pending action was created with. Mirror of the
 * `Rnkr69\LaraChatbot\Tools\ConfirmationLevel` enum. The TTL applied to the
 * pending action depends on this value:
 *
 *  - Confirm → `chatbot.limits.pending_action_ttl.confirm` (default 600s).
 *  - Manual  → `chatbot.limits.pending_action_ttl.manual`  (default 86400s).
 *  - Auto    → `chatbot.limits.pending_action_ttl.auto`    (default 60s).
 *
 * v1.1.3 (#16): `Auto` produces a pending action that starts as `Confirmed`
 * and closes to `Executed` when the widget reports — only POST-back on
 * failures. It lets the LLM see the result of an auto primitive on its
 * next turn (`[FAILED] tool=fill_form …`) without breaking Anthropic's
 * `tool_use_id` matching.
 */
enum PendingActionConfirmation: string
{
    case Confirm = 'confirm';
    case Manual  = 'manual';
    case Auto    = 'auto';
}
