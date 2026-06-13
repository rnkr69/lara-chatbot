<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int                          $id
 * @property string                       $user_type
 * @property int                          $user_id
 * @property string                       $name
 * @property string                       $slug
 * @property bool                         $is_default
 * @property int                          $layout_version
 * @property array<string, mixed>|null    $metadata
 * @property \Illuminate\Support\Carbon   $created_at
 * @property \Illuminate\Support\Carbon   $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Model|\Illuminate\Contracts\Auth\Authenticatable $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, DashboardWidget> $widgets
 */
class Dashboard extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_type',
        'user_id',
        'name',
        'slug',
        'is_default',
        'layout_version',
        'metadata',
    ];

    protected $casts = [
        'is_default'     => 'bool',
        'layout_version' => 'int',
        'metadata'       => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setTable(
            config('chatbot.persistence.prefix', 'chatbot_') . 'dashboards'
        );

        if ($connection = config('chatbot.persistence.connection')) {
            $this->setConnection($connection);
        }

        parent::__construct($attributes);
    }

    /**
     * Invariant "exactly one is_default=true per user":
     *
     *   - Auto-PROMOTE (v2.1, #10): on INSERTING a dashboard, if the user
     *     has none with `is_default=true`, this one becomes the default.
     *     Without this the user's first dashboard (created by pin-from-chat,
     *     which does not pass `is_default`) stayed `false` forever — the
     *     CHANGELOG promises "exactly one true per user" and that was not
     *     honored. Only on insert: we do not re-promote a dashboard the user
     *     explicitly demoted.
     *   - Auto-DEMOTE: on saving a dashboard with `is_default=true`, the rest
     *     of the same `(user_type, user_id)` are set to false. Portable
     *     cross-DB — MySQL/MariaDB does not support unique partials. The
     *     demote bypasses model events (direct `update()`) to avoid recursion.
     */
    protected static function booted(): void
    {
        static::saving(function (self $dashboard): void {
            // Auto-promote on insert when the user has no default yet.
            if (! $dashboard->is_default && ! $dashboard->exists) {
                $hasDefault = static::query()
                    ->where('user_type', $dashboard->user_type)
                    ->where('user_id', $dashboard->user_id)
                    ->where('is_default', true)
                    ->exists();

                if (! $hasDefault) {
                    $dashboard->is_default = true;
                }
            }

            if (! $dashboard->is_default) {
                return;
            }

            static::query()
                ->where('user_type', $dashboard->user_type)
                ->where('user_id', $dashboard->user_id)
                ->when($dashboard->exists, fn (Builder $q) => $q->where('id', '!=', $dashboard->getKey()))
                ->update(['is_default' => false]);
        });
    }

    public function user(): MorphTo
    {
        return $this->morphTo();
    }

    public function widgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class);
    }

    /**
     * Filters dashboards by their owning user. Accepts any Eloquent
     * Model (the host's User) — same contract as Conversation.
     */
    public function scopeForUser(Builder $query, Model $user): Builder
    {
        return $query
            ->where('user_type', $user->getMorphClass())
            ->where('user_id', $user->getKey());
    }

    /**
     * Filters to `is_default=true`. Combinable with `forUser` to find the
     * user's preferred dashboard: `Dashboard::forUser($u)->default()->first()`.
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }
}
