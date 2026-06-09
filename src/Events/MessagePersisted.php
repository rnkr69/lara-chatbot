<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Models\Message;

/**
 * Evento que el `ChatService` dispara tras persistir el assistant message
 * que cierra un turn. Es el gancho oficial del paquete para telemetría de
 * coste y sinks externos (Prometheus, BigQuery, OpenTelemetry, etc.) — el
 * host engancha listeners desde su `EventServiceProvider` sin tocar el
 * paquete.
 *
 * Diseñado para responder a la pregunta "¿cuánto cuesta el bot al mes?"
 * sin imponer una solución concreta:
 *
 *   - El paquete persiste `tokens_in` / `tokens_out` por `Message` (ya
 *     existía como columna) y emite este evento. Cero ingestión hacia
 *     fuera por defecto.
 *   - El host decide qué hacer: incrementar contadores de Prometheus,
 *     stream a BigQuery, agregar a un dashboard interno, etc.
 *   - Para una agregación ad-hoc sin instrumentar nada, ejecutar
 *     `php artisan chatbot:cost-report --since=YYYY-MM-DD` — lee la
 *     tabla, multiplica por `chatbot.telemetry.prices.{provider}.{model}`
 *     y escupe el coste.
 *
 * Se dispara **una vez por turn** (después de persistir el assistant
 * message, antes del `done` SSE). Si la pipeline crasheó antes de
 * persistir, NO se emite — sólo lo que llegó al disco se contabiliza.
 */
final class MessagePersisted
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly Conversation $conversation,
        public readonly Message $message,
        public readonly ?string $provider,
        public readonly ?string $model,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
    ) {}
}
