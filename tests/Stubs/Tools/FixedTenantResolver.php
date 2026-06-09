<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Rnkr69\LaraChatbot\Authorization\Contracts\TenantResolver;
use Rnkr69\LaraChatbot\Tools\Contracts\BackendTool;

/**
 * `TenantResolver` controlable: devuelve la lista preestablecida (o null
 * para bypass).
 */
class FixedTenantResolver implements TenantResolver
{
    /**
     * @param  array<int, int|string>|null  $tenantIds
     */
    public function __construct(
        public ?array $tenantIds = [10, 20],
    ) {}

    public function resolveAccessibleTenantIds(
        Authenticatable $user,
        BackendTool $tool,
        array $pageContext,
    ): ?array {
        return $this->tenantIds;
    }
}
