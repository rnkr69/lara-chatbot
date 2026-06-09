<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Llm;

/**
 * Value object con las opciones de una llamada al LLM.
 *
 * Pensado para ser construido por el orquestador (E08 `ChatService`) a
 * partir de la `Conversation` activa: si `conversation.metadata.provider`
 * o `conversation.metadata.model` están definidos, sobrescriben la config
 * global; si no, `LlmGateway` cae a `chatbot.provider` / `chatbot.model`.
 *
 * `promptContext` es el array que se entrega al `SystemPromptBuilder`
 * (claves `user`, `pageContext`, `tools`, `locale`). `systemPrompt` es un
 * escape hatch: si está set, el builder no se invoca y se usa la cadena
 * dada tal cual (útil para `chatbot:test-connection` y para tests).
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
