<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Rnkr69\LaraChatbot\Models\Conversation;

/**
 * Validates the payload of the endpoint `POST /chatbot/stream` (E09).
 *
 *   - `message`         required (string, ≤4000 chars).
 *   - `conversation_id` optional. If sent, it must exist in the
 *                        `chatbot_conversations` table AND belong to the
 *                        authenticated user (via `user_type`+`user_id`+ not soft
 *                        deleted). If the `exists` rule fails, Laravel
 *                        returns 422 with field detail, not 404 — this
 *                        decision is recorded by E09 in §1/E09.
 *   - `page_context`    optional (array). Size truncation against
 *                        `chatbot.limits.page_context_kb` is applied by the
 *                        controller; here only the type is validated. The
 *                        fine-grained sanitization (only strings/numbers/bool/simple
 *                        arrays) is formalized by E14.
 */
class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route group's `auth` middleware already guarantees an
        // authenticated user; this check is defense-in-depth.
        return $this->user() !== null;
    }

    /**
     * When the payload arrives as `multipart/form-data` (i.e. it carries file
     * attachments), `page_context` travels as a JSON string rather than a
     * native array. Decode it here so the `array` rule and the controller see a
     * consistent shape regardless of transport.
     */
    protected function prepareForValidation(): void
    {
        $ctx = $this->input('page_context');

        if (is_string($ctx) && $ctx !== '') {
            $decoded = json_decode($ctx, true);
            if (is_array($decoded)) {
                $this->merge(['page_context' => $decoded]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $user = $this->user();

        $convTable   = (new Conversation)->getTable();
        $morphClass  = $user instanceof Model ? $user->getMorphClass() : (string) ($user::class ?? '');
        $userKey     = $user instanceof Model ? $user->getKey() : ($user?->getAuthIdentifier());

        $attachmentsEnabled = (bool) config('chatbot.attachments.enabled', false);
        $maxFiles           = (int) config('chatbot.attachments.max_files', 5);
        $maxSizeKb          = (int) config('chatbot.attachments.max_size_kb', 10240);
        $allowedExtensions  = config('chatbot.attachments.allowed_extensions', []);
        $allowedExtensions  = is_array($allowedExtensions) ? $allowedExtensions : [];

        return [
            // Optional when the turn carries at least one attachment (a user may
            // just drop a document with no words).
            'message' => ['nullable', 'required_without:attachments', 'string', 'max:4000'],

            'conversation_id' => [
                'nullable',
                'integer',
                Rule::exists($convTable, 'id')->where(function ($q) use ($morphClass, $userKey) {
                    $q->where('user_type', $morphClass)
                        ->where('user_id', $userKey)
                        ->whereNull('deleted_at');
                }),
            ],

            'page_context' => ['nullable', 'array'],

            // Attachments are rejected outright when the feature is disabled;
            // otherwise bounded by count, size and an extension allow-list.
            'attachments'   => $attachmentsEnabled
                ? ['nullable', 'array', "max:{$maxFiles}"]
                : ['nullable', 'prohibited'],
            'attachments.*' => $attachmentsEnabled
                ? array_values(array_filter([
                    'file',
                    "max:{$maxSizeKb}",
                    $allowedExtensions !== [] ? 'mimes:' . implode(',', $allowedExtensions) : null,
                ]))
                : ['prohibited'],
        ];
    }
}
