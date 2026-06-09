<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Services;

use Generator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Rnkr69\LaraChatbot\Events\MessagePersisted;
use Rnkr69\LaraChatbot\Events\ToolInvoked;
use Rnkr69\LaraChatbot\Llm\Exceptions\LlmException;
use Rnkr69\LaraChatbot\Llm\LlmGateway;
use Rnkr69\LaraChatbot\Llm\PromptOptions;
use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Models\Message;
use Rnkr69\LaraChatbot\Models\MessageRole;
use Rnkr69\LaraChatbot\Sse\SseEvent;
use Rnkr69\LaraChatbot\Tools\BaseFrontendTool;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\Contracts\BackendTool;
use Rnkr69\LaraChatbot\Tools\Contracts\FrontendTool;
use Rnkr69\LaraChatbot\Tools\Support\PrismToolFactory;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;
use Rnkr69\LaraChatbot\Tools\ToolResult;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Throwable;

/**
 * Orquestador del ciclo de vida de un mensaje de chat (E08 ROADMAP §5/E08).
 *
 * Responsabilidad: dada una `Conversation`, un mensaje del usuario y el
 * page context actual, producir el stream de `SseEvent` que el endpoint
 * (`/chatbot/stream`, E09) reenvía al cliente, y dejar la conversación
 * persistida con el turn completo (user + assistant + tool calls/results).
 *
 * Pasos del flujo:
 *
 *   1. Persiste el mensaje del usuario.
 *   2. Construye el historial (limita a `chatbot.limits.history_messages`).
 *   3. Resuelve las tools del usuario via `ToolRegistry::forUser`. Filtra
 *      backend tools con `confirmation != Auto` (limitación v1).
 *   4. Construye `PromptOptions` con override por conversación
 *      (`metadata.provider/model`) y `maxSteps`/`maxTokens`/`locale` de
 *      config / del User.
 *   5. Llama `LlmGateway::streamChat`.
 *   6. Para cada `StreamEvent` de Prism:
 *      - `TextDeltaEvent`   → `SseEvent::text` y acumula texto.
 *      - `ToolCallEvent`    → corre cascada (`execute` o `handle`),
 *                              dispara `ToolInvoked`, y emite
 *                              `frontend_action` (FrontendTool) o
 *                              `tool_call` (backend) según el tipo. El
 *                              resultado se mete en un FIFO por nombre que
 *                              el closure de Prism consume después.
 *      - `ToolResultEvent`  → emite `tool_result` para backend; para
 *                              frontend no emite nada extra (ya se emitió
 *                              `frontend_action`). Los datos se acumulan
 *                              para persistir el assistant message.
 *      - `ErrorEvent`       → `SseEvent::error`. Recoverable=continúa.
 *      - `StreamEndEvent`   → captura usage; el done se emite tras
 *                              persistir.
 *   7. Persiste el assistant message con `content`, `tool_calls`,
 *      `tool_results` y tokens.
 *   8. Emite `SseEvent::done` con `message_id` y `usage`.
 *
 * El closure de cada `Prism\Prism\Tool` (creado por `PrismToolFactory`)
 * devuelve la serialización del `ToolResult` precomputado para que el
 * LLM cierre el step coherentemente — la cascada NO se ejecuta dos veces.
 *
 * Gap cross-host (E08): se dispara `ToolInvoked` por CADA invocación de
 * tool, incluyendo rechazos por autorización. El host engancha listeners
 * para audit/PII desde su `EventServiceProvider`.
 */
class ChatService
{
    public function __construct(
        protected LlmGateway $gateway,
        protected ToolRegistry $registry,
        protected PrismToolFactory $factory,
        protected Dispatcher $events,
        protected PendingActionStore $pendingActions,
    ) {}

