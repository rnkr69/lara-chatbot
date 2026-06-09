<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Dashboard;

use RuntimeException;

/**
 * Excepción de dominio que `PinService::pin()` lanza cuando la operación de
 * pin no puede completarse por una razón anticipada (no por un bug en
 * runtime). Cada caso lleva una `category` estable que los callers
 * (controller HTTP, `AddToDashboardTool`) traducen a su shape propio de
 * error: el controller a JSON 422, la tool a `ToolResult::error(...)`.
 *
 * Categorías:
 *
 *   - `cap_reached`  el dashboard ya tiene tantos widgets como permite el
 *                    cap `chatbot.dashboard.max_widgets_per_dashboard`.
 *                    `context = ['cap' => int, 'current' => int]`.
 *   - `not_pinnable` el tool source declara `pinnable()=false` o
 *                    `confirmation()` distinto de `Auto`. Defense-in-depth:
 *                    el controller HTTP también lo pre-chequea para preservar
 *                    el shape histórico de la respuesta, pero el service
 *                    siempre falla aunque el caller olvide la guardia.
 *                    `context = ['tool' => string]`.
 *
 * El `getMessage()` ya viene en castellano legible y se puede propagar
 * verbatim a usuario final cuando el caller lo necesita.
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
            sprintf('Dashboard ya alcanzó el máximo de %d widgets (actuales: %d).', $cap, $current),
            'cap_reached',
            ['cap' => $cap, 'current' => $current],
        );
    }

    public static function notPinnable(string $toolName): self
    {
        return new self(
            sprintf(
                'Tool `%s` no es pinnable (requiere pinnable() === true y confirmation === Auto).',
                $toolName,
            ),
            'not_pinnable',
            ['tool' => $toolName],
        );
    }
}
