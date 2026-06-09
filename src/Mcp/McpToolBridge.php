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
 * Puente entre el ecosistema MCP (Model Context Protocol) — provisto por
 * `prism-php/relay` — y el `ToolRegistry` del paquete (E06).
 *
 * Responsabilidades:
 *
 *   1. Detectar si Relay está instalado en el host (`isAvailable()`).
 *      Relay es una dependencia opcional: el paquete NO lo lista en
 *      `composer.json` para no obligar a su instalación. El host añade
 *      `composer require prism-php/relay` cuando quiere activarlo.
 *
 *   2. Leer `chatbot.mcp.servers[]`, filtrar los `enabled = true`, y para
 *      cada uno: pedir a Relay la lista de `Prism\Prism\Tool`, cachearla
 *      por server con TTL configurable, envolver cada tool en
 *      `McpBackendTool` y registrarla en el `ToolRegistry` con el prefijo
 *      `mcp.<server>.<tool>`.
 *
 *   3. Aislar fallos: un server caído / mal configurado no debe abortar
 *      el boot del paquete entero. Se loguea warning por server y se
 *      continúa con el resto. El comando `chatbot:tools:list` (E07) muestra
 *      al operador qué se cargó.
 *
 * El `Relay` facade (`Prism\Relay\Facades\Relay`) se llama vía `app()`
 * para que los tests puedan reemplazarlo con `Mockery` sin que el bridge
 * dependa del facade global a tiempo de carga.
 */
class McpToolBridge
{
    /**
     * FQCN del facade de Relay. Usar la string evita que el autoloader
     * intente resolver la clase cuando el paquete no está instalado.
     */
    public const RELAY_FACADE = 'Prism\\Relay\\Facades\\Relay';

    public function __construct(
        protected Container $container,
        protected ConfigRepository $config,
        protected CacheRepository $cache,
    ) {}

    /**
     * `true` si `prism-php/relay` está disponible en el classpath.
     */
    public function isAvailable(): bool
    {
        return class_exists(self::RELAY_FACADE);
    }

    /**
     * Mapeo `name => config` de los servers declarados en
     * `chatbot.mcp.servers`. Devuelve un array vacío si no hay ninguno.
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
     * Lista los nombres de los servers configurados, independientemente de
     * si están `enabled`. Útil para diagnóstico desde `chatbot:tools:list`.
     *
     * @return array<int, string>
     */
    public function configuredServerNames(): array
    {
        return array_keys($this->serverConfigs());
    }

    /**
     * Punto de entrada principal: registra todas las tools de los servers
     * activos en el `ToolRegistry` recibido.
     *
     * Idempotente — si una tool con el mismo `name()` ya existía en el
     * registry, se sobrescribe (el comportamiento del registry).
     *
     * @return array<string, int>  conteo de tools registradas por server
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
                    '[chatbot] Falló la carga de tools del server MCP "%s": %s',
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
     * Devuelve las tools del server, cacheadas por `cache_ttl` segundos.
     * TTL = 0 desactiva la cache.
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
     * Invocación al facade de Relay. Se aísla en su propio método para que
     * los tests puedan mockearla con `Mockery` sin tocar el resto del
     * bridge. Devuelve siempre array (Relay devuelve array<Tool>).
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