    /**
     * @param  array<string, mixed>  $pageContext
     * @return Generator<int, SseEvent>
     */
    public function handle(Conversation $conversation, string $userMessage, array $pageContext = []): Generator
    {
        /** @var Authenticatable $user */
        $user = $conversation->user;

        $this->persistUserMessage($conversation, $userMessage);
        $this->maybeAutoTitle($conversation, $userMessage);

        $locale       = $this->resolveLocale($user);
        $ctx          = new ToolContext(
            user: $user,
            pageContext: $pageContext,
            conversation: $conversation,
            locale: $locale,
        );
        $tools        = $this->resolveTools($user);
        $resultBuffer = [];
        $prismTools   = array_map(
            // Arrow functions capture by value; PHP has no `use (&$x)` syntax for
            // them. The orchestrator writes results into $resultBuffer in
            // onToolCall(), but the inner closure built by PrismToolFactory needs
            // to read from the SAME array — a regular closure with
            // `use (&$resultBuffer, $ctx)` makes the wrapper, the factory and
            // the inner closure all share one array.
            function (BackendTool $tool) use (&$resultBuffer, $ctx): \Prism\Prism\Tool {
                return $this->factory->wrap($tool, $ctx, $resultBuffer);
            },
            array_values($tools),
        );

        $messages = $this->buildHistory($conversation);
        $options  = $this->buildPromptOptions($conversation, $tools, $pageContext, $locale);

        $assistantText = '';
        $toolCalls     = [];
        $toolResults   = [];
        $usage         = null;
        $callIndex     = [];

        try {
            foreach ($this->gateway->streamChat($messages, $prismTools, $options) as $event) {
                if (! $event instanceof StreamEvent) {
                    continue;
                }

                if ($event instanceof TextDeltaEvent) {
                    if ($event->delta === '') {
                        continue;
                    }
                    $assistantText .= $event->delta;
                    yield SseEvent::text($event->delta);
                    continue;
                }

                if ($event instanceof ToolCallEvent) {
                    yield from $this->onToolCall(
                        $event,
                        $tools,
                        $ctx,
                        $resultBuffer,
                        $toolCalls,
                        $callIndex,
                    );
                    continue;
                }

                if ($event instanceof ToolResultEvent) {
                    yield from $this->onToolResult(
                        $event,
                        $tools,
                        $callIndex,
                        $toolResults,
                    );
                    continue;
                }

                if ($event instanceof ErrorEvent) {
                    yield SseEvent::error($event->message, $event->errorType);
                    continue;
                }

                if ($event instanceof StreamEndEvent) {
                    $usage = $event->usage;
                }
            }
        } catch (LlmException $e) {
            yield SseEvent::error($e->getMessage(), $e->reason);
        } catch (Throwable $e) {
            yield SseEvent::error($e->getMessage() !== '' ? $e->getMessage() : 'Internal error.', 'unknown');
        }

        $assistantMessage = $this->persistAssistantMessage(
            $conversation,
            $assistantText,
            $toolCalls,
            $toolResults,
            $usage,
        );

        // Telemetry hook. Effective provider/model: per-conversation override
        // (`metadata.provider/model`) falls back to package config defaults.
        $convoMeta = is_array($conversation->metadata) ? $conversation->metadata : [];
        $this->events->dispatch(new MessagePersisted(
            user: $user,
            conversation: $conversation,
            message: $assistantMessage,
            provider: is_string($convoMeta['provider'] ?? null) ? $convoMeta['provider'] : (is_string(config('chatbot.provider')) ? config('chatbot.provider') : null),
            model: is_string($convoMeta['model'] ?? null) ? $convoMeta['model'] : (is_string(config('chatbot.model')) ? config('chatbot.model') : null),
            tokensIn: $assistantMessage->tokens_in ?? 0,
            tokensOut: $assistantMessage->tokens_out ?? 0,
        ));

        yield SseEvent::done(
            $assistantMessage->id,
            $usage instanceof \Prism\Prism\ValueObjects\Usage
                ? [
                    'prompt_tokens'     => $usage->promptTokens,
                    'completion_tokens' => $usage->completionTokens,
                ]
                : [],
            $conversation->id,
            $conversation->title,
        );
    }

