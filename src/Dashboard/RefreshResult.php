<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Dashboard;

use Carbon\CarbonImmutable;
use Rnkr69\LaraChatbot\Models\WidgetRefreshStatus;

/**
 * Immutable result of a replay over a `DashboardWidget` (v2.0 / E3).
 *
 * Each call to `ReplayService::replay()` produces one. It is consumed by:
 *   - `ReplayService` itself, which persists `last_refreshed_at`,
 *     `last_refresh_status`, `last_refresh_error` and optionally the
 *     `snapshot` (only on `Fresh`) on the widget.
 *   - The E4 controller, which serializes it in the response of the manual
 *     refresh + bulk SSE so the frontend can paint the state.
 *
 * Design:
 *   - `snapshot` always present: on `Fresh` it is the new one, otherwise
 *     it is the preserved one (old data is never lost).
 *   - `error` only on `Stale`/`Error`/`Unauthorized`/`SourceMissing`. The
 *     shape `{category, message, captured_at}` matches
 *     `chatbot_dashboard_widgets.last_refresh_error`.
 *   - `lastRefreshedAt` is always set: it indicates WHEN the replay was
 *     attempted, not whether it succeeded. The UI reads this timestamp to show
 *     "0 s ago" in the header.
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
     * The tool returned a block of the same type as the widget. The new
     * snapshot replaces the previous one; no error.
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
     * The tool executed but returned a block of another type (or none). The
     * previous snapshot is kept; the UI flags the widget to re-pin.
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
     * The permission/scope/tenant/ownership cascade failed. The previous
     * snapshot is kept — unauthorized fresh data is NEVER delivered.
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
     * Runtime/validation error or any non-authorization rejection from the
     * tool. The previous snapshot is kept.
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
     * The tool referenced by `widget.source.tool` no longer exists in the
     * ToolRegistry (the host deleted or renamed it). Snapshot frozen; the UI
     * invites the user to unpin.
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
                sprintf('Tool `%s` is not registered.', $tool),
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
