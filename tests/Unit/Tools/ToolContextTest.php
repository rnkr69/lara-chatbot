<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Tests\Stubs\Tools\FakeUser;
use Rnkr69\LaraChatbot\Tools\ToolContext;

it('exposes user, page context, conversation and locale as readonly props', function () {
    $user = new FakeUser(7);
    $ctx  = new ToolContext(
        user:        $user,
        pageContext: ['route' => 'orders.index'],
        conversation: null,
        locale:      'es',
    );

    expect($ctx->user)->toBe($user)
        ->and($ctx->pageContext)->toBe(['route' => 'orders.index'])
        ->and($ctx->conversation)->toBeNull()
        ->and($ctx->locale)->toBe('es');
});

it('defaults page context to empty array and locale to null', function () {
    $ctx = new ToolContext(user: new FakeUser);

    expect($ctx->pageContext)->toBe([])
        ->and($ctx->locale)->toBeNull();
});
