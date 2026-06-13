<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Contracts;

/**
 * Marker that distinguishes the tools the LLM "calls" but that actually
 * delegate their execution to the frontend widget (navigate, open modal, fill
 * form, etc.). The package treats them in E08 just like any
 * `BackendTool` for the authorization cascade (permission/scope/tenant);
 * the difference is exclusively the shape of the SSE event that
 * `ChatService` emits when the LLM invokes them:
 *
 *   - Backend tool → `event: tool_call` + backend execution → `event: tool_result`.
 *   - Frontend tool → `event: frontend_action` with `{tool, args, action_id, confirmation}`
 *                     and the LLM is returned "queued" so it can continue.
 *
 * The contract remains pure `BackendTool` in E08: `handle()` validates
 * args and authorizes, but doesn't touch the host (the widget will do the
 * real side-effect). E11 will expand this contract with concrete primitives
 * (`NavigateTool`, `HighlightTool`, ...) and a `BaseFrontendTool` that
 * automates the "shim" by returning `ToolResult::success(['status' => 'queued'])`.
 *
 * In E08 the orchestrator detects frontend tools by `instanceof FrontendTool`,
 * not by a string flag in the name — the decision was made so that
 * E11 can enrich the interface without having to redo the branching.
 */
interface FrontendTool extends BackendTool
{
}
