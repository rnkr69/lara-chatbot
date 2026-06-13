<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Rnkr69\LaraChatbot\Models\Conversation;

/**
 * Optional trait for the host's `User` model. Exposes the inverse side of
 * the polymorphic association that `Conversation` declares with `morphTo()`.
 *
 * Usage:
 *
 *     // app/Models/User.php
 *     use Rnkr69\LaraChatbot\Models\Concerns\HasChatbotConversations;
 *
 *     class User extends Authenticatable
 *     {
 *         use HasChatbotConversations;
 *     }
 *
 *     // In a host controller:
 *     $user->chatbotConversations()->latest()->paginate();
 *
 * Not required: the package works without the host adding the trait
 * because internal queries use `Conversation::forUser($user)`.
 */
trait HasChatbotConversations
{
    public function chatbotConversations(): MorphMany
    {
        return $this->morphMany(Conversation::class, 'user');
    }
}
