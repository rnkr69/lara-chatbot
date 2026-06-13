<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Rnkr69\LaraChatbot\Models\WidgetRefreshPolicy;
use Rnkr69\LaraChatbot\Models\WidgetRefreshStatus;

/**
 * JSON shape of a dashboard widget (v2.0 / E4).
 *
 * The `dashboard_id` is omitted because the endpoint serving the widgets is
 * already scoped to the parent dashboard (`/chatbot/dashboards/{slug}/widgets`).
 *
 * `source.page_context_snapshot` IS INCLUDED in the response — the frontend
 * can show the user which context was captured on pin (a "linked to
 * this invoice" badge, an audit tooltip) and needs the full snapshot
 * to re-pin/clone widgets without losing the context.
 *
 * @property-read \Rnkr69\LaraChatbot\Models\DashboardWidget $resource
 */
class DashboardWidgetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WidgetRefreshPolicy $policy */
        $policy = $this->resource->refresh_policy;
        /** @var WidgetRefreshStatus $status */
        $status = $this->resource->last_refresh_status;

        return [
            'id'                  => $this->resource->id,
            'block_type'          => $this->resource->block_type,
            'title'               => $this->resource->title,
            'position'            => $this->resource->position,
            'snapshot'            => $this->resource->snapshot,
            'source'              => $this->resource->source,
            'source_signature'    => $this->resource->source_signature,
            'refresh_policy'      => $policy->value,
            'last_refresh_status' => $status->value,
            'last_refresh_error'  => $this->resource->last_refresh_error,
            'last_refreshed_at'   => optional($this->resource->last_refreshed_at)->toIso8601String(),
            'order_index'         => (int) $this->resource->order_index,
            'created_at'          => optional($this->resource->created_at)->toIso8601String(),
            'updated_at'          => optional($this->resource->updated_at)->toIso8601String(),
        ];
    }
}
