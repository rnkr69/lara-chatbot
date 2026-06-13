<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Models;

/**
 * Lifecycle of a `chatbot_pending_actions` row (E16). The values
 * `confirmed` and `pending` are intermediate; `rejected`, `executed`,
 * `expired` are terminal. The `POST /chatbot/actions/{id}/confirm` endpoint
 * rejects with 409 Conflict any calls that try to transition from a
 * terminal state.
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
