<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Validation\RulesToFormSchema;

it('maps required + email + boolean to schema field types (v1.1.1, finding #13.a)', function () {
    $mapper = new RulesToFormSchema;

    $schema = $mapper->fromRules([
        'name'    => 'required|string|max:255',
        'email'   => 'required|email',
        'opt_in'  => 'nullable|boolean',
    ]);

    expect($schema)->toHaveCount(3);
    expect($schema[0])->toMatchArray(['name' => 'name', 'type' => 'text', 'required' => true, 'max' => 255.0]);
    expect($schema[1])->toMatchArray(['name' => 'email', 'type' => 'email', 'required' => true]);
    expect($schema[2])->toMatchArray(['name' => 'opt_in', 'type' => 'boolean']);
});

it('maps in:a,b,c rule to type=select with string options', function () {
    $mapper = new RulesToFormSchema;

    $schema = $mapper->fromRules([
        'priority' => 'required|in:standard,express,critical',
    ]);

    expect($schema[0]['type'])->toBe('select');
    expect($schema[0]['options'])->toBe(['standard', 'express', 'critical']);
});

it('preserves labels from FormRequest::attributes()', function () {
    $mapper = new RulesToFormSchema;

    $schema = $mapper->fromRules(
        ['email' => 'required|email'],
        ['email' => 'Your email address'],
    );

    expect($schema[0]['label'])->toBe('Your email address');
});

it('maps numeric, integer, date and date_format rules', function () {
    $mapper = new RulesToFormSchema;

    $schema = $mapper->fromRules([
        'amount'       => 'required|numeric|min:0',
        'count'        => 'required|integer',
        'departure'    => 'required|date_format:Y-m-d H:i',
        'expires_on'   => 'nullable|date',
    ]);

    $byName = collect($schema)->keyBy('name');
    expect($byName['amount']['type'])->toBe('number');
    expect($byName['amount']['min'])->toBe(0.0);
    expect($byName['count']['type'])->toBe('number');
    expect($byName['departure']['type'])->toBe('datetime');
    expect($byName['expires_on']['type'])->toBe('date');
});

it('drops array-nested rule keys (items.*.name)', function () {
    $mapper = new RulesToFormSchema;

    $schema = $mapper->fromRules([
        'items.*.name' => 'required|string',
        'title'        => 'required|string',
    ]);

    expect($schema)->toHaveCount(1);
    expect($schema[0]['name'])->toBe('title');
});
