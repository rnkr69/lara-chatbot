<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs\Tools;

use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\Contracts\FrontendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

/**
 * Frontend tool con confirmación variable — para los tests de E16.
 * Simula una operación que necesita confirmación del usuario antes de
 * ejecutarse en el cliente.
 */
class ConfirmFrontendTool extends BaseBackendTool implements FrontendTool
{
    public ConfirmationLevel $confirmationOverride = ConfirmationLevel::Confirm;

    public function name(): string
    {
        return 'confirm_dialog';
    }

    public function description(): string
    {
        return 'Frontend tool that requires user approval before running.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string', 'description' => 'Texto al usuario.'],
            ],
            'required' => ['message'],
        ];
    }

    public function confirmation(): ConfirmationLevel
    {
        return $this->confirmationOverride;
    }

    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        return ToolResult::success();
    }
}
