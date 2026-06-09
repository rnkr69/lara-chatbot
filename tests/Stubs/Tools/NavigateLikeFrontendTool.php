<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs\Tools;

use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\Contracts\FrontendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;

/**
 * Frontend tool de prueba — usa el marker `FrontendTool` para que el
 * orquestador la detecte y emita `frontend_action` en lugar de
 * `tool_call`. E11 introducirá una `BaseFrontendTool` real con shim
 * automatizado; aquí basta con `BaseBackendTool` + `FrontendTool`
 * marker.
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

        // Las frontend tools no tocan estado del host; el orquestador
        // sobrescribirá el resultado con un payload `queued + action_id`
        // antes de devolverlo al LLM. Aquí devolvemos `success` neutro
        // para que el orquestador interprete que la cascada pasó.
        return ToolResult::success(['validated' => true]);
    }
}
