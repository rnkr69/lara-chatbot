<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Rnkr69\LaraChatbot\Models\PendingAction;

/**
 * Serializes a `PendingAction` for the endpoint `POST /chatbot/actions/{id}/confirm`
 * (E16). The widget consumes this shape to decide what to do after the
 * response:
 *
 *   - `status=confirmed` with `confirmation=confirm` → the widget executes the
 *      primitive locally and calls the endpoint again with `result`.
 *   - `status=executed`  → closed flow, the row is terminal.
 *   - `status=rejected`  → closed flow.
 *
 * @mixin PendingAction
 */
class PendingActionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'action_id'       => $this->action_id,
            'conversation_id' => $this->conversation_id,
            'tool'            => $this->tool,
            'args'            => $this->args,
            'status'          => $this->status->value,
            'confirmation'    => $this->confirmation->value,
            'result'          => $this->result,
            'expires_at'      => $this->expires_at?->toIso8601String(),
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
        ];
    }
}
