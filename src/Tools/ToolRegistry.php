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
 * Central registry of backend tools.
 *
 * The host registers tools in three ways:
 *   1. Auto-discovery (default): the ServiceProvider scans the paths in
 *      `chatbot.tools.paths` and registers any concrete class that
 *      implements `BackendTool`.
 *   2. Manual: the host's AppServiceProvider calls
 *      `app(ToolRegistry::class)->register(MyTool::class)`.
 *   3. MCP bridge (E07): the `McpToolBridge` registers remote tools with
 *      the `mcp.<server>.<tool>` prefix.
 *
 * `forUser($user)` returns only the tools whose `permissions()` the user
 * satisfies. It is used by the orchestrator (E08) to build the array of
 * tools it shows the LLM on each turn.
 *
 * Noisy boot of the E04 cross-host gap: on `register()`, if the tool declares
 * `tenantScope=true` and the container has no `TenantResolver` bound,
 * it throws `MissingTenantResolverException`. The idea is to fail early, not
 * on the tool's first invocation in production.
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
     * Registers a tool. Accepts an already-built instance or a class-string.
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
     * Tools whose `permissions()` the user satisfies. A tool with no declared
     * permissions is public and always passes.
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
     * All registered tools, unfiltered.
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
     * Auto-discovery: scans the paths declared in `chatbot.tools.paths`
     * (relative to `base_path()`) and registers any concrete class that
     * implements `BackendTool`. Idempotent: re-registering a tool with the
     * same `name()` overwrites it.
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
     * Extracts the FQCN from a PHP file by looking for `namespace` + `class` /
     * `final class`. Sufficient for auto-discovery (one file = one top-level
     * class by convention).
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
            throw new RuntimeException("Class `{$class}` does not exist.");
        }

        $instance = $this->container->make($class);

        if (! $instance instanceof BackendTool) {
            throw new RuntimeException(
                "Class `{$class}` does not implement Rnkr69\\LaraChatbot\\Tools\\Contracts\\BackendTool."
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
