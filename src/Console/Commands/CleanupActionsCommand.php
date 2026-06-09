<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Illuminate\Console\Command;
use Rnkr69\LaraChatbot\Services\PendingActionStore;

/**
 * `php artisan chatbot:cleanup-actions`
 *
 * Marca como `expired` todos los `chatbot_pending_actions` con
 * `status=pending` y `expires_at < now()`. Diseñado para ser schedulable —
 * el host típicamente lo añade a `app/Console/Kernel.php`:
 *
 *     $schedule->command('chatbot:cleanup-actions')->everyFiveMinutes();
 *
 * Estrategia soft (E16 §4): preserva auditoría — el LLM lee los expirados
 * en la sección `## Pending actions` del siguiente turno y puede ofrecer
 * al usuario reintentar o seguir sin la acción. Si el host quiere borrar
 * filas viejas físicamente, puede hacerlo aparte con un cleanup propio
 * (e.g. `WHERE status='expired' AND updated_at < NOW() - INTERVAL 30 DAY`).
 */
class CleanupActionsCommand extends Command
{
    protected $signature = 'chatbot:cleanup-actions';

    protected $description = 'Marca como `expired` los pending actions de chatbot cuyo TTL ha pasado.';

    public function handle(PendingActionStore $store): int
    {
        $count = $store->expirePending();

        if ($count === 0) {
            $this->line('No hay pending actions caducados.');
        } else {
            $this->info(sprintf('Pending actions marcados como expired: %d.', $count));
        }

        return self::SUCCESS;
    }
}
