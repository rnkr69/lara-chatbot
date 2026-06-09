<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Rnkr69\LaraChatbot\Authorization\Contracts\Authorizer;
use Rnkr69\LaraChatbot\Authorization\Contracts\TenantResolver;
use Rnkr69\LaraChatbot\Tools\Contracts\BackendTool;
use Rnkr69\LaraChatbot\Tools\Exceptions\MissingTenantResolverException;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * Registro central de backend tools.
 *
 * El host registra tools de tres formas:
 *   1. Auto-discovery (default): el ServiceProvider escanea las paths de
 *      `chatbot.tools.paths` y registra cualquier clase concreta que
 *      implemente `BackendTool`.
 *   2. Manual: el AppServiceProvider del host llama
 *      `app(ToolRegistry::class)->register(MyTool::class)`.
 *   3. Bridge MCP (E07): el `McpToolBridge` registra tools remotas con
 *      prefijo `mcp.<server>.<tool>`.
 *
 * `forUser($user)` devuelve sólo las tools cuya `permissions()` el usuario
 * cumple. Se usa por el orquestador (E08) para construir el array de tools
 * que enseña al LLM en cada turno.
 *
 * Boot ruidoso del gap cross-host E04: al `register()`, si la tool declara
 * `tenantScope=true` y el contenedor no tiene `TenantResolver` bind,
 * lanza `MissingTenantResolverException`. La idea es fallar pronto, no en
 * la primera invocación de la tool en producción.
 */
class ToolRegistry
{
    /**
     * @var array<string, BackendTool>
     */
    protected array $tools = [];

    public function __construct(
        protected Container $container,
    ) {}

    /**
     * Registra una tool. Acepta una instancia ya construida o un class-string.
     */
    public function register(string|BackendTool $tool): self
    {
        $instance = $tool instanceof BackendTool ? $tool : $this->resolve($tool);

        $this->ensureTenantResolverIfNeeded($instance);

        $this->tools[$instance->name()] = $instance;

        return $this;
    }

    /**
     * @param  array<int, string|BackendTool>  $tools
     */
    public function registerMany(array $tools): self
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }

        return $this;
    }

    /**
     * Tools cuyas `permissions()` el usuario cumple. Una tool sin permisos
     * declarados es pública y siempre pasa.
     *
     * @return array<string, BackendTool>
     */
    public function forUser(Authenticatable $user): array
    {
        $authorizer = $this->container->make(Authorizer::class);

        $allowed = [];

        foreach ($this->tools as $name => $tool) {
            if ($authorizer->check($user, $tool->permissions())) {
                $allowed[$name] = $tool;
            }
        }

        return $allowed;
    }

    /**
     * Todas las tools registradas, sin filtro.
     *
     * @return array<string, BackendTool>
     */
    public function all(): array
    {
        return $this->tools;
    }

    public function get(string $name): ?BackendTool
    {
        return $this->tools[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function clear(): self
    {
        $this->tools = [];

        return $this;
    }

    /**
     * Auto-discovery: escanea las paths declaradas en `chatbot.tools.paths`
     * (relativas al `base_path()`) y registra cualquier clase concreta que
     * implemente `BackendTool`. Idempotente: re-registrar una tool con el
     * mismo `name()` la sobreescribe.
     *
     * @param  array<int, string>  $paths
     */
    public function discover(array $paths, ?string $basePath = null): self
    {
        $basePath = $basePath ?? base_path();

        foreach ($paths as $path) {
            $absolute = Str::startsWith($path, ['/', '\\']) ? $path : $basePath . DIRECTORY_SEPARATOR . $path;

            if (! is_dir($absolute)) {
                continue;
            }

            foreach ($this->discoverClassesIn($absolute) as $class) {
                $this->register($class);
            }
        }

        return $this;
    }

    /**
     * @return iterable<int, class-string<BackendTool>>
     */
    protected function discoverClassesIn(string $path): iterable
    {
        $finder = (new Finder)->files()->in($path)->name('*.php');

        foreach ($finder as $file) {
            $class = $this->classFromFile($file->getRealPath());

            if ($class === null) {
                continue;
            }

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if (
                $reflection->isAbstract()
                || ! $reflection->implementsInterface(BackendTool::class)
            ) {
                continue;
            }

            yield $class;
        }
    }

    /**
     * Extrae el FQCN de un archivo PHP buscando `namespace` + `class` /
     * `final class`. Suficiente para auto-discovery (un archivo = una clase
     * top-level por convención).
     */
    protected function classFromFile(string $path): ?string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        if (! preg_match('/^\s*namespace\s+([^;]+);/m', $contents, $nsMatch)) {
            return null;
        }

        if (! preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $contents, $classMatch)) {
            return null;
        }

        return trim($nsMatch[1]) . '\\' . trim($classMatch[1]);
    }

    protected function resolve(string $class): BackendTool
    {
        if (! class_exists($class)) {
            throw new RuntimeException("La clase `{$class}` no existe.");
        }

        $instance = $this->container->make($class);

        if (! $instance instanceof BackendTool) {
            throw new RuntimeException(
                "La clase `{$class}` no implementa Rnkr69\\LaraChatbot\\Tools\\Contracts\\BackendTool."
            );
        }

        return $instance;
    }

    protected function ensureTenantResolverIfNeeded(BackendTool $tool): void
    {
        if (! $tool->tenantScope()) {
            return;
        }

        if (! $this->container->bound(TenantResolver::class)) {
            throw MissingTenantResolverException::forTool($tool->name());
        }
    }
}
