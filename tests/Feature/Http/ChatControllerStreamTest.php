<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Rnkr69\LaraChatbot\Http\Controllers\ChatController;
use Rnkr69\LaraChatbot\Http\Requests\SendMessageRequest;
use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Models\Message;
use Rnkr69\LaraChatbot\Models\MessageRole;
use Rnkr69\LaraChatbot\Services\ChatService;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;

beforeEach(function () {
    $this->artisan('migrate')->run();
    $this->withoutMiddleware(VerifyCsrfToken::class);
    Cache::flush();
});

/**
 * Creates a TestUser with `id` and synced raw attributes — without a real
 * table (the orchestrator tests use the same pattern in ChatServiceTest).
 */
function makeAuthedUser(int $id = 1): TestUser
{
    $user = new TestUser(['id' => $id, 'name' => "User-{$id}"]);
    $user->setRawAttributes(['id' => $id, 'name' => "User-{$id}"], sync: true);

    return $user;
}

/**
 * Parses the SSE body into a list of `['event' => string, 'data' => array]`.
 * Tolerant of `\r\n` and trailing blank lines.
 *
 * @return list<array{event: string, data: array<string, mixed>}>
 */
function parseSseBody(string $body): array
{
    $events = [];
    $blocks = preg_split("/\r?\n\r?\n/", $body) ?: [];

    foreach ($blocks as $block) {
        $block = trim($block, "\r\n");
        if ($block === '') {
            continue;
        }

        $event = null;
        $data  = null;

        foreach (explode("\n", $block) as $line) {
            $line = rtrim($line, "\r");
            if (str_starts_with($line, 'event: ')) {
                $event = substr($line, 7);
            } elseif (str_starts_with($line, 'data: ')) {
                $data = substr($line, 6);
            }
        }

        if ($event === null || $data === null) {
            continue;
        }

        $decoded   = json_decode($data, true);
        $events[]  = ['event' => $event, 'data' => is_array($decoded) ? $decoded : []];
    }

    return $events;
}

it('streams text + done as SSE frames in order for a happy-path POST', function () {
    Prism::fake([
        TextResponseFake::make()
            ->withText('hola mundo')
            ->withFinishReason(FinishReason::Stop)
            ->withUsage(new Usage(promptTokens: 5, completionTokens: 7)),
    ]);

    $user = makeAuthedUser();

    $response = $this->actingAs($user, 'web')
        ->post('/chatbot/stream', ['message' => 'hi']);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/event-stream');
    expect($response->headers->get('Cache-Control'))->toContain('no-cache');
    expect($response->headers->get('X-Accel-Buffering'))->toBe('no');

    $body   = $response->streamedContent();
    $events = parseSseBody($body);

    $kinds = array_map(fn (array $e) => $e['event'], $events);
    expect($kinds)->toContain('text')
        ->and(end($kinds))->toBe('done');

    // Concatenating the deltas should reconstitute the LLM text.
    $combined = '';
    foreach ($events as $ev) {
        if ($ev['event'] === 'text') {
            $combined .= (string) ($ev['data']['delta'] ?? '');
        }
    }
    expect($combined)->toBe('hola mundo');

    $done = end($events);
    expect($done['data']['usage']['prompt_tokens'])->toBe(5)
        ->and($done['data']['usage']['completion_tokens'])->toBe(7);

    expect(Conversation::query()->forUser($user)->count())->toBe(1);

    $msgs = Message::query()->orderBy('id')->get();
    expect($msgs)->toHaveCount(2)
        ->and($msgs[0]->role)->toBe(MessageRole::User)
        ->and($msgs[0]->content[0]['text'])->toBe('hi')
        ->and($msgs[1]->role)->toBe(MessageRole::Assistant)
        ->and($msgs[1]->content[0]['text'])->toBe('hola mundo');
});

it('emits events in the SSE wire format `event: <name>\\ndata: <json>\\n\\n`', function () {
    Prism::fake([
        TextResponseFake::make()
            ->withText('x')
            ->withFinishReason(FinishReason::Stop),
    ]);

    $user = makeAuthedUser();

    $response = $this->actingAs($user, 'web')
        ->post('/chatbot/stream', ['message' => 'hi']);

    $body = $response->streamedContent();

    expect($body)->toMatch('/event: text\ndata: \{[^\n]+\}\n\n/')
        ->and($body)->toMatch('/event: done\ndata: \{[^\n]+\}\n\n/');
});

