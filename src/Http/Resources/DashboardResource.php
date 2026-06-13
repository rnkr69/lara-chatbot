<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape of a user's dashboard (v2.0 / E4).
 *
 * The `user_type`/`user_id` morph is omitted (same as in `ConversationResource`):
 * the client always operates within its own scope and does not need those
 * fields for anything useful.
 *
 * The `widget_count` is a count materialized on-demand:
 *   - `index` computes it with a single aggregated query (when the controller
 *     does `withCount('widgets')`).
 *   - `show` does not need it because it already includes `widgets` inline (the
 *     `widgets` relation eager-loaded → the `widgets` key appears inside `data`);
 *     the `widget_count` is omitted to avoid sending two representations of the
 *     same data.
 *
 * `widgets` only appears when the relation is eager-loaded (`whenLoaded`),
 * so `index`/`store`/`update` —which do not load it— do not include it.
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
