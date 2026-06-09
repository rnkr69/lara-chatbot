<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Rnkr69\LaraChatbot\Events\ToolInvoked;
use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Models\Message;
use Rnkr69\LaraChatbot\Models\MessageRole;
use Rnkr69\LaraChatbot\Services\ChatService;
use Rnkr69\LaraChatbot\Sse\SseEvent;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\EchoBackendTool;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\NavigateLikeFrontendTool;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult as PrismToolResult;
use Prism\Prism\ValueObjects\Usage;

beforeEach(function () {
    $this->artisan('migrate')->run();
});

/**
 * Helper: crea una conversación persistida + setea el TestUser como
 * relación cargada para evitar la query morphTo (sin tabla users).
 */
function makeConversation(int $userId = 1): Conversation
{
    $user = new TestUser(['id' => $userId, 'name' => 'Tester']);
    $user->setRawAttributes(['id' => $userId, 'name' => 'Tester'], sync: true);

    $conversation = Conversation::create([
        'user_type' => TestUser::class,
        'user_id'   => $userId,
        'title'     => null,
        'metadata'  => null,
    ]);

    $conversation->setRelation('user', $user);

    return $conversation;
}

/**
 * @return list<SseEvent>
 */
function collectChatEvents(Conversation $c, string $message, array $pageContext = []): array
{
    $service = app(ChatService::class);

    $events = [];
    foreach ($service->handle($c, $message, $pageContext) as $event) {
        $events[] = $event;
    }

    return $events;
}

it('emits text deltas and a final done event for a plain text response', function () {
    Prism::fake([
        TextResponseFake::make()
            ->withText('hola mundo')
            ->withFinishReason(FinishReason::Stop)
            ->withUsage(new Usage(promptTokens: 7, completionTokens: 11)),
    ]);

    $c = makeConversation();

    $events = collectChatEvents($c, 'hi');

    $kinds = array_map(fn (SseEvent $e) => $e->event, $events);

    // Texto chunked + done. Sin tool_call/tool_result/frontend_action.
    expect($kinds)->toContain('text')
        ->and($kinds)->toContain('done')
        ->and(in_array('tool_call', $kinds, true))->toBeFalse()
        ->and(in_array('frontend_action', $kinds, true))->toBeFalse();

    $textChunks = array_filter($events, fn (SseEvent $e) => $e->event === 'text');
    $combined   = implode('', array_map(fn (SseEvent $e) => $e->data['delta'], $textChunks));
    expect($combined)->toBe('hola mundo');

    $done = end($events);
    expect($done->event)->toBe('done')
        ->and($done->data['usage'])->toMatchArray([
            'prompt_tokens'     => 7,
            'completion_tokens' => 11,
        ]);
});

it('persists user and assistant messages with text content', function () {
    Prism::fake([
        TextResponseFake::make()
            ->withText('respuesta')
            ->withFinishReason(FinishReason::Stop)
            ->withUsage(new Usage(promptTokens: 4, completionTokens: 6)),
    ]);

    $c = makeConversation();

    $events = collectChatEvents($c, 'pregunta');

    expect(Message::query()->where('conversation_id', $c->id)->count())->toBe(2);

    $user = Message::query()->where('conversation_id', $c->id)->where('role', MessageRole::User->value)->first();
    expect($user->content)->toBeArray()
        ->and($user->content[0]['text'])->toBe('pregunta');

    $assistant = Message::query()->where('conversation_id', $c->id)->where('role', MessageRole::Assistant->value)->first();
    expect($assistant->content[0]['text'])->toBe('respuesta')
        ->and($assistant->tokens_in)->toBe(4)
        ->and($assistant->tokens_out)->toBe(6)
        ->and($assistant->tool_calls)->toBeNull();

    $done = end($events);
    expect($done->data['message_id'])->toBe($assistant->id);
});

