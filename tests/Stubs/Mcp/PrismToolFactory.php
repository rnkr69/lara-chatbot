<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs\Mcp;

use Closure;
use Prism\Prism\Tool as PrismTool;

/**
 * Helper para construir `Prism\Prism\Tool` en tests sin tener que repetir
 * la cadena `as()->for()->using()` en cada caso.
 */
class PrismToolFactory
{
    public static function string(
        string $name,
        string $description = 'A fake MCP tool.',
        ?Closure $handler = null,
    ): PrismTool {
        $tool = (new PrismTool)
            ->as($name)
            ->for($description)
            ->withoutErrorHandling();

        $tool->using($handler ?? fn () => 'OK from ' . $name);

        return $tool;
    }

    public static function withParam(
        string $name,
        string $paramName = 'q',
        ?Closure $handler = null,
    ): PrismTool {
        $tool = (new PrismTool)
            ->as($name)
            ->for('A fake MCP tool with one string param.')
            ->withStringParameter($paramName, 'Search query.')
            ->withoutErrorHandling();

        $tool->using($handler ?? fn (string $q): string => 'echo:' . $q);

        return $tool;
    }
}
