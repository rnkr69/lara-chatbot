<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Models\PendingAction;
use Rnkr69\LaraChatbot\Models\PendingActionStatus;
use Rnkr69\LaraChatbot\Services\ChatService;
use Rnkr69\LaraChatbot\Sse\SseEvent;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult as PrismToolResult;

/**
 * E16 — ROADMAP §5/E16 DoD:
 *   "E2E test of the confirm flow: the LLM asks to execute, the user rejects,
 *    and in the next turn the LLM "knows" about it."
 *
 * This test composes the full flow: turn 1 (ChatService produces the pending
 * action) → POST /chatbot/actions/{id}/confirm with accept=false → turn 2
 * (we verify the second turn's system prompt mentions the rejected action
 * under `## Pending actions`).
 */

beforeEach(function () {
    $this->artisan('migrate')->run();
    $this->withoutMiddleware(VerifyCsrfToken::class);
});

function makeUserAndConversation(int $userId = 1): array
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

    return [$user, $conversation];
}

it('completes the confirm-then-reject loop and the LLM "sabe" in the next turn (E16 DoD)', function () {
    [$user, $conversation] = makeUserAndConversation();

    // ── Turn 1 ───────────────────────────────────────────────────────────
    // The LLM invokes a frontend tool with confirmation=confirm. ChatService
    // must persist the pending action and return awaiting_user to the LLM.

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
                            id: 'call_e16_dod_1',
                            name: 'confirm_dialog',
                            arguments: ['message' => 'Send the email?'],
                        ),
                    ])
                    ->withToolResults([
                        new PrismToolResult(
                            toolCallId: 'call_e16_dod_1',
                            toolName: 'confirm_dialog',
                            args: ['message' => 'Send the email?'],
                            result: '{"status":"awaiting_user"}',
                        ),
                    ])
                    ->withFinishReason(FinishReason::ToolCalls),
                TextStepFake::make()
                    ->withText('Te confirmo cuando aceptes.')
                    ->withFinishReason(FinishReason::Stop),
            ]))
            ->withFinishReason(FinishReason::Stop),
    ]);

    $events1 = [];
    foreach (app(ChatService::class)->handle($conversation, 'send the email') as $event) {
        $events1[] = $event;
    }

    $kinds1 = array_map(fn (SseEvent $e) => $e->event, $events1);
    $feIdx  = array_search('frontend_action', $kinds1, true);
    expect($feIdx)->not->toBeFalse('turn 1 did not emit frontend_action');

    /** @var SseEvent $fe */
    $fe = $events1[$feIdx];
    expect($fe->data['confirmation'])->toBe('confirm');

    $pending = PendingAction::query()->first();
    expect($pending)->not->toBeNull()
        ->and($pending->action_id)->toBe($fe->data['action_id'])
        ->and($pending->status)->toBe(PendingActionStatus::Pending);

    // ── User rejects via the REST endpoint ───────────────────────────────

    $this->actingAs($user, 'web')->postJson(
        "/chatbot/actions/{$pending->action_id}/confirm",
        ['accept' => false, 'result' => ['reason' => 'No me interesa.']],
    )->assertOk();

    expect($pending->refresh()->status)->toBe(PendingActionStatus::Rejected);

    // ── Turn 2 ───────────────────────────────────────────────────────────
    // We verify via PrismFake::assertRequest that the second turn's system
    // prompt contains the `## Pending actions` section with the REJECTED row.
    // `assertRequest` is an instance method of PrismFake — the `Prism::fake()`
    // facade returns the instance so it can be called.

    $fake2 = Prism::fake([
        TextResponseFake::make()
            ->withText('Understood, I will not send the email.')
            ->withFinishReason(FinishReason::Stop),
    ]);

    $events2 = [];
    foreach (app(ChatService::class)->handle($conversation, 'what happened with that action?') as $event) {
        $events2[] = $event;
    }

    $fake2->assertRequest(function (array $requests) use ($pending) {
        // The last request is the one from turn 2.
        $req     = $requests[count($requests) - 1];
        $prompt  = (string) ($req->systemPrompts()[0]->content ?? '');

        expect($prompt)
            ->toContain('## Pending actions')
            ->toContain('[REJECTED]')
            ->toContain($pending->action_id)
            ->toContain('confirm_dialog');
    });
});

it('verifies expiration via the cleanup command and reflects it in the next turn prompt', function () {
    [, $conversation] = makeUserAndConversation();

    $tool = new \Rnkr69\LaraChatbot\Tests\Stubs\Tools\ConfirmFrontendTool;
    app(ToolRegistry::class)->clear()->register($tool);

    Prism::fake([
        TextResponseFake::make()
            ->withSteps(collect([
                TextStepFake::make()
                    ->withText('')
                    ->withToolCalls([
                        new ToolCall(
                            id: 'call_exp',
                            name: 'confirm_dialog',
                            arguments: ['message' => 'Do thing?'],
                        ),
                    ])
                    ->withToolResults([
                        new PrismToolResult(
                            toolCallId: 'call_exp',
                            toolName: 'confirm_dialog',
                            args: ['message' => 'Do thing?'],
                            result: '{"status":"awaiting_user"}',
                        ),
                    ])
                    ->withFinishReason(FinishReason::ToolCalls),
                TextStepFake::make()
                    ->withText('OK, espero.')
                    ->withFinishReason(FinishReason::Stop),
            ]))
            ->withFinishReason(FinishReason::Stop),
    ]);

    foreach (app(ChatService::class)->handle($conversation, 'do thing') as $_e) {
        // drain
    }

    $pending = PendingAction::query()->first();
    expect($pending)->not->toBeNull();

    // Make it expire and run the command.
    $pending->update(['expires_at' => now()->subMinute()]);

    $this->artisan('chatbot:cleanup-actions')->assertExitCode(0);

    expect($pending->refresh()->status)->toBe(PendingActionStatus::Expired);

    // We verify the system prompt section in the next turn reflects the
    // [EXPIRED] status. `assertRequest` is invoked on the fake instance
    // (not on the facade).
    $fake2 = Prism::fake([
        TextResponseFake::make()
            ->withText('OK')
            ->withFinishReason(FinishReason::Stop),
    ]);

    foreach (app(ChatService::class)->handle($conversation, 'follow up') as $_e) {
        // drain
    }

    $fake2->assertRequest(function (array $requests) use ($pending) {
        $req    = $requests[count($requests) - 1];
        $prompt = (string) ($req->systemPrompts()[0]->content ?? '');

        expect($prompt)->toContain('## Pending actions')
            ->toContain('[EXPIRED]')
            ->toContain($pending->action_id);
    });
});
