<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Llm;

use Generator;
use Illuminate\Support\Facades\Log;
use Rnkr69\LaraChatbot\Llm\Exceptions\LlmException;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\PendingRequest as TextPendingRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Throwable;

/**
 * Wrapper around the Prism SDK. No other component of the package should
 * call `Prism::text()` directly: always going through here guarantees
 * that the provider/model are resolved from config (with per-conversation
 * override), that the system prompt is built uniformly, and that errors
 * are translated to `LlmException`.
 *
 * E08 (`ChatService`) will use `streamChat()` to produce the SSE stream;
 * `chat()` is the non-streaming fallback used by `chatbot:test-connection`
 * and by hosts that don't want SSE.
 */
class LlmGateway
{
    public function __construct(
        protected SystemPromptBuilder $systemPromptBuilder,
    ) {}

    /**
     * Streaming call. Returns Prism's `StreamEvent`s
     * (TextDelta, ToolCall, ToolResult, Error, etc.). E08 translates them to
     * package-specific SSE events.
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
        $opts    = $this->normalizeOptions($options);
        $request = $this->buildRequest($messages, $tools, $opts);

        // Observabilidad: una línea por llamada al LLM con modelo, duración,
        // recuento de eventos (texto/tool_call/tool_result) y si el modelo llegó
        // a emitir alguna tool call. Imprescindible para diagnosticar cuándo el
        // modelo "narra y no llama la herramienta" o cuándo el gateway falla.
        $model   = (string) ($opts->model ?? config('chatbot.model'));
        $started = microtime(true);
        $counts  = ['text' => 0, 'tool_call' => 0, 'tool_result' => 0, 'thinking' => 0, 'other' => 0];
        $lastEvt = '';
        $finish  = null;   // FinishReason del StreamEndEvent (Stop, Length, ContentFilter, ToolCalls…)
        $usage   = null;   // tokens (incl. thought_tokens y cache read/write)
        Log::info('[chatbot][llm] streamChat START', [
            'model' => $model, 'messages' => count($messages), 'tools' => count($tools),
        ]);

        try {
            foreach ($request->asStream() as $event) {
                $cls     = class_basename($event);
                $lastEvt = $cls;
                if (stripos($cls, 'ToolCall') !== false)       { $counts['tool_call']++; }
                elseif (stripos($cls, 'ToolResult') !== false) { $counts['tool_result']++; }
                elseif (stripos($cls, 'Thinking') !== false)   { $counts['thinking']++; }
                elseif (stripos($cls, 'Text') !== false)       { $counts['text']++; }
                else                                           { $counts['other']++; }

                // El StreamEndEvent trae POR QUÉ terminó y el consumo de tokens.
                // Clave para diagnosticar turnos vacíos: p.ej. finish=Length con
                // thought alto = el razonamiento se comió el presupuesto y no dio
                // texto; finish=ContentFilter = bloqueo; cache_read alto = el prompt
                // cacheó (coste bajo).
                if ($cls === 'StreamEndEvent') {
                    try {
                        $finish = $event->finishReason?->name;
                        $u = $event->usage ?? null;
                        if ($u !== null) {
                            $usage = [
                                'in'          => $u->promptTokens ?? null,
                                'out'         => $u->completionTokens ?? null,
                                'thought'     => $u->thoughtTokens ?? null,
                                'cache_read'  => $u->cacheReadInputTokens ?? null,
                                'cache_write' => $u->cacheWriteInputTokens ?? null,
                            ];
                        }
                    } catch (Throwable) { /* best-effort */ }
                }

                yield $event;
            }
            Log::info('[chatbot][llm] streamChat DONE', [
                'model'             => $model,
                'ms'                => (int) round((microtime(true) - $started) * 1000),
                'events'            => $counts,
                'last_event'        => $lastEvt,
                'emitted_tool_call' => $counts['tool_call'] > 0,
                'finish_reason'     => $finish,
                'usage'             => $usage,
            ]);
        } catch (Throwable $e) {
            Log::error('[chatbot][llm] streamChat ERROR', [
                'model'     => $model,
                'ms'        => (int) round((microtime(true) - $started) * 1000),
                'events'    => $counts,
                'error'     => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            throw LlmException::fromPrism($e);
        }
    }

    /**
     * Non-streaming call. Returns an already-consolidated `TextResponse`.
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
     * Shortcut for the `chatbot:test-connection` command: sends a single
     * "ping" message without tools and returns the received text (or throws).
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
     * Applies the system prompt to the request, optionally with prompt caching
     * (v1.1.1 finding #14.g; corregido en v0.5.6).
     *
     * If `chatbot.llm.cache_system_prompt=true` AND the provider is Anthropic
     * AND `$options->systemPrompt` is not overridden by the caller, the prompt
     * is split into `cacheable` (header + tools + decision strategy + locale)
     * and `dynamic` (page context + pending actions). The cacheable block is
     * emitted as a `SystemMessage` with `providerOptions(['cacheType' =>
     * 'ephemeral'])` — la vía correcta en Prism v0.100 (el `NormalizesCacheControl`
     * del driver de Anthropic lo traduce a `cache_control` sobre el bloque).
     * El bloque dinámico va como un segundo SystemMessage SIN cachear.
     *
     * Nota histórica: hasta v0.5.5 esto usaba `withProviderMeta`/`usingProviderMeta`
     * guardados con `method_exists`; esos métodos NO existen en Prism v0.100, así
     * que las dos ramas se saltaban en silencio y NUNCA se enviaba `cache_control`
     * (`cache_read`/`cache_write` = 0 en cada turno).
     *
     * Result: ~90% input cost savings on multi-turn / multi-step conversations
     * with a large system prompt + tools (Anthropic cache TTL = 5 min).
     */
    protected function applySystemPrompt(TextPendingRequest $request, PromptOptions $options, string $provider): TextPendingRequest
    {
        // Explicit override from the caller (chatbot:test-connection ping) → no split.
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

        // Prism v0.100: el prompt caching de Anthropic se activa poniendo
        // `cacheType` en el propio SystemMessage (lo lee NormalizesCacheControl),
        // NO con un meta a nivel de request. El bloque ESTABLE (header + tools +
        // estrategia + locale) viaja con cache_control ephemeral; el DINÁMICO
        // (page context + fecha/hora) va aparte y SIN cachear, para no invalidar
        // el prefijo en cada turno. Como en la request de Anthropic el orden es
        // tools → system → messages, marcar el bloque estable del system cachea
        // además las definiciones de tools (van antes en el prefijo cacheado).
        // Anthropic exige ≥1024 tokens en el bloque cacheado; el header+addendum
        // los supera holgadamente.
        $stable = (new SystemMessage(trim($split['cacheable'])))
            ->withProviderOptions(['cacheType' => 'ephemeral']);

        $dynamic = trim((string) ($split['dynamic'] ?? ''));

        return $dynamic === ''
            ? $request->withSystemPrompts([$stable])
            : $request->withSystemPrompts([$stable, new SystemMessage($dynamic)]);
    }
}
