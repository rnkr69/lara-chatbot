<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Mcp;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;
use Prism\Prism\Tool as PrismTool;
use Throwable;

/**
 * Bridge between the MCP ecosystem (Model Context Protocol) — provided by
 * `prism-php/relay` — and the package's `ToolRegistry` (E06).
 *
 * Responsibilities:
 *
 *   1. Detect whether Relay is installed in the host (`isAvailable()`).
 *      Relay is an optional dependency: the package does NOT list it in
 *      `composer.json` so as not to force its installation. The host adds
 *      `composer require prism-php/relay` when it wants to enable it.
 *
 *   2. Read `chatbot.mcp.servers[]`, filter the `enabled = true` ones, and for
 *      each one: ask Relay for the list of `Prism\Prism\Tool`, cache it
 *      per server with a configurable TTL, wrap each tool in
 *      `McpBackendTool` and register it in the `ToolRegistry` with the prefix
 *      `mcp.<server>.<tool>`.
 *
 *   3. Isolate failures: a server that is down / misconfigured must not abort
 *      the boot of the entire package. A warning is logged per server and we
 *      continue with the rest. The `chatbot:tools:list` command (E07) shows
 *      the operator what was loaded.
 *
 * The `Relay` facade (`Prism\Relay\Facades\Relay`) is called via `app()`
 * so that tests can replace it with `Mockery` without the bridge
 * depending on the global facade at load time.
 */
class McpToolBridge
{
    /**
     * FQCN of the Relay facade. Using the string prevents the autoloader
     * from trying to resolve the class when the package is not installed.
     */
    public const RELAY_FACADE = 'Prism\\Relay\\Facades\\Relay';

    public function __construct(
        protected Container $container,
        protected ConfigRepository $config,
        protected CacheRepository $cache,
    ) {}

    /**
     * `true` if `prism-php/relay` is available on the classpath.
     */
    public function isAvailable(): bool
    {
        return class_exists(self::RELAY_FACADE);
    }

    /**
     * `name => config` mapping of the servers declared in
     * `chatbot.mcp.servers`. Returns an empty array if there are none.
     *
     * @return array<string, array<string, mixed>>
     */
    public function serverConfigs(): array
    {
        $servers = $this->config->get('chatbot.mcp.servers', []);

        if (! is_array($servers)) {
            return [];
        }

        $normalized = [];

        foreach ($servers as $name => $cfg) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $normalized[$name] = is_array($cfg) ? $cfg : [];
        }

        return $normalized;
    }

    /**
     * Lists the names of the configured servers, regardless of
     * whether they are `enabled`. Useful for diagnostics from `chatbot:tools:list`.
     *
     * @return array<int, string>
     */
    public function configuredServerNames(): array
    {
        return array_keys($this->serverConfigs());
    }

    /**
     * Main entry point: registers all the tools of the active servers
     * in the received `ToolRegistry`.
     *
     * Idempotent — if a tool with the same `name()` already existed in the
     * registry, it is overwritten (the registry's behavior).
     *
     * @return array<string, int>  count of tools registered per server
     */
    public function registerInto(ToolRegistry $registry): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $counts = [];

        foreach ($this->serverConfigs() as $name => $cfg) {
            if (! ($cfg['enabled'] ?? true)) {
                continue;
            }

            try {
                $tools = $this->fetchTools($name, $cfg);
            } catch (Throwable $e) {
                Log::warning(sprintf(
                    '[chatbot] Failed to load tools from MCP server "%s": %s',
                    $name,
                    $e->getMessage(),
                ));

                continue;
            }

            $registered = 0;

            foreach ($tools as $tool) {
                if (! $tool instanceof PrismTool) {
                    continue;
                }

                $registry->register(new McpBackendTool($name, $tool, $cfg));
                $registered++;
            }

            $counts[$name] = $registered;
        }

        return $counts;
    }

    /**
     * Returns the server's tools, cached for `cache_ttl` seconds.
     * TTL = 0 disables the cache.
     *
     * @param  array<string, mixed>  $cfg
     * @return array<int, PrismTool>
     */
    protected function fetchTools(string $server, array $cfg): array
    {
        $ttl = (int) ($cfg['cache_ttl'] ?? 0);

        if ($ttl <= 0) {
            return $this->callRelayTools($server);
        }

        $key = sprintf('chatbot.mcp.tools.%s', $server);

        return $this->cache->remember($key, $ttl, fn () => $this->callRelayTools($server));
    }

    /**
     * Invocation of the Relay facade. It is isolated in its own method so that
     * tests can mock it with `Mockery` without touching the rest of the
     * bridge. Always returns an array (Relay returns array<Tool>).
     *
     * @return array<int, PrismTool>
     */
    protected function callRelayTools(string $server): array
    {
        $facade = self::RELAY_FACADE;
        $tools  = $facade::tools($server);

        return is_array($tools) ? array_values($tools) : [];
    }
}
