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

            $table->enum('role', ['user', 'assistant', 'tool', 'system']);

            // Bloques tipados (text, card, table, list, actions, chart, custom).
            // El orquestador serializa el contenido del mensaje a este array.
            $table->json('content');

            // Sólo poblados cuando role=assistant invoca tools (tool_calls)
            // o role=tool devuelve resultados (tool_results).
            $table->json('tool_calls')->nullable();
            $table->json('tool_results')->nullable();

            // Tokens consumidos por mensaje (informativo; el LLM los reporta).
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);

            $table->timestamps();
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
        return config('chatbot.persistence.prefix', 'chatbot_') . 'messages';
    }

    protected function conversationsTable(): string
    {
        return config('chatbot.persistence.prefix', 'chatbot_') . 'conversations';
    }
};
