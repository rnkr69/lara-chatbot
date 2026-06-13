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
 * @property int $id
 * @property string $user_type
 * @property int $user_id
 * @property string|null $title
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Model|\Illuminate\Contracts\Auth\Authenticatable $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Message> $messages
 */
class Conversation extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_type',
        'user_id',
        'title',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setTable(
            config('chatbot.persistence.prefix', 'chatbot_') . 'conversations'
        );

        if ($connection = config('chatbot.persistence.connection')) {
            $this->setConnection($connection);
        }

        parent::__construct($attributes);
    }

    public function user(): MorphTo
    {
        return $this->morphTo();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Filters conversations by their owning user. Accepts any Eloquent
     * Model (the host's User) — uses morphClass + key, does not require
     * the Authenticatable contract.
     */
    public function scopeForUser(Builder $query, Model $user): Builder
    {
        return $query
            ->where('user_type', $user->getMorphClass())
            ->where('user_id', $user->getKey());
    }
}
