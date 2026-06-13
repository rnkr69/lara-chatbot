<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Models\Message;

/**
 * Event that `ChatService` fires after persisting the assistant message
 * that closes a turn. It is the package's official hook for cost
 * telemetry and external sinks (Prometheus, BigQuery, OpenTelemetry, etc.) — the
 * host hooks listeners from its `EventServiceProvider` without touching the
 * package.
 *
 * Designed to answer the question "how much does the bot cost per month?"
 * without imposing a concrete solution:
 *
 *   - The package persists `tokens_in` / `tokens_out` per `Message` (it
 *     already existed as a column) and emits this event. Zero outbound
 *     ingestion by default.
 *   - The host decides what to do: increment Prometheus counters,
 *     stream to BigQuery, aggregate to an internal dashboard, etc.
 *   - For an ad-hoc aggregation without instrumenting anything, run
 *     `php artisan chatbot:cost-report --since=YYYY-MM-DD` — it reads the
 *     table, multiplies by `chatbot.telemetry.prices.{provider}.{model}`
 *     and spits out the cost.
 *
 * It fires **once per turn** (after persisting the assistant
 * message, before the `done` SSE). If the pipeline crashed before
 * persisting, it is NOT emitted — only what reached disk is counted.
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
