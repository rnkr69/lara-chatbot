<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida el payload del endpoint `POST /chatbot/conversations` (E10).
 *
 *   - `title`    opcional. Hasta 200 chars (cubre títulos generados por el
 *                LLM tras los primeros mensajes y los que el host quiera
 *                injectar manualmente).
 *   - `metadata` opcional. Array libre que la app del host usa para guardar
 *                provider/model overrides (E08 los lee desde
 *                `Conversation->metadata->provider/model`), tags, flags, etc.
 *                Se persiste tal cual en la columna JSON.
 */
class StoreConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title'    => ['nullable', 'string', 'max:200'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
