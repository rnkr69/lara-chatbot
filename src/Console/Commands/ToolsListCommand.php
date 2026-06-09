<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Illuminate\Console\Command;
use Rnkr69\LaraChatbot\Mcp\McpBackendTool;
use Rnkr69\LaraChatbot\Mcp\McpToolBridge;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;

/**
 * Lista todas las backend tools registradas (locales + MCP) y diagnostica
 * el estado del bridge MCP.
 *
 * El comando sirve dos propósitos:
 *
 *   1. Operativa: ver qué le ofrece el chatbot al LLM en este host. Útil
 *      tras añadir una tool nueva o cambiar `chatbot.tools.paths`.
 *
 *   2. Diagnóstico (DoD ROADMAP §5/E07): si `chatbot.mcp.servers` tiene
 *      entradas pero `prism-php/relay` no está instalado, lo señala con
 *      un warning accionable. Si Relay está y un server falló, el bridge
 *      ya logueó al boot — aquí no se vuelve a llamar a Relay para evitar
 *      side effects en el comando.
 */
class ToolsListCommand extends Command
{
    protected $signature = 'chatbot:tools:list';

    protected $description = 'Lista las backend tools registradas (locales y MCP) en el ToolRegistry.';

    public function handle(ToolRegistry $registry, McpToolBridge $bridge): int
    {
        $tools = $registry->all();

        if ($tools === []) {
            $this->components->info('No hay tools registradas.');
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
                'Hay ' . count($servers) . ' server(s) MCP configurado(s) en chatbot.mcp.servers '
                . '(' . implode(', ', $servers) . ') pero `prism-php/relay` no está instalado. '
                . 'Las tools MCP no se cargarán. Ejecuta `composer require prism-php/relay` para '
                . 'activarlas, o vacía la sección para silenciar este aviso.'
            );

            return;
        }

        $this->newLine();
        $this->components->info(
            'Bridge MCP activo. Servers configurados: ' . implode(', ', $servers) . '.'
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
