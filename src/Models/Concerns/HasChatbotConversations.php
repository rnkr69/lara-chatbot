<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Rnkr69\LaraChatbot\Models\Conversation;

/**
 * Trait opcional para el modelo `User` del host. Expone la relación inversa
 * de la asociación polimórfica que `Conversation` declara con `morphTo()`.
 *
 * Uso:
 *
 *     // app/Models/User.php
 *     use Rnkr69\LaraChatbot\Models\Concerns\HasChatbotConversations;
 *
 *     class User extends Authenticatable
 *     {
 *         use HasChatbotConversations;
 *     }
 *
 *     // En un controlador del host:
 *     $user->chatbotConversations()->latest()->paginate();
 *
 * No es obligatorio: el paquete funciona sin que el host añada el trait
 * porque las consultas internas usan `Conversation::forUser($user)`.
 */
trait HasChatbotConversations
{
    public function chatbotConversations(): MorphMany
    {
        return $this->morphMany(Conversation::class, 'user');
    }
}
