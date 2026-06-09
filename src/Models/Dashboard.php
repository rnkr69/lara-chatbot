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
     * Invariante "exactamente uno is_default=true por usuario":
     *
     *   - Auto-PROMOTE (v2.1, #10): al INSERTAR un dashboard, si el usuario
     *     no tiene ninguno `is_default=true`, este pasa a serlo. Sin esto el
     *     primer dashboard del usuario (creado por pin-from-chat, que no pasa
     *     `is_default`) quedaba `false` para siempre — el CHANGELOG promete
     *     "exactly one true per user" y no se cumplía. Sólo en insert: no
     *     re-promovemos un dashboard que el usuario degradó explícitamente.
     *   - Auto-DEMOTE: al salvar un dashboard con `is_default=true`, el resto
     *     del mismo `(user_type, user_id)` pasa a false. Portable cross-DB —
     *     MySQL/MariaDB no soporta unique partials. El demote bypassa los
     *     eventos de modelo (`update()` directo) para evitar recursión.
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
     * Filtra dashboards por el usuario propietario. Acepta cualquier
     * Eloquent Model (User del host) — mismo contrato que Conversation.
     */
    public function scopeForUser(Builder $query, Model $user): Builder
    {
        return $query
            ->where('user_type', $user->getMorphClass())
            ->where('user_id', $user->getKey());
    }

    /**
     * Filtra al `is_default=true`. Combinable con `forUser` para encontrar
     * el dashboard preferido del usuario: `Dashboard::forUser($u)->default()->first()`.
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }
}