    /**
     * @param  array<string, BackendTool>  $tools
     * @param  array<string, list<ToolResult>>  $resultBuffer
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @param  array<string, array<string, mixed>>  $callIndex
     * @return Generator<int, SseEvent>
     */
    protected function onToolCall(
        ToolCallEvent $event,
        array $tools,
        ToolContext $ctx,
        array &$resultBuffer,
        array &$toolCalls,
        array &$callIndex,
    ): Generator {
        $name = $event->toolCall->name;
        $args = $this->safeArguments($event->toolCall);
        $tool = $tools[$name] ?? null;

        $callIndex[$event->toolCall->id] = ['name' => $name, 'args' => $args];
        $toolCalls[] = [
            'id'   => $event->toolCall->id,
            'name' => $name,
            'args' => $args,
        ];

        if ($tool === null) {
            yield SseEvent::error("Tool desconocida: {$name}", 'unknown_tool');
            // Empuja un error para que, si Prism termina llamando al closure
            // (caso raro tras error), no se quede colgado.
            $resultBuffer[$name][] = ToolResult::error('runtime', "Tool desconocida: {$name}");
            return;
        }

        $start    = microtime(true);
        $result   = $this->executeTool($tool, $args, $ctx);
        $duration = (microtime(true) - $start) * 1000.0;

        $this->events->dispatch(new ToolInvoked(
            user: $ctx->user,
            tool: $tool,
            args: $args,
            result: $result,
            durationMs: $duration,
            conversation: $ctx->conversation,
        ));

        if ($tool instanceof FrontendTool) {
            if ($result->isOk()) {
                // Mergea los datos que `handle()` haya devuelto en los args
                // del `frontend_action` (E11/§4): primitivas puras de UI
                // devuelven `success([])` y los args quedan tal cual; tools
                // FE con lógica backend (DownloadFileTool firma una URL,
                // p.ej.) devuelven `success(['download_url' => ...])` y esos
                // campos llegan al widget. En colisión gana `result->data`
                // (es valor backend-firmado/validado, no el del LLM).
                $payloadArgs = $result->data === [] ? $args : array_merge($args, $result->data);

                // Finding #25 (1.1.4): el nombre que viaja en
                // `frontend_action.tool` puede divergir del `name()` del
                // tool — una subclase de `DownloadFileTool` (p.ej.
                // `DownloadManifestTool` que añade ownership-check) puede
                // override `name()` con un nombre propio para que el LLM lo
                // descubra, pero el widget sólo conoce el primitive
                // canónico del bundle (`'download_file'`). El hook
                // `frontendPrimitiveName()` (default = `name()`) deja a la
                // subclase declarar el dispatch correcto sin que el host
                // tenga que registrar un handler custom en
                // `Chatbot.registerTool`.
                $primitiveName = $tool instanceof BaseFrontendTool
                    ? $tool->frontendPrimitiveName()
                    : $name;

                $confirmation = $tool->confirmation();

                // E16: para frontend tools `confirm`/`manual` persistimos un
                // pending action; el LLM ve `awaiting_user` (no `queued`) y
                // sabrá en el siguiente turno si el usuario aceptó/rechazó.
                // El `action_id` del evento SSE es el UUID persistido — el
                // widget lo usa como handle al llamar a
                // `POST /chatbot/actions/{action_id}/confirm`.
                if ($confirmation !== ConfirmationLevel::Auto) {
                    $pending = $this->pendingActions->create(
                        conversation: $ctx->conversation,
                        tool: $name,
                        args: $payloadArgs,
                        confirmation: $confirmation,
                    );

                    yield SseEvent::frontendAction(
                        tool: $primitiveName,
                        args: $payloadArgs,
                        actionId: $pending->action_id,
                        confirmation: $confirmation->value,
                    );

                    $resultBuffer[$name][] = ToolResult::awaitingUser(
                        $pending->action_id,
                        sprintf('Awaiting user %s for tool %s', $confirmation->value, $name),
                    );

                    return;
                }

                // Confirmación Auto (v1.1.3 #16): persistimos un pending
                // action `Confirmed` para que el widget pueda hacer POST-back
                // si la primitive falla. El `Confirmed` se transita a
                // `Executed` cuando llega un result con `ok:false`; el LLM
                // ve `[FAILED]` en el siguiente turno (sin romper el
                // matching de `tool_use_id` de Anthropic, que ya recibió
                // este turno un `tool_result.queued`). Happy path = no
                // POST-back, el row se queda en `Confirmed` para siempre.
                //
                // Si la persistencia falla (típicamente: el host no migró
                // todavía al esquema 1.1.3 que acepta `auto` en la columna
                // `confirmation`), degradamos al UUID-suelto del flow
                // 1.1.2: emitimos el frontend_action igualmente, sólo que
                // no habrá canal de POST-back para fallos.
                try {
                    $pending = $this->pendingActions->createAutoConfirmed(
                        conversation: $ctx->conversation,
                        tool: $name,
                        args: $payloadArgs,
                    );
                    $actionId = $pending->action_id;
                } catch (\Throwable) {
                    $actionId = (string) Str::uuid();
                }

                yield SseEvent::frontendAction(
                    tool: $primitiveName,
                    args: $payloadArgs,
                    actionId: $actionId,
                    confirmation: ConfirmationLevel::Auto->value,
                );

                $resultBuffer[$name][] = ToolResult::success([
                    'status'    => 'queued',
                    'action_id' => $actionId,
                ]);

                return;
            }

            // Cascade rechazó la frontend tool — el widget no debe ejecutar
            // nada. Emitimos `tool_result` para que el host vea el rechazo
            // (es un canal informativo) y devolvemos el error al LLM.
            yield SseEvent::toolResult($name, false, (string) ($result->errorMessage ?? $result->errorCategory));
            $resultBuffer[$name][] = $result;

            return;
        }

        yield SseEvent::toolCall($name, $args);

        // v2.0 (E1) — si la tool devolvió blocks tipados en su ToolResult,
        // los emitimos como frames `block` para que el widget los pinte
        // (rieles dormidos en v1.x: el shape existía pero no se serializaba).
        // Cada block se enriquece con:
        //   - id: UUID fresco. El cliente lo usa como handle (pin, scroll).
        //         Si la tool ya trajera un id en el array crudo, lo ignoramos
        //         por contrato (el author NO debe setearlo — ver plan §4.1).
        //   - source: `{tool, args, page_context_keys}`. Lo consume el replay
        //         engine (E3) cuando el block se pinea al dashboard.
        //   - pinnable: sólo true cuando la tool declara `pinnable() === true`
        //         Y `confirmation() === Auto`. Enforcement aguas arriba: tools
        //         que mutan (confirm/manual) jamás propagan el flag aunque
        //         override `pinnable()` por descuido (plan §9, riesgos).
        if ($result->isOk() && $result->blocks !== []) {
            // v2.1 (#11) — the dashboard opt-out must be clean: with
            // `chatbot.dashboard.enabled = false` no block is ever stamped
            // `pinnable`, so the widget never mounts the 📌 button and the
            // CHANGELOG promise ("`pinnable()` is silently ignored") holds.
            // This is the authoritative gate — the orchestrator is the only
            // thing that stamps `pinnable`.
            $pinnable = $tool->pinnable()
                && $tool->confirmation() === ConfirmationLevel::Auto
                && (bool) config('chatbot.dashboard.enabled', true);
            $source = [
                'tool'               => $name,
                'args'               => $args,
                'page_context_keys'  => array_values(array_filter(
                    array_keys($ctx->pageContext),
                    'is_string',
                )),
            ];

            // v2.1.2 (#27) — `block_ordinal`: posición 0-based del bloque
            // entre los de su mismo tipo DENTRO de este `ToolResult`. Es la
            // mitad estable del descriptor `{block_type, ordinal}` con el que
            // el replay (E3) re-localiza el bloque pineado cuando un tool
            // emite varios (KPIs + gráfica — el caso canónico del dashboard).
            // El `id` no sirve para eso: `Str::uuid()` genera uno nuevo por
            // invocación, así que jamás casa entre el pin y un replay posterior.
            $ordinalByType = [];

            foreach ($result->blocks as $rawBlock) {
                $blockType = $rawBlock['type'] ?? null;
                if (! is_string($blockType) || $blockType === '') {
                    continue;
                }
                $blockData = $rawBlock['data'] ?? [];
                if (! is_array($blockData)) {
                    $blockData = [];
                }

                $ordinal = $ordinalByType[$blockType] ?? 0;
                $ordinalByType[$blockType] = $ordinal + 1;

                // v2.2.1 (PR-B) — passthrough del bag `meta` que el tool estampe
                // en el raw block. El orquestador no lo interpreta: el carril
                // canónico hoy es `meta.side_effects` (5 tools dashboard) y el
                // bundle del widget lo levanta a un `CustomEvent`. Tools v1.x
                // sin `meta` siguen igual.
                $rawMeta = $rawBlock['meta'] ?? null;
                $meta = is_array($rawMeta) && $rawMeta !== [] ? $rawMeta : null;

                yield SseEvent::block(
                    type: $blockType,
                    data: $blockData,
                    id: (string) Str::uuid(),
                    source: $source,
                    pinnable: $pinnable ?: null,
                    blockOrdinal: $ordinal,
                    meta: $meta,
                );
            }
        }

        $resultBuffer[$name][] = $result;
    }

