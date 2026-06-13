<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Mcp;

use Rnkr69\LaraChatbot\Authorization\AccessScope;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\Contracts\BackendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;
use Prism\Prism\Tool as PrismTool;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;
use Throwable;

/**
 * Adapter that wraps a `Prism\Prism\Tool` (returned by
 * `Prism\Relay\Facades\Relay::tools($server)`) and exposes it under the
 * package's `BackendTool` contract, so that the `ToolRegistry` (E06) and the
 * `ChatService` (E08) treat it the same as a local tool.
 *
 * Adapter conventions:
 *
 *   - `name()` carries the prefix `mcp.<server>.<tool>` (dots are valid
 *     in JSON Schema and in the format Prism sends to the LLM). This avoids
 *     collisions with local tools and makes it clear to the operator which
 *     tools are remote when inspecting `chatbot:tools:list`.
 *   - `permissions()` is taken from `chatbot.mcp.servers.<server>.permissions`
 *     and AND-applied to ALL the server's tools. Per-MCP-tool granularity
 *     is not supported in v1 (it stays in the v1.1 backlog if it emerges).
 *   - `defaultScope()` returns `All` because the MCP server is the source of
 *     truth: filtering by `accessibleUserIds` makes no sense when the data
 *     lives outside the host. The effective authorization is the server's
 *     `permissions()` list plus whatever guard the MCP server applies on
 *     its side.
 *   - `tenantScope()` always returns `false`. Critical: if it were `true`,
 *     `ToolRegistry::register()` would require a `TenantResolver` even if the
 *     host does not use tenant scope; it would break the package's boot for
 *     the mere fact of configuring an MCP server. The tenant scope cross-host
 *     gap (E04) applies only to local tools that filter host data.
 *   - `handle()` invokes the `Prism\Prism\Tool` handler with the args
 *     spread as named parameters. The return value is normalized:
 *       * `string` or `ToolOutput` → `ToolResult::success(['result' => ...])`.
 *       * `ToolError`             → `ToolResult::error('runtime', message)`.
 *       * uncaught `Throwable`    → `ToolResult::error('runtime', getMessage)`.
 *
 * It deliberately does not extend `BaseBackendTool`: the local cascade
 * (JSON Schema validation → Authorizer → tenant) does not apply the same way.
 * Authorization (`Authorizer::check` with `permissions()`) is still applied by
 * the `ToolRegistry::forUser()`. Validating the args is the responsibility
 * of the LLM and the MCP server at the other end.
 */
class McpBackendTool implements BackendTool
{
    /**
     * @param  array<string, mixed>  $serverConfig
     */
    public function __construct(
        protected readonly string $serverName,
        protected readonly PrismTool $prismTool,
        protected readonly array $serverConfig = [],
    ) {}

    public function name(): string
    {
        return sprintf('mcp.%s.%s', $this->serverName, $this->prismTool->name());
    }

    public function description(): string
    {
        return $this->prismTool->description();
    }

    public function parameters(): array
    {
        $properties = $this->prismTool->parametersAsArray();
        $required   = $this->prismTool->requiredParameters();

        $schema = [
            'type'       => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties,
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    public function permissions(): array
    {
        $perms = $this->serverConfig['permissions'] ?? [];

        return is_array($perms) ? array_values(array_filter($perms, 'is_string')) : [];
    }

    public function defaultScope(): AccessScope
    {
        return AccessScope::All;
    }

    public function confirmation(): ConfirmationLevel
    {
        return ConfirmationLevel::Auto;
    }

    public function tenantScope(): bool
    {
        return false;
    }

    /**
     * v2.0 (E1) — MCP tools are opaque: the package does not know whether an
     * MCP invocation is read-only or mutates state on the remote server. By
     * default we treat any MCP tool as NOT pinnable; hosts that trust that a
     * specific MCP server only exposes read tools can subclass
     * `McpBackendTool` and override this method.
     */
    public function pinnable(): bool
    {
        return false;
    }

    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        try {
            $value = $this->prismTool->handle(...$args);
        } catch (Throwable $e) {
            return ToolResult::error('runtime', $e->getMessage() !== '' ? $e->getMessage() : $e::class);
        }

        if ($value instanceof ToolError) {
            return ToolResult::error('runtime', $value->message);
        }

        if ($value instanceof ToolOutput) {
            return ToolResult::success(['result' => $value->result]);
        }

        return ToolResult::success(['result' => (string) $value]);
    }

    /**
     * Access to the server name (useful for tests and for listing tools
     * grouped by server from the `chatbot:tools:list` command).
     */
    public function serverName(): string
    {
        return $this->serverName;
    }

    public function prismTool(): PrismTool
    {
        return $this->prismTool;
    }
}