it('translates a backend tool call into tool_call + tool_result and dispatches ToolInvoked', function () {
    Event::fake([ToolInvoked::class]);

    $tool = new EchoBackendTool;
    app(ToolRegistry::class)->clear()->register($tool);

    Prism::fake([
        TextResponseFake::make()
            ->withSteps(collect([
                TextStepFake::make()
                    ->withText('')
                    ->withToolCalls([
                        new ToolCall(
                            id: 'call_1',
                            name: 'echo_tool',
                            arguments: ['message' => 'pong'],
                        ),
                    ])
                    ->withToolResults([
                        new PrismToolResult(
                            toolCallId: 'call_1',
                            toolName: 'echo_tool',
                            args: ['message' => 'pong'],
                            result: '{"status":"ok"}',
                        ),
                    ])
                    ->withFinishReason(FinishReason::ToolCalls)
                    ->withMeta(new Meta('id-1', 'fake')),
                TextStepFake::make()
                    ->withText('listo')
                    ->withFinishReason(FinishReason::Stop)
                    ->withMeta(new Meta('id-2', 'fake')),
            ]))
            ->withFinishReason(FinishReason::Stop)
            ->withUsage(new Usage(promptTokens: 1, completionTokens: 2)),
    ]);

    $c = makeConversation();

    $events = collectChatEvents($c, 'echo pls');

    $kinds = array_map(fn (SseEvent $e) => $e->event, $events);

    expect($kinds)->toContain('tool_call')
        ->and($kinds)->toContain('tool_result')
        ->and($kinds)->toContain('text')
        ->and($kinds)->toContain('done');

    Event::assertDispatched(ToolInvoked::class, function (ToolInvoked $e) {
        return $e->tool->name() === 'echo_tool'
            && $e->args === ['message' => 'pong']
            && $e->result->isOk();
    });

    $assistant = Message::query()
        ->where('conversation_id', $c->id)
        ->where('role', MessageRole::Assistant->value)
        ->first();

    expect($assistant->tool_calls)->toBeArray()
        ->and($assistant->tool_calls[0]['name'])->toBe('echo_tool')
        ->and($assistant->tool_results)->toBeArray()
        ->and($assistant->tool_results[0]['name'])->toBe('echo_tool');
});

it('emits frontend_action BEFORE the next text chunk for a FrontendTool call', function () {
    Event::fake([ToolInvoked::class]);

    $tool = new NavigateLikeFrontendTool;
    app(ToolRegistry::class)->clear()->register($tool);

    Prism::fake([
        TextResponseFake::make()
            ->withSteps(collect([
                TextStepFake::make()
                    ->withText('')
                    ->withToolCalls([
                        new ToolCall(
                            id: 'call_fe_1',
                            name: 'navigate_like',
                            arguments: ['url' => '/orders'],
                        ),
                    ])
                    ->withToolResults([
                        new PrismToolResult(
                            toolCallId: 'call_fe_1',
                            toolName: 'navigate_like',
                            args: ['url' => '/orders'],
                            result: '{"status":"queued"}',
                        ),
                    ])
                    ->withFinishReason(FinishReason::ToolCalls),
                TextStepFake::make()
                    ->withText('hecho')
                    ->withFinishReason(FinishReason::Stop),
            ]))
            ->withFinishReason(FinishReason::Stop),
    ]);

    $c = makeConversation();

    $events = collectChatEvents($c, 'open orders');

    $sequence = array_values(array_map(fn (SseEvent $e) => $e->event, $events));

    $feIdx   = array_search('frontend_action', $sequence, true);
    $textIdx = null;
    foreach ($sequence as $i => $kind) {
        if ($kind === 'text') {
            $textIdx = $i;
            break;
        }
    }

    expect($feIdx)->not->toBeFalse('frontend_action no se emitió')
        ->and($textIdx)->not->toBeNull('no hubo evento text tras la tool')
        ->and($feIdx)->toBeLessThan($textIdx);

    // Y NO se debe emitir tool_call para una FrontendTool.
    expect(in_array('tool_call', $sequence, true))->toBeFalse();

    $feEvent = $events[$feIdx];
    expect($feEvent->data['tool'])->toBe('navigate_like')
        ->and($feEvent->data['args'])->toMatchArray(['url' => '/orders'])
        ->and($feEvent->data['confirmation'])->toBe('auto')
        ->and($feEvent->data['action_id'])->toBeString()
        ->and($feEvent->data['action_id'])->not->toBe('');

    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $e) => $e->tool->name() === 'navigate_like');
});

it('dispatches ToolInvoked with an error result when the cascade rejects the tool', function () {
    Event::fake([ToolInvoked::class]);

    $tool = new EchoBackendTool;
    $tool->shouldFail = true;
    app(ToolRegistry::class)->clear()->register($tool);

    Prism::fake([
        TextResponseFake::make()
            ->withSteps(collect([
                TextStepFake::make()
                    ->withText('')
                    ->withToolCalls([
                        new ToolCall(
                            id: 'call_err',
                            name: 'echo_tool',
                            arguments: ['message' => 'x'],
                        ),
                    ])
                    ->withToolResults([
                        new PrismToolResult(
                            toolCallId: 'call_err',
                            toolName: 'echo_tool',
                            args: ['message' => 'x'],
                            result: '{"status":"error"}',
                        ),
                    ])
                    ->withFinishReason(FinishReason::ToolCalls),
                TextStepFake::make()
                    ->withText('disculpas')
                    ->withFinishReason(FinishReason::Stop),
            ]))
            ->withFinishReason(FinishReason::Stop),
    ]);

    $c = makeConversation();

    collectChatEvents($c, 'fail it');

    Event::assertDispatched(ToolInvoked::class, function (ToolInvoked $e) {
        return $e->tool->name() === 'echo_tool'
            && $e->result->isError()
            && $e->result->errorCategory === 'runtime';
    });
});

