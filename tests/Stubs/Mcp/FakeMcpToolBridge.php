<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs\Mcp;

use Rnkr69\LaraChatbot\Mcp\McpToolBridge;
use Prism\Prism\Tool as PrismTool;

/**
 * Fake bridge used by the tests to fake `prism-php/relay` without
 * installing the package. Overrides `isAvailable()` and `callRelayTools()` —
 * tests configure the set of tools each server "exposes".
 *
 * Also exposes `$callsByServer` to verify the cache prevents repeated
 * calls to Relay within the TTL.
 */
class FakeMcpToolBridge extends McpToolBridge
{
    public bool $availability = true;

    /** @var array<string, array<int, PrismTool>> */
    public array $toolsByServer = [];

    /** @var array<string, int> */
    public array $callsByServer = [];

    /** @var array<string, \Throwable> */
    public array $errorsByServer = [];

    public function isAvailable(): bool
    {
        return $this->availability;
    }

    /**
     * @param  array<int, PrismTool>  $tools
     */
    public function setTools(string $server, array $tools): void
    {
        $this->toolsByServer[$server] = $tools;
    }

    public function failServer(string $server, \Throwable $e): void
    {
        $this->errorsByServer[$server] = $e;
    }

    protected function callRelayTools(string $server): array
    {
        $this->callsByServer[$server] = ($this->callsByServer[$server] ?? 0) + 1;

        if (isset($this->errorsByServer[$server])) {
            throw $this->errorsByServer[$server];
        }

        return $this->toolsByServer[$server] ?? [];
    }
}
