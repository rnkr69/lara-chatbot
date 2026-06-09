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
 * Housekeeping del Personal Dashboard (v2.0 / E10). Borra widgets y
 * dashboards inservibles aplicando thresholds configurables. Diseñado
 * para hosts en producción que quieren limpiar drift acumulado sin
 * tocar lo activo. Receta de scheduler en `config/chatbot.php` →
 * sección `chatbot.dashboard.prune`.
 *
 * Modos (cada uno OPT-IN — el comando sin flags sale con error de uso):
 *
 *  --source-missing       Soft-delete widgets con
 *                         `last_refresh_status='source_missing'` cuyo
 *                         `last_refreshed_at` es anterior a
 *                         `prune.source_missing_days` (default 30).
 *
 *  --stale                Soft-delete widgets cuyo `last_refreshed_at`
 *                         es anterior a `prune.stale_days` (default 90)
 *                         O nunca se han refrescado, exceptuando los
 *                         que ya cuentan como `source_missing` (cubre
 *                         orphans de pin sin replay subsiguiente).
 *
 *  --empty-dashboards     Soft-delete dashboards creados hace más de
 *                         `prune.empty_dashboard_days` (default 180)
 *                         que no tienen widgets activos
 *                         (`whereDoesntHave('widgets')` sobre
 *                         soft-delete scope normal).
 *
 *  --purge-soft-deleted   Hard-delete (forceDelete) de widgets Y
 *                         dashboards cuyo `deleted_at` es anterior a
 *                         `prune.purge_soft_deleted_days` (default 30).
 *                         Aplica `onlyTrashed`; combinable con cualquier
 *                         flag anterior — los widgets recién soft-deleted
 *                         por este mismo run NO se purgan (su `deleted_at`
 *                         es de hace segundos, no de hace días).
 *
 * Dry-run por defecto; ejecuta con `--force`. El output emite una tabla
 * por modo activado con las filas candidatas (limit 100) y un resumen
 * final con totales.
 *
 * Convenciones:
 *  - Soft-delete (`$query->delete()`) cuando el modelo usa
 *    `SoftDeletes` — paridad con `DELETE /chatbot/dashboards/{slug}`.
 *  - Hard-delete (`forceDelete()`) sólo via `--purge-soft-deleted`.
 *  - Combinable: `--source-missing --stale --purge-soft-deleted` corre
 *    los tres pasos en orden (soft-delete primero, hard-delete después)
 *    sobre las filas que ya estaban soft-deleted antes de empezar.
 */
class DashboardsPruneCommand extends Command
{
    protected $signature = 'chatbot:dashboards:prune
        {--source-missing : Soft-delete widgets con last_refresh_status=source_missing sostenido}
        {--stale : Soft-delete widgets sin refresh reciente}
        {--empty-dashboards : Soft-delete dashboards sin widgets activos creados hace tiempo}
        {--purge-soft-deleted : Hard-delete (forceDelete) de filas soft-deleted hace tiempo}
        {--source-missing-days= : Threshold en días para --source-missing (override config)}
        {--stale-days= : Threshold en días para --stale (override config)}
        {--empty-dashboard-days= : Threshold en días para --empty-dashboards (override config)}
        {--purge-older-than-days= : Threshold en días para --purge-soft-deleted (override config)}
        {--force : Ejecuta los borrados (sin esto, dry-run)}';

    protected $description = 'Housekeeping del Personal Dashboard: borra widgets/dashboards inservibles.';

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
     * Resuelve el threshold en días con prioridad CLI > config > fallback,
     * validando que el resultado sea un entero no negativo. Lanza
     * `InvalidArgumentException` si el override CLI no es parseable.
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
     * Hard-delete (forceDelete) sobre widgets y dashboards soft-deleted
     * cuyo `deleted_at` es anterior al threshold. Purga widgets primero
     * para evitar que el cascade FK de un dashboard purgado se trague
     * widgets activos huérfanos por error.
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