/**
 * v1.1 (findings #2): a `Throwable` from a backend tool's handle() must
 * never leak its raw message to the LLM in production. The catch-all in
 * ChatService::executeTool now logs with a correlation_id and substitutes
 * the visible message based on app.debug.
 */
function buildThrowingToolStep(string $exceptionMessage = 'SQLSTATE[42S22]: Column not found: registry'): TextResponseFake
{
    return TextResponseFake::make()
        ->withSteps(collect([
            TextStepFake::make()
                ->withText('')
                ->withToolCalls([
                    new ToolCall(
                        id: 'call_throw',
                        name: 'echo_tool',
                        arguments: ['message' => 'boom'],
                    ),
                ])
                ->withToolResults([
                    new PrismToolResult(
                        toolCallId: 'call_throw',
                        toolName: 'echo_tool',
                        args: ['message' => 'boom'],
                        result: '{"status":"error"}',
                    ),
                ])
                ->withFinishReason(FinishReason::ToolCalls),
            TextStepFake::make()
                ->withText('apologies')
                ->withFinishReason(FinishReason::Stop),
        ]))
        ->withFinishReason(FinishReason::Stop);
}

it('masks tool exception messages in production (app.debug=false) and logs with correlation_id (v1.1 findings #2)', function () {
    config(['app.debug' => false]);
    Log::spy();
    Event::fake([ToolInvoked::class]);

    $tool = new EchoBackendTool;
    $tool->shouldThrow = new \RuntimeException('SQLSTATE[42S22]: Column not found: registry');
    app(ToolRegistry::class)->clear()->register($tool);

    Prism::fake([buildThrowingToolStep()]);

    collectChatEvents(makeConversation(), 'show me mission 25');

    // The error reaches the LLM as a generic message — no SQL state, no column name.
    Event::assertDispatched(ToolInvoked::class, function (ToolInvoked $e) {
        return $e->tool->name() === 'echo_tool'
            && $e->result->isError()
            && $e->result->errorCategory === 'runtime'
            && str_starts_with((string) $e->result->errorMessage, 'Internal tool error (ref: ')
            && ! str_contains((string) $e->result->errorMessage, 'SQLSTATE');
    });

    // The full exception is logged with a correlation_id so the operator can find it.
    Log::shouldHaveReceived('error')->withArgs(function (string $msg, array $ctx) {
        return $msg === '[chatbot] tool execution threw'
            && isset($ctx['correlation_id'])
            && $ctx['exception'] === \RuntimeException::class
            && str_contains((string) $ctx['message'], 'SQLSTATE');
    })->atLeast()->once();
});

it('exposes raw tool exception messages in dev (app.debug=true) for faster diagnosis (v1.1 findings #2)', function () {
    config(['app.debug' => true]);
    Log::spy();
    Event::fake([ToolInvoked::class]);

    $tool = new EchoBackendTool;
    $tool->shouldThrow = new \RuntimeException('SQLSTATE[42S22]: Column not found: registry');
    app(ToolRegistry::class)->clear()->register($tool);

    Prism::fake([buildThrowingToolStep()]);

    collectChatEvents(makeConversation(), 'show me mission 25');

    Event::assertDispatched(ToolInvoked::class, function (ToolInvoked $e) {
        return $e->tool->name() === 'echo_tool'
            && $e->result->isError()
            && (string) $e->result->errorMessage === 'SQLSTATE[42S22]: Column not found: registry';
    });
});

it('filters out backend tools whose confirmation is not Auto and warns', function () {
    Log::spy();

    $tool = new EchoBackendTool;
    $tool->confirmationOverride = ConfirmationLevel::Confirm;
    app(ToolRegistry::class)->clear()->register($tool);

    $fake = Prism::fake([
        TextResponseFake::make()->withText('ok')->withFinishReason(FinishReason::Stop),
    ]);

    $c = makeConversation();

    collectChatEvents($c, 'should not pass tool to LLM');

    // El log sale; verificamos que se llamó al menos una vez con la marca
    // `[chatbot]` y el nombre de la tool.
    Log::shouldHaveReceived('warning')->withArgs(
        fn ($message) => is_string($message)
            && str_contains($message, '[chatbot]')
            && str_contains($message, 'echo_tool')
    );

    // La tool NO debió enviarse a Prism: assertRequest comprueba el último
    // request grabado.
    $fake->assertRequest(function (array $recorded) {
        $tools = $recorded[0]->tools();
        $names = array_map(fn ($t) => $t->name(), $tools);
        expect($names)->not->toContain('echo_tool');
    });
});

