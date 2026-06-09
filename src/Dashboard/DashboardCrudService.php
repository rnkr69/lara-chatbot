<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Dashboard;

use Illuminate\Support\Str;
use Rnkr69\LaraChatbot\Models\Dashboard;

/**
 * v2.2 — Capa de edición/borrado de dashboards compartida entre:
 *
 *   1. **Controller HTTP** (`PATCH/DELETE /chatbot/dashboards/{slug}`).
 *      Path histórico — la sidebar del dashboard manda renames /
 *      set-default / delete por click.
 *   2. **`EditDashboardTool` / `DeleteDashboardTool`** (edición conversacional
 *      v2.2). El LLM aplica los mismos cambios pedidos en lenguaje natural
 *      ("renombra el panel a Operaciones Q1", "borra el panel viejo").
 *
 * Igual que `WidgetCrudService`, no valida ownership ni autoriza — el caller
 * resuelve el dashboard del usuario y delega aquí sólo el "apply + persist".
 *
 * El hook `saving` del modelo `Dashboard` auto-DEMOTE al resto del usuario
 * cuando uno se marca `is_default=true`, así que el servicio no orquesta esa
 * parte — sólo asigna el flag y guarda. El auto-PROMOTE post-delete del
 * próximo dashboard del usuario sí lo implementa este servicio (paridad
 * con `ApiDashboardController::promoteNextDefault`).
 */
class DashboardCrudService
{
    /**
     * Aplica cambios selectivos al dashboard. Semántica PATCH como en
     * `WidgetCrudService`. Si se renombra, el slug se regenera (paridad con
     * el controller HTTP) — el caller debe leer `new_slug` del retorno
     * cuando aplique el cambio para actualizar URLs.
     *
     * @param  array{name?: string, is_default?: bool, metadata?: array<string,mixed>|null} $changes
     * @return array{applied: array<string, mixed>, new_slug?: string}
     */
    public function update(Dashboard $dashboard, array $changes): array
    {
        $applied = [];
        $newSlug = null;

        if (array_key_exists('name', $changes)) {
            $name = (string) $changes['name'];
            $dashboard->name = $name;

            // Re-derive slug al renombrar. Conservar el slug viejo crea
            // disonancia "el nombre dice X pero la URL dice Y" que confunde
            // a quien copie/pegue links. Si colisiona con otro dashboard
            // del mismo usuario (no este), aplicamos sufijo numérico.
            $derived = $this->deriveUniqueSlug(
                $dashboard->user_type,
                $dashboard->user_id,
                $name,
                $dashboard->id,
            );

            if ($derived !== $dashboard->slug) {
                $newSlug = $derived;
                $dashboard->slug = $derived;
            }

            $applied['name'] = $name;
        }

        if (array_key_exists('is_default', $changes)) {
            $dashboard->is_default = (bool) $changes['is_default'];
            $applied['is_default'] = (bool) $changes['is_default'];
        }

        if (array_key_exists('metadata', $changes)) {
            $dashboard->metadata = $changes['metadata'];
            $applied['metadata'] = $changes['metadata'];
        }

        if ($applied === []) {
            return ['applied' => []];
        }

        $dashboard->save();

        $result = ['applied' => $applied];
        if ($newSlug !== null) {
            $result['new_slug'] = $newSlug;
        }

        return $result;
    }

    /**
     * Soft-delete del dashboard. Si era el `is_default`, promueve el próximo
     * más recientemente actualizado a default (paridad con
     * `ApiDashboardController::promoteNextDefault`). Devuelve el slug del
     * dashboard promovido — útil para que el LLM lo mencione al usuario
     * ("borré X; ahora tu default es Y") — o `null` si no había próximo.
     */
    public function delete(Dashboard $dashboard): ?string
    {
        $wasDefault = (bool) $dashboard->is_default;
        $userType = $dashboard->user_type;
        $userId = $dashboard->user_id;

        $dashboard->delete();

        if (! $wasDefault) {
            return null;
        }

        /** @var Dashboard|null $next */
        $next = Dashboard::query()
            ->where('user_type', $userType)
            ->where('user_id', $userId)
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($next === null) {
            return null;
        }

        $next->is_default = true;
        $next->save();

        return $next->slug;
    }

    /**
     * Deriva un slug único dentro del scope `(user_type, user_id)`. Empieza
     * con `Str::slug($name)`; si colisiona, prueba `-2`, `-3`… hasta dar
     * con uno libre. `$excludeId` se pasa al PATCH para que el dashboard
     * que se renombra no compita contra sí mismo. Si `Str::slug` produce
     * cadena vacía (input con sólo símbolos), fallback a `'dashboard'`.
     *
     * `Dashboard::withTrashed()` es deliberado (v2.1.1 #21): la constraint
     * UNIQUE no excluye soft-deleted, así que enumeramos también esos
     * slugs.
     */
    public function deriveUniqueSlug(string $userType, mixed $userId, string $name, ?int $excludeId = null): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'dashboard';
        }

        if (strlen($base) > 130) {
            $base = substr($base, 0, 130);
        }

        $candidate = $base;
        $suffix    = 2;

        while ($this->slugExists($userType, $userId, $candidate, $excludeId)) {
            $candidate = $base . '-' . $suffix;
            $suffix++;

            if ($suffix > 9999) {
                $candidate = $base . '-' . Str::lower(Str::random(8));
                break;
            }
        }

        return $candidate;
    }

    protected function slugExists(string $userType, mixed $userId, string $slug, ?int $excludeId): bool
    {
        return Dashboard::withTrashed()
            ->where('user_type', $userType)
            ->where('user_id', $userId)
            ->where('slug', $slug)
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists();
    }
}
