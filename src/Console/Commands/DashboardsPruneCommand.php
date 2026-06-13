<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Rnkr69\LaraChatbot\Models\Dashboard;
use Rnkr69\LaraChatbot\Models\DashboardWidget;
use Rnkr69\LaraChatbot\Models\WidgetRefreshStatus;

/**
 * `php artisan chatbot:dashboards:prune`
 *
 * Personal Dashboard housekeeping (v2.0 / E10). Deletes unusable widgets
 * and dashboards by applying configurable thresholds. Designed for
 * production hosts that want to clean up accumulated drift without
 * touching what is active. Scheduler recipe in `config/chatbot.php` →
 * `chatbot.dashboard.prune` section.
 *
 * Modes (each one OPT-IN — the command with no flags exits with a usage error):
 *
 *  --source-missing       Soft-delete widgets with
 *                         `last_refresh_status='source_missing'` whose
 *                         `last_refreshed_at` is older than
 *                         `prune.source_missing_days` (default 30).
 *
 *  --stale                Soft-delete widgets whose `last_refreshed_at`
 *                         is older than `prune.stale_days` (default 90)
 *                         OR have never been refreshed, except those
 *                         that already count as `source_missing` (covers
 *                         pin orphans with no subsequent replay).
 *
 *  --empty-dashboards     Soft-delete dashboards created more than
 *                         `prune.empty_dashboard_days` (default 180)
 *                         ago that have no active widgets
 *                         (`whereDoesntHave('widgets')` over the normal
 *                         soft-delete scope).
 *
 *  --purge-soft-deleted   Hard-delete (forceDelete) of widgets AND
 *                         dashboards whose `deleted_at` is older than
 *                         `prune.purge_soft_deleted_days` (default 30).
 *                         Applies `onlyTrashed`; combinable with any
 *                         previous flag — widgets just soft-deleted
 *                         by this same run are NOT purged (their `deleted_at`
 *                         is seconds ago, not days ago).
 *
 * Dry-run by default; execute with `--force`. The output emits a table
 * per activated mode with the candidate rows (limit 100) and a final
 * summary with totals.
 *
 * Conventions:
 *  - Soft-delete (`$query->delete()`) when the model uses
 *    `SoftDeletes` — parity with `DELETE /chatbot/dashboards/{slug}`.
 *  - Hard-delete (`forceDelete()`) only via `--purge-soft-deleted`.
 *  - Combinable: `--source-missing --stale --purge-soft-deleted` runs
 *    the three steps in order (soft-delete first, hard-delete after)
 *    over the rows that were already soft-deleted before starting.
 */
class DashboardsPruneCommand extends Command
{
    protected $signature = 'chatbot:dashboards:prune
        {--source-missing : Soft-delete widgets with a sustained last_refresh_status=source_missing}
        {--stale : Soft-delete widgets with no recent refresh}
        {--empty-dashboards : Soft-delete dashboards with no active widgets created a while ago}
        {--purge-soft-deleted : Hard-delete (forceDelete) rows soft-deleted a while ago}
        {--source-missing-days= : Threshold in days for --source-missing (config override)}
        {--stale-days= : Threshold in days for --stale (config override)}
        {--empty-dashboard-days= : Threshold in days for --empty-dashboards (config override)}
        {--purge-older-than-days= : Threshold in days for --purge-soft-deleted (config override)}
        {--force : Execute the deletions (without this, dry-run)}';

    protected $description = 'Personal Dashboard housekeeping: delete unusable widgets/dashboards.';

