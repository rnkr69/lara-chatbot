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
 * Responsabilidad: serializar al protocolo SSE (`event: <name>\ndata: <json>\n\n`)
 * los `SseEvent` que `ChatService::handle` (E08) produce para el turno
 * actual del usuario.
 *
 * Aplica rate limit por usuario (`chatbot:stream:{user_id}`) usando
 * `Illuminate\Support\Facades\RateLimiter` y respetando
 * `chatbot.limits.rate_limit.{enabled,requests_per_minute}`. Si el cliente
 * cierra la conexión, el loop rompe el `foreach` sobre el Generator del
 * orquestador — esto fuerza la suspensión del Generator y, por tanto, deja
 * de iterarse el stream de Prism (no se gastan más tokens). La detección
 * de cierre se hace vía un Closure inyectable (binding container
 * `chatbot.connection_aborted`) cuyo default llama a la función PHP
 * `connection_aborted()`.
 *
 * Page context (E14, D13): la sanitización tipo a tipo la aplica el
 * `PageContextSanitizer` (drop de closures/objects/recursos/null/floats no
 * finitos; preserva string/int/float/bool/array). Si TRAS sanitizar el JSON
 * sigue excediendo `chatbot.limits.page_context_kb`, se aplica D11 como
 * fallback: descarte binario completo + `Log::info` (no 422). El turno
 * sigue sin contexto.
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
     * Devuelve un `Response` 429 si el usuario excedió el rate limit, o
     * `null` si está dentro del límite. Cuando está dentro del límite,
     * cuenta el hit antes de devolver `null`. Si el rate limit está
     * deshabilitado por config (`chatbot.limits.rate_limit.enabled=false`),
     * devuelve `null` sin hacer nada.
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
     * Recupera la conversación del usuario (si `conversation_id` viene en
     * el payload — la regla `exists` ya garantizó la propiedad) o crea una
     * nueva. La conversación devuelta tiene `user` cargado como relación
     * para evitar la query morphTo posterior en `ChatService`.
     */
    protected function resolveConversation(SendMessageRequest $request, mixed $user): Conversation
    {
        $conversationId = $request->input('conversation_id');

        if ($conversationId !== null && $conversationId !== '') {
            $conversation = Conversation::query()
                ->forUser($user)
                ->find((int) $conversationId);

            // Defensivo: la regla `exists` del FormRequest ya cubre este caso
            // con 422; si llegamos aquí con null algo se desincronizó.
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
     * Sanitización en dos fases (E14):
     *   1. Tipo a tipo via `PageContextSanitizer` (drop closures/objects/
     *      recursos/null/floats no finitos; preserva primitivas + arrays).
     *   2. Truncado binario fallback (D11): si el JSON resultante todavía
     *      excede `chatbot.limits.page_context_kb`, se descarta entero y se
     *      loguea info — el turno continúa sin page context.
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
                '[chatbot] page_context descartado por exceder %d bytes (limit %d KB) tras sanitizar.',
                $limit,
                $limitKb,
            ));

            return [];
        }

        return $sanitized;
    }

    /**
     * Formatea un `SseEvent` al frame `event: <name>\ndata: <json>\n\n`
     * exigido por el protocolo SSE (W3C EventSource).
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
     * El paquete inyecta el detector de cierre via container binding
     * `chatbot.connection_aborted`. Default = closure que invoca
     * `connection_aborted()` (PHP runtime). Tests lo sobrescriben con un
     * closure determinista para verificar que el loop rompe.
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
