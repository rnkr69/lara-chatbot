<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools;

use Rnkr69\LaraChatbot\Tools\Contracts\FrontendTool;

/**
 * Base class for implementing `FrontendTool` (E11) with the "shim" wired.
 *
 * A frontend tool is a tool that the LLM "invokes" but that the orchestrator
 * does NOT execute as a backend action (it doesn't touch the DB, doesn't
 * mutate host state): the real execution is done by the widget in the
 * browser. The authorization cascade (validate args + permission + tenant)
 * is applied just like in `BaseBackendTool` — we inherit the cascade with
 * `extends BaseBackendTool` — but `handle()` by default returns a neutral
 * `ToolResult::success([])`, without touching anything in the host. The
 * orchestrator (`ChatService::onToolCall`, E08):
 *
 *   1. Runs the `execute()` cascade (validate → permission → tenant → handle).
 *   2. If OK, emits `event: frontend_action` with `{tool, args + result.data,
 *      action_id, confirmation}` so the widget materializes the effect.
 *   3. Puts `success(['status' => 'queued', 'action_id' => $uuid])` into the
 *      buffer that returns to the LLM, so the step closes coherently.
 *
 * Decision §4/E11: `BaseFrontendTool extends BaseBackendTool` (DRY) instead
 * of duplicating the cascade in a parallel class. The `FrontendTool` interface
 * already extends `BackendTool` (D8, E08) and the orchestrator already
 * branches by `instanceof FrontendTool` — extending `BaseBackendTool` reuses
 * the existing cascade without new couplings.
 *
 * Use cases:
 *
 *   1. Pure UI primitives (NavigateTool, HighlightTool, ShowToastTool,
 *      etc.): don't override `handle()`. The base returns `success([])` and the
 *      orchestrator emits `frontend_action` with the LLM's original args.
 *
 *   2. Primitives with backend logic that supplies data to the widget
 *      (DownloadFileTool signs a URL, for example): override `handle()`
 *      to return `success(['download_url' => $signed, 'expires_at' => $iso])`.
 *      Those fields are MERGED into `frontend_action.args` before being emitted
 *      to the widget — the LLM sees a neutral "queued" but the widget receives
 *      the data needed to execute the action.
 *
 * Hosts can extend `BaseFrontendTool` to create their own FE tools
 * (e.g. `OpenInvoiceModalTool`) reusing the cascade and the shim.
 */
abstract class BaseFrontendTool extends BaseBackendTool implements FrontendTool
{
    /**
     * Default hook for frontend tools without backend logic. Returns an
     * empty payload that the orchestrator interprets as "the LLM's args are
     * already sufficient". Subclasses that need to enrich
     * `frontend_action.args` (e.g. sign a URL) override this method and
     * return the fields to merge via `ToolResult::success([...])`.
     *
     * Do NOT touch the DB or cause side effects beyond what is strictly
     * necessary — a FE tool's contract is "I validate the args and let the
     * widget act".
     *
     * @param  array<string, mixed>  $args
     */
    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        return ToolResult::success([]);
    }

    /**
     * Canonical name of the widget primitive this tool should dispatch when
     * invoked. By default it returns `name()` — preserving the 1-to-1
     * mapping between tool and bundle primitive (e.g.
     * `NavigateTool::name() === 'navigate'`) that was implicit until 1.1.3.
     *
     * Subclasses of a FE primitive (typical: a host extends
     * `DownloadFileTool` to validate ownership before signing the URL and
     * overrides `name()` with a custom name like `download_manifest`)
     * must override this method and return the parent's canonical name
     * (`'download_file'`). That way the LLM sees the tool with the custom
     * name (with its own `description`), but the widget still resolves to the
     * bundle primitive when it receives `frontend_action.tool`.
     *
     * `ChatService` (E08) reads this value at the moment it emits the
     * `frontend_action` event; a misaligned default causes
     * `unknown_tool` in the widget because it won't find a handler for the
     * custom `name()` (finding #25).
     */
    public function frontendPrimitiveName(): string
    {
        return $this->name();
    }
}
