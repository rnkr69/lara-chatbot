<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs\Tools;

use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

/**
 * Tool with no required permissions (public). Must appear in `forUser` for
 * any user.
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
        return 'Public test tool.';
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
