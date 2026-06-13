<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Rnkr69\LaraChatbot\Mcp\McpBackendTool;
use Rnkr69\LaraChatbot\Mcp\McpToolBridge;
use Rnkr69\LaraChatbot\Tests\Stubs\Mcp\FakeMcpToolBridge;
use Rnkr69\LaraChatbot\Tests\Stubs\Mcp\PrismToolFactory;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\EchoBackendTool;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\PublicTool;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;

uses()->group('mcp');

beforeEach(function () {
    app(ToolRegistry::class)->clear();
});

it('reports empty registry when no tools are registered', function () {
    config()->set('chatbot.mcp.servers', []);

    Artisan::call('chatbot:tools:list');
    $output = Artisan::output();

    expect($output)->toContain('No tools registered');
});

it('lists registered local tools with origin=local', function () {
    config()->set('chatbot.mcp.servers', []);
    app(ToolRegistry::class)->register(PublicTool::class);

    Artisan::call('chatbot:tools:list');
    $output = Artisan::output();

    expect($output)->toContain('public_tool')
        ->and($output)->toContain('local');
});

it('lists MCP tools with origin=mcp:<server>', function () {
    config()->set('chatbot.mcp.servers', []);

    $registry = app(ToolRegistry::class);
    $registry->register(new McpBackendTool(
        'tickets',
        PrismToolFactory::string('list_open'),
    ));

    Artisan::call('chatbot:tools:list');
    $output = Artisan::output();

    expect($output)->toContain('mcp.tickets.list_open')
        ->and($output)->toContain('mcp:tickets');
});

it('warns when chatbot.mcp.servers has entries but Relay is not installed', function () {
    config()->set('chatbot.mcp.servers', [
        'tickets' => ['enabled' => true],
    ]);

    Artisan::call('chatbot:tools:list');
    $output = Artisan::output();

    expect($output)->toContain('prism-php/relay')
        ->and($output)->toContain('tickets');
});

it('does not warn when no MCP servers are configured', function () {
    config()->set('chatbot.mcp.servers', []);

    Artisan::call('chatbot:tools:list');
    $output = Artisan::output();

    expect($output)->not->toContain('prism-php/relay');
});

it('shows pinnable=yes for a properly configured pinnable tool (#5)', function () {
    config()->set('chatbot.mcp.servers', []);
    $tool = new EchoBackendTool;
    $tool->pinnableOverride = true;
    $tool->confirmationOverride = ConfirmationLevel::Auto;
    app(ToolRegistry::class)->register($tool);

    Artisan::call('chatbot:tools:list');
    $output = Artisan::output();

    expect($output)->toContain('echo_tool')
        ->and($output)->toContain('yes');
});

it('warns about a tool that is pinnable() but has a non-Auto confirmation (#5)', function () {
    config()->set('chatbot.mcp.servers', []);
    $tool = new EchoBackendTool;
    $tool->pinnableOverride = true;
    $tool->confirmationOverride = ConfirmationLevel::Confirm;
    app(ToolRegistry::class)->register($tool);

    Artisan::call('chatbot:tools:list');
    $output = Artisan::output();

    // Both the inline column flag and the explicit warning block mention it.
    expect($output)->toContain('non-Auto')
        ->and($output)->toContain('echo_tool')
        ->and($output)->toContain('confirmation === Auto');
});

it('reports MCP active when servers are configured and the bridge is available', function () {
    config()->set('chatbot.mcp.servers', [
        'tickets' => ['enabled' => true],
    ]);

    $fake = new FakeMcpToolBridge(app(), app('config'), app('cache.store'));
    $fake->availability = true;

    app()->instance(McpToolBridge::class, $fake);

    Artisan::call('chatbot:tools:list');
    $output = Artisan::output();

    expect($output)->toContain('MCP bridge active')
        ->and($output)->toContain('tickets');
});
