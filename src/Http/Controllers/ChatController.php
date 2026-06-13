<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Controllers;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Rnkr69\LaraChatbot\Http\Requests\SendMessageRequest;
use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Services\ChatService;
use Rnkr69\LaraChatbot\Services\PageContextSanitizer;
use Rnkr69\LaraChatbot\Sse\SseEvent;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Endpoint `POST /chatbot/stream` (E09 ROADMAP §5/E09).
 *
 * Responsibility: serialize to the SSE protocol (`event: <name>\ndata: <json>\n\n`)
 * the `SseEvent`s that `ChatService::handle` (E08) produces for the user's
 * current turn.
 *
 * Applies a per-user rate limit (`chatbot:stream:{user_id}`) using
 * `Illuminate\Support\Facades\RateLimiter` and respecting
 * `chatbot.limits.rate_limit.{enabled,requests_per_minute}`. If the client
 * closes the connection, the loop breaks the `foreach` over the
 * orchestrator's Generator — this forces the Generator to suspend and,
 * therefore, the Prism stream stops being iterated (no more tokens are
 * spent). Close detection is done via an injectable Closure (container
 * binding `chatbot.connection_aborted`) whose default calls the PHP function
 * `connection_aborted()`.
 *
 * Page context (E14, D13): type-by-type sanitization is applied by the
 * `PageContextSanitizer` (drops closures/objects/resources/null/non-finite
 * floats; preserves string/int/float/bool/array). If AFTER sanitizing the JSON
 * still exceeds `chatbot.limits.page_context_kb`, D11 applies as a
 * fallback: full binary discard + `Log::info` (no 422). The turn
 * continues without context.
 */
class ChatController extends Controller
{
    public function __construct(
        protected Container $container,
        protected PageContextSanitizer $sanitizer,
    ) {}

    public function stream(SendMessageRequest $request, ChatService $service): Response
    {
        $user = $request->user();

        if (($limit = $this->checkRateLimit($user)) instanceof Response) {
            return $limit;
        }

        $conversation = $this->resolveConversation($request, $user);

        $userMessage = (string) $request->input('message', '');
        $pageContext = $this->sanitizePageContext($request->input('page_context', []));

        $isAborted = $this->resolveAbortDetector();

        $callback = function () use ($service, $conversation, $userMessage, $pageContext, $isAborted): void {
            foreach ($service->handle($conversation, $userMessage, $pageContext) as $event) {
                /** @var SseEvent $event */
                echo $this->formatSseFrame($event);

                if (function_exists('ob_get_level') && ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();

                if ($isAborted()) {
                    break;
                }
            }
        };

        return new StreamedResponse($callback, 200, [
            'Content-Type'      => 'text/event-stream; charset=UTF-8',
            'Cache-Control'     => 'no-cache, private',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    /**
     * Returns a 429 `Response` if the user exceeded the rate limit, or
     * `null` if within the limit. When within the limit, it counts the
     * hit before returning `null`. If the rate limit is
     * disabled by config (`chatbot.limits.rate_limit.enabled=false`),
     * it returns `null` without doing anything.
     */
    protected function checkRateLimit(mixed $user): ?Response
    {
        $config  = config('chatbot.limits.rate_limit', []);
        $enabled = (bool) ($config['enabled'] ?? true);
        $max     = (int) ($config['requests_per_minute'] ?? 30);

        if (! $enabled || $max <= 0) {
            return null;
        }

        $userKey = $user instanceof Model ? $user->getKey() : $user?->getAuthIdentifier();
        $key     = "chatbot:stream:{$userKey}";

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response('', 429, [
                'Retry-After'           => (string) $retryAfter,
                'X-RateLimit-Reset'     => (string) $retryAfter,
                'X-RateLimit-Limit'     => (string) $max,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    /**
     * Retrieves the user's conversation (if `conversation_id` comes in
     * the payload — the `exists` rule already guaranteed ownership) or creates a
     * new one. The returned conversation has `user` loaded as a relation
     * to avoid the later morphTo query in `ChatService`.
     */
    protected function resolveConversation(SendMessageRequest $request, mixed $user): Conversation
    {
        $conversationId = $request->input('conversation_id');

        if ($conversationId !== null && $conversationId !== '') {
            $conversation = Conversation::query()
                ->forUser($user)
                ->find((int) $conversationId);

            // Defensive: the FormRequest's `exists` rule already covers this
            // case with 422; if we reach here with null, something got out of sync.
            abort_if($conversation === null, 404);
        } else {
            $conversation = Conversation::create([
                'user_type' => $user instanceof Model ? $user->getMorphClass() : (string) ($user::class ?? ''),
                'user_id'   => $user instanceof Model ? $user->getKey() : $user?->getAuthIdentifier(),
                'title'     => null,
                'metadata'  => null,
            ]);
        }

        $conversation->setRelation('user', $user);

        return $conversation;
    }

    /**
     * Two-phase sanitization (E14):
     *   1. Type by type via `PageContextSanitizer` (drops closures/objects/
     *      resources/null/non-finite floats; preserves primitives + arrays).
     *   2. Binary truncation fallback (D11): if the resulting JSON still
     *      exceeds `chatbot.limits.page_context_kb`, it is discarded entirely and
     *      logged at info level — the turn continues without page context.
     *
     * @return array<string, mixed>
     */
    protected function sanitizePageContext(mixed $raw): array
    {
        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $sanitized = $this->sanitizer->sanitize($raw);

        if ($sanitized === []) {
            return [];
        }

        $limitKb = (int) config('chatbot.limits.page_context_kb', 16);
        $limit   = max(1, $limitKb) * 1024;

        $encoded = json_encode($sanitized);
        if (! is_string($encoded) || strlen($encoded) > $limit) {
            Log::info(sprintf(
                '[chatbot] page_context dropped for exceeding %d bytes (limit %d KB) after sanitizing.',
                $limit,
                $limitKb,
            ));

            return [];
        }

        return $sanitized;
    }

    /**
     * Formats an `SseEvent` to the frame `event: <name>\ndata: <json>\n\n`
     * required by the SSE protocol (W3C EventSource).
     */
    protected function formatSseFrame(SseEvent $event): string
    {
        $data = json_encode($event->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($data)) {
            $data = '{}';
        }

        return "event: {$event->event}\n"
            . "data: {$data}\n\n";
    }

    /**
     * The package injects the close detector via the container binding
     * `chatbot.connection_aborted`. Default = closure that invokes
     * `connection_aborted()` (PHP runtime). Tests override it with a
     * deterministic closure to verify that the loop breaks.
     */
    protected function resolveAbortDetector(): Closure
    {
        if ($this->container->bound('chatbot.connection_aborted')) {
            $resolved = $this->container->make('chatbot.connection_aborted');
            if ($resolved instanceof Closure) {
                return $resolved;
            }
        }

        return static fn (): bool => connection_aborted() === 1;
    }
}
