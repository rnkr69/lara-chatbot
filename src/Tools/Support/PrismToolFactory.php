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
 * Adapta una `BackendTool` (contrato del paquete) a `Prism\Prism\Tool`
 * (contrato que `Prism::text()->withTools(...)` espera).
 *
 * El orquestador `ChatService` (E08) es la fuente de verdad de la
 * ejecución: cuando recibe `ToolCallEvent` del stream, corre la cascada
 * (`BaseBackendTool::execute()` o `BackendTool::handle()`), dispara el
 * evento `ToolInvoked` y empuja el `ToolResult` resuelto al final del
 * `&$buffer` indexado por nombre de tool. Cuando Prism (en producción)
 * invoca el closure de la tool, este consume del FIFO con el mismo nombre
 * y devuelve la serialización para que el LLM la siga viendo como un
 * `tool_result` válido.
 *
 * Por qué FIFO por nombre y no por `tool_call_id`: el closure de Prism
 * recibe sólo `(...$args)`, no el id. Como Prism procesa los tool calls
 * de un nombre dado en orden de aparición en el stream, FIFO es seguro y
 * no exige ampliar el contrato del SDK.
 *
 * En tests con `Prism::fake()` el closure NO se invoca (el fake yield-ea
 * `ToolResultEvent` directamente), así que el buffer queda intacto. Eso
 * está bien: el orquestador comprueba sus aserciones sobre los `SseEvent`
 * emitidos.
 */
final class PrismToolFactory
{
    /**
     * Construye un `Prism\Prism\Tool` para la `BackendTool` dada. El
     * `&$buffer` se pasa por referencia para que el orquestador y el
     * closure compartan el FIFO de resultados.
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

        // Capturar &$buffer y $name por uso. El closure se invoca en
        // producción; en tests con `Prism::fake()` no se llama.
        $closure = function (...$args) use (&$buffer, $name): string {
            $queue = $buffer[$name] ?? [];

            if ($queue === []) {
                // Fallback defensivo: el orquestador no precomputó el
                // resultado (no debería pasar). Devolver un mensaje neutro
                // para que el LLM no se quede colgado.
                return json_encode(['status' => 'error', 'error' => 'runtime', 'message' => 'No precomputed tool result available.']) ?: 'error';
            }

            /** @var ToolResult $result */
            $result = array_shift($queue);
            $buffer[$name] = $queue;

            $payload = $result->toArray();
            $encoded = json_encode($payload);

            return is_string($encoded) ? $encoded : 'tool_result_serialization_failed';
        };

        $prism = $prism->using($closure);

        return $prism;
    }

    /**
     * Mapea el JSON Schema mínimo (`type=object`, `properties`, `required`,
     * `enum`) al API fluido de Prism. Soporta el subset estable que
     * `JsonSchemaToRules` ya cubre — cualquier rama no soportada se ignora
     * silenciosamente; el LLM recibe la tool sin ese parámetro.
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
