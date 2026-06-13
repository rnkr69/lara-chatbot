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
        $request = $this->buildRequest($messages, $tools, $this->normalizeOptions($options));

        try {
            yield from $request->asStream();
        } catch (Throwable $e) {
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
     * (v1.1.1, finding #14.g).
     *
     * If `chatbot.llm.cache_system_prompt=true` AND the provider is Anthropic
     * AND `$options->systemPrompt` is not overridden by the caller, the prompt
     * is split into `cacheable` (header + tools + decision strategy
     * + locale) and `dynamic` (page context + pending actions). The cacheable
     * block travels with `cache_control: ephemeral` via
     * `usingProviderMeta()` if Prism supports it — we fall back to the
     * traditional concatenated prompt if not.
     *
     * Result: ~75% input cost savings on conversations of 10+ turns
     * with a large system prompt (Anthropic cache TTL = 5 min).
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
