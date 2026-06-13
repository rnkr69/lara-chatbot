<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Models\PendingAction;
use Rnkr69\LaraChatbot\Models\PendingActionConfirmation;
use Rnkr69\LaraChatbot\Models\PendingActionStatus;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;

beforeEach(function () {
    $this->artisan('migrate')->run();
    $this->withoutMiddleware(VerifyCsrfToken::class);
});

/**
 * Helper: creates a virtual TestUser + a conversation + a pending action in
 * `pending` state ready to be resolved via the endpoint.
 *
 * @return array{user: TestUser, conversation: Conversation, pending: PendingAction}
 */
function seedPendingAction(int $userId = 1, ?Carbon $expiresAt = null, PendingActionConfirmation $confirmation = PendingActionConfirmation::Confirm): array
{
    $user = new TestUser(['id' => $userId, 'name' => "User-{$userId}"]);
    $user->setRawAttributes(['id' => $userId, 'name' => "User-{$userId}"], sync: true);

    $conversation = Conversation::create([
        'user_type' => TestUser::class,
        'user_id'   => $userId,
        'title'     => null,
        'metadata'  => null,
    ]);

    $pending = PendingAction::create([
        'conversation_id' => $conversation->id,
        'action_id'       => (string) Str::uuid(),
        'tool'            => 'confirm_dialog',
        'args'            => ['message' => 'Do it?'],
        'status'          => PendingActionStatus::Pending,
        'confirmation'    => $confirmation,
        'result'          => null,
        'expires_at'      => $expiresAt ?? now()->addMinutes(10),
    ]);

    return ['user' => $user, 'conversation' => $conversation, 'pending' => $pending];
}

it('marks a pending action as rejected when accept=false', function () {
    ['user' => $user, 'pending' => $pending] = seedPendingAction();

    $response = $this->actingAs($user, 'web')->postJson(
        "/chatbot/actions/{$pending->action_id}/confirm",
        ['accept' => false],
    );

    $response->assertOk();
    expect($response->json('data.status'))->toBe('rejected');

    $pending->refresh();
    expect($pending->status)->toBe(PendingActionStatus::Rejected);
});

it('records a rejection reason in the result payload', function () {
    ['user' => $user, 'pending' => $pending] = seedPendingAction();

    $this->actingAs($user, 'web')->postJson(
        "/chatbot/actions/{$pending->action_id}/confirm",
        ['accept' => false, 'result' => ['reason' => 'changed mind']],
    )->assertOk();

    expect($pending->refresh()->result)->toMatchArray(['reason' => 'changed mind']);
});

it('marks a pending action as confirmed when accept=true without result', function () {
    ['user' => $user, 'pending' => $pending] = seedPendingAction();

    $response = $this->actingAs($user, 'web')->postJson(
        "/chatbot/actions/{$pending->action_id}/confirm",
        ['accept' => true],
    );

    $response->assertOk();
    expect($response->json('data.status'))->toBe('confirmed');
    expect($pending->refresh()->status)->toBe(PendingActionStatus::Confirmed);
});

it('marks a pending action as executed when accept=true with a result payload', function () {
    ['user' => $user, 'pending' => $pending] = seedPendingAction();

    $response = $this->actingAs($user, 'web')->postJson(
        "/chatbot/actions/{$pending->action_id}/confirm",
        ['accept' => true, 'result' => ['ok' => true]],
    );

    $response->assertOk();
    expect($response->json('data.status'))->toBe('executed');
    $pending->refresh();
    expect($pending->status)->toBe(PendingActionStatus::Executed)
        ->and($pending->result)->toMatchArray(['ok' => true]);
});

it('transitions confirmed -> executed in the two-step flow when widget reports back', function () {
    ['user' => $user, 'pending' => $pending] = seedPendingAction();

    // Step 1: accept without result.
    $this->actingAs($user, 'web')->postJson(
        "/chatbot/actions/{$pending->action_id}/confirm",
        ['accept' => true],
    )->assertOk();

    // Step 2: widget reports execution result.
    $response = $this->actingAs($user, 'web')->postJson(
        "/chatbot/actions/{$pending->action_id}/confirm",
        ['accept' => true, 'result' => ['ok' => true]],
    );

    $response->assertOk();
    expect($response->json('data.status'))->toBe('executed');
    expect($pending->refresh()->status)->toBe(PendingActionStatus::Executed);
});

it('returns 409 when confirming a row already in a terminal status', function () {
    ['user' => $user, 'pending' => $pending] = seedPendingAction();

    // Reject first.
    $this->actingAs($user, 'web')->postJson(
        "/chatbot/actions/{$pending->action_id}/confirm",
        ['accept' => false],
    )->assertOk();

    // Try to accept now.
    $response = $this->actingAs($user, 'web')->postJson(
        "/chatbot/actions/{$pending->action_id}/confirm",
        ['accept' => true],
    );

    $response->assertStatus(409);
    expect($response->json('pending_action.status'))->toBe('rejected');
});

it('returns 404 when the pending action belongs to another user', function () {
    ['pending' => $pending] = seedPendingAction(userId: 1);

    $foreign = new TestUser(['id' => 99, 'name' => 'Foreign']);
    $foreign->setRawAttributes(['id' => 99, 'name' => 'Foreign'], sync: true);

    $response = $this->actingAs($foreign, 'web')->postJson(
        "/chatbot/actions/{$pending->action_id}/confirm",
        ['accept' => true],
    );

    $response->assertNotFound();
});

it('returns 404 when the action_id does not exist', function () {
    ['user' => $user] = seedPendingAction();

    $missing = (string) Str::uuid();
    $response = $this->actingAs($user, 'web')->postJson(
        "/chatbot/actions/{$missing}/confirm",
        ['accept' => true],
    );

    $response->assertNotFound();
});

it('returns 422 when accept is missing', function () {
    ['user' => $user, 'pending' => $pending] = seedPendingAction();

    $response = $this->actingAs($user, 'web')->postJson(
        "/chatbot/actions/{$pending->action_id}/confirm",
        ['result' => ['ok' => true]],
    );

    $response->assertStatus(422);
});

it('rejects unauthenticated requests via the auth middleware', function () {
    ['pending' => $pending] = seedPendingAction();

    $response = $this->postJson("/chatbot/actions/{$pending->action_id}/confirm", ['accept' => true]);

    $response->assertStatus(401);
});

it('marks an expired pending row and responds 409 instead of resolving it', function () {
    ['user' => $user, 'pending' => $pending] = seedPendingAction(
        expiresAt: now()->subSecond(),
    );

    $response = $this->actingAs($user, 'web')->postJson(
        "/chatbot/actions/{$pending->action_id}/confirm",
        ['accept' => true],
    );

    $response->assertStatus(409);
    expect($pending->refresh()->status)->toBe(PendingActionStatus::Expired);
});
