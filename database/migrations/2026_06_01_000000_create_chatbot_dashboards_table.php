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

            // morph user — mismo patrón que chatbot_conversations.
            $table->morphs('user');

            // Nombre visible en la sidebar ("Mi panel", "Operaciones"…).
            $table->string('name', 120);

            // Slug en la URL `/chatbot/dashboard?dashboard={slug}`. Único por
            // usuario; el slug global no es único — dos usuarios distintos
            // pueden tener su propio "operaciones".
            $table->string('slug', 140);

            // Dashboard preferido al abrir `/chatbot/dashboard` sin query.
            // Invariante "exactamente uno true por usuario" se aplica con un
            // hook `saving` en el modelo Dashboard (auto-demote del resto del
            // mismo user_type+user_id). No se usa unique parcial DB porque
            // MySQL/MariaDB no lo soporta y la portabilidad del paquete es
            // requisito (hosts eligen su DB).
            $table->boolean('is_default')->default(false);

            // Versión del shape de `position` que viajan los widgets de este
            // dashboard. Reservado: si en una v2.x cambia la grilla
            // (12-col → 24-col, x/y → row/col, etc.) los widgets viejos se
            // migran preservando este número como pivote.
            $table->unsignedInteger('layout_version')->default(1);

            // Tema, colores, refresh_default_policy, etc. Free-form JSON
            // editable por el usuario en el sidebar/settings panel (E5).
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Lookup primario: la sidebar lista los dashboards del usuario.
            // morphs() ya añade (user_type, user_id); añadimos deleted_at
            // para el patrón habitual `WHERE user … AND deleted_at IS NULL`.
            $table->index(['user_type', 'user_id', 'deleted_at']);

            // Slug único por usuario. NO global — dos usuarios pueden tener
            // su propio "operaciones".
            $table->unique(['user_type', 'user_id', 'slug']);
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
        return config('chatbot.persistence.prefix', 'chatbot_') . 'dashboards';
    }
};
