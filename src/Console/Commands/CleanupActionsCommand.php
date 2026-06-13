<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Illuminate\Console\Command;
use Rnkr69\LaraChatbot\Services\PendingActionStore;

/**
 * `php artisan chatbot:cleanup-actions`
 *
 * Marks as `expired` all `chatbot_pending_actions` with
 * `status=pending` and `expires_at < now()`. Designed to be schedulable —
 * the host typically adds it to `app/Console/Kernel.php`:
 *
 *     $schedule->command('chatbot:cleanup-actions')->everyFiveMinutes();
 *
 * Soft strategy (E16 §4): preserves audit trail — the LLM reads the expired
 * ones in the `## Pending actions` section of the next turn and can offer
 * the user to retry or continue without the action. If the host wants to
 * physically delete old rows, it can do so separately with its own cleanup
 * (e.g. `WHERE status='expired' AND updated_at < NOW() - INTERVAL 30 DAY`).
 */
class CleanupActionsCommand extends Command
{
    protected $signature = 'chatbot:cleanup-actions';

    protected $description = 'Mark chatbot pending actions whose TTL has elapsed as `expired`.';

    public function handle(PendingActionStore $store): int
    {
        $count = $store->expirePending();

        if ($count === 0) {
            $this->line('No expired pending actions.');
        } else {
            $this->info(sprintf('Pending actions marked as expired: %d.', $count));
        }

        return self::SUCCESS;
    }
}
