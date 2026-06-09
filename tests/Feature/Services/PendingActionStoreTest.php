<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Models\PendingAction;
use Rnkr69\LaraChatbot\Models\PendingActionStatus;
use Rnkr69\LaraChatbot\Services\InvalidPendingActionTransition;
use Rnkr69\LaraChatbot\Services\PendingActionStore;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;

beforeEach(function () {
    $this->artisan('migrate')->run();
});

function makeConv(int $userId = 1): Conversation
{
    $user = new TestUser(['id' => $userId, 'name' => 'U']);
    $user->setRawAttributes(['id' => $userId, 'name' => 'U'], sync: true);

    return Conversation::create([
        'user_type' => TestUser::class,
        'user_id'   => $userId,
        'title'     => null,
        'metadata'  => null,
    ]);
}

it('creates a pending action with confirm TTL from config', function () {
    config()->set('chatbot.limits.pending_action_ttl.confirm', 600);

    $store = app(PendingActionStore::class);
    $conv  = makeConv();

    $pending = $store->create($conv, 'demo_tool', ['x' => 1], ConfirmationLevel::Confirm);

    expect($pending->status)->toBe(PendingActionStatus::Pending)
        ->and($pending->confirmation->value)->toBe('confirm')
        ->and($pending->action_id)->toBeString()
        ->and(strlen($pending->action_id))->toBe(36)
        ->and($pending->expires_at)->not->toBeNull();

    $delta = abs($pending->expires_at->diffInSeconds(now()));
    expect($delta)->toBeGreaterThan(550)->and($delta)->toBeLessThan(650);
});

it('uses the manual TTL when confirmation is Manual', function () {
    config()->set('chatbot.limits.pending_action_ttl.manual', 86_400);

    $store = app(PendingActionStore::class);
    $conv  = makeConv();

    $pending = $store->create($conv, 'demo_tool', ['x' => 1], ConfirmationLevel::Manual);

    $delta = abs($pending->expires_at->diffInSeconds(now()));
    expect($delta)->toBeGreaterThan(86_300)->and($delta)->toBeLessThan(86_500);
});

it('refuses to create a pending action for ConfirmationLevel::Auto', function () {
    $store = app(PendingActionStore::class);
    $conv  = makeConv();

    expect(fn () => $store->create($conv, 'auto_tool', [], ConfirmationLevel::Auto))
        ->toThrow(InvalidArgumentException::class);
});

it('transitions pending -> confirmed via markConfirmed', function () {
    $store = app(PendingActionStore::class);
    $pending = $store->create(makeConv(), 'demo', [], ConfirmationLevel::Confirm);

    $updated = $store->markConfirmed($pending);

    expect($updated->status)->toBe(PendingActionStatus::Confirmed);
});

it('transitions pending -> rejected with optional result via markRejected', function () {
    $store = app(PendingActionStore::class);
    $pending = $store->create(makeConv(), 'demo', [], ConfirmationLevel::Confirm);

    $updated = $store->markRejected($pending, ['reason' => 'meh']);

    expect($updated->status)->toBe(PendingActionStatus::Rejected)
        ->and($updated->result)->toMatchArray(['reason' => 'meh']);
});

it('transitions pending -> executed and confirmed -> executed via markExecuted', function () {
    $store = app(PendingActionStore::class);

    $a = $store->create(makeConv(), 'demo', [], ConfirmationLevel::Confirm);
    $b = $store->create(makeConv(2), 'demo', [], ConfirmationLevel::Confirm);

    $store->markExecuted($a, ['ok' => true]);
    expect($a->refresh()->status)->toBe(PendingActionStatus::Executed)
        ->and($a->result)->toMatchArray(['ok' => true]);

    $store->markConfirmed($b);
    $store->markExecuted($b->refresh(), ['ok' => true]);
    expect($b->refresh()->status)->toBe(PendingActionStatus::Executed);
});

it('throws on invalid transitions from terminal statuses', function () {
    $store = app(PendingActionStore::class);
    $pending = $store->create(makeConv(), 'demo', [], ConfirmationLevel::Confirm);

    $store->markRejected($pending);

    expect(fn () => $store->markConfirmed($pending->refresh()))
        ->toThrow(InvalidPendingActionTransition::class);
    expect(fn () => $store->markExecuted($pending->refresh()))
        ->toThrow(InvalidPendingActionTransition::class);
});

it('marks expired pending rows via expirePending and leaves others alone', function () {
    $store = app(PendingActionStore::class);
    $conv  = makeConv();

    // 1 row con TTL en el pasado, 1 row con TTL futuro, 1 row ya rejected.
    $expired = $store->create($conv, 'a', [], ConfirmationLevel::Confirm);
    $expired->update(['expires_at' => now()->subMinutes(5)]);

    $still = $store->create($conv, 'b', [], ConfirmationLevel::Confirm);

    $rejected = $store->create($conv, 'c', [], ConfirmationLevel::Confirm);
    $rejected->update(['expires_at' => now()->subMinutes(5)]);
    $store->markRejected($rejected);

    $count = $store->expirePending();

    expect($count)->toBe(1)
        ->and($expired->refresh()->status)->toBe(PendingActionStatus::Expired)
        ->and($still->refresh()->status)->toBe(PendingActionStatus::Pending)
        ->and($rejected->refresh()->status)->toBe(PendingActionStatus::Rejected);
});

it('scope forUser filters via the conversation owner', function () {
    $u1 = new TestUser(['id' => 1, 'name' => 'A']);
    $u1->setRawAttributes(['id' => 1, 'name' => 'A'], sync: true);
    $u2 = new TestUser(['id' => 2, 'name' => 'B']);
    $u2->setRawAttributes(['id' => 2, 'name' => 'B'], sync: true);

    $store = app(PendingActionStore::class);
    $store->create(makeConv(1), 'a', [], ConfirmationLevel::Confirm);
    $store->create(makeConv(2), 'b', [], ConfirmationLevel::Confirm);

    expect(PendingAction::query()->forUser($u1)->count())->toBe(1)
        ->and(PendingAction::query()->forUser($u2)->count())->toBe(1);
});
