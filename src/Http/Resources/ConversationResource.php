<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape of a conversation returned by the controller (E10). The
 * `user_type`/`user_id` morph are omitted — the client never needs them
 * because it always operates within its own scope.
 *
 * @property-read \Rnkr69\LaraChatbot\Models\Conversation $resource
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->resource->id,
            'title'      => $this->resource->title,
            'metadata'   => $this->resource->metadata,
            'created_at' => optional($this->resource->created_at)->toIso8601String(),
            'updated_at' => optional($this->resource->updated_at)->toIso8601String(),
        ];
    }
}
