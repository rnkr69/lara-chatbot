<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Authorization\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Rnkr69\LaraChatbot\Tools\Contracts\BackendTool;

/**
 * 4ª dimensión de autorización (gap de hosts multi-tenant):
 * mapea (usuario, tool, page context) a la lista de IDs de tenant/entidad
 * accesibles. La cascada de autorización pasa de
 *
 *     permiso → scope → ownership
 *
 * a
 *
 *     permiso → scope → tenant → ownership
 *
 * cuando una tool declara `tenantScope=true` en E06.
 *
 * **Origen**: hosts multi-tenant (`corporation_id`) y hosts entity-scoped
 * (`event_id`).
 *
 * Comportamiento esperado:
 *  - Devolver `null` ⇒ el invocador tiene acceso a todos los tenants
 *    (bypass del filtro `whereIn` que aplicaría la tool).
 *  - Devolver `[]`   ⇒ el invocador no tiene acceso a ningún tenant
 *    (la tool debe devolver lista vacía o lanzar ToolUnauthorizedException).
 *  - Devolver `[id1, id2, ...]` ⇒ aplicar `whereIn(tenant_field, $ids)`.
 *
 * El `TenantResolver` es **opcional**: el paquete no lo bind por defecto.
 * Sólo se enlaza si `chatbot.authorization.tenant_resolver` apunta a una
 * clase concreta. Tools con `tenantScope=true` cuando no hay resolver
 * registrado hacen fallar el boot del ToolRegistry (E06).
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
