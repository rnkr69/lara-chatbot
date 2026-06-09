<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Rnkr69\LaraChatbot\Models\WidgetRefreshPolicy;

/**
 * Valida el payload de `PATCH /chatbot/dashboards/{slug}/widgets/{id}`
 * (v2.0 / E4). Cubre reubicación + redimensionado + retitle + cambio de
 * refresh policy en un único endpoint (mismo verbo que mover en Notion/Grafana).
 *
 *   - `position`         opcional. Sólo si presente — el cliente debe
 *                         mandar el shape completo `{x,y,w,h}` para evitar
 *                         estados intermedios inconsistentes.
 *   - `title`            opcional. null permitido (volver al título inferido).
 *   - `refresh_policy`   opcional. Enum string: `on_open` | `manual` | `never`.
 *
 * El controller decide qué campos del body están presentes (vía
 * `$request->has(...)`) y aplica `fill()` selectivo.
 */
class MoveWidgetRequest extends FormRequest
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
        $policies = array_map(static fn (WidgetRefreshPolicy $p): string => $p->value, WidgetRefreshPolicy::cases());

        return [
            'position'       => ['sometimes', 'required', 'array'],
            'position.x'     => ['required_with:position', 'integer', 'min:0', 'max:11'],
            'position.y'     => ['required_with:position', 'integer', 'min:0'],
            'position.w'     => ['required_with:position', 'integer', 'min:1', 'max:12'],
            'position.h'     => ['required_with:position', 'integer', 'min:1', 'max:60'],
            'title'          => ['sometimes', 'nullable', 'string', 'max:180'],
            'refresh_policy' => ['sometimes', 'required', 'string', 'in:' . implode(',', $policies)],
        ];
    }
}
