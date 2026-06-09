<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Validation;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Throwable;

/**
 * Mapper de Laravel validation rules a schema simple consumible por el
 * page context y `fill_form` (v1.1.1, finding #13.a).
 *
 * Pasa de:
 *
 *     [
 *         'name'    => 'required|string|max:255',
 *         'email'   => 'required|email',
 *         'subject' => 'required|in:support,sales,other',
 *         'amount'  => 'nullable|numeric|min:0',
 *     ]
 *
 * A:
 *
 *     [
 *         ['name' => 'name',    'type' => 'text',   'required' => true,
 *          'max' => 255],
 *         ['name' => 'email',   'type' => 'email',  'required' => true],
 *         ['name' => 'subject', 'type' => 'select', 'required' => true,
 *          'options' => ['support', 'sales', 'other']],
 *         ['name' => 'amount',  'type' => 'number'],
 *     ]
 *
 * Soporta los tipos de regla más comunes (string/email/numeric/integer/
 * boolean/date/datetime/in/required/nullable/max/min). Reglas no
 * reconocidas se ignoran silenciosamente; reglas instancia (`Rule::in(...)`)
 * se inspeccionan para extraer values.
 *
 * El mapper NO instancia FormRequests: el integrador es responsable de
 * obtener el array `rules()` (típicamente vía
 * `(new ContactRequest)->rules()` o `ContactRequest::rules()` si es static).
 */
class RulesToFormSchema
{
    /**
     * @param  array<string, string|array<int, mixed>>  $rules
     * @param  array<string, string>  $labels  Mapa name → label amigable (FormRequest::attributes()).
     * @return list<array<string, mixed>>
     */
    public function fromRules(array $rules, array $labels = []): array
    {
        $fields = [];

        foreach ($rules as $name => $rule) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            // Skip nested array rules (e.g. 'items.*.name') for v1 — the LLM
            // can't fill array fields via the page context schema cleanly.
            // Hosts that need this can compose the schema by hand.
            if (str_contains($name, '.')) {
                continue;
            }

            $tokens = $this->tokenize($rule);

            $field = ['name' => $name];

            if (isset($labels[$name])) {
                $field['label'] = $labels[$name];
            }

            $type = $this->inferType($tokens);
            $field['type'] = $type;

            if ($this->isRequired($tokens)) {
                $field['required'] = true;
            }

            $opts = $this->extractEnumOptions($tokens);
            if ($opts !== null) {
                $field['options'] = $opts;
                if ($field['type'] === 'text') {
                    $field['type'] = 'select';
                }
            }

            $max = $this->extractNumeric($tokens, 'max');
            if ($max !== null) {
                $field['max'] = $max;
            }

            $min = $this->extractNumeric($tokens, 'min');
            if ($min !== null) {
                $field['min'] = $min;
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * @param  string|array<int, mixed>  $rule
     * @return list<string|object>
     */
    protected function tokenize(string|array $rule): array
    {
        if (is_string($rule)) {
            return array_values(array_filter(explode('|', $rule), static fn (string $t) => $t !== ''));
        }

        $out = [];
        foreach ($rule as $part) {
            if (is_string($part) && $part !== '') {
                foreach (explode('|', $part) as $t) {
                    if ($t !== '') {
                        $out[] = $t;
                    }
                }
                continue;
            }
            if (is_object($part)) {
                $out[] = $part;
            }
        }
        return $out;
    }

    /**
     * @param  list<string|object>  $tokens
     */
    protected function inferType(array $tokens): string
    {
        foreach ($tokens as $t) {
            if (! is_string($t)) {
                continue;
            }
            $name = strtolower(explode(':', $t, 2)[0]);
            $type = match ($name) {
                'email'                => 'email',
                'integer', 'numeric'   => 'number',
                'boolean', 'bool'      => 'boolean',
                'date_format'          => 'datetime',
                'date'                 => 'date',
                'url'                  => 'url',
                'file', 'image'        => 'file',
                default                => null,
            };
            if ($type !== null) {
                return $type;
            }
        }
        return 'text';
    }

    /**
     * @param  list<string|object>  $tokens
     */
    protected function isRequired(array $tokens): bool
    {
        foreach ($tokens as $t) {
            if (is_string($t) && strtolower($t) === 'required') {
                return true;
            }
        }
        return false;
    }

    /**
     * Devuelve `[string, ...]` con las opciones del enum, o null si no
     * encuentra ninguna regla `in:` o `Rule::in(...)`.
     *
     * @param  list<string|object>  $tokens
     * @return list<string>|null
     */
    protected function extractEnumOptions(array $tokens): ?array
    {
        foreach ($tokens as $t) {
            if (is_string($t) && str_starts_with(strtolower($t), 'in:')) {
                $list = substr($t, 3);
                $items = array_values(array_filter(array_map('trim', explode(',', $list)), static fn ($v) => $v !== ''));
                return $items === [] ? null : $items;
            }

            if (class_exists(In::class) && $t instanceof In) {
                try {
                    $str = (string) $t;
                    if (str_starts_with($str, 'in:')) {
                        $list = substr($str, 3);
                        $parts = array_map(static fn (string $p) => trim($p, "\"'"), explode(',', $list));
                        $parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));
                        return $parts === [] ? null : $parts;
                    }
                } catch (Throwable) { /* fall through */ }
            }
        }
        return null;
    }

    /**
     * @param  list<string|object>  $tokens
     */
    protected function extractNumeric(array $tokens, string $rule): ?float
    {
        foreach ($tokens as $t) {
            if (! is_string($t)) {
                continue;
            }
            $parts = explode(':', $t, 2);
            if (count($parts) === 2 && strtolower($parts[0]) === $rule && is_numeric($parts[1])) {
                return (float) $parts[1];
            }
        }
        return null;
    }
}
