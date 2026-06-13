<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload of the endpoint `GET /chatbot/conversations` (E10).
 *
 *   - `q`        optional. LIKE search over `title`. Trimmed to 200 chars
 *                because the package's titles are nullable and cannot be
 *                arbitrarily long in the UI.
 *   - `page`     optional. Integer ≥ 1 (Laravel default = 1 if not sent).
 *   - `per_page` optional. Integer between 1 and `chatbot.limits.conversations_per_page.max`.
 *                If not sent, the controller applies the default
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
