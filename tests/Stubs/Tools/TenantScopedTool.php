<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs\Tools;

use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

/**
 * Tool con `tenantScope = true`. Su mero registro en `ToolRegistry`
 * exige que el host haya bind un `TenantResolver` (gap cross-host E04).
 */
class TenantScopedTool extends BaseBackendTool
{
    public function name(): string
    {
        return 'tenant_scoped_tool';
    }

    public function description(): string
    {
        return 'Tool con tenantScope=true (requiere TenantResolver).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];
    }

    public function tenantScope(): bool
    {
        return true;
    }

    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        return ToolResult::success();
    }
}