it('honors chatbot.limits.history_messages by trimming older messages', function () {
    config()->set('chatbot.limits.history_messages', 3);

    $fake = Prism::fake([
        TextResponseFake::make()->withText('ok')->withFinishReason(FinishReason::Stop),
    ]);

    $c = makeConversation();

    // 4 turnos previos (8 mensajes), todos persistidos antes del nuevo turno.
    for ($i = 1; $i <= 4; $i++) {
        $c->messages()->create([
            'role'    => MessageRole::User,
            'content' => [['type' => 'text', 'text' => "user-{$i}"]],
        ]);
        $c->messages()->create([
            'role'    => MessageRole::Assistant,
            'content' => [['type' => 'text', 'text' => "assistant-{$i}"]],
        ]);
    }

    collectChatEvents($c, 'pregunta nueva');

    $fake->assertRequest(function (array $recorded) {
        $messages = $recorded[0]->messages();

        // history_messages=3 ⇒ se envían los 3 últimos mensajes EXISTENTES
        // en BD en el momento del streamChat (incluye el user message recién
        // persistido). Sin la nueva, había 8 (assistant-4 último); con la
        // nueva (user "pregunta nueva") son 9, y los 3 últimos son:
        // assistant-4, user-pregunta-nueva. El historial es length 3.
        expect($messages)->toHaveCount(3);

        $contents = array_map(fn ($m) => $m->content, $messages);

        // El último es el mensaje recién persistido (user "pregunta nueva").
        expect(end($contents))->toBe('pregunta nueva');
    });
});

it('changes the system prompt when page_context changes between two consecutive turns (E14 DoD)', function () {
    $fake = Prism::fake([
        TextResponseFake::make()->withText('a')->withFinishReason(FinishReason::Stop),
        TextResponseFake::make()->withText('b')->withFinishReason(FinishReason::Stop),
    ]);

    $c = makeConversation();

    // Primer turno con page_context A.
    collectChatEvents($c, 'first', ['route' => 'orders.index', 'order_id' => 42]);

    // Segundo turno con page_context B (diferente ruta + nuevo campo).
    collectChatEvents($c, 'second', ['route' => 'invoices.show', 'id' => 999]);

    $fake->assertRequest(function (array $recorded) {
        // Dos requests grabados (uno por turno), en orden.
        expect(count($recorded))->toBeGreaterThanOrEqual(2);

        $systemPrompts = array_map(
            fn ($req) => implode("\n", array_map(fn ($sp) => $sp->content, $req->systemPrompts())),
            $recorded,
        );

        // El primer system prompt incluye la sección canónica + ruta A.
        expect($systemPrompts[0])
            ->toContain('## Current page')
            ->and($systemPrompts[0])->toContain('orders.index')
            ->and($systemPrompts[0])->toContain('42')
            ->and($systemPrompts[0])->not->toContain('invoices.show');

        // El segundo system prompt cambió: ruta B + nuevo id.
        expect($systemPrompts[1])
            ->toContain('## Current page')
            ->and($systemPrompts[1])->toContain('invoices.show')
            ->and($systemPrompts[1])->toContain('999')
            ->and($systemPrompts[1])->not->toContain('orders.index');

        // Y los dos system prompts son distintos entre sí — esto es el DoD
        // textual del ROADMAP §5/E14.
        expect($systemPrompts[0])->not->toEqual($systemPrompts[1]);
    });
});

it('persists assistant tokens_in and tokens_out from the StreamEnd usage', function () {
    Prism::fake([
        TextResponseFake::make()
            ->withText('hola')
            ->withFinishReason(FinishReason::Stop)
            ->withUsage(new Usage(promptTokens: 100, completionTokens: 50)),
    ]);

    $c = makeConversation();

    collectChatEvents($c, 'hi');

    $assistant = Message::query()
        ->where('conversation_id', $c->id)
        ->where('role', MessageRole::Assistant->value)
        ->first();

    expect($assistant->tokens_in)->toBe(100)
        ->and($assistant->tokens_out)->toBe(50);
});

/*
|--------------------------------------------------------------------------
| E15 — Renderizado de bloques tipados.
|--------------------------------------------------------------------------
|
| DoD ROADMAP §5/E15: el LLM responde "aquí los pedidos" + tabla con 3 filas.
| Decisión 2026-05-09: el contrato SSE se mantiene (RenderBlockTool emite
| `frontend_action` con tool=render_block); el widget intercepta esa señal y
| la convierte en un block para el assistant message. Este test fija ese
| contrato en backend para que cualquier futura refactorización lo respete.
*/

