<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection($this->getConnection())->create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained($this->conversationsTable())
                ->cascadeOnDelete();

            // UUID público que viaja como `action_id` en el evento SSE
            // `frontend_action`. El widget lo usa como handle al confirmar
            // o reportar resultado en POST /chatbot/actions/{action_id}/confirm.
            $table->uuid('action_id')->unique();

            $table->string('tool');

            $table->json('args');

            // Ciclo de vida:
            //  - pending   → emitido al widget, esperando decisión del usuario.
            //  - confirmed → usuario aceptó, widget ejecutará la primitiva (intermedio).
            //  - rejected  → usuario rechazó (terminal).
            //  - executed  → widget ejecutó la primitiva y reportó result (terminal).
            //  - expired   → expires_at pasó sin resolución (terminal, comando cleanup).
            $table->enum('status', ['pending', 'confirmed', 'rejected', 'executed', 'expired'])
                ->default('pending');

            // Diferencia el ciclo: `confirm` = el LLM pide y el usuario aprueba/rechaza
            // ejecución automática; `manual` = el usuario debe hacerlo a mano y reportar;
            // `auto` (v1.1.3 #16) = ejecutado en el widget sin banner — el row nace
            // como `Confirmed` y sólo transita a `Executed` si la primitive falla.
            // Se persiste para que el widget renderice el banner correcto y para
            // que el TTL aplicado por el comando cleanup sea el adecuado. Usamos
            // varchar+casting PHP en vez de `enum()` porque añadir nuevos valores
            // a un ENUM SQL exige ALTER cross-driver complejos y los hosts ya
            // están protegidos por el cast a `PendingActionConfirmation`.
            $table->string('confirmation', 16);

            // Resultado que el widget reporta de vuelta tras ejecutar (confirm) o
            // marcar como hecha/no-hecha (manual). null hasta que se resuelve.
            $table->json('result')->nullable();

            $table->timestamp('expires_at')->index();

            $table->timestamps();

            // Lookups frecuentes en el endpoint confirm + cleanup.
            $table->index(['status', 'expires_at']);
            $table->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists($this->table());
    }

    public function getConnection(): ?string
    {
        return config('chatbot.persistence.connection');
    }

    protected function table(): string
    {
        return config('chatbot.persistence.prefix', 'chatbot_') . 'pending_actions';
    }

    protected function conversationsTable(): string
    {
        return config('chatbot.persistence.prefix', 'chatbot_') . 'conversations';
    }
};
