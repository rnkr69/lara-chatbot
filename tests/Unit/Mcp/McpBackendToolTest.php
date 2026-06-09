<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Authorization\AccessScope;
use Rnkr69\LaraChatbot\Mcp\McpBackendTool;
use Rnkr69\LaraChatbot\Tests\Stubs\Mcp\PrismToolFactory;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\FakeUser;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Prism\Prism\Tool as PrismTool;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;

uses()->group('mcp');

it('prefixes the tool name with mcp.<server>.<tool>', function () {
    $adapter = new McpBackendTool(
        serverName: 'tickets',
        prismTool: PrismToolFactory::string('search_open'),
    );

    expect($adapter->name())->toBe('mcp.tickets.search_open');
});

it('forwards description and parameters from the underlying Prism tool', function () {
    $adapter = new McpBackendTool(
        'tickets',
        PrismToolFactory::withParam('lookup', 'q'),
    );

    expect($adapter->description())->toBe('A fake MCP tool with one string param.')
        ->and($adapter->parameters())->toMatchArray([
            'type'     => 'object',
            'required' => ['q'],
        ])
        ->and($adapter->parameters()['properties'])->toHaveKey('q');
});

it('emits an empty-object placeholder when the prism tool has no parameters', function () {
    $adapter = new McpBackendTool(
        'tickets',
        PrismToolFactory::string('ping'),
    );

    $params = $adapter->parameters();

    expect($params['type'])->toBe('object')
        ->and($params['properties'])->toBeInstanceOf(stdClass::class)
        ->and($params)->not->toHaveKey('required');
});

it('reads permissions from the server config and ignores non-string entries', function () {
    $adapter = new McpBackendTool(
        'tickets',
        PrismToolFactory::string('list'),
        ['permissions' => ['tickets.use_mcp', 42, null, 'tickets.read']],
    );

    expect($adapter->permissions())->toBe(['tickets.use_mcp', 'tickets.read']);
});

it('returns no permissions when the server config omits them', function () {
    $adapter = new McpBackendTool(
        'tickets',
        PrismToolFactory::string('list'),
    );

    expect($adapter->permissions())->toBe([]);
});

it('declares defaultScope=All, confirmation=Auto, tenantScope=false', function () {
    $adapter = new McpBackendTool(
        'tickets',
        PrismToolFactory::string('list'),
    );

    expect($adapter->defaultScope())->toBe(AccessScope::All)
        ->and($adapter->confirmation())->toBe(ConfirmationLevel::Auto)
        ->and($adapter->tenantScope())->toBeFalse();
});

it('handle() wraps a string result into ToolResult::success', function () {
    $adapter = new McpBackendTool(
        'tickets',
        PrismToolFactory::string('echo'),
    );

    $result = $adapter->handle([], new ToolContext(user: new FakeUser));

    expect($result->isOk())->toBeTrue()
        ->and($result->data)->toBe(['result' => 'OK from echo']);
});

it('handle() spreads args into the prism tool as named parameters', function () {
    $adapter = new McpBackendTool(
        'tickets',
        PrismToolFactory::withParam('echo', 'q'),
    );

    $result = $adapter->handle(['q' => 'hi'], new ToolContext(user: new FakeUser));

    expect($result->isOk())->toBeTrue()
        ->and($result->data)->toBe(['result' => 'echo:hi']);
});

it('handle() unwraps a ToolOutput into ToolResult::success', function () {
    $tool = (new PrismTool)
        ->as('rich')
        ->for('Returns a ToolOutput VO.')
        ->withoutErrorHandling();
    $tool->using(fn () => new ToolOutput('payload from MCP'));

    $adapter = new McpBackendTool('tickets', $tool);
    $result  = $adapter->handle([], new ToolContext(user: new FakeUser));

    expect($result->isOk())->toBeTrue()
        ->and($result->data)->toBe(['result' => 'payload from MCP']);
});

it('handle() converts a ToolError return into ToolResult::error(runtime)', function () {
    $tool = (new PrismTool)
        ->as('noisy')
        ->for('Returns a ToolError VO.')
        ->withoutErrorHandling()
        ->failed(fn (\Throwable $e) => 'remote failed: ' . $e->getMessage());
    $tool->using(function (): never {
        throw new \RuntimeException('remote 500');
    });

    $adapter = new McpBackendTool('tickets', $tool);
    $result  = $adapter->handle([], new ToolContext(user: new FakeUser));

    expect($result->isError())->toBeTrue()
        ->and($result->errorCategory)->toBe('runtime')
        ->and($result->errorMessage)->toContain('remote 500');
});

it('handle() catches an uncaught exception and returns ToolResult::error(runtime)', function () {
    $tool = (new PrismTool)
        ->as('crash')
        ->for('Throws raw.')
        ->withoutErrorHandling();
    $tool->using(function (): never {
        throw new \RuntimeException('boom');
    });

    $adapter = new McpBackendTool('tickets', $tool);
    $result  = $adapter->handle([], new ToolContext(user: new FakeUser));

    expect($result->isError())->toBeTrue()
        ->and($result->errorCategory)->toBe('runtime')
        ->and($result->errorMessage)->toBe('boom');
});

it('exposes serverName() and prismTool() for diagnostics', function () {
    $prism   = PrismToolFactory::string('list');
    $adapter = new McpBackendTool('tickets', $prism);

    expect($adapter->serverName())->toBe('tickets')
        ->and($adapter->prismTool())->toBe($prism);
});
