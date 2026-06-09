<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Rnkr69\LaraChatbot\Models\Conversation;

/**
 * Valida el payload del endpoint `POST /chatbot/stream` (E09).
 *
 *   - `message`         requerido (string, ≤4000 chars).
 *   - `conversation_id` opcional. Si se envía, debe existir en la tabla
 *                        `chatbot_conversations` Y pertenecer al usuario
 *                        autenticado (vía `user_type`+`user_id`+ no soft
 *                        deleted). Si falla la regla `exists`, Laravel
 *                        devuelve 422 con detalle de campo, no 404 — esta
 *                        decisión la registra E09 en §1/E09.
 *   - `page_context`    opcional (array). El truncado por tamaño contra
 *                        `chatbot.limits.page_context_kb` lo aplica el
 *                        controller; aquí sólo se valida el tipo. La
 *                        sanitización fina (sólo strings/números/bool/arrays
 *                        simples) la formaliza E14.
 */
class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El middleware `auth` del grupo de rutas ya garantiza usuario
        // autenticado; este check es defensa en profundidad.
        return $this->user() !== null;
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

        return [
            'message' => ['required', 'string', 'max:4000'],

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
        ];
    }
}
