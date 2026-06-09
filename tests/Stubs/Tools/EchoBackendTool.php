<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs\Tools;

use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

/**
 * Backend tool de prueba para los tests de `ChatService`. Echo simple del
 * arg `message` y, opcionalmente, fuerza un error de runtime si la flag
 * está activa.
 */
class EchoBackendTool extends BaseBackendTool
{
    public bool $shouldFail = false;
    /** v1.1 — when set, handle() throws this from inside execute(). Used to
     *  exercise ChatService::executeTool catch-all path (findings #2). */
    public ?\Throwable $shouldThrow = null;
    public ConfirmationLevel $confirmationOverride = ConfirmationLevel::Auto;

    /**
     * v2.0 (E1) — opt-in: when set, handle() returns these blocks alongside
     * the echoed data. Used to exercise the orchestrator's auto-stamp of
     * `id` / `source` / `pinnable` for `ToolResult::blocks[]`.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $emitBlocks = [];

    /**
     * v2.0 (E1) — null = inherit `BaseBackendTool::pinnable()` (false).
     * `true` / `false` = explicit override the orchestrator should see.
     */
    public ?bool $pinnableOverride = null;

    public int $invocations = 0;

    public function name(): string
    {
        return 'echo_tool';
    }

    public function description(): string
    {
        return 'Echoes the given message back.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'message' => ['type' => 'string', 'description' => 'Mensaje a devolver.'],
            ],
            'required' => ['message'],
        ];
    }

    public function confirmation(): ConfirmationLevel
    {
        return $this->confirmationOverride;
    }

    public function pinnable(): bool
    {
        return $this->pinnableOverride ?? parent::pinnable();
    }

    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        $this->invocations++;

        if ($this->shouldThrow !== null) {
            throw $this->shouldThrow;
        }

        if ($this->shouldFail) {
            return ToolResult::error('runtime', 'forced failure');
        }

        return ToolResult::success(
            ['echoed' => (string) ($args['message'] ?? '')],
            $this->emitBlocks,
        );
    }
}
