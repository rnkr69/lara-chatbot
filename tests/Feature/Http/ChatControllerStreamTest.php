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
 * Crea un TestUser con `id` y atributos crudos sincronizados — sin tabla
 * real (los tests del orquestador usan el mismo patrón en ChatServiceTest).
 */
function makeAuthedUser(int $id = 1): TestUser
{
    $user = new TestUser(['id' => $id, 'name' => "User-{$id}"]);
    $user->setRawAttributes(['id' => $id, 'name' => "User-{$id}"], sync: true);

    return $user;
}

/**
 * Parsea el body SSE en una lista de `['event' => string, 'data' => array]`.
 * Tolerante a `\r\n` y a líneas en blanco al final.
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

    // Concatenación de los deltas debe reconstituir el texto del LLM.
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
            'message'         => 'continúa',
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

    // No se persiste user message para un payload inválido.
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

    // Pre-acumula 2 attempts → la próxima petición debe 429.
    RateLimiter::hit($key, 60);
    RateLimiter::hit($key, 60);

    Prism::fake([
        TextResponseFake::make()
            ->withText('no debería usarse')
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

    // Aunque haya muchos hits previos, deshabilitado = no se chequea.
    for ($i = 0; $i < 99; $i++) {
        RateLimiter::hit($key, 60);
    }

    $response = $this->actingAs($user, 'web')->post('/chatbot/stream', ['message' => 'hi']);
    $response->assertOk();
    $response->streamedContent();
});

it('breaks the SSE loop early when the client connection is aborted, leaving the assistant message unpersisted', function () {
    // Texto largo → varios chunks de TextDelta antes de done.
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

    // Bypass del routing para tener control determinista del callback. El
    // routing happy-path ya está cubierto por los otros tests.
    $service    = app(ChatService::class);
    $controller = app(ChatController::class);

    $request = SendMessageRequest::create('/chatbot/stream', 'POST', ['message' => 'long stream']);
    $request->setContainer($this->app);
    $request->setUserResolver(fn () => $user);
    $request->validateResolved();

    $response = $controller->stream($request, $service);

    // Captura del stream usando un buffer-callback (igual patrón que
    // `Illuminate\Testing\TestResponse::streamedContent()`): el `ob_flush()`
    // interno del controller dispara este callback, que acumula en
    // `$collected` y retorna '' para no propagar al outer SAPI. Esto evita
    // que los frames se pierdan al ser flushed durante el test.
    $collected = '';
    ob_start(function (string $chunk) use (&$collected): string {
        $collected .= $chunk;
        return '';
    });
    $response->sendContent();
    ob_end_clean();
    $body = $collected;

    $events = parseSseBody($body);

    // El loop emite el frame ANTES de checar abort; con threshold K, salen
    // K+1 frames y luego el break. Verificamos que sea muy inferior al
    // total que produciría el stream completo (texto largo → muchos chunks).
    expect(count($events))->toBeLessThanOrEqual($maxBeforeAbort + 1)
        ->and(count($events))->toBeGreaterThan(0);

    // No `done` (Generator se suspendió a mitad — el postloop NO corrió).
    $kinds = array_map(fn (array $e) => $e['event'], $events);
    expect($kinds)->not->toContain('done');

    // User msg persistido (handle lo crea ANTES del foreach), assistant NO.
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