it('emits a render_block frontend_action with type+data when RenderBlockTool is invoked (E15 DoD)', function () {
    $tool = new \Rnkr69\LaraChatbot\Tools\Frontend\RenderBlockTool;
    app(ToolRegistry::class)->clear()->register($tool);

    $rows = [
        ['id' => 1, 'customer' => 'Acme', 'total' => 99],
        ['id' => 2, 'customer' => 'Globex', 'total' => 250],
        ['id' => 3, 'customer' => 'Initech', 'total' => 12.5],
    ];
    $args = [
        'type' => 'table',
        'data' => [
            'caption' => 'Pedidos recientes',
            'columns' => [
                ['key' => 'id',       'label' => 'ID'],
                ['key' => 'customer', 'label' => 'Cliente'],
                ['key' => 'total',    'label' => 'Total'],
            ],
            'rows' => $rows,
        ],
    ];

    Prism::fake([
        TextResponseFake::make()
            ->withSteps(collect([
                TextStepFake::make()
                    ->withText('aquí los pedidos:')
                    ->withToolCalls([
                        new ToolCall(
                            id: 'call_render_1',
                            name: 'render_block',
                            arguments: $args,
                        ),
                    ])
                    ->withToolResults([
                        new PrismToolResult(
                            toolCallId: 'call_render_1',
                            toolName: 'render_block',
                            args: $args,
                            result: '{"status":"queued"}',
                        ),
                    ])
                    ->withFinishReason(FinishReason::ToolCalls),
                TextStepFake::make()
                    ->withText('')
                    ->withFinishReason(FinishReason::Stop),
            ]))
            ->withFinishReason(FinishReason::Stop),
    ]);

    $c = makeConversation();

    $events = collectChatEvents($c, 'list orders');

    $kinds = array_map(fn (SseEvent $e) => $e->event, $events);

    // Backend tools no se emiten para FrontendTool (regresión-guard).
    expect(in_array('tool_call', $kinds, true))->toBeFalse();

    $feIdx = array_search('frontend_action', $kinds, true);
    expect($feIdx)->not->toBeFalse('render_block no se emitió como frontend_action');

    $fe = $events[$feIdx];
    expect($fe->data['tool'])->toBe('render_block')
        ->and($fe->data['confirmation'])->toBe('auto')
        ->and($fe->data['action_id'])->toBeString()
        ->and($fe->data['action_id'])->not->toBe('');

    // El widget recoge `args.type` + `args.data` para el cascade de renderers.
    expect($fe->data['args'])->toMatchArray([
        'type' => 'table',
    ]);
    expect($fe->data['args']['data'])->toBeArray()
        ->and($fe->data['args']['data']['rows'])->toHaveCount(3)
        ->and($fe->data['args']['data']['rows'][0])->toMatchArray(['id' => 1, 'customer' => 'Acme'])
        ->and($fe->data['args']['data']['columns'])->toHaveCount(3);
});

/*
|--------------------------------------------------------------------------
| E16 — Niveles de confirmación para frontend tools.
|--------------------------------------------------------------------------
|
| DoD ROADMAP §5/E16: cuando una FrontendTool con confirmation=confirm|manual
| se invoca, ChatService persiste un row en `chatbot_pending_actions` y el
| LLM ve `awaiting_user` (no `queued`). El widget gestiona la confirmación
| via REST en otro turno.
*/

it('persists a pending action and returns awaiting_user to the LLM for confirmation=confirm (E16)', function () {
    $tool = new \Rnkr69\LaraChatbot\Tests\Stubs\Tools\ConfirmFrontendTool;
    $tool->confirmationOverride = ConfirmationLevel::Confirm;
    app(ToolRegistry::class)->clear()->register($tool);

    Prism::fake([
        TextResponseFake::make()
            ->withSteps(collect([
                TextStepFake::make()
                    ->withText('')
                    ->withToolCalls([
                        new ToolCall(
                            id: 'call_e16_1',
                            name: 'confirm_dialog',
                            arguments: ['message' => 'Run it?'],
                        ),
                    ])
                    ->withToolResults([
                        new PrismToolResult(
                            toolCallId: 'call_e16_1',
                            toolName: 'confirm_dialog',
                            args: ['message' => 'Run it?'],
                            result: '{"status":"awaiting_user"}',
                        ),
                    ])
                    ->withFinishReason(FinishReason::ToolCalls),
                TextStepFake::make()
                    ->withText('Esperando tu confirmación.')
                    ->withFinishReason(FinishReason::Stop),
            ]))
            ->withFinishReason(FinishReason::Stop),
    ]);

    $c = makeConversation();

    $events = collectChatEvents($c, 'do something risky');

    $kinds = array_map(fn (SseEvent $e) => $e->event, $events);
    $feIdx = array_search('frontend_action', $kinds, true);
    expect($feIdx)->not->toBeFalse('frontend_action no se emitió');

    $fe = $events[$feIdx];
    expect($fe->data['confirmation'])->toBe('confirm')
        ->and($fe->data['tool'])->toBe('confirm_dialog')
        ->and($fe->data['action_id'])->toBeString()
        ->and($fe->data['action_id'])->not->toBe('');

    // El row quedó persistido como pending con el mismo action_id.
    $pending = \Rnkr69\LaraChatbot\Models\PendingAction::query()->first();
    expect($pending)->not->toBeNull()
        ->and($pending->action_id)->toBe($fe->data['action_id'])
        ->and($pending->status->value)->toBe('pending')
        ->and($pending->confirmation->value)->toBe('confirm')
        ->and($pending->args)->toMatchArray(['message' => 'Run it?'])
        ->and($pending->expires_at)->not->toBeNull();
});

