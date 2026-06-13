<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload of `POST /chatbot/dashboards` (v2.0 / E4).
 *
 *   - `name`        required. Up to 120 chars (matches `chatbot_dashboards.name`).
 *                   The server derives the `slug` with `Str::slug($name)` + a
 *                   numeric suffix if it collides within the user's scope.
 *                   `name` is NOT unique per user; the `slug` is, at the
 *                   schema level (`unique (user_type, user_id, slug)`).
 *   - `is_default`  optional. If true, the model's `saving` hook auto-demotes
 *                   the user's other dashboards.
 *   - `metadata`    optional. Free-form JSON that the frontend (E5) uses for theme,
 *                   refresh_default_policy and colors.
 *
 * The cap `chatbot.dashboard.max_dashboards_per_user` (default 20) is enforced
 * in the controller (not here) because the Form Request does not know which user is
 * authenticated at the time the `rules()` resolves — the count query lives
 * in the controller where `$this->user()` is already resolved.
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
