<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs\Tools;

use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

/**
 * Tool with a mandatory integer `target_id`. Used to validate that invalid
 * args trigger `ToolResult::error('validation', ...)` WITHOUT invoking
 * `handle()`.
 */
class StrictArgsTool extends BaseBackendTool
{
    public bool $handleCalled = false;

    public function name(): string
    {
        return 'strict_args_tool';
    }

    public function description(): string
    {
        return 'Tool con target_id integer obligatorio.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target_id' => ['type' => 'integer'],
                'status'    => ['type' => 'string', 'enum' => ['paid', 'pending']],
            ],
            'required' => ['target_id'],
        ];
    }

    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        $this->handleCalled = true;

        return ToolResult::success($args);
    }
}
