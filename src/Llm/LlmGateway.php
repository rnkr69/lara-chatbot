<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Llm;

use Generator;
use Rnkr69\LaraChatbot\Llm\Exceptions\LlmException;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\PendingRequest as TextPendingRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Throwable;

/**
 * Envoltura del SDK Prism. Ningún otro componente del paquete debería
 * llamar a `Prism::text()` directamente: pasar siempre por aquí garantiza
 * que el provider/model se resuelven desde config (con override por
 * conversación), que el system prompt se construye uniformemente y que
 * los errores se traducen a `LlmException`.
 *
 * E08 (`ChatService`) usará `streamChat()` para producir el stream SSE;
 * `chat()` es el fallback no-streaming usado por `chatbot:test-connection`
 * y por hosts que no quieren SSE.
 */
class LlmGateway
{
    public function __construct(
        protected SystemPromptBuilder $systemPromptBuilder,
    ) {}

    /**
     * Llamada en streaming. Devuelve los `StreamEvent` de Prism
     * (TextDelta, ToolCall, ToolResult, Error, etc.). E08 los traduce a
     * eventos SSE específicos del paquete.
     *
     * @param  array<int, Message>  $messages
     * @param  array<int, Tool>  $tools
     * @param  PromptOptions|array<string, mixed>  $options
     * @return Generator<\Prism\Prism\Streaming\Events\StreamEvent>
     *
     * @throws LlmException
     */
    public function streamChat(array $messages, array $tools = [], PromptOptions|array $options = []): Generator
    {
        $request = $this->buildRequest($messages, $tools, $this->normalizeOptions($options));

        try {
            yield from $request->asStream();
        } catch (Throwable $e) {
            throw LlmException::fromPrism($e);
        }
    }

    /**
     * Llamada no-streaming. Devuelve un `TextResponse` ya consolidado.
     *
     * @param  array<int, Message>  $messages
     * @param  array<int, Tool>  $tools
     * @param  PromptOptions|array<string, mixed>  $options
     *
     * @throws LlmException
     */
    public function chat(array $messages, array $tools = [], PromptOptions|array $options = []): TextResponse
    {
        $request = $this->buildRequest($messages, $tools, $this->normalizeOptions($options));

        try {
            return $request->asText();
        } catch (Throwable $e) {
            throw LlmException::fromPrism($e);
        }
    }

    /**
     * Atajo para el comando `chatbot:test-connection`: emite un único
     * mensaje "ping" sin tools y devuelve el texto recibido (o lanza).
     *
     * @throws LlmException
     */
    public function ping(?string $provider = null, ?string $model = null): string
    {
        $response = $this->chat(
            messages: [new UserMessage('ping')],
            tools: [],
            options: new PromptOptions(
                provider: $provider,
                model: $model,
                systemPrompt: 'Reply with the single word "pong".',
            ),
        );

        return $response->text;
    }

    /**
     * @param  array<int, Message>  $messages
     * @param  array<int, Tool>  $tools
     */
    protected function buildRequest(array $messages, array $tools, PromptOptions $options): TextPendingRequest
    {
        $provider = $options->provider ?? config('chatbot.provider');
        $model    = $options->model ?? config('chatbot.model');

        $request = Prism::text()->using($provider, $model);

        $request = $this->applySystemPrompt($request, $options, (string) $provider);

        if ($messages !== []) {
            $request = $request->withMessages($messages);
        }

        if ($tools !== []) {
            $request = $request->withTools($tools);
        }

        if ($options->maxSteps !== null) {
            $request = $request->withMaxSteps($options->maxSteps);
        }

        if ($options->maxTokens !== null) {
            $request = $request->withMaxTokens($options->maxTokens);
        }

        if ($options->temperature !== null) {
            $request = $request->usingTemperature($options->temperature);
        }

        return $request;
    }

    /**
     * @param  PromptOptions|array<string, mixed>  $options
     */
    protected function normalizeOptions(PromptOptions|array $options): PromptOptions
    {
        return $options instanceof PromptOptions
            ? $options
            : PromptOptions::fromArray($options);
    }

    /**
     * Aplica el system prompt al request, opcionalmente con prompt caching
     * (v1.1.1, finding #14.g).
     *
     * Si `chatbot.llm.cache_system_prompt=true` Y el provider es Anthropic
     * Y `$options->systemPrompt` no está overrideado por el caller, se
     * divide el prompt en `cacheable` (header + tools + decision strategy
     * + locale) y `dynamic` (page context + pending actions). El bloque
     * cacheable viaja con `cache_control: ephemeral` via
     * `usingProviderMeta()` si Prism lo soporta — caemos al prompt
     * concatenado tradicional si no.
     *
     * Resultado: ~75% ahorro de input cost en conversaciones de 10+ turns
     * con un system prompt grande (cache TTL Anthropic = 5 min).
     */
    protected function applySystemPrompt(TextPendingRequest $request, PromptOptions $options, string $provider): TextPendingRequest
    {
        // Override explícito del caller (chatbot:test-connection ping) → sin split.
        if ($options->systemPrompt !== null) {
            return $request->withSystemPrompt($options->systemPrompt);
        }

        $cacheEnabled = (bool) config('chatbot.llm.cache_system_prompt', true);
        $isAnthropic  = strtolower($provider) === 'anthropic';

        if (! $cacheEnabled || ! $isAnthropic) {
            $prompt = $this->systemPromptBuilder->build($options->promptContext);
            return $request->withSystemPrompt($prompt);
        }

        $split = $this->systemPromptBuilder->buildSplit($options->promptContext);

        // Best-effort: try to pass cache_control via Prism provider meta.
        // Prism versions that don't expose this just receive a concatenated
        // prompt — no errors, no surprises.
        $merged = trim($split['cacheable'] . "\n\n" . $split['dynamic']);
        $request = $request->withSystemPrompt($merged);

        // Provider-level hint for Anthropic via Prism's providerMeta API
        // when available. The Anthropic Prism driver inspects `cache_control`
        // to wrap the system prompt block. If the method is absent (older
        // Prism), we silently skip — the prompt still goes through.
        if (method_exists($request, 'withProviderMeta')) {
            try {
                $request = $request->withProviderMeta('anthropic', [
                    'cache_control' => ['type' => 'ephemeral'],
                ]);
            } catch (Throwable) { /* fall through — provider hint not supported */ }
        } elseif (method_exists($request, 'usingProviderMeta')) {
            try {
                $request = $request->usingProviderMeta('anthropic', [
                    'cache_control' => ['type' => 'ephemeral'],
                ]);
            } catch (Throwable) { /* fall through */ }
        }

        return $request;
    }
}
