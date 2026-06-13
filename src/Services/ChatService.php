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
 * Orchestrator of a chat message's lifecycle (E08 ROADMAP §5/E08).
 *
 * Responsibility: given a `Conversation`, a user message and the current
 * page context, produce the stream of `SseEvent`s that the endpoint
 * (`/chatbot/stream`, E09) forwards to the client, and leave the conversation
 * persisted with the complete turn (user + assistant + tool calls/results).
 *
 * Flow steps:
 *
 *   1. Persist the user message.
 *   2. Build the history (limited to `chatbot.limits.history_messages`).
 *   3. Resolve the user's tools via `ToolRegistry::forUser`. Filters out
 *      backend tools with `confirmation != Auto` (v1 limitation).
 *   4. Build `PromptOptions` with per-conversation override
 *      (`metadata.provider/model`) and `maxSteps`/`maxTokens`/`locale` from
 *      config / the User.
 *   5. Call `LlmGateway::streamChat`.
 *   6. For each Prism `StreamEvent`:
 *      - `TextDeltaEvent`   → `SseEvent::text` and accumulates text.
 *      - `ToolCallEvent`    → runs the cascade (`execute` or `handle`),
 *                              dispatches `ToolInvoked`, and emits
 *                              `frontend_action` (FrontendTool) or
 *                              `tool_call` (backend) depending on the type. The
 *                              result is pushed into a per-name FIFO that
 *                              the Prism closure consumes afterwards.
 *      - `ToolResultEvent`  → emits `tool_result` for backend; for
 *                              frontend it emits nothing extra (`frontend_action`
 *                              was already emitted). The data is accumulated
 *                              to persist the assistant message.
 *      - `ErrorEvent`       → `SseEvent::error`. Recoverable=continues.
 *      - `StreamEndEvent`   → captures usage; the done is emitted after
 *                              persisting.
 *   7. Persist the assistant message with `content`, `tool_calls`,
 *      `tool_results` and tokens.
 *   8. Emit `SseEvent::done` with `message_id` and `usage`.
 *
 * Each `Prism\Prism\Tool` closure (created by `PrismToolFactory`)
 * returns the serialization of the precomputed `ToolResult` so that the
 * LLM closes the step coherently — the cascade is NOT executed twice.
 *
 * Cross-host gap (E08): `ToolInvoked` is dispatched for EACH tool
 * invocation, including authorization rejections. The host hooks listeners
 * for audit/PII from its `EventServiceProvider`.
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
            yield SseEvent::error("Unknown tool: {$name}", 'unknown_tool');
            // Push an error so that, if Prism ends up calling the closure
            // (rare case after an error), it doesn't hang.
            $resultBuffer[$name][] = ToolResult::error('runtime', "Unknown tool: {$name}");
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
                // Merge the data that `handle()` may have returned into the
                // `frontend_action` args (E11/§4): pure UI primitives
                // return `success([])` and the args are left as-is; FE tools
                // with backend logic (DownloadFileTool signs a URL,
                // for example) return `success(['download_url' => ...])` and those
                // fields reach the widget. On collision, `result->data` wins
                // (it is a backend-signed/validated value, not the LLM's).
                $payloadArgs = $result->data === [] ? $args : array_merge($args, $result->data);

                // Finding #25 (1.1.4): the name that travels in
                // `frontend_action.tool` may diverge from the tool's
                // `name()` — a subclass of `DownloadFileTool` (e.g.
                // `DownloadManifestTool` that adds an ownership-check) can
                // override `name()` with its own name so the LLM
                // discovers it, but the widget only knows the bundle's
                // canonical primitive (`'download_file'`). The
                // `frontendPrimitiveName()` hook (default = `name()`) lets the
                // subclass declare the correct dispatch without the host
                // having to register a custom handler in
                // `Chatbot.registerTool`.
                $primitiveName = $tool instanceof BaseFrontendTool
                    ? $tool->frontendPrimitiveName()
                    : $name;

                $confirmation = $tool->confirmation();

                // E16: for `confirm`/`manual` frontend tools we persist a
                // pending action; the LLM sees `awaiting_user` (not `queued`) and
                // will know on the next turn whether the user accepted/rejected.
                // The SSE event's `action_id` is the persisted UUID — the
                // widget uses it as a handle when calling
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

                // Auto confirmation (v1.1.3 #16): we persist a pending
                // action `Confirmed` so that the widget can POST-back
                // if the primitive fails. The `Confirmed` transitions to
                // `Executed` when a result with `ok:false` arrives; the LLM
                // sees `[FAILED]` on the next turn (without breaking
                // Anthropic's `tool_use_id` matching, which already received
                // a `tool_result.queued` this turn). Happy path = no
                // POST-back, the row stays `Confirmed` forever.
                //
                // If persistence fails (typically: the host has not yet
                // migrated to the 1.1.3 schema that accepts `auto` in the
                // `confirmation` column), we degrade to the loose-UUID of the
                // 1.1.2 flow: we emit the frontend_action anyway, only
                // there will be no POST-back channel for failures.
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

            // The cascade rejected the frontend tool — the widget must not
            // execute anything. We emit `tool_result` so the host sees the
            // rejection (it is an informational channel) and return the error to the LLM.
            yield SseEvent::toolResult($name, false, (string) ($result->errorMessage ?? $result->errorCategory));
            $resultBuffer[$name][] = $result;

            return;
        }

        yield SseEvent::toolCall($name, $args);

        // v2.0 (E1) — if the tool returned typed blocks in its ToolResult,
        // we emit them as `block` frames so the widget paints them
        // (dormant rails in v1.x: the shape existed but was not serialized).
        // Each block is enriched with:
        //   - id: fresh UUID. The client uses it as a handle (pin, scroll).
        //         If the tool already brought an id in the raw array, we ignore it
        //         by contract (the author must NOT set it — see plan §4.1).
        //   - source: `{tool, args, page_context_keys}`. Consumed by the replay
        //         engine (E3) when the block is pinned to the dashboard.
        //   - pinnable: true only when the tool declares `pinnable() === true`
        //         AND `confirmation() === Auto`. Enforcement upstream: tools
        //         that mutate (confirm/manual) never propagate the flag even if
        //         they override `pinnable()` by mistake (plan §9, risks).
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

            // v2.1.2 (#27) — `block_ordinal`: the block's 0-based position
            // among those of its same type WITHIN this `ToolResult`. It is the
            // stable half of the `{block_type, ordinal}` descriptor with which
            // the replay (E3) re-locates the pinned block when a tool
            // emits several (KPIs + chart — the dashboard's canonical case).
            // The `id` is no good for that: `Str::uuid()` generates a new one per
            // invocation, so it never matches between the pin and a later replay.
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

                // v2.2.1 (PR-B) — passthrough of the `meta` bag that the tool stamps
                // on the raw block. The orchestrator does not interpret it: the
                // canonical rail today is `meta.side_effects` (5 dashboard tools) and the
                // widget bundle raises it to a `CustomEvent`. v1.x tools
                // without `meta` are unaffected.
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

        // For frontend tools the informational event was already emitted in
        // `onToolCall` (`frontend_action` or rejection `tool_result`). We
        // emit nothing extra when receiving the ToolResultEvent — the LLM is
        // consuming it internally.
        if ($tool instanceof FrontendTool) {
            return;
        }

        $ok      = $event->success;
        $summary = $this->summariseResult($event->toolResult->result);

        yield SseEvent::toolResult($name, $ok, $summary);
    }

    /**
     * Converts the `ToolResultEvent.toolResult.result` (string|int|float|array|null)
     * to a short string for the `tool_result` SSE event. For arrays with a
     * `status` key it returns that marker; for the rest it truncates to 120 chars.
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
            // BaseBackendTool exposes `execute()` with the full cascade
            // (validate → permission → tenant → handle). Tools that
            // implement `BackendTool` directly (e.g. McpBackendTool)
            // don't have it — we fall back to `handle()`.
            if (method_exists($tool, 'execute')) {
                /** @var ToolResult $result */
                $result = $tool->execute($args, $ctx);

                return $result;
            }

            return $tool->handle($args, $ctx);
        } catch (Throwable $e) {
            // v1.1 (findings #2): do not leak the exception's raw message
            // to the LLM in production — a `SQLSTATE[42S22]: Column not found`
            // travels to the user and reveals the schema. Always log with a
            // correlation_id; the message to the LLM depends on APP_DEBUG.
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
                    '[chatbot] Backend tool `%s` declares confirmation=%s. In v1 only `auto` is supported for backend tools; it is omitted from the LLM catalog. (Backlog v2.)',
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

            // role=tool and role=system are skipped in v1 — the system prompt
            // is built by `SystemPromptBuilder`, and the tool_results from
            // previous turns live synthesized in the assistant's text.
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
                // E16: the builder queries this conversation's pending
                // actions to inject the
                // `## Pending actions` section on the next turn.
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
            // Silent failure → fall back to truncation. The conversation gets
            // an imperfect title instead of remaining untitled.
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
     * Generates a concise title by delegating to the LLM. By default it uses
     * the model configured in `chatbot.titles.llm_model` (typically Haiku); if
     * empty it uses the package's default model. Limits `max_tokens` and
     * `temperature` to bound cost and variance. Returns null if the
     * call fails for any reason (the layer above falls back to truncation).
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
