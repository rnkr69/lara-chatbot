<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Llm\LlmGateway;
use Rnkr69\LaraChatbot\Llm\PromptOptions;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;

it('returns a TextResponse from chat() using configured provider/model', function () {
    Prism::fake([
        TextResponseFake::make()
            ->withText('hello there')
            ->withFinishReason(FinishReason::Stop),
    ]);

    $response = app(LlmGateway::class)->chat(
        messages: [new UserMessage('Hi')],
        options: new PromptOptions(systemPrompt: 'You are testable.'),
    );

    expect($response->text)->toBe('hello there');
});

it('passes a tool call back to the caller in chat()', function () {
    Prism::fake([
        TextResponseFake::make()
            ->withText('')
            ->withToolCalls([
                new ToolCall(
                    id: 'call_1',
                    name: 'get_orders',
                    arguments: ['user_id' => 1],
                ),
            ])
            ->withFinishReason(FinishReason::ToolCalls),
    ]);

    $response = app(LlmGateway::class)->chat(
        messages: [new UserMessage('Show my orders')],
        options: new PromptOptions(systemPrompt: 'You are testable.'),
    );

    expect($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls[0]->name)->toBe('get_orders')
        ->and($response->toolCalls[0]->arguments())->toMatchArray(['user_id' => 1]);
});

it('streams events through streamChat() generator', function () {
    Prism::fake([
        TextResponseFake::make()
            ->withText('streamed answer')
            ->withFinishReason(FinishReason::Stop),
    ]);

    $events = [];
    foreach (app(LlmGateway::class)->streamChat(
        messages: [new UserMessage('hi')],
        options: new PromptOptions(systemPrompt: 'You are testable.'),
    ) as $event) {
        $events[] = $event;
    }

    expect($events)->not->toBeEmpty();
});

it('overrides provider/model from PromptOptions over config defaults', function () {
    config()->set('chatbot.provider', 'anthropic');
    config()->set('chatbot.model', 'claude-default');

    $fake = Prism::fake([
        TextResponseFake::make()->withText('ok')->withFinishReason(FinishReason::Stop),
    ]);

    app(LlmGateway::class)->chat(
        messages: [new UserMessage('hi')],
        options: new PromptOptions(
            provider: 'openai',
            model: 'gpt-test',
            systemPrompt: 'You are testable.',
        ),
    );

    $fake->assertRequest(function (array $recorded): void {
        expect($recorded)->toHaveCount(1)
            ->and($recorded[0]->model())->toBe('gpt-test');
    });
});

it('falls back to config defaults when PromptOptions does not set provider/model', function () {
    config()->set('chatbot.provider', 'anthropic');
    config()->set('chatbot.model', 'claude-fallback');

    $fake = Prism::fake([
        TextResponseFake::make()->withText('ok')->withFinishReason(FinishReason::Stop),
    ]);

    app(LlmGateway::class)->chat(
        messages: [new UserMessage('hi')],
        options: new PromptOptions(systemPrompt: 'You are testable.'),
    );

    $fake->assertRequest(function (array $recorded): void {
        expect($recorded[0]->model())->toBe('claude-fallback')
            ->and($recorded[0]->provider())->toBe('anthropic');
    });
});

it('uses the SystemPromptBuilder when no explicit system prompt is given', function () {
    $fake = Prism::fake([
        TextResponseFake::make()->withText('ok')->withFinishReason(FinishReason::Stop),
    ]);

    app(LlmGateway::class)->chat(
        messages: [new UserMessage('hi')],
        options: new PromptOptions(
            promptContext: ['locale' => 'es', 'pageContext' => ['route' => 'orders.index']],
        ),
    );

    $fake->assertRequest(function (array $recorded): void {
        $systemPrompts = $recorded[0]->systemPrompts();

        $combined = collect($systemPrompts)
            ->map(fn ($message) => $message->content)
            ->implode("\n");

        expect($combined)
            ->toContain('Always respond in Spanish')
            ->and($combined)
            ->toContain('orders.index');
    });
});

it('forwards tools via withTools() to the underlying request', function () {
    $fake = Prism::fake([
        TextResponseFake::make()->withText('ok')->withFinishReason(FinishReason::Stop),
    ]);

    $tool = (new \Prism\Prism\Tool)
        ->as('echo_tool')
        ->for('echoes input')
        ->using(fn (string $msg) => $msg);

    app(LlmGateway::class)->chat(
        messages: [new UserMessage('hi')],
        tools: [$tool],
        options: new PromptOptions(systemPrompt: 'You are testable.'),
    );

    $fake->assertRequest(function (array $recorded): void {
        expect($recorded[0]->tools())->toHaveCount(1)
            ->and($recorded[0]->tools()[0]->name())->toBe('echo_tool');
    });
});
