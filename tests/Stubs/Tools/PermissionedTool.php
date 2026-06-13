<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs\Tools;

use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

/**
 * Tool requiring the `orders.read` permission. Must appear in `forUser` only
 * if the `Authorizer` grants it.
 */
class PermissionedTool extends BaseBackendTool
{
    public function name(): string
    {
        return 'permissioned_tool';
    }

    public function description(): string
    {
        return 'Tool con permiso orders.read.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];
    }

    public function permissions(): array
    {
        return ['orders.read'];
    }

    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        return ToolResult::success();
    }
}
