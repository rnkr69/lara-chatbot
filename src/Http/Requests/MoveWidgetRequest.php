<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Rnkr69\LaraChatbot\Models\WidgetRefreshPolicy;

/**
 * Validates the payload of `PATCH /chatbot/dashboards/{slug}/widgets/{id}`
 * (v2.0 / E4). Covers relocation + resize + retitle + change of
 * refresh policy in a single endpoint (same verb as move in Notion/Grafana).
 *
 *   - `position`         optional. Only if present — the client must
 *                         send the full shape `{x,y,w,h}` to avoid
 *                         inconsistent intermediate states.
 *   - `title`            optional. null allowed (revert to the inferred title).
 *   - `refresh_policy`   optional. Enum string: `on_open` | `manual` | `never`.
 *
 * The controller decides which body fields are present (via
 * `$request->has(...)`) and applies a selective `fill()`.
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
