<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $conversation_id
 * @property MessageRole $role
 * @property array<int, array<string, mixed>> $content
 * @property array<int, array<string, mixed>>|null $tool_calls
 * @property array<int, array<string, mixed>>|null $tool_results
 * @property int $tokens_in
 * @property int $tokens_out
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Conversation $conversation
 */
class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'tool_calls',
        'tool_results',
        'tokens_in',
        'tokens_out',
    ];

    protected $casts = [
        'role'         => MessageRole::class,
        'content'      => 'array',
        'tool_calls'   => 'array',
        'tool_results' => 'array',
        'tokens_in'    => 'integer',
        'tokens_out'   => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setTable(
            config('chatbot.persistence.prefix', 'chatbot_') . 'messages'
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
}
