<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Services\PageContextSanitizer;

beforeEach(function () {
    $this->sanitizer = new PageContextSanitizer;
});

it('preserves scalar primitive types', function () {
    $out = $this->sanitizer->sanitize([
        'route'   => 'invoices.index',
        'page'    => 3,
        'rate'    => 0.75,
        'paid'    => true,
        'unpaid'  => false,
    ]);

    expect($out)->toBe([
        'route'   => 'invoices.index',
        'page'    => 3,
        'rate'    => 0.75,
        'paid'    => true,
        'unpaid'  => false,
    ]);
});

it('drops closures, objects, resources and null values', function () {
    $resource = fopen('php://memory', 'r');
    $out = $this->sanitizer->sanitize([
        'fn'       => fn () => 'nope',
        'obj'      => new stdClass,
        'resource' => $resource,
        'kept'     => 'yes',
        'nothing'  => null,
    ]);
    fclose($resource);

    expect($out)->toBe(['kept' => 'yes']);
});

it('drops non-finite floats but preserves zero and negative finite floats', function () {
    $out = $this->sanitizer->sanitize([
        'nan'   => NAN,
        'inf'   => INF,
        'minf'  => -INF,
        'zero'  => 0.0,
        'neg'   => -1.5,
    ]);

    expect($out)->toBe([
        'zero' => 0.0,
        'neg'  => -1.5,
    ]);
});

it('preserves nested associative arrays recursively', function () {
    $out = $this->sanitizer->sanitize([
        'crud' => [
            'entity' => 'invoice',
            'action' => 'index',
            'filters' => [
                'status' => 'open',
                'tag_ids' => [1, 2, 3],
            ],
        ],
    ]);

    expect($out)->toBe([
        'crud' => [
            'entity' => 'invoice',
            'action' => 'index',
            'filters' => [
                'status' => 'open',
                'tag_ids' => [1, 2, 3],
            ],
        ],
    ]);
});

it('preserves list arrays as lists (sequential int keys)', function () {
    $out = $this->sanitizer->sanitize([
        'selected_ids' => [10, 20, 30],
    ]);

    expect($out['selected_ids'])->toBe([10, 20, 30]);
    expect(array_is_list($out['selected_ids']))->toBeTrue();
});

it('drops bad children inside a list and re-indexes the survivors', function () {
    $resource = fopen('php://memory', 'r');
    $out = $this->sanitizer->sanitize([
        'mixed' => [1, fn () => null, 'two', $resource, 4],
    ]);
    fclose($resource);

    expect($out['mixed'])->toBe([1, 'two', 4]);
    expect(array_is_list($out['mixed']))->toBeTrue();
});

it('preserves HTML strings opaquely (no parsing) — escaping is the consumer\'s job', function () {
    $out = $this->sanitizer->sanitize([
        'note' => '<b>important</b> <script>alert(1)</script>',
    ]);

    expect($out)->toBe([
        'note' => '<b>important</b> <script>alert(1)</script>',
    ]);
});

it('coerces non-string root keys to string', function () {
    $out = $this->sanitizer->sanitize([
        0    => 'first',
        'a'  => 'b',
        7    => 'seven',
    ]);

    expect($out)->toBe([
        '0' => 'first',
        'a' => 'b',
        '7' => 'seven',
    ]);
});

it('cuts off arrays that exceed the configured max depth', function () {
    $deep = ['a' => ['b' => ['c' => ['d' => 'leaf']]]];

    expect($this->sanitizer->sanitize($deep, 8))->toBe([
        'a' => ['b' => ['c' => ['d' => 'leaf']]],
    ]);

    // depth=2 admits root + 2 levels of array; the 'd' leaf at depth 3 lives
    // inside an array at depth 3 → its parent gets pruned.
    expect($this->sanitizer->sanitize($deep, 2))->toBe([
        'a' => ['b' => []],
    ]);

    expect($this->sanitizer->sanitize($deep, 0))->toBe([]);
});

it('drops object values inside nested arrays', function () {
    $out = $this->sanitizer->sanitize([
        'crud' => [
            'entity' => 'invoice',
            'panel'  => new stdClass,
        ],
    ]);

    expect($out)->toBe([
        'crud' => ['entity' => 'invoice'],
    ]);
});

it('returns an empty array when given empty input', function () {
    expect($this->sanitizer->sanitize([]))->toBe([]);
});
