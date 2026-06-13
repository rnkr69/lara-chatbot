<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs\Tools;

use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\Contracts\FrontendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

/**
 * Test frontend tool — uses the `FrontendTool` marker so the
 * orchestrator detects it and emits `frontend_action` instead of
 * `tool_call`. E11 will introduce a real `BaseFrontendTool` with an
 * automated shim; here `BaseBackendTool` + the `FrontendTool` marker
 * suffices.
 */
class NavigateLikeFrontendTool extends BaseBackendTool implements FrontendTool
{
    public ConfirmationLevel $confirmationOverride = ConfirmationLevel::Auto;
    public bool $shouldDeny = false;
    public int $invocations = 0;

    public function name(): string
    {
        return 'navigate_like';
    }

    public function description(): string
    {
        return 'Pretends to navigate the host UI to a URL.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'URL destino.'],
            ],
            'required' => ['url'],
        ];
    }

    public function confirmation(): ConfirmationLevel
    {
        return $this->confirmationOverride;
    }

    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        $this->invocations++;

        if ($this->shouldDeny) {
            return ToolResult::error('unauthorized', 'fake denial');
        }

        // Frontend tools do not touch host state; the orchestrator
        // will overwrite the result with a `queued + action_id` payload
        // before returning it to the LLM. Here we return a neutral `success`
        // so the orchestrator interprets that the cascade passed.
        return ToolResult::success(['validated' => true]);
    }
}
