<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Models;

/**
 * Tipo de confirmación con la que se creó un pending action. Espejo del
 * enum `Rnkr69\LaraChatbot\Tools\ConfirmationLevel`. El TTL aplicado al pending
 * action depende de este valor:
 *
 *  - Confirm → `chatbot.limits.pending_action_ttl.confirm` (default 600s).
 *  - Manual  → `chatbot.limits.pending_action_ttl.manual`  (default 86400s).
 *  - Auto    → `chatbot.limits.pending_action_ttl.auto`    (default 60s).
 *
 * v1.1.3 (#16): `Auto` produce un pending action que nace como `Confirmed`
 * y se cierra a `Executed` cuando el widget reporta — sólo POST-back en
 * fallos. Permite que el LLM vea el resultado de una primitive auto en su
 * siguiente turno (`[FAILED] tool=fill_form …`) sin romper el matching de
 * `tool_use_id` de Anthropic.
 */
enum PendingActionConfirmation: string
{
    case Confirm = 'confirm';
    case Manual  = 'manual';
    case Auto    = 'auto';
}
