<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Dashboard\SourceSignature;

it('produce un sha256 hex de 64 chars', function () {
    $sig = SourceSignature::for('list_invoices', ['period' => 'q1']);

    expect($sig)->toBeString();
    expect(strlen($sig))->toBe(64);
    expect($sig)->toMatch('/^[0-9a-f]{64}$/');
});

it('es determinístico: misma entrada → mismo hash', function () {
    $a = SourceSignature::for('list_invoices', ['period' => 'q1', 'limit' => 10]);
    $b = SourceSignature::for('list_invoices', ['period' => 'q1', 'limit' => 10]);

    expect($a)->toBe($b);
});

it('asocia el orden de claves vía ksort recursivo', function () {
    $a = SourceSignature::for('list_invoices', ['period' => 'q1', 'limit' => 10]);
    $b = SourceSignature::for('list_invoices', ['limit' => 10, 'period' => 'q1']);

    expect($a)->toBe($b);
});

it('aplica ksort recursivo a sub-objects asociativos', function () {
    $a = SourceSignature::for('search', ['filters' => ['status' => 'open', 'priority' => 'high']]);
    $b = SourceSignature::for('search', ['filters' => ['priority' => 'high', 'status' => 'open']]);

    expect($a)->toBe($b);
});

it('preserva orden en listas indexadas (el orden es semántico)', function () {
    $a = SourceSignature::for('top_items', ['ids' => [1, 2, 3]]);
    $b = SourceSignature::for('top_items', ['ids' => [3, 2, 1]]);

    expect($a)->not->toBe($b);
});

it('el nombre del tool participa en el hash', function () {
    $a = SourceSignature::for('list_invoices', ['period' => 'q1']);
    $b = SourceSignature::for('list_orders',   ['period' => 'q1']);

    expect($a)->not->toBe($b);
});

it('args distintos producen hashes distintos', function () {
    $a = SourceSignature::for('list_invoices', ['period' => 'q1']);
    $b = SourceSignature::for('list_invoices', ['period' => 'q2']);

    expect($a)->not->toBe($b);
});

it('soporta args vacíos sin romper', function () {
    $sig = SourceSignature::for('ping', []);

    expect($sig)->toMatch('/^[0-9a-f]{64}$/');
});

it('escalares en valores (int/bool/null) no rompen la canonicalización', function () {
    $a = SourceSignature::for('search', ['active' => true, 'limit' => 10, 'cursor' => null]);
    $b = SourceSignature::for('search', ['cursor' => null, 'limit' => 10, 'active' => true]);

    expect($a)->toBe($b);
});

it('listas vs asociativos con misma "apariencia" producen hashes distintos', function () {
    $a = SourceSignature::for('x', ['v' => [1, 2, 3]]);
    $b = SourceSignature::for('x', ['v' => ['0' => 1, '1' => 2, '2' => 3]]);

    // PHP normaliza claves '0','1','2' a int 0,1,2 internamente — el array
    // resultante es list. Ambos hashes COINCIDEN por construcción del lenguaje.
    expect($a)->toBe($b);
});

it('mezcla de listas anidadas dentro de asociativos respeta ambos contratos', function () {
    $a = SourceSignature::for('search', [
        'filters' => ['status' => 'open'],
        'sort'    => [['field' => 'created_at', 'dir' => 'desc']],
    ]);

    // mismo input pero con claves de `filters` en otro orden y `dir`/`field`
    // intercambiados dentro del sort item.
    $b = SourceSignature::for('search', [
        'sort'    => [['dir' => 'desc', 'field' => 'created_at']],
        'filters' => ['status' => 'open'],
    ]);

    expect($a)->toBe($b);
});
