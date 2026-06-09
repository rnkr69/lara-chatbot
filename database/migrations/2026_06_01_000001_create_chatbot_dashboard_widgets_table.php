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

            $table->foreignId('dashboard_id')
                ->constrained($this->dashboardsTable())
                ->cascadeOnDelete();

            // Layout en la grilla 12-col de gridstack.js: `{x, y, w, h}`. El
            // shape concreto vive en el frontend (E5) pero la persistencia
            // es free-form para no acoplar el schema al renderer.
            $table->json('position');

            // Tipo del block subyacente: `table`, `kpi`, `chart`, `card`,
            // `list`. El renderer del dashboard lo despacha al mismo
            // `renderBlock()` que el widget flotante usa (cero duplicación).
            $table->string('block_type');

            // Override editable por el usuario desde el header del widget.
            // null → renderer infiere uno (primer header de tabla,
            // LLM-summary, etc.).
            $table->string('title', 180)->nullable();

            // Snapshot persistido al pinear (y reemplazado al success de un
            // replay). Shape: `{ data, captured_at, byte_size }`. El cap
            // `config('chatbot.dashboard.snapshot_max_bytes')` lo enforce
            // E4 al recibir el POST `/widgets`; aquí sólo guardamos.
            $table->json('snapshot');

            // Metadata para re-ejecutar el tool: `{ tool, args,
            // page_context_snapshot, captured_scope }`. null cuando el
            // block es estático (no procede de un tool) — caso poco común
            // en pinned widgets pero permitido para futuros casos.
            $table->json('source')->nullable();

            // sha256(tool, args canonical) — dedupe por usuario+dashboard.
            // 64 chars hex. Canonicalización: ksort recursivo en
            // asociativos, preservar orden en listas (page=1 ≠ page=2).
            // Compute helper: `Rnkr69\LaraChatbot\Dashboard\SourceSignature::for()`.
            // null cuando `source` es null (block estático).
            $table->string('source_signature', 64)->nullable();

            // `on_open` (default) | `manual` | `never`. varchar+cast PHP en
            // vez de enum() SQL — mismo patrón que `confirmation` en
            // chatbot_pending_actions (#16): evolucionar el set no exige
            // ALTER cross-driver. El cast a WidgetRefreshPolicy enum protege
            // a los hosts del valor crudo.
            $table->string('refresh_policy', 16)->default('on_open');

            // Cuándo fue el último replay exitoso. Al pinear se setea a
            // `now()` porque el snapshot recién creado ES fresco — el plan
            // §4.4 lista 'fresh' como valor inicial coherente.
            $table->timestamp('last_refreshed_at')->nullable();

            // Estado del último replay (o del pin si todavía no se ha
            // refrescado). Valores: `fresh` (default al pinear) |
            // `stale` (block_type cambió tras replay) | `error` (runtime
            // failure) | `unauthorized` (cascada auth falló) |
            // `source_missing` (tool ya no existe en el registry).
            // varchar+cast por la misma razón que refresh_policy.
            $table->string('last_refresh_status', 24)->default('fresh');

            // Detalle estructurado cuando last_refresh_status ∈ {error,
            // unauthorized}: `{category, message, captured_at}`. La UI lo
            // muestra como tooltip del badge ⚠️. null en estados ok.
            $table->json('last_refresh_error')->nullable();

            // Fallback determinístico cuando la grilla no está disponible
            // (lectores de pantalla, render sin gridstack). Default 0 →
            // creation_order break-tied por id ASC.
            $table->unsignedInteger('order_index')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // Lookup primario: pintar un dashboard = listar sus widgets no
            // borrados. cascadeOnDelete del FK cubre hard-delete del padre;
            // soft-delete del padre no cascadea automáticamente (Eloquent
            // no lo hace) — el modelo Dashboard tampoco lo simula: el query
            // `dashboard->widgets()` ya filtra por `deleted_at IS NULL` del
            // dashboard antes de cargar widgets.
            $table->index(['dashboard_id', 'deleted_at']);

            // Dedupe: "¿este usuario ya tiene un widget con estos args en
            // algún dashboard?". No es unique — el usuario puede legítimamente
            // pinear el mismo block en dos dashboards distintos.
            $table->index('source_signature');
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
        return config('chatbot.persistence.prefix', 'chatbot_') . 'dashboard_widgets';
    }

    protected function dashboardsTable(): string
    {
        return config('chatbot.persistence.prefix', 'chatbot_') . 'dashboards';
    }
};
