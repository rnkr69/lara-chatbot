<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Tools\Support\JsonSchemaToRules;

it('returns empty rules when schema is not an object', function () {
    expect(JsonSchemaToRules::convert(['type' => 'string']))->toBe([]);
});

it('marks required fields and adds the typed rule', function () {
    $rules = JsonSchemaToRules::convert([
        'type' => 'object',
        'properties' => [
            'target_id' => ['type' => 'integer'],
            'note'      => ['type' => 'string'],
        ],
        'required' => ['target_id'],
    ]);

    expect($rules)->toMatchArray([
        'target_id' => ['required', 'integer'],
        'note'      => ['sometimes', 'string'],
    ]);
});

it('maps each json schema scalar type to a Laravel rule', function () {
    $rules = JsonSchemaToRules::convert([
        'type' => 'object',
        'properties' => [
            's' => ['type' => 'string'],
            'i' => ['type' => 'integer'],
            'n' => ['type' => 'number'],
            'b' => ['type' => 'boolean'],
            'a' => ['type' => 'array'],
            'o' => ['type' => 'object'],
        ],
        'required' => [],
    ]);

    expect($rules['s'])->toContain('string')
        ->and($rules['i'])->toContain('integer')
        ->and($rules['n'])->toContain('numeric')
        ->and($rules['b'])->toContain('boolean')
        ->and($rules['a'])->toContain('array')
        ->and($rules['o'])->toContain('array');
});

it('appends an in: rule when enum is declared', function () {
    $rules = JsonSchemaToRules::convert([
        'type' => 'object',
        'properties' => [
            'status' => ['type' => 'string', 'enum' => ['paid', 'pending']],
        ],
        'required' => [],
    ]);

    expect($rules['status'])->toContain('in:paid,pending');
});

it('skips type rule when type is missing or unknown', function () {
    $rules = JsonSchemaToRules::convert([
        'type' => 'object',
        'properties' => [
            'mystery' => ['description' => 'no type given'],
        ],
        'required' => [],
    ]);

    expect($rules['mystery'])->toBe(['sometimes']);
});

it('returns empty array when there are no properties', function () {
    expect(JsonSchemaToRules::convert(['type' => 'object']))->toBe([]);
});