    public function handle(): int
    {
        $sourceMissing    = (bool) $this->option('source-missing');
        $stale            = (bool) $this->option('stale');
        $emptyDashboards  = (bool) $this->option('empty-dashboards');
        $purgeSoftDeleted = (bool) $this->option('purge-soft-deleted');
        $force            = (bool) $this->option('force');

        if (! $sourceMissing && ! $stale && ! $emptyDashboards && ! $purgeSoftDeleted) {
            $this->error('Specify at least one of --source-missing, --stale, --empty-dashboards, --purge-soft-deleted.');

            return self::INVALID;
        }

        $softDeleted    = 0;
        $wouldSoftDelete = 0;
        $hardDeleted    = 0;
        $wouldHardDelete = 0;

        try {
            if ($sourceMissing) {
                $days = $this->resolveDays('source-missing-days', 'source_missing_days', 30);
                [$d, $w] = $this->pruneSourceMissingWidgets($days, $force);
                $softDeleted    += $d;
                $wouldSoftDelete += $w;
            }

            if ($stale) {
                $days = $this->resolveDays('stale-days', 'stale_days', 90);
                [$d, $w] = $this->pruneStaleWidgets($days, $force);
                $softDeleted    += $d;
                $wouldSoftDelete += $w;
            }

            if ($emptyDashboards) {
                $days = $this->resolveDays('empty-dashboard-days', 'empty_dashboard_days', 180);
                [$d, $w] = $this->pruneEmptyDashboards($days, $force);
                $softDeleted    += $d;
                $wouldSoftDelete += $w;
            }

            if ($purgeSoftDeleted) {
                $days = $this->resolveDays('purge-older-than-days', 'purge_soft_deleted_days', 30);
                [$d, $w] = $this->purgeSoftDeleted($days, $force);
                $hardDeleted    += $d;
                $wouldHardDelete += $w;
            }
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $this->newLine();
        $this->line(sprintf('Mode: %s', $force ? 'EXECUTED' : 'DRY-RUN'));
        $this->line(sprintf('Soft-deleted: %d (would: %d)', $softDeleted, $wouldSoftDelete));
        $this->line(sprintf('Hard-deleted: %d (would: %d)', $hardDeleted, $wouldHardDelete));

        if (! $force) {
            $this->comment('Dry-run mode. Use --force to execute the deletions.');
        }

        return self::SUCCESS;
    }

    /**
     * Resolves the threshold in days with priority CLI > config > fallback,
     * validating that the result is a non-negative integer. Throws
     * `InvalidArgumentException` if the CLI override is not parseable.
     */
    private function resolveDays(string $cliOption, string $configKey, int $fallback): int
    {
        $cli = $this->option($cliOption);

        if ($cli !== null && $cli !== '') {
            if (! is_numeric($cli)) {
                throw new InvalidArgumentException(sprintf(
                    '--%s must be a non-negative integer (got "%s").',
                    $cliOption,
                    is_scalar($cli) ? (string) $cli : gettype($cli)
                ));
            }
            $cliInt = (int) $cli;
            if ($cliInt < 0) {
                throw new InvalidArgumentException(sprintf(
                    '--%s must be a non-negative integer (got %d).',
                    $cliOption,
                    $cliInt
                ));
            }

            return $cliInt;
        }

        $configValue = config("chatbot.dashboard.prune.$configKey", $fallback);
        $configInt   = (int) $configValue;

        return $configInt < 0 ? $fallback : $configInt;
    }

    /**
     * @return array{0:int,1:int} [deletedCount, wouldDeleteCount]
     */
    private function pruneSourceMissingWidgets(int $days, bool $force): array
    {
        $threshold = CarbonImmutable::now()->subDays($days);

        $query = DashboardWidget::query()
            ->where('last_refresh_status', WidgetRefreshStatus::SourceMissing->value)
            ->whereNotNull('last_refreshed_at')
            ->where('last_refreshed_at', '<', $threshold);

        return $this->reportAndSoftDeleteWidgets(
            $query,
            sprintf('source-missing widgets (status=source_missing AND last_refreshed_at < %d days ago)', $days),
            $force
        );
    }

    /**
     * @return array{0:int,1:int}
     */
    private function pruneStaleWidgets(int $days, bool $force): array
    {
        $threshold = CarbonImmutable::now()->subDays($days);

        $query = DashboardWidget::query()
            ->where('last_refresh_status', '!=', WidgetRefreshStatus::SourceMissing->value)
            ->where(function (Builder $q) use ($threshold): void {
                $q->whereNull('last_refreshed_at')
                    ->orWhere('last_refreshed_at', '<', $threshold);
            });

        return $this->reportAndSoftDeleteWidgets(
            $query,
            sprintf('stale widgets (no recent refresh in %d days, excluding source-missing)', $days),
            $force
        );
    }

    /**
     * @return array{0:int,1:int}
     */
    private function pruneEmptyDashboards(int $days, bool $force): array
    {
        $threshold = CarbonImmutable::now()->subDays($days);

        $query = Dashboard::query()
            ->where('created_at', '<', $threshold)
            ->whereDoesntHave('widgets');

        $count = (clone $query)->count();

        $this->newLine();
        $this->line(sprintf(
            '=== empty dashboards (created > %d days ago, no active widgets) ===',
            $days
        ));

        if ($count === 0) {
            $this->line('Nothing to delete.');

            return [0, 0];
        }

        $rows = (clone $query)
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'user_type', 'user_id', 'name', 'created_at'])
            ->map(fn (Dashboard $d): array => [
                $d->id,
                $d->user_type . '#' . $d->user_id,
                $d->name,
                $d->created_at?->toDateTimeString() ?? '-',
            ])
            ->all();

