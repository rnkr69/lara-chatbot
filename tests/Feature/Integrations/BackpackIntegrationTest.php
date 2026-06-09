<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Integrations\Backpack\BackpackPageContextProvider;
use Rnkr69\LaraChatbot\Integrations\Backpack\BladeHelpers;

it('returns null currentContext when Backpack is not installed (D15 default)', function () {
    // Backpack is not on the package's composer dev-deps; the class
    // genuinely does not exist in the test runtime. The provider must
    // degrade gracefully (no exception, no half-built array).
    $provider = new BackpackPageContextProvider;

    expect($provider->currentContext())->toBeNull();
});

it('emits an empty string from the Blade directive when no context is available', function () {
    $rendered = BladeHelpers::renderMetaTag();

    expect($rendered)->toBe('');
});

it('builds the meta tag from a fake provider returning a context shape', function () {
    // We cannot reach Backpack from this suite, but we CAN swap the
    // provider in the container and verify the directive emits the meta
    // tag wrapper around the JSON the provider returned.
    app()->instance(
        BackpackPageContextProvider::class,
        new class extends BackpackPageContextProvider {
            public function currentContext(): ?array
            {
                return [
                    'crud' => [
                        'entity'       => 'App\\Models\\Invoice',
                        'action'       => 'list',
                        'filters'      => ['status' => 'open'],
                        'selected_ids' => [10, 20],
                    ],
                ];
            }
        },
    );

    $rendered = BladeHelpers::renderMetaTag();

    expect($rendered)
        ->toStartWith('<meta name="chatbot:context" content=\'')
        ->toEndWith("'>")
        ->and($rendered)->toContain('"entity":"App\\\\Models\\\\Invoice"')
        ->and($rendered)->toContain('"action":"list"')
        ->and($rendered)->toContain('"selected_ids":[10,20]');
});

it('drops empty crud sub-fields so the meta tag stays compact', function () {
    app()->instance(
        BackpackPageContextProvider::class,
        new class extends BackpackPageContextProvider {
            public function currentContext(): ?array
            {
                // Only `entity` populated — others empty.
                return ['crud' => ['entity' => 'App\\Models\\Order']];
            }
        },
    );

    $rendered = BladeHelpers::renderMetaTag();

    expect($rendered)
        ->toContain('"entity":"App\\\\Models\\\\Order"')
        ->and($rendered)->not->toContain('"filters"')
        ->and($rendered)->not->toContain('"selected_ids"');
});

// v1.1.3 (#20) — DataTables row decoration meta options.

it('emits a chatbot:options meta tag when dt_row_decoration is enabled (v1.1.3, finding #20)', function () {
    config()->set('chatbot.backpack.datatables_row_decoration', true);
    config()->set('chatbot.backpack.datatables_selected_sync', false);
    app()->instance(
        BackpackPageContextProvider::class,
        new class extends BackpackPageContextProvider {
            public function currentContext(): ?array
            {
                return ['crud' => ['entity' => 'App\\Models\\Mission', 'action' => 'list']];
            }
        },
    );

    $rendered = BladeHelpers::renderMetaTag();

    expect($rendered)->toContain('<meta name="chatbot:context"');
    expect($rendered)->toContain('<meta name="chatbot:options"');
    expect($rendered)->toContain('"dt_row_decoration":true');
});

it('emits dt_selected_sync in the chatbot:options meta tag when enabled (v1.1.4, finding #26)', function () {
    config()->set('chatbot.backpack.datatables_row_decoration', false);
    config()->set('chatbot.backpack.datatables_selected_sync', true);
    app()->instance(
        BackpackPageContextProvider::class,
        new class extends BackpackPageContextProvider {
            public function currentContext(): ?array
            {
                return ['crud' => ['entity' => 'App\\Models\\Mission', 'action' => 'list']];
            }
        },
    );

    $rendered = BladeHelpers::renderMetaTag();

    expect($rendered)->toContain('<meta name="chatbot:options"');
    expect($rendered)->toContain('"dt_selected_sync":true');
    // dt_row_decoration esta off; el payload no debe declararlo.
    expect($rendered)->not->toContain('"dt_row_decoration":true');
});

it('omits the options meta tag when every backpack flag is off (v1.1.3 #20 / v1.1.4 #26)', function () {
    config()->set('chatbot.backpack.datatables_row_decoration', false);
    config()->set('chatbot.backpack.datatables_selected_sync', false);
    app()->instance(
        BackpackPageContextProvider::class,
        new class extends BackpackPageContextProvider {
            public function currentContext(): ?array
            {
                return ['crud' => ['entity' => 'App\\Models\\Mission', 'action' => 'list']];
            }
        },
    );

    $rendered = BladeHelpers::renderMetaTag();

    expect($rendered)->toContain('<meta name="chatbot:context"');
    expect($rendered)->not->toContain('chatbot:options');
});

it('does not emit the options meta tag on non-Backpack pages (v1.1.3, finding #20)', function () {
    // No provider override → currentContext() returns null → context tag is
    // empty → options tag is suppressed too. Hosts that include the directive
    // in their global layout don't pay for the meta on every page.
    config()->set('chatbot.backpack.datatables_row_decoration', true);
    config()->set('chatbot.backpack.datatables_selected_sync', true);

    $rendered = BladeHelpers::renderMetaTag();

    expect($rendered)->toBe('');
});
