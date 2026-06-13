<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Tools\Contracts\BackendTool;
use Rnkr69\LaraChatbot\Tools\ToolResult;

/**
 * Event that `ChatService` (E08) fires after EVERY tool invocation
 * (backend, frontend or MCP), regardless of its success or failure. It is the
 * package's official hook for audit/PII redaction/telemetry/bulk
 * partial-success — the host hooks listeners from its `EventServiceProvider`
 * without touching the package.
 *
 * Cross-host gap (audit log + PII redaction): hosts want to be able to
 * trace all tool invocations without patching the orchestrator. This
 * event fulfills that contract.
 *
 * Conventions:
 *
 *   - It fires once per tool call, INCLUDING authorization
 *     rejections (`ToolResult::error('unauthorized', ...)` or
 *     `error('out_of_scope', ...)`). The listener can distinguish
 *     `result->isOk()` vs `isError()`.
 *   - `args` is the array as it arrived from the LLM (what your listener will see if
 *     it logs raw). If you need redaction, do it in the listener by reading
 *     `tool->parameters()` to know which keys are sensitive.
 *   - `result` is the final `ToolResult` (post-cascade). For bulk tools,
 *     it contains the partial-success counts in `data` (see
 *     `docs/backend-tools.md`, bulk pattern).
 *   - `durationMs` measures the wall-clock of the invocation (includes validation,
 *     authorization and `handle()`). Useful for detecting slow tools.
 *   - `conversation` may be `null` in sandboxes (`chatbot:test-connection`).
 */
final class ToolInvoked
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function __construct(
        public readonly Authenticatable $user,
        public readonly BackendTool $tool,
        public readonly array $args,
        public readonly ToolResult $result,
        public readonly float $durationMs,
        public readonly ?Conversation $conversation = null,
    ) {}
}
