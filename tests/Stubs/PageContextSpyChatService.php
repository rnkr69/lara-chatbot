<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs;

use Generator;
use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Models\MessageRole;
use Rnkr69\LaraChatbot\Services\ChatService;
use Rnkr69\LaraChatbot\Sse\SseEvent;

/**
 * ChatService spy used by the E14 feature tests to capture the
 * `page_context` the controller forwards after sanitization (D13). It makes
 * no LLM call: it persists a canned assistant message and emits a single
 * `done` SSE event to close the response cleanly.
 *
 * It does not extend `ChatService` to avoid pulling in its constructor
 * dependencies; the container bind with `instance()` replaces the real
 * service for the lifetime of the test request.
 */
class PageContextSpyChatService extends ChatService
{
    /** @var array<string, mixed>|null */
    public ?array $captured = null;

    public function __construct() {}

    /**
     * @param  array<string, mixed>  $pageContext
     */
    public function handle(Conversation $conversation, string $userMessage, array $pageContext = []): Generator
    {
        $this->captured = $pageContext;

        $message = $conversation->messages()->create([
            'role'    => MessageRole::Assistant,
            'content' => [['type' => 'text', 'text' => 'ok']],
        ]);

        yield SseEvent::done($message->id, []);
    }
}
