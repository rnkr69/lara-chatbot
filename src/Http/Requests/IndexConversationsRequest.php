<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida el payload del endpoint `GET /chatbot/conversations` (E10).
 *
 *   - `q`        opcional. Búsqueda LIKE sobre `title`. Recortado a 200 chars
 *                porque títulos del paquete son nullable y no pueden ser
 *                arbitrariamente largos en la UI.
 *   - `page`     opcional. Entero ≥ 1 (Laravel default = 1 si no se envía).
 *   - `per_page` opcional. Entero entre 1 y `chatbot.limits.conversations_per_page.max`.
 *                Si no se envía, el controller aplica el default
 *                `chatbot.limits.conversations_per_page.default`.
 */
class IndexConversationsRequest extends FormRequest
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
        $max = (int) config('chatbot.limits.conversations_per_page.max', 100);

        return [
            'q'        => ['nullable', 'string', 'max:200'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . $max],
        ];
    }
}
