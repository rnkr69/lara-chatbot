<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Support;

use Rnkr69\LaraChatbot\Tools\Contracts\BackendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool as PrismTool;

/**
 * Adapts a `BackendTool` (the package's contract) to `Prism\Prism\Tool`
 * (the contract `Prism::text()->withTools(...)` expects).
 *
 * The `ChatService` orchestrator (E08) is the source of truth for
 * execution: when it receives a `ToolCallEvent` from the stream, it runs
 * the cascade (`BaseBackendTool::execute()` or `BackendTool::handle()`),
 * fires the `ToolInvoked` event and pushes the resolved `ToolResult` to the
 * end of `&$buffer` indexed by tool name. When Prism (in production)
 * invokes the tool's closure, it consumes from the FIFO with the same name
 * and returns the serialization so the LLM keeps seeing it as a valid
 * `tool_result`.
 *
 * Why FIFO by name and not by `tool_call_id`: Prism's closure receives
 * only `(...$args)`, not the id. Since Prism processes the tool calls
 * of a given name in their order of appearance in the stream, FIFO is safe
 * and doesn't require extending the SDK's contract.
 *
 * In tests with `Prism::fake()` the closure is NOT invoked (the fake yields
 * a `ToolResultEvent` directly), so the buffer stays intact. That is
 * fine: the orchestrator checks its assertions against the emitted
 * `SseEvent`s.
 */
final class PrismToolFactory
{
    /**
     * Builds a `Prism\Prism\Tool` for the given `BackendTool`. The
     * `&$buffer` is passed by reference so the orchestrator and the
     * closure share the FIFO of results.
     *
     * @param  array<string, list<ToolResult>>  $buffer
     */
    public function wrap(BackendTool $tool, ToolContext $ctx, array &$buffer): PrismTool
    {
        $name = $tool->name();

        $prism = (new PrismTool)
            ->as($name)
            ->for($tool->description() ?: $name);

        $this->applyParameters($prism, $tool->parameters());

        // Capture &$buffer and $name by use. The closure is invoked in
        // production; in tests with `Prism::fake()` it is not called.
        $closure = function (...$args) use (&$buffer, $name): string {
            $queue = $buffer[$name] ?? [];

            if ($queue === []) {
                // Defensive fallback: the orchestrator did not precompute the
                // result (shouldn't happen). Return a neutral message
                // so the LLM doesn't hang.
                return json_encode(['status' => 'error', 'error' => 'runtime', 'message' => 'No precomputed tool result available.']) ?: 'error';
            }

            /** @var ToolResult $result */
            $result = array_shift($queue);
            $buffer[$name] = $queue;

            // El payload que vuelve al LLM omite `blocks` por defecto: son
            // presentación para el widget y, si el modelo los ve, los reproduce
            // como texto (contenido duplicado). El host puede reactivarlos con
            // `chatbot.llm.send_blocks_to_model`.
            $includeBlocks = (bool) config('chatbot.llm.send_blocks_to_model', false);
            $payload = $result->toModelArray($includeBlocks);
            $encoded = json_encode($payload);

            return is_string($encoded) ? $encoded : 'tool_result_serialization_failed';
        };

        $prism = $prism->using($closure);

        return $prism;
    }

    /**
     * Maps the minimal JSON Schema (`type=object`, `properties`, `required`,
     * `enum`) to Prism's fluent API. Supports the stable subset that
     * `JsonSchemaToRules` already covers — any unsupported branch is ignored
     * silently; the LLM receives the tool without that parameter.
     *
     * @param  array<string, mixed>  $schema
     */
    protected function applyParameters(PrismTool $prism, array $schema): void
    {
        if (($schema['type'] ?? null) !== 'object') {
            return;
        }

        $properties = $schema['properties'] ?? [];

        if (! is_array($properties) || $properties === []) {
            return;
        }

        $required = $schema['required'] ?? [];

        if (! is_array($required)) {
            $required = [];
        }

        foreach ($properties as $propName => $propSchema) {
            if (! is_string($propName) || ! is_array($propSchema)) {
                continue;
            }

            $isRequired  = in_array($propName, $required, true);
            $description = is_string($propSchema['description'] ?? null)
                ? $propSchema['description']
                : '';

            $schemaObj = $this->propertyToSchema($propName, $description, $propSchema);

            if ($schemaObj instanceof Schema) {
                $prism->withParameter($schemaObj, $isRequired);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $property
     */
    protected function propertyToSchema(string $name, string $description, array $property): ?Schema
    {
        if (isset($property['enum']) && is_array($property['enum']) && $property['enum'] !== []) {
            $options = array_values(array_filter(
                $property['enum'],
                static fn ($v): bool => is_string($v) || is_int($v) || is_float($v),
            ));

            if ($options !== []) {
                return new EnumSchema($name, $description, $options);
            }
        }

        return match ($property['type'] ?? null) {
            'string'  => new StringSchema($name, $description),
            'integer',
            'number'  => new NumberSchema($name, $description),
            'boolean' => new BooleanSchema($name, $description),
            'array'   => new ArraySchema($name, $description, new StringSchema('item', 'array item')),
            'object'  => new ObjectSchema($name, $description, []),
            default   => null,
        };
    }
}
