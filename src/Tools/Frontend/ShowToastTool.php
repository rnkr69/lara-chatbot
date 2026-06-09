<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Frontend;

use Rnkr69\LaraChatbot\Tools\BaseFrontendTool;

/**
 * Muestra un toast/notificación efímera al usuario fuera del hilo de chat.
 *
 * Útil para confirmar que una acción del usuario se procesó ("Pedido
 * aprobado"), advertirle de un cambio de estado o mostrar feedback breve
 * que no debe ocupar el chat. El widget delega en el sistema de toasts del
 * host si está disponible (vía `registerNotifier`); en su defecto pinta su
 * propio toast nativo.
 *
 * Confirmation: `auto`. Es estrictamente informativo.
 */
class ShowToastTool extends BaseFrontendTool
{
    public function name(): string
    {
        return 'show_toast';
    }

    public function description(): string
    {
        return 'Display a short, non-blocking toast notification to the user. Use to confirm that an action succeeded, surface a tip, or warn about a transient condition. Keep `message` concise (one sentence). `level` is `info` (default), `success`, `warning`, or `error`. Do NOT use this to ask questions — toasts auto-dismiss; ask in chat instead.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string', 'description' => 'Toast text. One sentence, no markdown.'],
                'level'   => ['type' => 'string', 'enum' => ['info', 'success', 'warning', 'error'], 'description' => 'Visual style. Default `info`.'],
            ],
            'required' => ['message'],
        ];
    }
}
