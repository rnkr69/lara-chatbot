<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Dashboard;

/**
 * Normalizes and clamps the position/size values of the gridstack grid that
 * the Personal Dashboard uses. They are shared by `PinService` (on widget
 * creation) and `WidgetCrudService::update()` (on move/resize). Keeping it in
 * a single place avoids drift between two paths that must stay identical.
 *
 * The grid is 12 columns (`x: 0–11`, `w: 1–12`). `y` has no lower
 * cap other than `>= 0`; gridstack relocates to the lowest free row when
 * `9999` (the "auto-place" sentinel) is passed. `h` starts at 1 with no
 * theoretical upper cap — the historical controller clamp was `>=1` and that is what we preserve.
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
     * Suggested initial size by block type (v2.1, #18). It is only the
     * starting point on pin — the user resizes freely from
     * gridstack. An unknown `block_type` falls to the historical medium size.
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
