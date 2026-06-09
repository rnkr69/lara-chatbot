<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs;

use Generator;
use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Models\MessageRole;
use Rnkr69\LaraChatbot\Services\ChatService;
use Rnkr69\LaraChatbot\Sse\SseEvent;

/**
 * Spy de ChatService usado por los feature tests de E14 para capturar el
 * `page_context` que el controller forwarda tras la sanitización (D13). No
 * hace llamada al LLM: persiste un assistant message canned y emite un
 * único `done` SSE event para cerrar la respuesta limpiamente.
 *
 * No extiende `ChatService` para evitar arrastrar sus dependencias en el
 * constructor; el bind del container con `instance()` reemplaza al servicio
 * real para el ciclo de vida del request del test.
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
