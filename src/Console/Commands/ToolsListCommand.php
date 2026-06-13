<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Illuminate\Console\Command;
use Rnkr69\LaraChatbot\Mcp\McpBackendTool;
use Rnkr69\LaraChatbot\Mcp\McpToolBridge;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;

/**
 * Lists all registered backend tools (local + MCP) and diagnoses the
 * state of the MCP bridge.
 *
 * The command serves two purposes:
 *
 *   1. Operations: see what the chatbot offers the LLM on this host. Useful
 *      after adding a new tool or changing `chatbot.tools.paths`.
 *
 *   2. Diagnostics (DoD ROADMAP §5/E07): if `chatbot.mcp.servers` has
 *      entries but `prism-php/relay` is not installed, it flags it with
 *      an actionable warning. If Relay is present and a server failed, the
 *      bridge already logged it at boot — here Relay is not called again to
 *      avoid side effects in the command.
 */
class ToolsListCommand extends Command
{
    protected $signature = 'chatbot:tools:list';

    protected $description = 'List the registered backend tools (local and MCP) in the ToolRegistry.';

    public function handle(ToolRegistry $registry, McpToolBridge $bridge): int
    {
        $tools = $registry->all();

        if ($tools === []) {
            $this->components->info('No tools registered.');
        } else {
            $rows = [];
            /** @var list<string> $misconfigured */
            $misconfigured = [];

            foreach ($tools as $tool) {
                // v2.1 (#5) — surface the pin contract: a tool is pinnable in
                // practice only when it opts in AND keeps `confirmation` Auto
                // (the orchestrator enforces the AND). A `pinnable()` tool
                // with a non-Auto confirmation is misconfigured — the flag is
                // silently dropped, so flag it loudly here.
                $isPinnable = $tool->pinnable();
                $isAuto     = $tool->confirmation() === ConfirmationLevel::Auto;
                $pinnable   = match (true) {
                    $isPinnable && $isAuto  => 'yes',
                    $isPinnable && ! $isAuto => '⚠ non-Auto',
                    default                  => '—',
                };
                if ($isPinnable && ! $isAuto) {
                    $misconfigured[] = $tool->name();
                }

                $rows[] = [
                    'name'     => $tool->name(),
                    'origin'   => $tool instanceof McpBackendTool ? 'mcp:' . $tool->serverName() : 'local',
                    'perms'    => implode(', ', $tool->permissions()) ?: '—',
                    'pinnable' => $pinnable,
                    'desc'     => $this->truncate($tool->description(), 52),
                ];
            }

            $this->table(['Name', 'Origin', 'Permissions', 'Pinnable', 'Description'], $rows);

            if ($misconfigured !== []) {
                $this->newLine();
                $this->components->warn(
                    count($misconfigured) . ' tool(s) declare pinnable() but have a non-Auto '
                    . 'confirmation (' . implode(', ', $misconfigured) . '). The orchestrator '
                    . 'requires pinnable() === true AND confirmation === Auto, so the pin flag '
                    . 'is silently dropped for these. Drop pinnable() or set confirmation to Auto. '
                    . 'See docs/backend-tools.md §9.'
                );
            }
        }

        $this->reportMcpStatus($bridge);

        return self::SUCCESS;
    }

    protected function reportMcpStatus(McpToolBridge $bridge): void
    {
        $servers = $bridge->configuredServerNames();

        if ($servers === []) {
            return;
        }

        if (! $bridge->isAvailable()) {
            $this->newLine();
            $this->components->warn(
                'There are ' . count($servers) . ' MCP server(s) configured in chatbot.mcp.servers '
                . '(' . implode(', ', $servers) . ') but `prism-php/relay` is not installed. '
                . 'MCP tools will not load. Run `composer require prism-php/relay` to '
                . 'enable them, or empty the section to silence this warning.'
            );

            return;
        }

        $this->newLine();
        $this->components->info(
            'MCP bridge active. Configured servers: ' . implode(', ', $servers) . '.'
        );
    }

    protected function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max - 1) . '…';
    }
}
