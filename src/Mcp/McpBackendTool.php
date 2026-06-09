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
 * Adapter que envuelve una `Prism\Prism\Tool` (devuelta por
 * `Prism\Relay\Facades\Relay::tools($server)`) y la expone bajo el contrato
 * `BackendTool` del paquete, para que el `ToolRegistry` (E06) y el
 * `ChatService` (E08) la traten igual que una tool local.
 *
 * Convenciones del adapter:
 *
 *   - `name()` lleva prefijo `mcp.<server>.<tool>` (los puntos son válidos
 *     en JSON Schema y en el formato que Prism envía al LLM). Esto evita
 *     colisiones con tools locales y deja claro al operador qué tools son
 *     remotas al inspeccionar `chatbot:tools:list`.
 *   - `permissions()` se toma de `chatbot.mcp.servers.<server>.permissions`
 *     y aplica AND a TODAS las tools del server. Granularidad por tool MCP
 *     no se soporta en v1 (queda en backlog v1.1 si emerge).
 *   - `defaultScope()` devuelve `All` porque el server MCP es la fuente de
 *     verdad: filtrar por `accessibleUserIds` no tiene sentido cuando los
 *     datos viven fuera del host. La autorización efectiva es la lista de
 *     `permissions()` del server más cualquier guard que el server MCP
 *     aplique en su lado.
 *   - `tenantScope()` devuelve `false` siempre. Crítico: si fuera `true`,
 *     `ToolRegistry::register()` exigiría `TenantResolver` aunque el host
 *     no use tenant scope; rompería el boot del paquete por el sólo hecho
 *     de configurar un server MCP. El gap cross-host de tenant scope (E04)
 *     aplica sólo a tools locales que filtran datos del host.
 *   - `handle()` invoca el handler de la `Prism\Prism\Tool` con los args
 *     spread como named parameters. El retorno se normaliza:
 *       * `string` o `ToolOutput` → `ToolResult::success(['result' => ...])`.
 *       * `ToolError`             → `ToolResult::error('runtime', message)`.
 *       * `Throwable` no atrapado → `ToolResult::error('runtime', getMessage)`.
 *
 * No extiende `BaseBackendTool` a propósito: la cascada local (validación
 * JSON Schema → Authorizer → tenant) no aplica de la misma forma. La
 * autorización (`Authorizer::check` con `permissions()`) la sigue aplicando
 * el `ToolRegistry::forUser()`. La validación de args es responsabilidad
 * del LLM y del server MCP en el otro extremo.
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
     * v2.0 (E1) — MCP tools son opacas: el paquete no sabe si una invocación
     * MCP es read-only o muta estado en el servidor remoto. Por defecto
     * tratamos cualquier MCP tool como NO pinnable; los hosts que confían
     * en que un MCP server concreto sólo expone tools de lectura pueden
     * subclasificar `McpBackendTool` y override este método.
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
     * Acceso al server name (útil para tests y para listar tools agrupadas
     * por server desde el comando `chatbot:tools:list`).
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
