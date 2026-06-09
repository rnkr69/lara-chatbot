<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Dashboard;

/**
 * Calcula el `source_signature` (sha256 hex de 64 chars) de un block que
 * procede de una tool. Lo usan E4 al pinear (POST /widgets) y E3 al
 * deduplicar replay candidates.
 *
 * Canonicalización determinística:
 *   - Arrays asociativos → `ksort` recursivo, mismas claves en cualquier
 *     orden colisionan: `{a:1,b:2}` ≡ `{b:2,a:1}`.
 *   - Arrays indexados (lists) → preservan orden. El orden es semántico
 *     en muchos casos (paginación, sorting, top-N): `[1,2,3]` ≢ `[3,2,1]`.
 *   - `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` para estabilidad
 *     de bytes entre runtimes con/sin extensiones intl.
 *
 * El input del tool y los args se concatenan con `|` como separador
 * inyectable-seguro: no es base64, no es URL, sólo entra a sha256.
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
