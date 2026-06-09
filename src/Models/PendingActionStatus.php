<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Models;

/**
 * Ciclo de vida de un `chatbot_pending_actions` row (E16). Los valores
 * `confirmed` y `pending` son intermedios; `rejected`, `executed`, `expired`
 * son terminales. El endpoint `POST /chatbot/actions/{id}/confirm` rechaza
 * con 409 Conflict las llamadas que intenten transicionar desde un estado
 * terminal.
 */
enum PendingActionStatus: string
{
    case Pending   = 'pending';
    case Confirmed = 'confirmed';
    case Rejected  = 'rejected';
    case Executed  = 'executed';
    case Expired   = 'expired';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Rejected, self::Executed, self::Expired => true,
            self::Pending, self::Confirmed                => false,
        };
    }
}