        $this->table(['dashboard_id', 'user', 'name', 'created_at'], $rows);
        $this->line(sprintf(
            '%d dashboard(s) %s.',
            $count,
            $force ? 'soft-deleted' : 'would be soft-deleted'
        ));

        if (! $force) {
            return [0, $count];
        }

        $deleted = (int) $query->delete();

        return [$deleted, 0];
    }

    /**
     * Hard-delete (forceDelete) over soft-deleted widgets and dashboards
     * whose `deleted_at` is older than the threshold. Purges widgets first
     * to prevent the FK cascade of a purged dashboard from accidentally
     * swallowing orphaned active widgets.
     *
     * @return array{0:int,1:int}
     */
    private function purgeSoftDeleted(int $days, bool $force): array
    {
        $threshold = CarbonImmutable::now()->subDays($days);

        $widgetQuery = DashboardWidget::onlyTrashed()
            ->where('deleted_at', '<', $threshold);
        $dashboardQuery = Dashboard::onlyTrashed()
            ->where('deleted_at', '<', $threshold);

        $widgetCount    = (clone $widgetQuery)->count();
        $dashboardCount = (clone $dashboardQuery)->count();
        $total          = $widgetCount + $dashboardCount;

        $this->newLine();
        $this->line(sprintf(
            '=== purge soft-deleted (deleted_at < %d days ago) ===',
            $days
        ));

        if ($total === 0) {
            $this->line('Nothing to purge.');

            return [0, 0];
        }

        $rows = [];
        foreach ((clone $widgetQuery)->orderBy('id')->limit(100)->get(['id', 'dashboard_id', 'block_type', 'deleted_at']) as $w) {
            $rows[] = [
                'widget',
                $w->id,
                (string) $w->dashboard_id,
                $w->block_type,
                $w->deleted_at?->toDateTimeString() ?? '-',
            ];
        }
        foreach ((clone $dashboardQuery)->orderBy('id')->limit(100)->get(['id', 'user_type', 'user_id', 'name', 'deleted_at']) as $d) {
            $rows[] = [
                'dashboard',
                $d->id,
                $d->user_type . '#' . $d->user_id,
                $d->name,
                $d->deleted_at?->toDateTimeString() ?? '-',
            ];
        }

        $this->table(['kind', 'id', 'parent_or_user', 'detail', 'deleted_at'], $rows);
        $this->line(sprintf(
            '%d row(s) %s (widgets: %d, dashboards: %d).',
            $total,
            $force ? 'hard-deleted' : 'would be hard-deleted',
            $widgetCount,
            $dashboardCount
        ));

        if (! $force) {
            return [0, $total];
        }

        $purged = (int) $widgetQuery->forceDelete() + (int) $dashboardQuery->forceDelete();

        return [$purged, 0];
    }

    /**
     * @return array{0:int,1:int}
     */
    private function reportAndSoftDeleteWidgets(Builder $query, string $headline, bool $force): array
    {
        $count = (clone $query)->count();

        $this->newLine();
        $this->line(sprintf('=== %s ===', $headline));

        if ($count === 0) {
            $this->line('Nothing to delete.');

            return [0, 0];
        }

        $rows = (clone $query)
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'dashboard_id', 'block_type', 'last_refresh_status', 'last_refreshed_at'])
            ->map(fn (DashboardWidget $w): array => [
                $w->id,
                $w->dashboard_id,
                $w->block_type,
                $w->last_refresh_status->value,
                $w->last_refreshed_at?->toDateTimeString() ?? 'never',
            ])
            ->all();

        $this->table(['widget_id', 'dashboard_id', 'block_type', 'status', 'last_refreshed_at'], $rows);
        $this->line(sprintf(
            '%d widget(s) %s.',
            $count,
            $force ? 'soft-deleted' : 'would be soft-deleted'
        ));

        if (! $force) {
            return [0, $count];
        }

        $deleted = (int) $query->delete();

        return [$deleted, 0];
    }
}
