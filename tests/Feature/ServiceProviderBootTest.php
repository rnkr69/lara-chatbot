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
    // #28 — un host que publicó `config/chatbot.php` en una versión anterior
    // tiene un array `dashboard` (o `page`, `replay`…) SIN las claves
    // anidadas nuevas de releases posteriores. `mergeConfigFrom` (plano)
    // las perdería en silencio; `replaceConfigRecursivelyFrom` las rellena
    // desde el default del paquete dejando ganar lo que el host sí definió.
    $stale = require __DIR__ . '/../../config/chatbot.php';

    // El host fija un valor propio que DEBE sobrevivir…
    $stale['dashboard']['replay']['concurrency'] = 3;
    // …y le faltan claves anidadas nuevas que el merge debe reponer.
    unset(
        $stale['dashboard']['mount_widget'],
        $stale['dashboard']['replay']['driver'],
        $stale['page']['back_url'],
    );

    config(['chatbot' => $stale]);

    // Re-ejecuta el register del provider sobre el config "stale" del host.
    (new \Rnkr69\LaraChatbot\ChatbotServiceProvider(app()))->register();

    expect(config('chatbot.dashboard.mount_widget'))->toBeTrue()           // repuesta del paquete
        ->and(config('chatbot.dashboard.replay.driver'))->toBe('sync')      // repuesta del paquete
        ->and(config('chatbot.dashboard.replay.concurrency'))->toBe(3)      // valor del host intacto
        ->and(config()->has('chatbot.page.back_url'))->toBeTrue();          // clave anidada repuesta
});
