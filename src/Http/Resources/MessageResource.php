<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape JSON de un mensaje devuelto por `GET /chatbot/conversations/{id}` (E10).
 * `role` se serializa como string (valor del backed enum) para no acoplar al
 * cliente al FQCN del enum interno del paquete.
 *
 * @property-read \Rnkr69\LaraChatbot\Models\Message $resource
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->resource->id,
            'role'         => $this->resource->role->value,
            'content'      => $this->resource->content,
            'tool_calls'   => $this->resource->tool_calls,
            'tool_results' => $this->resource->tool_results,
            'tokens_in'    => $this->resource->tokens_in,
            'tokens_out'   => $this->resource->tokens_out,
            'created_at'   => optional($this->resource->created_at)->toIso8601String(),
        ];
    }
}
