<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape JSON de una conversación devuelta por el controller (E10). El
 * `user_type`/`user_id` morph se omiten — el cliente nunca los necesita
 * porque siempre opera dentro de su propio scope.
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
