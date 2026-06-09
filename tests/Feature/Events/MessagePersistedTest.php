<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Rnkr69\LaraChatbot\Events\MessagePersisted;
use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Services\ChatService;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;

beforeEach(function () {
    $this->artisan('migrate')->run();
});

it('fires MessagePersisted with tokens and provider/model after persisting the assistant message', function () {
    config()->set('chatbot.provider', 'anthropic');
    config()->set('chatbot.model', 'claude-sonnet-4-6');

    Event::fake([MessagePersisted::class]);

    Prism::fake([
        TextResponseFake::make()
            ->withText('respuesta')
            ->withFinishReason(FinishReason::Stop)
            ->withUsage(new Usage(promptTokens: 42, completionTokens: 18)),
    ]);

    $user = new TestUser(['id' => 7, 'name' => 'Telemetry Tester']);
    $user->setRawAttributes(['id' => 7, 'name' => 'Telemetry Tester'], sync: true);
    $conversation = Conversation::create([
        'user_type' => TestUser::class,
        'user_id'   => 7,
        'title'     => null,
        'metadata'  => null,
    ]);
    $conversation->setRelation('user', $user);

    foreach (app(ChatService::class)->handle($conversation, 'hola') as $_) {
        // drain generator
    }

    Event::assertDispatched(MessagePersisted::class, function (MessagePersisted $e) {
        return $e->tokensIn === 42
            && $e->tokensOut === 18
            && $e->provider === 'anthropic'
            && $e->model === 'claude-sonnet-4-6'
            && $e->user->getAuthIdentifier() === 7
            && $e->message->tokens_in === 42
            && $e->message->tokens_out === 18;
    });
});

it('uses the per-conversation provider/model override when present in metadata', function () {
    config()->set('chatbot.provider', 'anthropic');
    config()->set('chatbot.model', 'claude-sonnet-4-6');

    Event::fake([MessagePersisted::class]);

    Prism::fake([
        TextResponseFake::make()
            ->withText('ok')
            ->withFinishReason(FinishReason::Stop)
            ->withUsage(new Usage(promptTokens: 5, completionTokens: 3)),
    ]);

    $user = new TestUser(['id' => 8, 'name' => 'Override Tester']);
    $user->setRawAttributes(['id' => 8, 'name' => 'Override Tester'], sync: true);
    $conversation = Conversation::create([
        'user_type' => TestUser::class,
        'user_id'   => 8,
        'title'     => null,
        'metadata'  => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
    ]);
    $conversation->setRelation('user', $user);

    foreach (app(ChatService::class)->handle($conversation, 'hola') as $_) {
        // drain
    }

    Event::assertDispatched(MessagePersisted::class, function (MessagePersisted $e) {
        return $e->provider === 'openai' && $e->model === 'gpt-4o-mini';
    });
});
