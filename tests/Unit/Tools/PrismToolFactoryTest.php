<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Tests\Stubs\Tools\FakeUser;
use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\Support\PrismToolFactory;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

/*
|--------------------------------------------------------------------------
| PrismToolFactory — E20 gap
|--------------------------------------------------------------------------
|
| The factory translates `BackendTool` (the package's contract) to `Prism\Tool`
| (the SDK's contract). A central piece of the orchestrator: if it breaks
| schema translation, the LLM receives tools with incorrect parameters and the
| tool calls fail or are ignored. Before E20 it was only exercised indirectly
| via ChatServiceTest. Here we cover its public API.
*/

/**
 * Helper: creates an ad-hoc BackendTool with arbitrary `parameters`.
 */
function makeTool(array $parameters, string $name = 'demo_tool'): BaseBackendTool
{
    return new class($parameters, $name) extends BaseBackendTool {
        public function __construct(private readonly array $params, private readonly string $toolName)
        {
        }

        public function name(): string { return $this->toolName; }
        public function description(): string { return 'demo'; }
        public function parameters(): array { return $this->params; }

        public function handle(array $args, ToolContext $ctx): ToolResult
        {
            return ToolResult::success($args);
        }
    };
}

it('maps string property to StringSchema with description', function () {
    $tool = makeTool([
        'type' => 'object',
        'properties' => [
            'message' => ['type' => 'string', 'description' => 'el mensaje'],
        ],
        'required' => ['message'],
    ]);

    $buffer = [];
    $prism  = (new PrismToolFactory)->wrap($tool, new ToolContext(user: new FakeUser), $buffer);

    expect($prism->name())->toBe('demo_tool')
        ->and($prism->parameters())->toHaveKey('message')
        ->and($prism->parameters()['message'])->toBeInstanceOf(StringSchema::class)
        ->and($prism->requiredParameters())->toBe(['message']);
});

it('maps integer and number properties to NumberSchema (Prism does not distinguish)', function () {
    $tool = makeTool([
        'type' => 'object',
        'properties' => [
            'qty'   => ['type' => 'integer', 'description' => 'cantidad'],
            'price' => ['type' => 'number',  'description' => 'precio'],
        ],
        'required' => [],
    ]);

    $buffer = [];
    $prism  = (new PrismToolFactory)->wrap($tool, new ToolContext(user: new FakeUser), $buffer);

    expect($prism->parameters()['qty'])->toBeInstanceOf(NumberSchema::class)
        ->and($prism->parameters()['price'])->toBeInstanceOf(NumberSchema::class)
        ->and($prism->requiredParameters())->toBe([]);
});

it('maps boolean / array / object properties and skips unsupported types silently', function () {
    $tool = makeTool([
        'type' => 'object',
        'properties' => [
            'enabled' => ['type' => 'boolean'],
            'tags'    => ['type' => 'array'],
            'meta'    => ['type' => 'object'],
            'wat'     => ['type' => 'unknown_kind'],
            'noType'  => ['description' => 'sin type'],
        ],
        'required' => [],
    ]);

    $buffer = [];
    $prism  = (new PrismToolFactory)->wrap($tool, new ToolContext(user: new FakeUser), $buffer);

    expect($prism->parameters())->toHaveKey('enabled')
        ->and($prism->parameters()['enabled'])->toBeInstanceOf(BooleanSchema::class)
        ->and($prism->parameters()['tags'])->toBeInstanceOf(ArraySchema::class)
        ->and($prism->parameters()['meta'])->toBeInstanceOf(ObjectSchema::class)
        ->and($prism->parameters())->not->toHaveKey('wat')
        ->and($prism->parameters())->not->toHaveKey('noType');
});

it('translates a property with `enum` into an EnumSchema regardless of declared type', function () {
    $tool = makeTool([
        'type' => 'object',
        'properties' => [
            'status' => [
                'type' => 'string',
                'enum' => ['paid', 'pending', 'cancelled'],
                'description' => 'estado',
            ],
        ],
        'required' => ['status'],
    ]);

    $buffer = [];
    $prism  = (new PrismToolFactory)->wrap($tool, new ToolContext(user: new FakeUser), $buffer);

    expect($prism->parameters()['status'])->toBeInstanceOf(EnumSchema::class)
        ->and($prism->parameters()['status']->toArray()['enum'])->toBe(['paid', 'pending', 'cancelled']);
});