    /**
     * @param  array<string, BackendTool>  $tools
     * @param  array<string, array<string, mixed>>  $callIndex
     * @param  array<int, array<string, mixed>>  $toolResults
     * @return Generator<int, SseEvent>
     */
    protected function onToolResult(
        ToolResultEvent $event,
        array $tools,
        array $callIndex,
        array &$toolResults,
    ): Generator {
        $callId = $event->toolResult->toolCallId;
        $meta   = $callIndex[$callId] ?? null;
        $name   = is_array($meta) && isset($meta['name']) && is_string($meta['name'])
            ? $meta['name']
            : null;

        $payload = [
            'id'     => $callId,
            'name'   => $name,
            'result' => $event->toolResult->result,
        ];
        $toolResults[] = $payload;

        if ($name === null) {
            return;
        }

        $tool = $tools[$name] ?? null;

        // Para frontend tools el evento informativo ya se emitió en
        // `onToolCall` (`frontend_action` o `tool_result` de rechazo). No
        // emitimos nada extra al recibir el ToolResultEvent — el LLM lo
        // está consumiendo internamente.
        if ($tool instanceof FrontendTool) {
            return;
        }

        $ok      = $event->success;
        $summary = $this->summariseResult($event->toolResult->result);

        yield SseEvent::toolResult($name, $ok, $summary);
    }

