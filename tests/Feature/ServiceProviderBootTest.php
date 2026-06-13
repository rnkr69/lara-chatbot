<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Authorization\Contracts\Authorizer;
use Rnkr69\LaraChatbot\Authorization\Contracts\ScopeResolver;
use Rnkr69\LaraChatbot\Authorization\GateAuthorizer;
use Rnkr69\LaraChatbot\Authorization\NullScopeResolver;

it('resolves Authorizer as GateAuthorizer when resolver=gate', function () {
    expect(app(Authorizer::class))->toBeInstanceOf(GateAuthorizer::class);
});

it('resolves ScopeResolver as NullScopeResolver when no class declared', function () {
    expect(app(ScopeResolver::class))->toBeInstanceOf(NullScopeResolver::class);
});

it('does not bind TenantResolver when host has not declared one', function () {
    expect(app()->bound(\Rnkr69\LaraChatbot\Authorization\Contracts\TenantResolver::class))->toBeFalse();
});

it('loads the chatbot config under the expected key', function () {
    expect(config('chatbot.provider'))->toBe('anthropic')
        ->and(config('chatbot.model'))->toBe('claude-sonnet-4-6')
        ->and(config('chatbot.system_prompt.view'))->toBe('chatbot::system_prompt')
        ->and(config('chatbot.system_prompt.addendum_view'))->toBeNull();
});

it('fills nested config keys missing from a host-published config (#28)', function () {
    // #28 — a host that published `config/chatbot.php` on an earlier version
    // has a `dashboard` array (or `page`, `replay`…) WITHOUT the new nested
    // keys from later releases. `mergeConfigFrom` (flat) would silently drop
    // them; `replaceConfigRecursivelyFrom` backfills them from the package
    // default while letting whatever the host did define win.
    $stale = require __DIR__ . '/../../config/chatbot.php';

    // The host sets its own value that MUST survive…
    $stale['dashboard']['replay']['concurrency'] = 3;
    // …and it is missing new nested keys that the merge must restore.
    unset(
        $stale['dashboard']['mount_widget'],
        $stale['dashboard']['replay']['driver'],
        $stale['page']['back_url'],
    );

    config(['chatbot' => $stale]);

    // Re-run the provider's register over the host's "stale" config.
    (new \Rnkr69\LaraChatbot\ChatbotServiceProvider(app()))->register();

    expect(config('chatbot.dashboard.mount_widget'))->toBeTrue()           // restored from package
        ->and(config('chatbot.dashboard.replay.driver'))->toBe('sync')      // restored from package
        ->and(config('chatbot.dashboard.replay.concurrency'))->toBe(3)      // host value untouched
        ->and(config()->has('chatbot.page.back_url'))->toBeTrue();          // nested key restored
});
