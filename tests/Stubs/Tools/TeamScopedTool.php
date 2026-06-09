<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs\Tools;

use Rnkr69\LaraChatbot\Authorization\AccessScope;
use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

/**
 * Tool con `defaultScope = Team`. Sirve para validar que
 * `accessibleQuery()` aplica `whereIn('user_id', $teamUserIds)` con los
 * IDs que devuelve el `ScopeResolver` registrado.
 */
class TeamScopedTool extends BaseBackendTool
{
    /**
     * Capturado por el último `handle()` para que el test pueda asertar
     * sobre los IDs que recibió.
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