it('persists with confirmation=manual and a manual TTL (E16)', function () {
    config()->set('chatbot.limits.pending_action_ttl.manual', 86_400);

    $tool = new \Rnkr69\LaraChatbot\Tests\Stubs\Tools\ConfirmFrontendTool;
    $tool->confirmationOverride = ConfirmationLevel::Manual;
    app(ToolRegistry::class)->clear()->register($tool);

    Prism::fake([
        TextResponseFake::make()
            ->withSteps(collect([
                TextStepFake::make()
                    ->withText('')
                    ->withToolCalls([
                        new ToolCall(
                            id: 'call_e16_m',
                            name: 'confirm_dialog',
                            arguments: ['message' => 'Sign the form'],
                        ),
                    ])
                    ->withToolResults([
                        new PrismToolResult(
                            toolCallId: 'call_e16_m',
                            toolName: 'confirm_dialog',
                            args: ['message' => 'Sign the form'],
                            result: '{"status":"awaiting_user"}',
                        ),
                    ])
                    ->withFinishReason(FinishReason::ToolCalls),
                TextStepFake::make()
                    ->withText('')
                    ->withFinishReason(FinishReason::Stop),
            ]))
            ->withFinishReason(FinishReason::Stop),
    ]);

    $c = makeConversation();

    collectChatEvents($c, 'manual please');

    $pending = \Rnkr69\LaraChatbot\Models\PendingAction::query()->first();
    expect($pending->confirmation->value)->toBe('manual');

    $delta = abs($pending->expires_at->diffInSeconds(now()));
    expect($delta)->toBeGreaterThan(86_300)->and($delta)->toBeLessThan(86_500);
});

/*
 |--------------------------------------------------------------------------
 | v2.0 — E1: auto-stamp de id + source + pinnable en blocks
 |--------------------------------------------------------------------------
 |
 | Cuando una backend tool devuelve `ToolResult::blocks[]`, el orquestador
 | debe emitir un frame SSE `block` por cada uno, estampando metadatos:
 |
 |   - `id` (UUID) — handle del bloque para el cliente.
 |   - `source` = { tool, args, page_context_keys } — descriptor para que
 |     el replay engine (E3) sepa cómo re-ejecutar.
 |   - `pinnable: true` SÓLO cuando `tool->pinnable() === true` Y
 |     `tool->confirmation() === Auto` (enforcement aguas arriba).
 |
 | Back-compat: tools v1.x que NO devuelven blocks no emiten frames `block`,
 | y tools sin override de `pinnable()` heredan `false`, por lo que sus
 | blocks (si los emitiesen) llegan al cliente sin el flag.
 */

/**
 * Helper: arma un `Prism::fake` que invoca `echo_tool` una vez con los args
 * dados. Centralizado para reducir el ruido en los tests v2 — la mecánica de
 * tool-call/result a través de Prism::fake ya quedó cubierta por el test
 * "translates a backend tool call into tool_call + tool_result …".
 *
 * @param  array<string, mixed>  $args
 */
function fakeEchoToolCall(array $args = ['message' => 'pong']): void
{
    Prism::fake([
        TextResponseFake::make()
            ->withSteps(collect([
                TextStepFake::make()
                    ->withText('')
                    ->withToolCalls([
                        new ToolCall(
                            id: 'call_1',
                            name: 'echo_tool',
                            arguments: $args,
                        ),
                    ])
                    ->withToolResults([
                        new PrismToolResult(
                            toolCallId: 'call_1',
                            toolName: 'echo_tool',
                            args: $args,
                            result: '{"status":"ok"}',
                        ),
                    ])
                    ->withFinishReason(FinishReason::ToolCalls)
                    ->withMeta(new Meta('id-1', 'fake')),
                TextStepFake::make()
                    ->withText('listo')
                    ->withFinishReason(FinishReason::Stop)
                    ->withMeta(new Meta('id-2', 'fake')),
            ]))
            ->withFinishReason(FinishReason::Stop)
            ->withUsage(new Usage(promptTokens: 1, completionTokens: 2)),
    ]);
}

it('does NOT emit block frames when a backend tool returns no blocks (v1.x back-compat)', function () {
    $tool = new EchoBackendTool;  // emitBlocks defaults to []
    app(ToolRegistry::class)->clear()->register($tool);

    fakeEchoToolCall();

    $c = makeConversation();

    $events = collectChatEvents($c, 'echo pls');
    $kinds  = array_map(fn (SseEvent $e) => $e->event, $events);

    expect(in_array('block', $kinds, true))->toBeFalse();
});

