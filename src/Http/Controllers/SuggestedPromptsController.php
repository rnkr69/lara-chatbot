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
 * Returns the list of suggested prompts that the widget shows in its
 * empty state. The config `chatbot.widget.suggested_prompts` can be:
 *
 *   - array<{label, prompt}>  static.
 *   - Closure(Authenticatable): array<{label, prompt}>  dynamic per role.
 *
 * The controller resolves the closure server-side (where it has access to the
 * authenticated user via Auth) and always returns the flat shape so
 * the JS widget does not have to know about the closure.
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
