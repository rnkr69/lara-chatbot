<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Support;

/**
 * Maps a minimal JSON Schema (the shape tools declare in
 * `parameters()`) to an array of Laravel Validator rules. Supports the
 * stable subset the package's tools use in practice:
 *
 *   - type: string|integer|number|boolean|array|object
 *   - required: list of required keys in the root schema
 *   - enum: list of accepted values (converted into `in:a,b,c`)
 *
 * Any unsupported branch (oneOf, $ref, deep nesting, etc.) is
 * ignored silently — the tool can override `validateArgs()` in
 * `BaseBackendTool` if it needs richer validation.
 *
 * Decision D8 (E06): JSON Schema is the source of truth that the LLM and
 * the frontend see; the Laravel rules are derived. Keeping the mapping
 * minimal avoids having to synchronize both by hand.
 */
final class JsonSchemaToRules
{
    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, array<int, string>>
     */
    public static function convert(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if ($type !== 'object') {
            return [];
        }

        $properties = $schema['properties'] ?? [];

        if (! is_array($properties)) {
            return [];
        }

        $required = $schema['required'] ?? [];

        if (! is_array($required)) {
            $required = [];
        }

        $rules = [];

        foreach ($properties as $name => $property) {
            if (! is_string($name) || ! is_array($property)) {
                continue;
            }

            $rules[$name] = self::rulesForProperty(
                $property,
                isRequired: in_array($name, $required, true),
            );
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $property
     * @return array<int, string>
     */
    private static function rulesForProperty(array $property, bool $isRequired): array
    {
        $rules = [$isRequired ? 'required' : 'sometimes'];

        $typeRule = match ($property['type'] ?? null) {
            'string'  => 'string',
            'integer' => 'integer',
            'number'  => 'numeric',
            'boolean' => 'boolean',
            'array'   => 'array',
            'object'  => 'array',
            default   => null,
        };

        if ($typeRule !== null) {
            $rules[] = $typeRule;
        }

        if (isset($property['enum']) && is_array($property['enum'])) {
            $values = array_map(static fn ($v) => is_scalar($v) ? (string) $v : null, $property['enum']);
            $values = array_filter($values, static fn ($v) => $v !== null);

            if ($values !== []) {
                $rules[] = 'in:' . implode(',', $values);
            }
        }

        return $rules;
    }
}
