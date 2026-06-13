<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Services;

/**
 * Sanitizes the `page_context` declared by the host before injecting it into
 * the system prompt (E14 ROADMAP §5/E14).
 *
 * Public type contract (D13, decided at the start of E14):
 *   - Survive: `string`, `int`, finite `float`, `bool`, `array` (associative
 *     or list) whose content in turn survives.
 *   - Discarded: `null` (at value level), `Closure`, any `object`,
 *     resources, `NaN`/`±INF`, values whose depth exceeds `$maxDepth`.
 *   - The keys of an associative array are coerced to string (`int` → `(string)`);
 *     lists keep integer keys.
 *
 * The sanitizer does NOT apply truncation by JSON size — that is the
 * responsibility of `ChatController@stream` (D11: binary discard fallback). Here
 * we work only at the type level to produce a "safe" payload that the
 * system prompt can dump without leaking HTML, closures or references.
 */
class PageContextSanitizer
{
    public const DEFAULT_MAX_DEPTH = 8;

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>
     */
    public function sanitize(array $raw, int $maxDepth = self::DEFAULT_MAX_DEPTH): array
    {
        $cleaned = $this->walk($raw, $maxDepth);

        if (! is_array($cleaned)) {
            return [];
        }

        // The root is ALWAYS associative (string keys). If a list arrived,
        // we convert it to associative keeping the indices as strings.
        $assoc = [];
        foreach ($cleaned as $key => $value) {
            $assoc[(string) $key] = $value;
        }

        return $assoc;
    }

    /**
     * Recursively walks the value and returns the sanitized version or `null`
     * to tell the caller "discard me". `null` in the caller is ignored.
     */
    protected function walk(mixed $value, int $depthLeft): mixed
    {
        if ($depthLeft < 0) {
            return null;
        }

        if (is_string($value) || is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }

        if (is_array($value)) {
            return $this->walkArray($value, $depthLeft - 1);
        }

        // null, object (including Closure), resource, etc. → drop.
        return null;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    protected function walkArray(array $value, int $depthLeft): array
    {
        $isList = array_is_list($value);
        $out = [];

        foreach ($value as $key => $child) {
            $sanitized = $this->walk($child, $depthLeft);
            if ($sanitized === null) {
                continue;
            }

            if ($isList) {
                $out[] = $sanitized;
                continue;
            }

            $out[(string) $key] = $sanitized;
        }

        return $out;
    }
}
