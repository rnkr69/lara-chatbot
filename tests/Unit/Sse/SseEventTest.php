<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Sse\SseEvent;

it('builds a text event with delta payload', function () {
    $event = SseEvent::text('hello');

    expect($event->event)->toBe('text')
        ->and($event->data)->toMatchArray(['delta' => 'hello']);
});

it('builds a tool_call event with name and args', function () {
    $event = SseEvent::toolCall('list_orders', ['status' => 'paid']);

    expect($event->event)->toBe('tool_call')
        ->and($event->data['name'])->toBe('list_orders')
        ->and($event->data['args'])->toMatchArray(['status' => 'paid']);
});

it('builds a tool_result event with ok flag and summary', function () {
    $event = SseEvent::toolResult('list_orders', true, 'ok');

    expect($event->event)->toBe('tool_result')
        ->and($event->data)->toMatchArray([
            'name'    => 'list_orders',
            'ok'      => true,
            'summary' => 'ok',
        ]);
});

it('builds a frontend_action event with action_id and confirmation', function () {
    $event = SseEvent::frontendAction('navigate', ['url' => '/x'], 'abc-123', 'auto');

    expect($event->event)->toBe('frontend_action')
        ->and($event->data['tool'])->toBe('navigate')
        ->and($event->data['args'])->toMatchArray(['url' => '/x'])
        ->and($event->data['action_id'])->toBe('abc-123')
        ->and($event->data['confirmation'])->toBe('auto');
});

it('builds a done event with message_id and usage', function () {
    $event = SseEvent::done(42, ['prompt_tokens' => 10, 'completion_tokens' => 20]);

    expect($event->event)->toBe('done')
        ->and($event->data['message_id'])->toBe(42)
        ->and($event->data['usage'])->toMatchArray([
            'prompt_tokens'     => 10,
            'completion_tokens' => 20,
        ]);
});

it('builds an error event with message and code', function () {
    $event = SseEvent::error('bad', 'rate_limit');

    expect($event->event)->toBe('error')
        ->and($event->data)->toMatchArray(['message' => 'bad', 'code' => 'rate_limit']);
});

it('builds a block event preserving type and data', function () {
    $event = SseEvent::block('card', ['title' => 'Hi']);

    expect($event->event)->toBe('block')
        ->and($event->data['type'])->toBe('card')
        ->and($event->data['data'])->toMatchArray(['title' => 'Hi']);
});

it('builds a block event WITHOUT v2 metadata when none provided (v1 back-compat)', function () {
    // E1 — calling `block()` with the legacy 2-arg shape must not introduce
    // any new keys in the payload. Consumers on v1 (renderers, listeners,
    // tests asserting exact shapes) keep working unchanged.
    $event = SseEvent::block('table', ['rows' => [['a' => 1]]]);

    expect(array_keys($event->data))->toBe(['type', 'data'])
        ->and($event->data)->not->toHaveKey('id')
        ->and($event->data)->not->toHaveKey('source')
        ->and($event->data)->not->toHaveKey('pinnable')
        ->and($event->data)->not->toHaveKey('block_ordinal');
});

it('builds a block event WITH v2 metadata stamped by the orchestrator', function () {
    $event = SseEvent::block(
        type: 'table',
        data: ['rows' => [['a' => 1]]],
        id: 'block-uuid-1',
        source: [
            'tool'              => 'list_invoices',
            'args'              => ['status' => 'paid'],
            'page_context_keys' => ['route', 'entity'],
        ],
        pinnable: true,
        blockOrdinal: 2,
    );

    expect($event->event)->toBe('block')
        ->and($event->data['type'])->toBe('table')
        ->and($event->data['id'])->toBe('block-uuid-1')
        ->and($event->data['source'])->toMatchArray([
            'tool' => 'list_invoices',
            'args' => ['status' => 'paid'],
        ])
        ->and($event->data['source']['page_context_keys'])->toBe(['route', 'entity'])
        ->and($event->data['pinnable'])->toBeTrue()
        ->and($event->data['block_ordinal'])->toBe(2);
});

it('omits pinnable from payload when not true (false / null are silent)', function () {
    // E1 — `pinnable: false` is the default for v1.x tools and we do NOT want
    // to leak the field when its value is the default. Same with `null`.
    $false = SseEvent::block('card', ['x' => 1], 'b1', null, false);
    $null  = SseEvent::block('card', ['x' => 1], 'b2', null, null);

    expect($false->data)->not->toHaveKey('pinnable')
        ->and($null->data)->not->toHaveKey('pinnable');
});

it('serializes block_ordinal including 0, omits it only when null (#27)', function () {
    // #27 — `block_ordinal` is the stable half of the replay descriptor.
    // Ordinal 0 (the first block of its type — the common single-block
    // case) MUST survive serialization; only `null` (a v1.x emit path that
    // never stamps it) is silent.
    $zero    = SseEvent::block('kpi', ['v' => 1], 'b1', null, true, 0);
    $second  = SseEvent::block('kpi', ['v' => 2], 'b2', null, true, 1);
    $absent  = SseEvent::block('kpi', ['v' => 3], 'b3', null, true, null);

    expect($zero->data['block_ordinal'])->toBe(0)
        ->and($second->data['block_ordinal'])->toBe(1)
        ->and($absent->data)->not->toHaveKey('block_ordinal');
});

it('omits id when passed as empty string (defensive)', function () {
    $event = SseEvent::block('card', ['x' => 1], id: '');

    expect($event->data)->not->toHaveKey('id');
});

it('propagates meta verbatim when provided (v2.2.1 PR-B side_effects)', function () {
    $event = SseEvent::block(
        type: 'card',
        data: ['title' => '✅ Added'],
        id: 'uuid-1',
        meta: ['side_effects' => ['type' => 'widget_added', 'dashboard_slug' => 'ops', 'widget_id' => 42]],
    );

    expect($event->data['meta'])->toBe([
        'side_effects' => ['type' => 'widget_added', 'dashboard_slug' => 'ops', 'widget_id' => 42],
    ]);
});

it('omits meta from payload when null or empty (back-compat)', function () {
    $null  = SseEvent::block('card', ['x' => 1], 'b1', null, null, null, null);
    $empty = SseEvent::block('card', ['x' => 1], 'b2', null, null, null, []);

    expect($null->data)->not->toHaveKey('meta')
        ->and($empty->data)->not->toHaveKey('meta');
});

it('toArray serializes the event/data shape', function () {
    $event = SseEvent::text('hola');

    expect($event->toArray())->toMatchArray([
        'event' => 'text',
        'data'  => ['delta' => 'hola'],
    ]);
});
