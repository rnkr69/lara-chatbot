<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Dashboard;

use RuntimeException;

/**
 * Domain exception that `PinService::pin()` throws when the pin operation
 * cannot complete for an anticipated reason (not because of a runtime bug).
 * Each case carries a stable `category` that the callers
 * (HTTP controller, `AddToDashboardTool`) translate to their own error
 * shape: the controller to JSON 422, the tool to `ToolResult::error(...)`.
 *
 * Categories:
 *
 *   - `cap_reached`  the dashboard already has as many widgets as the
 *                    `chatbot.dashboard.max_widgets_per_dashboard` cap allows.
 *                    `context = ['cap' => int, 'current' => int]`.
 *   - `not_pinnable` the source tool declares `pinnable()=false` or a
 *                    `confirmation()` other than `Auto`. Defense-in-depth:
 *                    the HTTP controller also pre-checks it to preserve
 *                    the historical response shape, but the service
 *                    always fails even if the caller forgets the guard.
 *                    `context = ['tool' => string]`.
 *
 * The `getMessage()` already comes as readable Spanish and can be propagated
 * verbatim to the end user when the caller needs it.
 */
final class PinException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        public readonly string $category,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function capReached(int $cap, int $current): self
    {
        return new self(
            sprintf('Dashboard already reached the maximum of %d widgets (current: %d).', $cap, $current),
            'cap_reached',
            ['cap' => $cap, 'current' => $current],
        );
    }

    public static function notPinnable(string $toolName): self
    {
        return new self(
            sprintf(
                'Tool `%s` is not pinnable (requires pinnable() === true and confirmation === Auto).',
                $toolName,
            ),
            'not_pinnable',
            ['tool' => $toolName],
        );
    }
}
