<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int                       $id
 * @property int                       $conversation_id
 * @property string                    $action_id
 * @property string                    $tool
 * @property array<string, mixed>      $args
 * @property PendingActionStatus       $status
 * @property PendingActionConfirmation $confirmation
 * @property array<string, mixed>|null $result
 * @property Carbon                    $expires_at
 * @property Carbon                    $created_at
 * @property Carbon                    $updated_at
 * @property-read Conversation         $conversation
 */
class PendingAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'action_id',
        'tool',
        'args',
        'status',
        'confirmation',
        'result',
        'expires_at',
    ];

    protected $casts = [
        'args'         => 'array',
        'result'       => 'array',
        'status'       => PendingActionStatus::class,
        'confirmation' => PendingActionConfirmation::class,
        'expires_at'   => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setTable(
            config('chatbot.persistence.prefix', 'chatbot_') . 'pending_actions'
        );

        if ($connection = config('chatbot.persistence.connection')) {
            $this->setConnection($connection);
        }

        parent::__construct($attributes);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Filters by pending actions whose conversation belongs to `$user`.
     * The package follows the 404-not-403 doctrine (E10 D12): any attempt
     * to touch someone else's pending action translates to `findOrFail` → 404.
     */
    public function scopeForUser(Builder $query, Model $user): Builder
    {
        $conversationsTable = config('chatbot.persistence.prefix', 'chatbot_') . 'conversations';

        return $query->whereHas('conversation', function (Builder $q) use ($user, $conversationsTable): void {
            $q->where($conversationsTable . '.user_type', $user->getMorphClass())
              ->where($conversationsTable . '.user_id', $user->getKey());
        });
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PendingActionStatus::Pending->value);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<', now());
    }
}
