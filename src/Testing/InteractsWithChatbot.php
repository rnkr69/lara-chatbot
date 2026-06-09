<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Testing;

use Illuminate\Contracts\Auth\Authenticatable;
use Rnkr69\LaraChatbot\Tools\Contracts\BackendTool;
use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;
use Rnkr69\LaraChatbot\Tools\ToolResult;

/**
 * Trait `InteractsWithChatbot` (v1.1.1, finding #14.c).
 *
 * Use desde test classes Pest/PHPUnit del host para invocar tools del
 * chatbot con una API fluida, en aislamiento (sin LLM, sin browser, sin
 * SSE). El trait se apoya en el `ToolRegistry` ya bind por el package
 * service provider — los tests deben extender la `TestCase` Orchestra
 * con el `ChatbotServiceProvider` cargado.
 *
 * Ejemplo:
 *
 *   use Rnkr69\LaraChatbot\Testing\InteractsWithChatbot;
 *
 *   it('list_my_missions filters by status', function () {
 *       $pilot = User::factory()->pilot()->create();
 *       Mission::factory()->for($pilot, 'pilot')->count(3)->create(['status' => 'draft']);
 *
 *       $result = $this->actingAs($pilot)
 *           ->chatbotTool('list_my_missions')
 *           ->withArgs(['status' => 'draft'])
 *           ->call();
 *
 *       expect($result->isOk())->toBeTrue();
 *       expect($result->data['count'])->toBe(3);
 *   });
 */
trait InteractsWithChatbot
{
    public function chatbotTool(string $name): ChatbotToolInvocation
    {
        /** @var ToolRegistry $registry */
        $registry = $this->app->make(ToolRegistry::class);

        $tool = $registry->get($name);
        if ($tool === null) {
            throw new \RuntimeException(
                "Tool `{$name}` not registered. Ensure your TestCase loads the package service provider "
                . 'and the tool path is in `chatbot.tools.paths`, or call `$registry->register($class)` '
                . 'manually before the assertion.'
            );
        }

        return new ChatbotToolInvocation($tool, $this->currentChatbotUser());
    }

    /**
     * Returns the user that `actingAs(...)` set, or null if none.
     */
    private function currentChatbotUser(): ?Authenticatable
    {
        if (function_exists('auth')) {
            try {
                return auth()->user();
            } catch (\Throwable) { /* fall through */ }
        }
        return null;
    }
}

/**
 * Fluent builder devuelto por `InteractsWithChatbot::chatbotTool()`.
 * Construye un ToolContext y ejecuta la tool aplicando la cascada
 * estándar de validación + autorización del `BaseBackendTool`.
 */
final class ChatbotToolInvocation
{
    /** @var array<string, mixed> */
    private array $args = [];

    /** @var array<string, mixed> */
    private array $pageContext = [];

    public function __construct(
        private readonly BackendTool $tool,
        private ?Authenticatable $user,
    ) {}

    /**
     * @param  array<string, mixed>  $args
     */
    public function withArgs(array $args): self
    {
        $this->args = $args;
        return $this;
    }

    public function actingAs(Authenticatable $user): self
    {
        $this->user = $user;
        return $this;
    }

    /**
     * @param  array<string, mixed>  $pageContext
     */
    public function withPageContext(array $pageContext): self
    {
        $this->pageContext = $pageContext;
        return $this;
    }

    public function call(): ToolResult
    {
        if ($this->user === null) {
            throw new \RuntimeException(
                'No invoking user. Call `$this->actingAs($user)` on the TestCase before `chatbotTool(...)`, '
                . 'or chain `->actingAs($user)` on the invocation.'
            );
        }

        $ctx = new ToolContext(
            user: $this->user,
            pageContext: $this->pageContext,
        );

        if ($this->tool instanceof BaseBackendTool) {
            return $this->tool->execute($this->args, $ctx);
        }

        return $this->tool->handle($this->args, $ctx);
    }
}
