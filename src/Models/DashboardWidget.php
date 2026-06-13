<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int                          $id
 * @property int                          $dashboard_id
 * @property array<string, mixed>         $position
 * @property string                       $block_type
 * @property string|null                  $title
 * @property array<string, mixed>         $snapshot
 * @property array<string, mixed>|null    $source
 * @property string|null                  $source_signature
 * @property WidgetRefreshPolicy          $refresh_policy
 * @property Carbon|null                  $last_refreshed_at
 * @property WidgetRefreshStatus          $last_refresh_status
 * @property array<string, mixed>|null    $last_refresh_error
 * @property int                          $order_index
 * @property Carbon                       $created_at
 * @property Carbon                       $updated_at
 * @property Carbon|null                  $deleted_at
 * @property-read Dashboard               $dashboard
 */
class DashboardWidget extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'dashboard_id',
        'position',
        'block_type',
        'title',
        'snapshot',
        'source',
        'source_signature',
        'refresh_policy',
        'last_refreshed_at',
        'last_refresh_status',
        'last_refresh_error',
        'order_index',
    ];

    protected $casts = [
        'position'            => 'array',
        'snapshot'            => 'array',
        'source'              => 'array',
        'last_refresh_error'  => 'array',
        'last_refreshed_at'   => 'datetime',
        'refresh_policy'      => WidgetRefreshPolicy::class,
        'last_refresh_status' => WidgetRefreshStatus::class,
        'order_index'         => 'int',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setTable(
            config('chatbot.persistence.prefix', 'chatbot_') . 'dashboard_widgets'
        );

        if ($connection = config('chatbot.persistence.connection')) {
            $this->setConnection($connection);
        }

        parent::__construct($attributes);
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    /**
     * Widgets whose last refresh is older than the threshold (or that have
     * never been refreshed). Used by E3 to find candidates for the bulk
     * replay when opening the dashboard. A null `last_refreshed_at` is
     * considered stale — covering the theoretical case of a widget created
     * outside the normal pin flow (import, seed, etc.).
     */
    public function scopeStaleAfter(Builder $query, Carbon $threshold): Builder
    {
        return $query->where(function (Builder $q) use ($threshold): void {
            $q->whereNull('last_refreshed_at')
                ->orWhere('last_refreshed_at', '<', $threshold);
        });
    }
}
