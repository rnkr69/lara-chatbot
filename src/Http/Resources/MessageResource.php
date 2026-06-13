<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape of a message returned by `GET /chatbot/conversations/{id}` (E10).
 * `role` is serialized as a string (the backed enum's value) so as not to couple the
 * client to the FQCN of the package's internal enum.
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
