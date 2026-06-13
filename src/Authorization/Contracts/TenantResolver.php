<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Authorization\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Rnkr69\LaraChatbot\Tools\Contracts\BackendTool;

/**
 * 4th authorization dimension (multi-tenant hosts gap):
 * maps (user, tool, page context) to the list of accessible tenant/entity
 * IDs. The authorization cascade goes from
 *
 *     permission → scope → ownership
 *
 * to
 *
 *     permission → scope → tenant → ownership
 *
 * when a tool declares `tenantScope=true` in E06.
 *
 * **Origin**: multi-tenant hosts (`corporation_id`) and entity-scoped hosts
 * (`event_id`).
 *
 * Expected behavior:
 *  - Returning `null` ⇒ the invoker has access to all tenants
 *    (bypasses the `whereIn` filter the tool would apply).
 *  - Returning `[]`   ⇒ the invoker has access to no tenant
 *    (the tool must return an empty list or throw ToolUnauthorizedException).
 *  - Returning `[id1, id2, ...]` ⇒ apply `whereIn(tenant_field, $ids)`.
 *
 * The `TenantResolver` is **optional**: the package does not bind it by
 * default. It's only bound if `chatbot.authorization.tenant_resolver`
 * points to a concrete class. Tools with `tenantScope=true` when no
 * resolver is registered fail the ToolRegistry boot (E06).
 */
interface TenantResolver
{
    /**
     * @param  array<string, mixed>  $pageContext
     * @return array<int, int|string>|null
     */
    public function resolveAccessibleTenantIds(
        Authenticatable $user,
        BackendTool $tool,
        array $pageContext,
    ): ?array;
}
