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
            $table->morphs('user'); // user_type (string) + user_id (unsignedBigInteger) + index
            $table->string('title')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
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
        return config('chatbot.persistence.prefix', 'chatbot_') . 'conversations';
    }
};
