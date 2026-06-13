<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Frontend;

use Rnkr69\LaraChatbot\Tools\BaseFrontendTool;

/**
 * Shows an ephemeral toast/notification to the user outside the chat thread.
 *
 * Useful to confirm that a user action was processed ("Order
 * approved"), warn them of a state change or show brief feedback
 * that should not take up the chat. The widget delegates to the host's
 * toast system if available (via `registerNotifier`); otherwise it renders
 * its own native toast.
 *
 * Confirmation: `auto`. It is strictly informative.
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