it('returns an empty-parameter Prism tool when the schema is not type=object', function () {
    $tool = makeTool([
        'type' => 'string', // no `object` → applyParameters early-returns
    ]);

    $buffer = [];
    $prism  = (new PrismToolFactory)->wrap($tool, new ToolContext(user: new FakeUser), $buffer);

    expect($prism->hasParameters())->toBeFalse();
});

it('returns an empty-parameter Prism tool when properties is missing or empty', function () {
    $tool = makeTool([
        'type'       => 'object',
        'properties' => [],
        'required'   => [],
    ]);

    $buffer = [];
    $prism  = (new PrismToolFactory)->wrap($tool, new ToolContext(user: new FakeUser), $buffer);

    expect($prism->hasParameters())->toBeFalse();
});

it('falls back to the tool name when description is empty', function () {
    $tool = new class extends BaseBackendTool {
        public function name(): string { return 'no_desc_tool'; }
        public function description(): string { return ''; }
        public function parameters(): array { return ['type' => 'object', 'properties' => [], 'required' => []]; }

        public function handle(array $args, ToolContext $ctx): ToolResult
        {
            return ToolResult::success();
        }
    };

    $buffer = [];
    $prism  = (new PrismToolFactory)->wrap($tool, new ToolContext(user: new FakeUser), $buffer);

    expect($prism->description())->toBe('no_desc_tool');
});

it('drains the FIFO buffer when the closure is invoked, in registration order', function () {
    $tool = makeTool([
        'type' => 'object',
        'properties' => ['x' => ['type' => 'integer']],
        'required' => [],
    ], name: 'fifo_tool');

    $buffer = [
        'fifo_tool' => [
            ToolResult::success(['n' => 1]),
            ToolResult::success(['n' => 2]),
        ],
    ];

    $prism  = (new PrismToolFactory)->wrap($tool, new ToolContext(user: new FakeUser), $buffer);

    // Access the registered closure via Reflection — Prism does not expose a public getter.
    $reflection = new \ReflectionClass($prism);
    $prop = $reflection->getProperty('fn');
    $prop->setAccessible(true);
    $closure = $prop->getValue($prism);

    $first  = $closure();
    $second = $closure();

    expect(json_decode($first, true))->toMatchArray(['status' => 'ok', 'data' => ['n' => 1]])
        ->and(json_decode($second, true))->toMatchArray(['status' => 'ok', 'data' => ['n' => 2]])
        ->and($buffer['fifo_tool'])->toBe([]);
});

it('shares the FIFO buffer when wrappers are built inside a ref-capturing closure (Bug #1 regression)', function () {
    // Reproduce the shape of ChatService::handle() — wrappers built inside a
    // closure that captures the buffer by reference. Prior to v1.1 this used
    // an arrow function, which captures by VALUE, so onToolCall's writes were
    // invisible to the inner closure and every backend call returned the
    // "No precomputed tool result available." fallback.
    $tool = makeTool([
        'type' => 'object',
        'properties' => [],
        'required' => [],
    ], name: 'shared_tool');

    $buffer  = [];
    $factory = new PrismToolFactory;
    $ctx     = new ToolContext(user: new FakeUser);

    $build = function () use ($factory, $tool, $ctx, &$buffer): \Prism\Prism\Tool {
        return $factory->wrap($tool, $ctx, $buffer);
    };
    $prism = $build();

    // Simulate onToolCall() writing to the outer buffer AFTER the wrapper was
    // built. With the regression-free closure, the inner closure must see this.
    $buffer['shared_tool'][] = ToolResult::success(['from' => 'outer']);

    $reflection = new \ReflectionClass($prism);
    $prop = $reflection->getProperty('fn');
    $prop->setAccessible(true);
    $closure = $prop->getValue($prism);

    $payload = json_decode($closure(), true);

    expect($payload)->toMatchArray(['status' => 'ok', 'data' => ['from' => 'outer']])
        ->and($buffer['shared_tool'])->toBe([]);
});

it('returns a runtime error JSON when the FIFO buffer is empty (defensive fallback)', function () {
    $tool = makeTool([
        'type' => 'object',
        'properties' => [],
        'required' => [],
    ], name: 'lonely_tool');

    $buffer = []; // no precomputed result for this tool name

    $prism  = (new PrismToolFactory)->wrap($tool, new ToolContext(user: new FakeUser), $buffer);

    $reflection = new \ReflectionClass($prism);
    $prop = $reflection->getProperty('fn');
    $prop->setAccessible(true);
    $closure = $prop->getValue($prism);

    $payload = json_decode($closure(), true);

    expect($payload)->toMatchArray([
        'status'  => 'error',
        'error'   => 'runtime',
        'message' => 'No precomputed tool result available.',
    ]);
});
