<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Services;

/**
 * Sanitiza el `page_context` declarado por el host antes de inyectarlo en
 * el system prompt (E14 ROADMAP §5/E14).
 *
 * Contrato público de tipos (D13, decidido al iniciar E14):
 *   - Sobreviven: `string`, `int`, `float` finito, `bool`, `array` (asociativo
 *     o lista) cuyo contenido a su vez sobreviva.
 *   - Se descartan: `null` (a nivel de valor), `Closure`, cualquier `object`,
 *     recursos, `NaN`/`±INF`, valores cuya profundidad supere `$maxDepth`.
 *   - Las llaves de un array asociativo se coercen a string (`int` → `(string)`);
 *     las listas mantienen claves enteras.
 *
 * El sanitizador NO aplica truncado por tamaño JSON — eso es responsabilidad
 * de `ChatController@stream` (D11: descarte binario fallback). Aquí
 * trabajamos sólo a nivel de tipos para producir un payload "seguro" que el
 * system prompt pueda volcar sin filtrar HTML, closures o referencias.
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

        // El root SIEMPRE es asociativo (string keys). Si llegó una lista,
        // convertimos a asociativo conservando los índices como string.
        $assoc = [];
        foreach ($cleaned as $key => $value) {
            $assoc[(string) $key] = $value;
        }

        return $assoc;
    }

    /**
     * Recorre recursivamente el valor y devuelve la versión saneada o `null`
     * para indicar "descártame" al caller. `null` en el caller se ignora.
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

        // null, object (incluido Closure), resource, etc. → drop.
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
