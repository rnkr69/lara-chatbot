<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Dashboard;

/**
 * Computes the `source_signature` (64-char sha256 hex) of a block that
 * comes from a tool. It is used by E4 on pin (POST /widgets) and by E3 to
 * deduplicate replay candidates.
 *
 * Deterministic canonicalization:
 *   - Associative arrays → recursive `ksort`, the same keys in any
 *     order collide: `{a:1,b:2}` ≡ `{b:2,a:1}`.
 *   - Indexed arrays (lists) → preserve order. Order is semantic
 *     in many cases (pagination, sorting, top-N): `[1,2,3]` ≢ `[3,2,1]`.
 *   - `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` for byte stability
 *     across runtimes with/without intl extensions.
 *
 * The tool input and the args are concatenated with `|` as an
 * injection-safe separator: it is not base64, not a URL, it only feeds sha256.
 */
final class SourceSignature
{
    public static function for(string $tool, array $args): string
    {
        return hash(
            'sha256',
            $tool . '|' . self::canonicalJson($args)
        );
    }

    private static function canonicalJson(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn (mixed $v): mixed => self::normalize($v), $value);
        }

        ksort($value);

        $result = [];

        foreach ($value as $key => $inner) {
            $result[$key] = self::normalize($inner);
        }

        return $result;
    }
}
