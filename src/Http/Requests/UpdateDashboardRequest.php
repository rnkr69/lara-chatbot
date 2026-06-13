<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload of `PATCH /chatbot/dashboards/{slug}` (v2.0 / E4).
 *
 *   - `name`        optional. If present, the server regenerates the slug
 *                   (Str::slug + numeric suffix if it collides) and persists it.
 *                   Keeping the old slug would create broken links on rename;
 *                   re-deriving it is the option that best matches the mental
 *                   model "the slug is the URL representation of the name".
 *   - `is_default`  optional. true → the `saving` hook auto-demotes the rest.
 *                   false → if it was the default, no automatic replacement here;
 *                   promotion happens on `DELETE` (not on `PATCH false`).
 *   - `metadata`    optional. Full replacement (not merge); the client sends
 *                   the final shape it wants to persist.
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
