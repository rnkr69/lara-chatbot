<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Llm\Exceptions\LlmException;
use Rnkr69\LaraChatbot\Llm\LlmGateway;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;

it('reports success and exit code 0 when the LLM ping returns a reply', function () {
    Prism::fake([
        TextResponseFake::make()->withText('pong')->withFinishReason(FinishReason::Stop),
    ]);

    $this->artisan('chatbot:test-connection')
        ->expectsOutputToContain('Pinging anthropic / claude-sonnet-4-6')
        ->expectsOutputToContain('LLM connection OK.')
        ->expectsOutputToContain('pong')
        ->assertExitCode(0);
});

it('reports failure and exit code 1 when the gateway throws an LlmException', function () {
    // Sustituimos el binding del gateway por uno que lanza directamente.
    app()->instance(LlmGateway::class, new class extends LlmGateway {
        public function __construct() {}

        public function ping(?string $provider = null, ?string $model = null): string
        {
            throw new LlmException('Unauthorized: invalid API key', 'auth');
        }
    });

    // Usamos Artisan::call para inspeccionar la salida bruta. PendingCommand
    // (->artisan(...)->expectsOutputToContain) recibe la salida fragmentada
    // por el formatter de Symfony cuando hay tags `<fg=...>`, lo que hace
    // que substrings que cruzan fragmentos no matcheen.
    $exit = \Illuminate\Support\Facades\Artisan::call('chatbot:test-connection');
    $output = \Illuminate\Support\Facades\Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('LLM connection failed.')
        ->and($output)->toContain('reason:')
        ->and($output)->toContain('auth')
        ->and($output)->toContain('Unauthorized: invalid API key');
});

it('honors --provider and --model overrides in the displayed banner', function () {
    Prism::fake([
        TextResponseFake::make()->withText('pong')->withFinishReason(FinishReason::Stop),
    ]);

    $this->artisan('chatbot:test-connection', [
        '--provider' => 'openai',
        '--model'    => 'gpt-fake',
    ])
        ->expectsOutputToContain('Pinging openai / gpt-fake')
        ->assertExitCode(0);
});