it('reuses an existing conversation when conversation_id is provided', function () {
    Prism::fake([
        TextResponseFake::make()
            ->withText('seguimos')
            ->withFinishReason(FinishReason::Stop),
    ]);

    $user     = makeAuthedUser();
    $existing = Conversation::create([
        'user_type' => TestUser::class,
        'user_id'   => $user->getKey(),
        'title'     => 'Chat previo',
        'metadata'  => null,
    ]);

    $response = $this->actingAs($user, 'web')
        ->post('/chatbot/stream', [
            'message'         => 'continue',
            'conversation_id' => $existing->id,
        ]);

    $response->assertOk();
    $response->streamedContent();

    expect(Conversation::query()->count())->toBe(1)
        ->and(Conversation::query()->find($existing->id)->messages()->count())->toBe(2);
});

it('returns 422 when message is missing or empty', function () {
    Prism::fake([]);

    $user = makeAuthedUser();

    $r1 = $this->actingAs($user, 'web')->postJson('/chatbot/stream', ['message' => '']);
    $r1->assertStatus(422);
    $r1->assertJsonValidationErrors(['message']);

    $r2 = $this->actingAs($user, 'web')->postJson('/chatbot/stream', []);
    $r2->assertStatus(422);
    $r2->assertJsonValidationErrors(['message']);
});

