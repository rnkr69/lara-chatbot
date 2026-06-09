<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Controllers;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * `GET /chatbot/suggested-prompts` (v1.1.1, finding #14.d).
 *
 * Devuelve la lista de prompts sugeridos que el widget muestra en su
 * empty state. La config `chatbot.widget.suggested_prompts` puede ser:
 *
 *   - array<{label, prompt}>  estático.
 *   - Closure(Authenticatable): array<{label, prompt}>  dinámico por rol.
 *
 * El controller resuelve el closure server-side (donde tiene acceso al
 * usuario autenticado vía Auth) y devuelve siempre el shape plano para
 * que el widget JS no tenga que saber del closure.
 */
class SuggestedPromptsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $raw = config('chatbot.widget.suggested_prompts', []);

        $user = $request->user();

        $list = $this->resolve($raw, $user);

        // Normalize to [{label, prompt}, ...] discarding malformed entries.
        $out = [];
        foreach ($list as $item) {
            if (! is_array($item)) {
                continue;
            }
            $label  = isset($item['label'])  && is_string($item['label'])  ? trim($item['label'])  : '';
            $prompt = isset($item['prompt']) && is_string($item['prompt']) ? trim($item['prompt']) : '';
            if ($label === '' || $prompt === '') {
                continue;
            }
            $out[] = ['label' => $label, 'prompt' => $prompt];
        }

        return new JsonResponse(['data' => $out]);
    }

    /**
     * @return array<int, mixed>
     */
    private function resolve(mixed $raw, ?Authenticatable $user): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if ($raw instanceof Closure) {
            try {
                $result = $raw($user);
                return is_array($result) ? $result : [];
            } catch (Throwable) {
                return [];
            }
        }

        return [];
    }
}
