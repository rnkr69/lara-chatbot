<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida el body del endpoint `POST /chatbot/actions/{action}/confirm` (E16).
 *
 *  - `accept`   bool requerido. true = el usuario aprueba; false = rechaza.
 *  - `result`   array opcional. Si se envía con `accept=true`, indica que el
 *               widget ya ejecutó la primitiva y reporta el outcome — el row
 *               transiciona directamente a `executed`. Sin `result` con
 *               `accept=true`, el row queda en `confirmed` y el widget
 *               ejecutará después y volverá a llamar al endpoint con result.
 *               Si llega con `accept=false`, se persiste como motivo del
 *               rechazo (ej. `{reason: 'Razón del usuario'}`).
 *
 * La autorización por ownership de la conversación parent (404-no-403,
 * doctrina E10/D12) la aplica el controller con `forUser`.
 */
class ConfirmActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Defense-in-depth: el middleware `auth` del grupo ya garantiza
        // usuario; aquí confirmamos que el request lo trae adjunto.
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'accept' => ['required', 'boolean'],
            'result' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