it('returns 422 when message exceeds the 4000 character limit', function () {
    Prism::fake([]);

    $user = makeAuthedUser();

    $response = $this->actingAs($user, 'web')->postJson('/chatbot/stream', [
        'message' => str_repeat('a', 4001),
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['message']);
});

it('returns 422 when conversation_id belongs to another user', function () {
    Prism::fake([]);

    $other = makeAuthedUser(99);
    $foreign = Conversation::create([
        'user_type' => TestUser::class,
        'user_id'   => $other->getKey(),
    ]);

    $self = makeAuthedUser(1);

    $response = $this->actingAs($self, 'web')->postJson('/chatbot/stream', [
        'message'         => 'hi',
        'conversation_id' => $foreign->id,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['conversation_id']);

    // No user message is persisted for an invalid payload.
    expect(Message::query()->count())->toBe(0);
});

it('returns 422 when conversation_id is soft-deleted (deleted_at not null)', function () {
    Prism::fake([]);

    $user = makeAuthedUser();
    $conv = Conversation::create([
        'user_type' => TestUser::class,
        'user_id'   => $user->getKey(),
    ]);
    $conv->delete(); // soft delete

    $response = $this->actingAs($user, 'web')->postJson('/chatbot/stream', [
        'message'         => 'hi',
        'conversation_id' => $conv->id,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['conversation_id']);
});

it('rejects unauthenticated requests via the auth middleware', function () {
    $response = $this->postJson('/chatbot/stream', ['message' => 'hi']);

    expect($response->status())->toBeIn([401, 302, 419, 403]);
});

it('returns 429 with Retry-After when the user exceeds the per-minute rate limit', function () {
    config()->set('chatbot.limits.rate_limit.enabled', true);
    config()->set('chatbot.limits.rate_limit.requests_per_minute', 2);

    $user = makeAuthedUser();
    $key  = "chatbot:stream:{$user->getKey()}";

    // Pre-accumulate 2 attempts → the next request should 429.
    RateLimiter::hit($key, 60);
    RateLimiter::hit($key, 60);

    Prism::fake([
        TextResponseFake::make()
            ->withText('should not be used')
            ->withFinishReason(FinishReason::Stop),
    ]);

    $response = $this->actingAs($user, 'web')->post('/chatbot/stream', [
        'message' => 'over the limit',
    ]);

    $response->assertStatus(429);
    expect($response->headers->get('Retry-After'))->not->toBeNull();
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(0);
    expect($response->headers->get('X-RateLimit-Limit'))->toBe('2');
});

it('does not enforce rate limit when chatbot.limits.rate_limit.enabled=false', function () {
    config()->set('chatbot.limits.rate_limit.enabled', false);

    Prism::fake([
        TextResponseFake::make()
            ->withText('ok')
            ->withFinishReason(FinishReason::Stop),
    ]);

    $user = makeAuthedUser();
    $key  = "chatbot:stream:{$user->getKey()}";

    // Even with many previous hits, disabled = not checked.
    for ($i = 0; $i < 99; $i++) {
        RateLimiter::hit($key, 60);
    }

    $response = $this->actingAs($user, 'web')->post('/chatbot/stream', ['message' => 'hi']);
    $response->assertOk();
    $response->streamedContent();
});

it('breaks the SSE loop early when the client connection is aborted, leaving the assistant message unpersisted', function () {
    // Long text → several TextDelta chunks before done.
    Prism::fake([
        TextResponseFake::make()
            ->withText(str_repeat('abc def ghi ', 30))
            ->withFinishReason(FinishReason::Stop)
            ->withUsage(new Usage(promptTokens: 10, completionTokens: 50)),
    ]);

    $maxBeforeAbort = 2;
    $checks         = 0;
    $this->app->instance('chatbot.connection_aborted', function () use (&$checks, $maxBeforeAbort): bool {
        return ++$checks > $maxBeforeAbort;
    });

    $user = makeAuthedUser();

    // Bypass routing to get deterministic control of the callback. The
    // happy-path routing is already covered by the other tests.
    $service    = app(ChatService::class);
    $controller = app(ChatController::class);

    $request = SendMessageRequest::create('/chatbot/stream', 'POST', ['message' => 'long stream']);
    $request->setContainer($this->app);
    $request->setUserResolver(fn () => $user);
    $request->validateResolved();

    $response = $controller->stream($request, $service);

    // Capture the stream using a buffer callback (same pattern as
    // `Illuminate\Testing\TestResponse::streamedContent()`): the controller's
    // internal `ob_flush()` fires this callback, which accumulates into
    // `$collected` and returns '' so nothing propagates to the outer SAPI.
    // This prevents the frames from being lost when flushed during the test.
    $collected = '';
    ob_start(function (string $chunk) use (&$collected): string {
        $collected .= $chunk;
        return '';
    });
    $response->sendContent();
    ob_end_clean();
    $body = $collected;

    $events = parseSseBody($body);

    // The loop emits the frame BEFORE checking abort; with threshold K, K+1
    // frames come out and then the break. We verify it is far below the total
    // the full stream would produce (long text → many chunks).
    expect(count($events))->toBeLessThanOrEqual($maxBeforeAbort + 1)
        ->and(count($events))->toBeGreaterThan(0);

    // No `done` (the Generator was suspended midway — the postloop did NOT run).
    $kinds = array_map(fn (array $e) => $e['event'], $events);
    expect($kinds)->not->toContain('done');

    // User msg persisted (handle creates it BEFORE the foreach), assistant NOT.
    expect(Message::query()->where('role', MessageRole::User->value)->count())->toBe(1)
        ->and(Message::query()->where('role', MessageRole::Assistant->value)->count())->toBe(0);
});

it('drops oversized page_context but still streams the turn (D11 fallback after fine sanitization)', function () {
    config()->set('chatbot.limits.page_context_kb', 1);

    Prism::fake([
        TextResponseFake::make()
            ->withText('ok')
            ->withFinishReason(FinishReason::Stop),
    ]);

    $user        = makeAuthedUser();
    $hugeContext = ['blob' => str_repeat('x', 2048)]; // > 1 KB

    $response = $this->actingAs($user, 'web')->post('/chatbot/stream', [
        'message'      => 'hi',
        'page_context' => $hugeContext,
    ]);

    $response->assertOk();
    $body   = $response->streamedContent();
    $events = parseSseBody($body);
    $kinds  = array_map(fn (array $e) => $e['event'], $events);

    expect($kinds)->toContain('done');
});

it('routes page_context through the sanitizer (E14 D13: null fields dropped)', function () {
    Prism::fake([
        TextResponseFake::make()
            ->withText('ok')
            ->withFinishReason(FinishReason::Stop),
    ]);

    // Spy ChatService that captures the page_context forwarded by the
    // controller and yields a single `done` SSE event so the response
    // completes without going through the LLM gateway.
    $spy = new \Rnkr69\LaraChatbot\Tests\Stubs\PageContextSpyChatService;
    $this->app->instance(ChatService::class, $spy);

    $user = makeAuthedUser();

    // Mix of: kept primitives, null (dropped), nested array with another null,
    // string that looks like HTML (kept opaquely).
    $response = $this->actingAs($user, 'web')->post('/chatbot/stream', [
        'message'      => 'hi',
        'page_context' => [
            'route'  => 'invoices.index',
            'note'   => '<b>html-stays</b>',
            'nope'   => null,
            'nested' => [
                'page'  => 3,
                'extra' => null,
            ],
        ],
    ]);

    $response->assertOk();
    $response->streamedContent(); // forces the StreamedResponse callback to run

    expect($spy->captured)->toBe([
        'route'  => 'invoices.index',
        'note'   => '<b>html-stays</b>',
        'nested' => ['page' => 3],
    ]);
});
