<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Events\ToolInvoked;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\EchoBackendTool;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\FakeUser;
use Rnkr69\LaraChatbot\Tools\ToolResult;

it('exposes user, tool, args, result, durationMs and conversation as readonly properties', function () {
    $user   = new FakeUser(7);
    $tool   = new EchoBackendTool;
    $args   = ['message' => 'hi'];
    $result = ToolResult::success(['echoed' => 'hi']);

    $event = new ToolInvoked(
        user: $user,
        tool: $tool,
        args: $args,
        result: $result,
        durationMs: 12.5,
        conversation: null,
    );

    expect($event->user)->toBe($user)
        ->and($event->tool)->toBe($tool)
        ->and($event->args)->toBe($args)
        ->and($event->result)->toBe($result)
        ->and($event->durationMs)->toBe(12.5)
        ->and($event->conversation)->toBeNull();
});
