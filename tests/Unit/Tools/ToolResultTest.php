<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Tools\ToolResult;

it('creates a success result with data and blocks', function () {
    $result = ToolResult::success(['count' => 3], [['type' => 'text', 'content' => 'hi']]);

    expect($result->isOk())->toBeTrue()
        ->and($result->isError())->toBeFalse()
        ->and($result->isAwaitingUser())->toBeFalse()
        ->and($result->data)->toBe(['count' => 3])
        ->and($result->blocks)->toBe([['type' => 'text', 'content' => 'hi']]);
});

it('creates an error result with category and message', function () {
    $result = ToolResult::error('validation', 'target_id is required');

    expect($result->isError())->toBeTrue()
        ->and($result->errorCategory)->toBe('validation')
        ->and($result->errorMessage)->toBe('target_id is required');
});

it('falls back to category as message when no message is given', function () {
    $result = ToolResult::error('unauthorized');

    expect($result->errorMessage)->toBe('unauthorized');
});

it('creates an awaiting_user result with pending action id', function () {
    $result = ToolResult::awaitingUser('action_123', '¿Confirmar?');

    expect($result->isAwaitingUser())->toBeTrue()
        ->and($result->pendingActionId)->toBe('action_123')
        ->and($result->errorMessage)->toBe('¿Confirmar?');
});

it('serializes a success result with status, data and blocks', function () {
    $payload = ToolResult::success(['x' => 1])->toArray();

    expect($payload)->toMatchArray([
        'status' => 'ok',
        'data'   => ['x' => 1],
        'blocks' => [],
    ]);
});

it('serializes an error result with status, error and message', function () {
    $payload = ToolResult::error('not_owner', 'no access')->toArray();

    expect($payload)->toMatchArray([
        'status'  => 'error',
        'error'   => 'not_owner',
        'message' => 'no access',
    ]);
});

it('serializes an awaiting_user result with pending_action_id and message', function () {
    $payload = ToolResult::awaitingUser('act_42', '¿Vamos?')->toArray();

    expect($payload)->toMatchArray([
        'status'            => 'awaiting_user',
        'pending_action_id' => 'act_42',
        'message'           => '¿Vamos?',
    ]);
});
