<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs\Tools;

use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

/**
 * Tool sin permisos requeridos (pública). Debe aparecer en `forUser` para
 * cualquier usuario.
 */
class PublicTool extends BaseBackendTool
{
    public bool $handleCalled = false;

    public function name(): string
    {
        return 'public_tool';
    }

    public function description(): string
    {
        return 'Tool pública de prueba.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];
    }

    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        $this->handleCalled = true;

        return ToolResult::success(['ok' => true]);
    }
}
