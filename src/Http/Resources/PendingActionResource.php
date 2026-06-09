<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Rnkr69\LaraChatbot\Models\PendingAction;

/**
 * Serializa un `PendingAction` para el endpoint `POST /chatbot/actions/{id}/confirm`
 * (E16). El widget consume este shape para decidir qué hacer tras la
 * respuesta:
 *
 *   - `status=confirmed` con `confirmation=confirm` → el widget ejecuta la
 *      primitiva localmente y vuelve a llamar al endpoint con `result`.
 *   - `status=executed`  → flujo cerrado, el row es terminal.
 *   - `status=rejected`  → flujo cerrado.
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
