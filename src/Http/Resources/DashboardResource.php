<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape JSON de un dashboard del usuario (v2.0 / E4).
 *
 * El `user_type`/`user_id` morph se omite (igual que en `ConversationResource`):
 * el cliente siempre opera dentro de su propio scope y no necesita esos
 * campos para nada útil.
 *
 * El `widget_count` es un conteo materializado on-demand:
 *   - `index` lo computa con una single query agregada (cuando el controller
 *     hace `withCount('widgets')`).
 *   - `show` no lo necesita porque ya incluye `widgets` inline (la relación
 *     `widgets` eager-loaded → la key `widgets` aparece dentro de `data`);
 *     se omite el `widget_count` para no enviar dos representaciones del
 *     mismo dato.
 *
 * `widgets` sólo aparece cuando la relación está eager-loaded (`whenLoaded`),
 * así que `index`/`store`/`update` —que no la cargan— no la incluyen.
 *
 * @property-read \Rnkr69\LaraChatbot\Models\Dashboard $resource
 */
class DashboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->resource->id,
            'slug'           => $this->resource->slug,
            'name'           => $this->resource->name,
            'is_default'     => (bool) $this->resource->is_default,
            'layout_version' => (int) $this->resource->layout_version,
            'metadata'       => $this->resource->metadata,
            'widget_count'   => $this->whenCounted('widgets'),
            'widgets'        => DashboardWidgetResource::collection($this->whenLoaded('widgets')),
            'created_at'     => optional($this->resource->created_at)->toIso8601String(),
            'updated_at'     => optional($this->resource->updated_at)->toIso8601String(),
        ];
    }
}
