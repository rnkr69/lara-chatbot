<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida el payload de `POST /chatbot/dashboards` (v2.0 / E4).
 *
 *   - `name`        requerido. Hasta 120 chars (matches `chatbot_dashboards.name`).
 *                   El servidor deriva el `slug` con `Str::slug($name)` + sufijo
 *                   numérico si colisiona dentro del scope del usuario.
 *                   `name` NO es único por usuario; el `slug` sí lo es a nivel
 *                   schema (`unique (user_type, user_id, slug)`).
 *   - `is_default`  opcional. Si true, el hook `saving` del modelo auto-demote
 *                   al resto de dashboards del usuario.
 *   - `metadata`    opcional. JSON libre que el frontend (E5) usa para tema,
 *                   refresh_default_policy y colores.
 *
 * El cap `chatbot.dashboard.max_dashboards_per_user` (default 20) se enforce
 * en el controller (no aquí) porque el Form Request no sabe qué usuario está
 * autenticado en el momento del `rules()` resolver — la query de count vive
 * en el controller donde `$this->user()` ya está resuelto.
 */
class CreateDashboardRequest extends FormRequest
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
            'name'       => ['required', 'string', 'min:1', 'max:120'],
            'is_default' => ['nullable', 'boolean'],
            'metadata'   => ['nullable', 'array'],
        ];
    }
}
