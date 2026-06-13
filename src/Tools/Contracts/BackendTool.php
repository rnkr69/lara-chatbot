<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Contracts;

use Rnkr69\LaraChatbot\Authorization\AccessScope;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

/**
 * Complete contract of a backend tool. What the LLM sees and the
 * orchestrator (`ChatService`, E08) executes.
 *
 * E04 introduced a minimal version (only `name()` + `permissions()`) because
 * the `TenantResolver` signature needed to reference the interface. E06
 * expands this same interface with the rest of the contract (it does NOT move
 * or rename it). The natural way to implement it is via `BaseBackendTool`,
 * which provides args validation, the authorization cascade (permission →
 * scope → tenant) and query helpers.
 *
 * Conventions:
 *   - `name()` snake_case, unique across the whole registry (`list_my_invoices`).
 *   - `description()` is read by the LLM, which decides whether to invoke the
 *     tool. It should be a single "what it's for" sentence in natural language.
 *   - `parameters()` minimal JSON Schema (type/properties/required/enum).
 *     It is mapped to Laravel Validator via `JsonSchemaToRules` to validate
 *     args before invoking `handle()`.
 *   - `permissions()` AND list. Empty list = public tool.
 *   - `defaultScope()` the scope the tool assumes if its query logic doesn't
 *     declare another. Admin tools may return `All`.
 *   - `confirmation()` in backend tools v1 must be `Auto`; other levels
 *     remain in the v2 backlog.
 *   - `tenantScope()` opt-in for the E04 cross-host gap (multi-tenant hosts). If a
 *     tool returns `true`, the `ToolRegistry` requires at boot that the host
 *     has bound a `TenantResolver`; on execution, the tenant filter enters
 *     the authorization cascade.
 */
interface BackendTool
{
    /**
     * Unique identifier of the tool. snake_case convention.
     */
    public function name(): string;

    /**
     * Short sentence the LLM reads to decide whether to invoke it.
     */
    public function description(): string;

    /**
     * Minimal JSON Schema of the args shape.
     *
     * Expected structure:
     *   [
     *     'type' => 'object',
     *     'properties' => [
     *       'order_id' => ['type' => 'integer', 'description' => '...'],
     *       'status'   => ['type' => 'string', 'enum' => ['paid', 'pending']],
     *     ],
     *     'required' => ['order_id'],
     *   ]
     *
     * Non-top-level keys (nested, oneOf, etc.) are ignored by the internal
     * validator; if you need richer validation, override
     * `BaseBackendTool::validateArgs()`.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * Permissions the user must have to invoke this tool. AND list.
     *
     * @return array<int, string>
     */
    public function permissions(): array;

    /**
     * Default scope for the authorization cascade ROADMAP §2.4.
     */
    public function defaultScope(): AccessScope;

    /**
     * Confirmation level. In backend tools v1 only `Auto` is supported
     * end-to-end by the orchestrator; other values are rejected by E08 until v2.
     */
    public function confirmation(): ConfirmationLevel;

    /**
     * If `true`, the `ToolRegistry` requires at boot that the host has bound a
     * `TenantResolver`. The cascade becomes permission → scope → tenant →
     * ownership. Default `false` — only opt-in for cross-host gap tools.
     */
    public function tenantScope(): bool;

    /**
     * v2.0 (E1) — declares whether the blocks this tool emits can be pinned
     * to the personal dashboard and replayed periodically. It is only
     * considered effective when it coincides with `confirmation() === Auto`:
     * tools that require confirmation or manual execution are always mutating
     * tools, so even if they return `true` here the orchestrator won't emit
     * `pinnable: true` on the blocks (enforcement upstream).
     *
     * Default `false` in `BaseBackendTool` — all existing tools stay the same
     * without changes. A tool opts in by subclassing and returning `true`
     * explicitly (e.g. read-only listings).
     *
     * `FrontendTool` inherits this signature via `extends BackendTool`; the
     * default implementation in `BaseBackendTool` (from which
     * `BaseFrontendTool` also inherits) returns `false`, so the universe of
     * "pinnable frontend tools" is empty by construction unless a host
     * explicitly enables it (which only makes sense when a FE tool produces
     * replayable data, not UI effects).
     */
    public function pinnable(): bool;

    /**
     * Executes the tool's logic. Args validation and the authorization
     * cascade must have been applied beforehand (`BaseBackendTool` does it).
     *
     * @param  array<string, mixed>  $args
     */
    public function handle(array $args, ToolContext $ctx): ToolResult;
}
