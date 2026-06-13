<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Llm;

/**
 * Value object with the options for an LLM call.
 *
 * Intended to be built by the orchestrator (E08 `ChatService`) from
 * the active `Conversation`: if `conversation.metadata.provider`
 * or `conversation.metadata.model` are defined, they override the global
 * config; otherwise, `LlmGateway` falls back to `chatbot.provider` / `chatbot.model`.
 *
 * `promptContext` is the array handed to the `SystemPromptBuilder`
 * (keys `user`, `pageContext`, `tools`, `locale`). `systemPrompt` is an
 * escape hatch: if set, the builder is not invoked and the given string is
 * used as-is (useful for `chatbot:test-connection` and for tests).
 */
final class PromptOptions
{
    /**
     * @param  array<string, mixed>  $promptContext
     */
    public function __construct(
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly ?string $systemPrompt = null,
        public readonly array $promptContext = [],
        public readonly ?int $maxSteps = null,
        public readonly ?int $maxTokens = null,
        public readonly int|float|null $temperature = null,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        return new self(
            provider:      $values['provider']      ?? null,
            model:         $values['model']         ?? null,
            systemPrompt:  $values['system_prompt'] ?? $values['systemPrompt'] ?? null,
            promptContext: $values['prompt_context'] ?? $values['promptContext'] ?? [],
            maxSteps:      $values['max_steps']     ?? $values['maxSteps']     ?? null,
            maxTokens:     $values['max_tokens']    ?? $values['maxTokens']    ?? null,
            temperature:   $values['temperature']   ?? null,
        );
    }
}