    /**
     * Convierte el `ToolResultEvent.toolResult.result` (string|int|float|array|null)
     * a un string corto para el evento SSE `tool_result`. Para arrays con
     * clave `status` devuelve esa marca; para el resto trunca a 120 chars.
     *
     * @param  string|int|float|array<string, mixed>|null  $rawResult
     */
    protected function summariseResult($rawResult): string
    {
        if ($rawResult === null) {
            return '';
        }

        if (is_array($rawResult)) {
            if (isset($rawResult['status']) && is_string($rawResult['status'])) {
                return $rawResult['status'];
            }

            $encoded = json_encode($rawResult);
            $rawResult = is_string($encoded) ? $encoded : '';
        } elseif (! is_string($rawResult)) {
            $rawResult = (string) $rawResult;
        }

        if ($rawResult === '') {
            return '';
        }

        $decoded = json_decode($rawResult, true);

        if (is_array($decoded) && isset($decoded['status']) && is_string($decoded['status'])) {
            return $decoded['status'];
        }

        return mb_strlen($rawResult) > 120
            ? mb_substr($rawResult, 0, 117) . '...'
            : $rawResult;
    }

    protected function executeTool(BackendTool $tool, array $args, ToolContext $ctx): ToolResult
    {
        try {
            // BaseBackendTool expone `execute()` con la cascada completa
            // (validate → permission → tenant → handle). Las tools que
            // implementan `BackendTool` directamente (e.g. McpBackendTool)
            // no la tienen — caemos a `handle()`.
            if (method_exists($tool, 'execute')) {
                /** @var ToolResult $result */
                $result = $tool->execute($args, $ctx);

                return $result;
            }

            return $tool->handle($args, $ctx);
        } catch (Throwable $e) {
            // v1.1 (findings #2): no filtrar el mensaje crudo de la excepción
            // al LLM en producción — un `SQLSTATE[42S22]: Column not found`
            // viaja al usuario y revela esquema. Loguear siempre con un
            // correlation_id; el mensaje al LLM depende de APP_DEBUG.
            $correlationId = (string) Str::uuid();

            Log::error('[chatbot] tool execution threw', [
                'tool'           => $tool->name(),
                'correlation_id' => $correlationId,
                'exception'      => $e::class,
                'message'        => $e->getMessage(),
                'file'           => $e->getFile() . ':' . $e->getLine(),
                'trace'          => $e->getTraceAsString(),
            ]);

            $debug = (bool) config('app.debug', false);

            $visible = $debug
                ? ($e->getMessage() !== '' ? $e->getMessage() : $e::class)
                : "Internal tool error (ref: {$correlationId}). Ask the operator to check the logs with this id.";

            return ToolResult::error('runtime', $visible);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function safeArguments(\Prism\Prism\ValueObjects\ToolCall $call): array
    {
        try {
            return $call->arguments();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, BackendTool>
     */
    protected function resolveTools(Authenticatable $user): array
    {
        $allowed = $this->registry->forUser($user);
        $filtered = [];

        foreach ($allowed as $name => $tool) {
            if (! $tool instanceof FrontendTool && $tool->confirmation() !== ConfirmationLevel::Auto) {
                Log::warning(sprintf(
                    '[chatbot] La backend tool `%s` declara confirmation=%s. En v1 sólo `auto` está soportado para backend tools; se omite del catálogo del LLM. (Backlog v2.)',
                    $name,
                    $tool->confirmation()->value,
                ));

                continue;
            }

            $filtered[$name] = $tool;
        }

        return $filtered;
    }

    /**
     * @return array<int, \Prism\Prism\Contracts\Message>
     */
    protected function buildHistory(Conversation $conversation): array
    {
        $limit = (int) config('chatbot.limits.history_messages', 20);
        if ($limit < 1) {
            $limit = 20;
        }

        $records = $conversation->messages()
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        $history = [];

        foreach ($records as $message) {
            /** @var Message $message */
            $text = $this->extractText($message->content ?? []);

            if ($message->role === MessageRole::User) {
                $history[] = new UserMessage($text);
                continue;
            }

            if ($message->role === MessageRole::Assistant) {
                $history[] = new AssistantMessage($text);
                continue;
            }

            // role=tool y role=system se obvian en v1 — el system prompt
            // lo construye `SystemPromptBuilder`, y los tool_results de
            // turnos previos viven sintetizados en el texto del assistant.
        }

        return $history;
    }

    /**
     * @param  array<int, array<string, mixed>>|array<string, mixed>|null  $content
     */
    protected function extractText($content): string
    {
        if (! is_array($content)) {
            return '';
        }

        $parts = [];

        foreach ($content as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $type = $entry['type'] ?? null;
            $text = $entry['text'] ?? null;

            if ($type === 'text' && is_string($text)) {
                $parts[] = $text;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param  array<string, BackendTool>  $tools
     * @param  array<string, mixed>  $pageContext
     */
    protected function buildPromptOptions(
        Conversation $conversation,
        array $tools,
        array $pageContext,
        ?string $locale,
    ): PromptOptions {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];

        $provider = is_string($metadata['provider'] ?? null) ? $metadata['provider'] : null;
        $model    = is_string($metadata['model'] ?? null) ? $metadata['model'] : null;

        $maxSteps  = (int) config('chatbot.limits.max_steps', 5);
        $maxTokens = (int) config('chatbot.limits.max_tokens', 4096);

        return new PromptOptions(
            provider:  $provider,
            model:     $model,
            promptContext: [
                'user'         => $conversation->user,
                'pageContext'  => $pageContext,
                'tools'        => array_values($tools),
                'locale'       => $locale,
                // E16: el builder consulta pending actions de esta
                // conversación para inyectar la sección
                // `## Pending actions` en el siguiente turno.
                'conversation' => $conversation,
            ],
            maxSteps:  $maxSteps > 0 ? $maxSteps : null,
            maxTokens: $maxTokens > 0 ? $maxTokens : null,
        );
    }

    protected function resolveLocale(Authenticatable $user): ?string
    {
        $locale = $user->locale ?? null;

        if (is_string($locale) && $locale !== '') {
            return $locale;
        }

        if (function_exists('app')) {
            $appLocale = app()->getLocale();

            return is_string($appLocale) && $appLocale !== '' ? $appLocale : null;
        }

        return null;
    }

    protected function persistUserMessage(Conversation $conversation, string $userMessage): Message
    {
        return $conversation->messages()->create([
            'role'    => MessageRole::User,
            'content' => [['type' => 'text', 'text' => $userMessage]],
        ]);
    }

    /**
     * Derive a human-readable title from the very first user message of a
     * conversation when the title column is null. Subsequent messages are
     * ignored — once a conversation has a title (whether auto-derived,
     * manually set via the CRUD endpoint, or from an external service) it
     * stays put.
     */
    protected function maybeAutoTitle(Conversation $conversation, string $userMessage): void
    {
        if ($conversation->title !== null) {
            return;
        }
        if ($conversation->messages()->where('role', MessageRole::User)->count() !== 1) {
            return;
        }
        $title = $this->deriveTitle($userMessage);
        if ($title === '') {
            return;
        }
        $conversation->title = $title;
        $conversation->save();
    }

    protected function deriveTitle(string $userMessage): string
    {
        $maxLen = (int) config('chatbot.titles.max_length', 60);
        if ($maxLen < 10) {
            $maxLen = 60;
        }

        $normalized = trim((string) preg_replace('/\s+/u', ' ', $userMessage));
        if ($normalized === '') {
            return (string) __('chatbot::chatbot.untitled_conversation');
        }

        if ((bool) config('chatbot.titles.use_llm', false)) {
            $llmTitle = $this->deriveTitleViaLlm($normalized, $maxLen);
            if ($llmTitle !== null && $llmTitle !== '') {
                return $llmTitle;
            }
            // Falla silenciosa → fallback a truncado. La conversación gana
            // un título imperfecto en lugar de quedar sin título.
        }

        return $this->truncateForTitle($normalized, $maxLen);
    }

    protected function truncateForTitle(string $normalized, int $maxLen): string
    {
        if (mb_strlen($normalized) <= $maxLen) {
            return $normalized;
        }
        $cut = mb_substr($normalized, 0, $maxLen);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > (int) ($maxLen * 0.6)) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }
        return rtrim($cut) . '…';
    }

    /**
     * Genera un título conciso delegando al LLM. Por defecto usa el modelo
     * configurado en `chatbot.titles.llm_model` (típicamente Haiku); si está
     * vacío usa el modelo por defecto del paquete. Limita `max_tokens` y
     * `temperature` para acotar el coste y la varianza. Devuelve null si la
     * llamada falla por cualquier motivo (la capa superior cae al truncado).
     */
    protected function deriveTitleViaLlm(string $userMessage, int $maxLen): ?string
    {
        $model = config('chatbot.titles.llm_model');
        $prompt = (string) config(
            'chatbot.titles.llm_prompt',
            'Generate a short 3-5 word title summarizing the following user message. '
            . 'Reply with only the title — no quotes, no punctuation, no explanation.'
        );

        try {
            $response = $this->gateway->chat(
                messages: [new UserMessage($userMessage)],
                tools: [],
                options: new PromptOptions(
                    model: is_string($model) && $model !== '' ? $model : null,
                    systemPrompt: $prompt,
                    maxTokens: 32,
                    temperature: 0.3,
                ),
            );
        } catch (Throwable $e) {
            Log::debug('[chatbot] title LLM call failed; falling back to truncate', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $text = trim($response->text);
        // Trim quotes/markdown punctuation that LLMs often add despite the rule.
        $text = trim($text, "\"'`.; \t\n\r\0\x0B");
        if ($text === '') {
            return null;
        }
        return $this->truncateForTitle($text, $maxLen);
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @param  array<int, array<string, mixed>>  $toolResults
     */
    protected function persistAssistantMessage(
        Conversation $conversation,
        string $assistantText,
        array $toolCalls,
        array $toolResults,
        ?\Prism\Prism\ValueObjects\Usage $usage,
    ): Message {
        $content = $assistantText !== ''
            ? [['type' => 'text', 'text' => $assistantText]]
            : [];

        return $conversation->messages()->create([
            'role'         => MessageRole::Assistant,
            'content'      => $content,
            'tool_calls'   => $toolCalls === [] ? null : $toolCalls,
            'tool_results' => $toolResults === [] ? null : $toolResults,
            'tokens_in'    => $usage?->promptTokens ?? 0,
            'tokens_out'   => $usage?->completionTokens ?? 0,
        ]);
    }

}
