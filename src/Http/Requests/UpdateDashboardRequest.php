<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida el payload de `PATCH /chatbot/dashboards/{slug}` (v2.0 / E4).
 *
 *   - `name`        opcional. Si presente, el server regenera el slug
 *                   (Str::slug + sufijo numérico si colisiona) y lo persiste.
 *                   Mantener el slug viejo crearía links rotos al renombrar;
 *                   re-derivarlo es la opción que mejor matchea la mental
 *                   model "el slug es la representación URL del nombre".
 *   - `is_default`  opcional. true → el hook `saving` auto-demote al resto.
 *                   false → si era default, ningún reemplazo automático aquí;
 *                   la promoción ocurre en `DELETE` (no en `PATCH false`).
 *   - `metadata`    opcional. Reemplazo completo (no merge); el cliente envía
 *                   el shape final que quiere persistir.
 */
class UpdateDashboardRequest extends FormRequest
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
            'name'       => ['sometimes', 'required', 'string', 'min:1', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
            'metadata'   => ['sometimes', 'nullable', 'array'],
        ];
    }
}
