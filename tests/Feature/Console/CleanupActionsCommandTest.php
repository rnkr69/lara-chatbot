<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Models\PendingAction;
use Rnkr69\LaraChatbot\Models\PendingActionConfirmation;
use Rnkr69\LaraChatbot\Models\PendingActionStatus;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;

beforeEach(function () {
    $this->artisan('migrate')->run();
});

function makeCleanupConv(int $userId = 1): Conversation
{
    return Conversation::create([
        'user_type' => TestUser::class,
        'user_id'   => $userId,
        'title'     => null,
        'metadata'  => null,
    ]);
}

it('marks expired pending rows as expired and reports the count (E16 DoD)', function () {
    $conv = makeCleanupConv();

    $expired1 = PendingAction::create([
        'conversation_id' => $conv->id,
        'action_id'       => 'aaaaaaaa-aaaa-aaaa-aaaa-000000000001',
        'tool'            => 'a',
        'args'            => [],
        'status'          => PendingActionStatus::Pending,
        'confirmation'    => PendingActionConfirmation::Confirm,
        'expires_at'      => now()->subMinute(),
    ]);

    $expired2 = PendingAction::create([
        'conversation_id' => $conv->id,
        'action_id'       => 'aaaaaaaa-aaaa-aaaa-aaaa-000000000002',
        'tool'            => 'b',
        'args'            => [],
        'status'          => PendingActionStatus::Pending,
        'confirmation'    => PendingActionConfirmation::Manual,
        'expires_at'      => now()->subHour(),
    ]);

    $stillFresh = PendingAction::create([
        'conversation_id' => $conv->id,
        'action_id'       => 'aaaaaaaa-aaaa-aaaa-aaaa-000000000003',
        'tool'            => 'c',
        'args'            => [],
        'status'          => PendingActionStatus::Pending,
        'confirmation'    => PendingActionConfirmation::Confirm,
        'expires_at'      => now()->addMinutes(10),
    ]);

    $alreadyExecuted = PendingAction::create([
        'conversation_id' => $conv->id,
        'action_id'       => 'aaaaaaaa-aaaa-aaaa-aaaa-000000000004',
        'tool'            => 'd',
        'args'            => [],
        'status'          => PendingActionStatus::Executed,
        'confirmation'    => PendingActionConfirmation::Confirm,
        'expires_at'      => now()->subDay(),
    ]);

    $this->artisan('chatbot:cleanup-actions')
        ->expectsOutputToContain('expired')
        ->assertExitCode(0);

    expect($expired1->refresh()->status)->toBe(PendingActionStatus::Expired)
        ->and($expired2->refresh()->status)->toBe(PendingActionStatus::Expired)
        ->and($stillFresh->refresh()->status)->toBe(PendingActionStatus::Pending)
        ->and($alreadyExecuted->refresh()->status)->toBe(PendingActionStatus::Executed);
});

it('reports nothing-to-do when no rows are expired', function () {
    makeCleanupConv();

    PendingAction::create([
        'conversation_id' => 1,
        'action_id'       => 'fresh-test-aaaa-aaaa-aaaaaaaaaaaa',
        'tool'            => 'fresh',
        'args'            => [],
        'status'          => PendingActionStatus::Pending,
        'confirmation'    => PendingActionConfirmation::Confirm,
        'expires_at'      => now()->addMinutes(15),
    ]);

    $this->artisan('chatbot:cleanup-actions')
        ->expectsOutputToContain('No expired pending actions')
        ->assertExitCode(0);
});
