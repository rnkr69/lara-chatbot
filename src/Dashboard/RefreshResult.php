<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Dashboard;

use Carbon\CarbonImmutable;
use Rnkr69\LaraChatbot\Models\WidgetRefreshStatus;

/**
 * Resultado inmutable de un replay sobre un `DashboardWidget` (v2.0 / E3).
 *
 * Cada llamada a `ReplayService::replay()` produce uno. Lo consume:
 *   - El propio `ReplayService`, que persiste `last_refreshed_at`,
 *     `last_refresh_status`, `last_refresh_error` y opcionalmente el
 *     `snapshot` (sólo en `Fresh`) en el widget.
 *   - El controller del E4, que lo serializa en el response del refresh
 *     manual + bulk SSE para que el frontend pinte el estado.
 *
 * Diseño:
 *   - `snapshot` siempre presente: en `Fresh` es el nuevo, en el resto
 *     es el preservado (no se pierden datos viejos jamás).
 *   - `error` sólo en `Stale`/`Error`/`Unauthorized`/`SourceMissing`. El
 *     shape `{category, message, captured_at}` coincide con
 *     `chatbot_dashboard_widgets.last_refresh_error`.
 *   - `lastRefreshedAt` siempre se setea: indica CUÁNDO se intentó el
 *     replay, no si tuvo éxito. La UI lee este timestamp para mostrar
 *     "hace 0 s" en el header.
 */
final class RefreshResult
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array{category: string, message: string, captured_at: string}|null  $error
     */
    private function __construct(
        public readonly WidgetRefreshStatus $status,
        public readonly array $snapshot,
        public readonly ?array $error,
        public readonly CarbonImmutable $lastRefreshedAt,
    ) {}

    /**
     * El tool devolvió un block del mismo type que el widget. El snapshot
     * nuevo reemplaza al anterior; sin error.
     *
     * @param  array<string, mixed>  $newSnapshot
     */
    public static function fresh(array $newSnapshot, CarbonImmutable $at): self
    {
        return new self(
            status: WidgetRefreshStatus::Fresh,
            snapshot: $newSnapshot,
            error: null,
            lastRefreshedAt: $at,
        );
    }

    /**
     * El tool ejecutó pero devolvió un block de otro type (o ninguno). El
     * snapshot anterior se conserva; la UI marca el widget para repinear.
     *
     * @param  array<string, mixed>  $previousSnapshot
     */
    public static function stale(
        array $previousSnapshot,
        string $message,
        CarbonImmutable $at,
    ): self {
        return new self(
            status: WidgetRefreshStatus::Stale,
            snapshot: $previousSnapshot,
            error: self::makeError('stale', $message, $at),
            lastRefreshedAt: $at,
        );
    }

    /**
     * Cascada permission/scope/tenant/ownership falló. Snapshot anterior
     * se conserva — JAMÁS se entregan datos frescos no autorizados.
     *
     * @param  array<string, mixed>  $previousSnapshot
     */
    public static function unauthorized(
        array $previousSnapshot,
        string $category,
        string $message,
        CarbonImmutable $at,
    ): self {
        return new self(
            status: WidgetRefreshStatus::Unauthorized,
            snapshot: $previousSnapshot,
            error: self::makeError($category, $message, $at),
            lastRefreshedAt: $at,
        );
    }

    /**
     * Error runtime/validation o cualquier rechazo no-autorizatorio del
     * tool. Snapshot anterior se conserva.
     *
     * @param  array<string, mixed>  $previousSnapshot
     */
    public static function error(
        array $previousSnapshot,
        string $category,
        string $message,
        CarbonImmutable $at,
    ): self {
        return new self(
            status: WidgetRefreshStatus::Error,
            snapshot: $previousSnapshot,
            error: self::makeError($category, $message, $at),
            lastRefreshedAt: $at,
        );
    }

    /**
     * El tool referenciado por `widget.source.tool` ya no existe en el
     * ToolRegistry (host la borró o renombró). Snapshot frozen; la UI
     * invita a unpin.
     *
     * @param  array<string, mixed>  $previousSnapshot
     */
    public static function sourceMissing(
        array $previousSnapshot,
        string $tool,
        CarbonImmutable $at,
    ): self {
        return new self(
            status: WidgetRefreshStatus::SourceMissing,
            snapshot: $previousSnapshot,
            error: self::makeError(
                'source_missing',
                sprintf('Tool `%s` no está registrada.', $tool),
                $at,
            ),
            lastRefreshedAt: $at,
        );
    }

    /**
     * @return array{category: string, message: string, captured_at: string}
     */
    private static function makeError(string $category, string $message, CarbonImmutable $at): array
    {
        return [
            'category'    => $category,
            'message'     => $message,
            'captured_at' => $at->toIso8601String(),
        ];
    }
}
