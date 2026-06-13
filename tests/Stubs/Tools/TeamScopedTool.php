<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs\Tools;

use Rnkr69\LaraChatbot\Authorization\AccessScope;
use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

/**
 * Tool with `defaultScope = Team`. Used to validate that
 * `accessibleQuery()` applies `whereIn('user_id', $teamUserIds)` with the
 * IDs returned by the registered `ScopeResolver`.
 */
class TeamScopedTool extends BaseBackendTool
{
    /**
     * Captured by the last `handle()` so the test can assert on the IDs
     * it received.
     *
     * @var array<int, int|string>|null
     */
    public ?array $lastAccessibleUserIds = null;

    public function name(): string
    {
        return 'team_scoped_tool';
    }

    public function description(): string
    {
        return 'Tool con defaultScope=Team.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];
    }

    public function defaultScope(): AccessScope
    {
        return AccessScope::Team;
    }

    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        $this->lastAccessibleUserIds = $this->accessibleUserIds($ctx->user, $this->defaultScope());

        return ToolResult::success(['ids' => $this->lastAccessibleUserIds]);
    }
}
