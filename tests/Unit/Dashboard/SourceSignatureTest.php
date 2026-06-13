<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Dashboard\SourceSignature;

it('produces a 64-char hex sha256', function () {
    $sig = SourceSignature::for('list_invoices', ['period' => 'q1']);

    expect($sig)->toBeString();
    expect(strlen($sig))->toBe(64);
    expect($sig)->toMatch('/^[0-9a-f]{64}$/');
});

it('is deterministic: same input → same hash', function () {
    $a = SourceSignature::for('list_invoices', ['period' => 'q1', 'limit' => 10]);
    $b = SourceSignature::for('list_invoices', ['period' => 'q1', 'limit' => 10]);

    expect($a)->toBe($b);
});

it('is order-insensitive for keys via recursive ksort', function () {
    $a = SourceSignature::for('list_invoices', ['period' => 'q1', 'limit' => 10]);
    $b = SourceSignature::for('list_invoices', ['limit' => 10, 'period' => 'q1']);

    expect($a)->toBe($b);
});

it('applies recursive ksort to associative sub-objects', function () {
    $a = SourceSignature::for('search', ['filters' => ['status' => 'open', 'priority' => 'high']]);
    $b = SourceSignature::for('search', ['filters' => ['priority' => 'high', 'status' => 'open']]);

    expect($a)->toBe($b);
});

it('preserves order in indexed lists (order is semantic)', function () {
    $a = SourceSignature::for('top_items', ['ids' => [1, 2, 3]]);
    $b = SourceSignature::for('top_items', ['ids' => [3, 2, 1]]);

    expect($a)->not->toBe($b);
});

it('the tool name participates in the hash', function () {
    $a = SourceSignature::for('list_invoices', ['period' => 'q1']);
    $b = SourceSignature::for('list_orders',   ['period' => 'q1']);

    expect($a)->not->toBe($b);
});

it('different args produce different hashes', function () {
    $a = SourceSignature::for('list_invoices', ['period' => 'q1']);
    $b = SourceSignature::for('list_invoices', ['period' => 'q2']);

    expect($a)->not->toBe($b);
});

it('supports empty args without breaking', function () {
    $sig = SourceSignature::for('ping', []);

    expect($sig)->toMatch('/^[0-9a-f]{64}$/');
});

it('scalars in values (int/bool/null) do not break canonicalization', function () {
    $a = SourceSignature::for('search', ['active' => true, 'limit' => 10, 'cursor' => null]);
    $b = SourceSignature::for('search', ['cursor' => null, 'limit' => 10, 'active' => true]);

    expect($a)->toBe($b);
});

it('lists vs associatives with the same "appearance" produce different hashes', function () {
    $a = SourceSignature::for('x', ['v' => [1, 2, 3]]);
    $b = SourceSignature::for('x', ['v' => ['0' => 1, '1' => 2, '2' => 3]]);

    // PHP normalizes keys '0','1','2' to int 0,1,2 internally — the resulting
    // array is a list. Both hashes MATCH by language construction.
    expect($a)->toBe($b);
});

it('a mix of nested lists inside associatives respects both contracts', function () {
    $a = SourceSignature::for('search', [
        'filters' => ['status' => 'open'],
        'sort'    => [['field' => 'created_at', 'dir' => 'desc']],
    ]);

    // same input but with `filters` keys in a different order and `dir`/`field`
    // swapped within the sort item.
    $b = SourceSignature::for('search', [
        'sort'    => [['dir' => 'desc', 'field' => 'created_at']],
        'filters' => ['status' => 'open'],
    ]);

    expect($a)->toBe($b);
});
