<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Support;

/**
 * Mapea un JSON Schema mínimo (el shape que las tools declaran en
 * `parameters()`) a un array de reglas de Laravel Validator. Soporta el
 * subset estable que las tools del paquete usan en la práctica:
 *
 *   - type: string|integer|number|boolean|array|object
 *   - required: lista de claves obligatorias en el schema raíz
 *   - enum: lista de valores admitidos (se convierte en `in:a,b,c`)
 *
 * Cualquier rama no soportada (oneOf, $ref, anidación profunda, etc.) se
 * ignora silenciosamente — la tool puede override `validateArgs()` en
 * `BaseBackendTool` si necesita validación más rica.
 *
 * Decisión D8 (E06): JSON Schema es la fuente de verdad que ven el LLM y
 * el frontend; las rules de Laravel son derivadas. Mantener el mapping
 * mínimo evita tener que sincronizar ambos a mano.
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