it('emits block frames with stamped id + source for every block returned by a backend tool', function () {
    $tool = new EchoBackendTool;
    $tool->emitBlocks = [
        ['type' => 'table', 'data' => ['rows' => [['x' => 1]]]],
        ['type' => 'card',  'data' => ['title' => 'Hi']],
    ];
    app(ToolRegistry::class)->clear()->register($tool);

    fakeEchoToolCall(['message' => 'pong']);

    $c = makeConversation();

    $events = collectChatEvents($c, 'echo pls', ['route' => '/orders', 'entity' => 'invoice']);

    $blocks = array_values(array_filter(
        $events,
        fn (SseEvent $e) => $e->event === 'block',
    ));

    expect($blocks)->toHaveCount(2);

    // Block #1 — table
    expect($blocks[0]->data['type'])->toBe('table')
        ->and($blocks[0]->data['data'])->toMatchArray(['rows' => [['x' => 1]]])
        ->and($blocks[0]->data)->toHaveKey('id')
        ->and($blocks[0]->data['id'])->toBeString()
        ->and($blocks[0]->data['id'])->not->toBe('')
        ->and($blocks[0]->data['source'])->toMatchArray([
            'tool' => 'echo_tool',
            'args' => ['message' => 'pong'],
        ])
        ->and($blocks[0]->data['source']['page_context_keys'])
            ->toEqualCanonicalizing(['route', 'entity']);

    // Block #2 — card
    expect($blocks[1]->data['type'])->toBe('card')
        ->and($blocks[1]->data['id'])->not->toBe($blocks[0]->data['id']); // unique UUIDs per block
});

it('stamps block_ordinal per block-type within the tool result (#27)', function () {
    // #27 — `block_ordinal` is the 0-based position of a block AMONG those
    // of its own type in the tool's output. A multi-block tool (KPIs +
    // chart — the canonical dashboard case) must stamp kpi→0, kpi→1, kpi→2,
    // chart→0 so the replay can re-select the exact pinned block instead
    // of always taking blocks[0].
    $tool = new EchoBackendTool;
    $tool->emitBlocks = [
        ['type' => 'kpi',   'data' => ['label' => 'Total']],
        ['type' => 'kpi',   'data' => ['label' => 'Average']],
        ['type' => 'kpi',   'data' => ['label' => 'Fuel']],
        ['type' => 'chart', 'data' => ['kind' => 'bar']],
    ];
    app(ToolRegistry::class)->clear()->register($tool);

    fakeEchoToolCall(['message' => 'pong']);

    $c = makeConversation();

    $events = collectChatEvents($c, 'echo pls');

    $blocks = array_values(array_filter(
        $events,
        fn (SseEvent $e) => $e->event === 'block',
    ));

    expect($blocks)->toHaveCount(4)
        ->and($blocks[0]->data['block_ordinal'])->toBe(0)  // 1st kpi
        ->and($blocks[1]->data['block_ordinal'])->toBe(1)  // 2nd kpi
        ->and($blocks[2]->data['block_ordinal'])->toBe(2)  // 3rd kpi
        ->and($blocks[3]->data['block_ordinal'])->toBe(0); // 1st chart — ordinal resets per type
});

it('omits `pinnable` from the block payload when the tool inherits the default (false)', function () {
    // pinnableOverride = null → falls back to BaseBackendTool::pinnable() === false
    $tool = new EchoBackendTool;
    $tool->emitBlocks = [['type' => 'card', 'data' => ['x' => 1]]];
    app(ToolRegistry::class)->clear()->register($tool);

    fakeEchoToolCall();

    $c = makeConversation();

    $events = collectChatEvents($c, 'echo pls');
    $block  = collect($events)->first(fn (SseEvent $e) => $e->event === 'block');

    expect($block)->not->toBeNull()
        ->and($block->data)->not->toHaveKey('pinnable');
});

it('stamps `pinnable: true` when the tool opts in AND confirmation is Auto', function () {
    $tool = new EchoBackendTool;
    $tool->pinnableOverride = true;
    $tool->confirmationOverride = ConfirmationLevel::Auto;
    $tool->emitBlocks = [['type' => 'table', 'data' => ['rows' => []]]];
    app(ToolRegistry::class)->clear()->register($tool);

    fakeEchoToolCall();

    $c = makeConversation();

    $events = collectChatEvents($c, 'echo pls');
    $block  = collect($events)->first(fn (SseEvent $e) => $e->event === 'block');

    expect($block->data['pinnable'])->toBeTrue();
});

