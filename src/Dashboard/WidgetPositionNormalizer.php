<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Dashboard;

/**
 * Normaliza y clampea valores de posición/tamaño del grid gridstack que
 * usa el Personal Dashboard. Lo comparten `PinService` (al crear el widget)
 * y `WidgetCrudService::update()` (al mover/redimensionar). Mantenerlo en
 * un único punto evita drift entre dos caminos que deben quedar idénticos.
 *
 * El grid es de 12 columnas (`x: 0–11`, `w: 1–12`). `y` no tiene cap
 * inferior salvo `>= 0`; gridstack reubica al row más bajo libre cuando se
 * pasa `9999` (sentinel "auto-place"). `h` arranca en 1 sin cap superior
 * teórico — el clamp del controller histórico era `>=1` y eso preservamos.
 */
final class WidgetPositionNormalizer
{
    /**
     * @param  array<string, mixed>|null  $raw
     * @return array{x:int, y:int, w:int, h:int}
     */
    public static function normalize(?array $raw, string $blockType = ''): array
    {
        $default = self::defaultSizeFor($blockType);

        if ($raw === null) {
            return ['x' => 0, 'y' => 9999, 'w' => $default['w'], 'h' => $default['h']];
        }

        return [
            'x' => isset($raw['x']) && is_int($raw['x']) ? max(0, min(11, $raw['x'])) : 0,
            'y' => isset($raw['y']) && is_int($raw['y']) ? max(0, $raw['y']) : 9999,
            'w' => isset($raw['w']) && is_int($raw['w']) ? max(1, min(12, $raw['w'])) : $default['w'],
            'h' => isset($raw['h']) && is_int($raw['h']) ? max(1, $raw['h']) : $default['h'],
        ];
    }

    /**
     * Tamaño inicial sugerido por tipo de block (v2.1, #18). Sólo es el punto
     * de partida al pinear — el usuario redimensiona libremente desde
     * gridstack. Un `block_type` desconocido cae al tamaño medio histórico.
     *
     * @return array{w:int, h:int}
     */
    public static function defaultSizeFor(string $blockType): array
    {
        return match ($blockType) {
            'kpi'           => ['w' => 3, 'h' => 2],
            'card', 'text'  => ['w' => 4, 'h' => 3],
            'table', 'list' => ['w' => 8, 'h' => 5],
            'chart'         => ['w' => 6, 'h' => 4],
            default         => ['w' => 6, 'h' => 4],
        };
    }
}
