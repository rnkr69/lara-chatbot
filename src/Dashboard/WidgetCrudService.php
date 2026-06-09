<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Dashboard;

use Rnkr69\LaraChatbot\Models\DashboardWidget;
use Rnkr69\LaraChatbot\Models\WidgetRefreshPolicy;

/**
 * v2.2 — Capa de edición/borrado de widgets compartida entre dos callers:
 *
 *   1. **Controller HTTP** (`PATCH/DELETE /chatbot/dashboards/{slug}/widgets/{id}`).
 *      El cliente JS de gridstack envía cambios atómicos (mover, redimensionar,
 *      retitular, cambiar refresh policy) en una sola request.
 *   2. **`EditWidgetTool` / `DeleteWidgetTool`** (edición conversacional v2.2).
 *      El LLM resuelve `widget_id` desde el page_context auto-inyectado al
 *      abrir `/chatbot/dashboard` y aplica los cambios pedidos en lenguaje
 *      natural ("mueve KPIs a la izquierda y hazlo más grande").
 *
 * El servicio NO valida ownership ni autoriza — el caller (controller
 * gracias a `findOwnedOr404` + middleware auth, o la tool gracias a su
 * cascada de scope) ya ha confirmado que el widget pertenece al usuario.
 * Aquí sólo aplicamos cambios y persistimos.
 *
 * Sin cambios de contrato HTTP: la `update()` produce exactamente el mismo
 * patrón de `fill()` selectivo que vivía inline en el controller; el shape
 * del Resource emitido al cliente es idéntico al de v2.1.x.
 */
class WidgetCrudService
{
    /**
     * Aplica cambios selectivos al widget. Sólo las claves PRESENTES en
     * `$changes` se tocan; las ausentes se preservan (semántica PATCH).
     *
     * `position` se normaliza con `WidgetPositionNormalizer`. `title` acepta
     * `null` para limpiar. `refresh_policy` se ignora silenciosamente si
     * no es un valor válido del enum (mismo comportamiento histórico —
     * `tryFrom` devuelve null → no se asigna).
     *
     * @param  array{position?: array<string,mixed>|null, title?: string|null, refresh_policy?: string} $changes
     * @return array<string, mixed> $appliedChanges  diff resumen ("qué cambió") para devolver al LLM.
     */
    public function update(DashboardWidget $widget, array $changes): array
    {
        $applied = [];

        if (array_key_exists('position', $changes)) {
            $rawPosition = $changes['position'];
            $normalized = WidgetPositionNormalizer::normalize(
                is_array($rawPosition) ? $rawPosition : null,
            );
            $widget->position = $normalized;
            $applied['position'] = $normalized;
        }

        if (array_key_exists('title', $changes)) {
            $widget->title = $changes['title'];
            $applied['title'] = $changes['title'];
        }

        if (array_key_exists('refresh_policy', $changes)) {
            $policy = WidgetRefreshPolicy::tryFrom((string) $changes['refresh_policy']);
            if ($policy !== null) {
                $widget->refresh_policy = $policy;
                $applied['refresh_policy'] = $policy->value;
            }
        }

        if ($applied === []) {
            return [];
        }

        $widget->save();

        return $applied;
    }

    /**
     * Soft-delete del widget. El dashboard se "touchea" para que la sidebar
     * refleje la edición reciente.
     */
    public function delete(DashboardWidget $widget): void
    {
        $dashboard = $widget->dashboard;
        $widget->delete();

        if ($dashboard !== null) {
            $dashboard->touch();
        }
    }
}
