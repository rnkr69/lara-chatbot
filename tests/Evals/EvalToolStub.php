<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Evals;

use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

/**
 * Tool stub for the eval harness. The fixture sets `$name`, `$description`,
 * and `$parameters` so each fixture can describe the tool catalogue the LLM
 * is expected to choose from. `handle()` is a no-op that just records the
 * invocation — the eval asserts on the tool_call event the orchestrator
 * emits, not on the body of the response.
 */
final class EvalToolStub extends BaseBackendTool
{
    /** @var array<int, array{args: array<string, mixed>}> */
    public array $invocations = [];

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        private readonly string $toolName,
        private readonly string $toolDescription,
        private readonly array $parameters,
    ) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return $this->toolDescription;
    }

    public function parameters(): array
    {
        return $this->parameters;
    }

    public function confirmation(): ConfirmationLevel
    {
        return ConfirmationLevel::Auto;
    }

    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        $this->invocations[] = ['args' => $args];

        return ToolResult::success(['echoed' => $args]);
    }
}