it('omits `pinnable` when chatbot.dashboard.enabled is false (#11 — clean opt-out)', function () {
    // v2.1 (#11): the dashboard opt-out must be clean. Even a tool that opts
    // into pinnable() with confirmation Auto must NOT get its blocks stamped
    // `pinnable` when the dashboard feature is disabled — otherwise the
    // widget mounts a 📌 button that 404s on click. The orchestrator is the
    // authoritative gate.
    config()->set('chatbot.dashboard.enabled', false);

    $tool = new EchoBackendTool;
    $tool->pinnableOverride = true;
    $tool->confirmationOverride = ConfirmationLevel::Auto;
    $tool->emitBlocks = [['type' => 'table', 'data' => ['rows' => []]]];
    app(ToolRegistry::class)->clear()->register($tool);

    fakeEchoToolCall();

    $c = makeConversation();

    $events = collectChatEvents($c, 'echo pls');
    $block  = collect($events)->first(fn (SseEvent $e) => $e->event === 'block');

    expect($block)->not->toBeNull()
        ->and($block->data)->not->toHaveKey('pinnable');
});

it('propagates `meta` verbatim on the block frame when the tool stamps it (v2.2.1 PR-B side_effects)', function () {
    $tool = new EchoBackendTool;
    $tool->emitBlocks = [[
        'type' => 'card',
        'data' => ['title' => '✅ Added'],
        'meta' => ['side_effects' => ['type' => 'widget_added', 'dashboard_slug' => 'ops', 'widget_id' => 42]],
    ]];
    app(ToolRegistry::class)->clear()->register($tool);

    fakeEchoToolCall();

    $c = makeConversation();

    $events = collectChatEvents($c, 'echo pls');
    $block  = collect($events)->first(fn (SseEvent $e) => $e->event === 'block');

    expect($block)->not->toBeNull()
        ->and($block->data['meta'])->toBe([
            'side_effects' => ['type' => 'widget_added', 'dashboard_slug' => 'ops', 'widget_id' => 42],
        ]);
});

it('omits `meta` from the block frame when the tool does not stamp it (v1 back-compat)', function () {
    $tool = new EchoBackendTool;
    $tool->emitBlocks = [['type' => 'card', 'data' => ['x' => 1]]];
    app(ToolRegistry::class)->clear()->register($tool);

    fakeEchoToolCall();

    $c = makeConversation();

    $events = collectChatEvents($c, 'echo pls');
    $block  = collect($events)->first(fn (SseEvent $e) => $e->event === 'block');

    expect($block)->not->toBeNull()
        ->and($block->data)->not->toHaveKey('meta');
});

it('filters non-Auto backend tools out of the LLM catalog (D9) so they never reach the stamping path', function () {
    // Plan §9 riesgo "Tool con efectos secundarios marcada pinnable por
    // descuido" tiene DOS defensas en cascada:
    //
    //   (1) D9: `ChatService::resolveTools` filtra backend tools con
    //       `confirmation !== Auto` del catálogo que se le pasa al LLM, así
    //       que el LLM ni siquiera puede invocarlas. Esto es lo que
    //       verificamos aquí.
    //   (2) En `onToolCall`, el bool `pinnable` se calcula con AND de
    //       `pinnable()` Y `confirmation === Auto`. Si por algún camino
    //       una non-Auto consiguiera ejecutarse igual, el flag jamás se
    //       propaga (verificación lógica de la AND en el código fuente +
    //       el test que confirma que SseEvent::block omite el campo cuando
    //       el bool es false — `SseEventTest`).
    //
    // Si esta capa cae alguna vez, el atacante todavía choca con la (2);
    // pero el test demuestra que la prevención principal está en su sitio.
    $tool = new EchoBackendTool;
    $tool->pinnableOverride = true;
    $tool->confirmationOverride = ConfirmationLevel::Confirm; // mutating-style
    $tool->emitBlocks = [['type' => 'table', 'data' => []]];
    app(ToolRegistry::class)->clear()->register($tool);

    fakeEchoToolCall();

    $c = makeConversation();

    $events = collectChatEvents($c, 'echo pls');

    // La tool fue filtrada del catálogo: no se invoca, no hay tool_call,
    // no hay block. El LLM produce su texto sin acceso a la tool.
    $kinds = array_map(fn (SseEvent $e) => $e->event, $events);
    expect(in_array('tool_call', $kinds, true))->toBeFalse()
        ->and(in_array('block', $kinds, true))->toBeFalse()
        ->and($tool->invocations)->toBe(0);
});

it('ignores blocks with no string type (defensive)', function () {
    $tool = new EchoBackendTool;
    $tool->emitBlocks = [
        ['type' => '',    'data' => ['x' => 1]],     // empty type
        ['type' => 42,    'data' => ['x' => 1]],     // non-string type
        ['type' => 'card','data' => ['x' => 1]],     // valid → emitted
        ['data' => ['x' => 1]],                       // no type key
    ];
    app(ToolRegistry::class)->clear()->register($tool);

    fakeEchoToolCall();

    $c = makeConversation();

    $events = collectChatEvents($c, 'echo pls');
    $blocks = array_values(array_filter(
        $events,
        fn (SseEvent $e) => $e->event === 'block',
    ));

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]->data['type'])->toBe('card');
});

