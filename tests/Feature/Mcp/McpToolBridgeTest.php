<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Rnkr69\LaraChatbot\Mcp\McpBackendTool;
use Rnkr69\LaraChatbot\Mcp\McpToolBridge;
use Rnkr69\LaraChatbot\Tests\Stubs\Mcp\FakeMcpToolBridge;
use Rnkr69\LaraChatbot\Tests\Stubs\Mcp\PrismToolFactory;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;

uses()->group('mcp');

beforeEach(function () {
    app(ToolRegistry::class)->clear();
});

function makeFakeBridge(): FakeMcpToolBridge
{
    return new FakeMcpToolBridge(
        app(),
        app('config'),
        app('cache.store'),
    );
}

it('isAvailable() returns false when prism-php/relay is not installed', function () {
    // El paquete real prism-php/relay NO está en vendor/ del paquete: la
    // dependencia es opt-in del host. El bridge real debe reflejarlo.
    $bridge = app(McpToolBridge::class);

    expect($bridge->isAvailable())->toBeFalse();
});

it('registerInto() does nothing when the bridge is not available', function () {
    $bridge   = makeFakeBridge();
    $bridge->availability = false;
    $bridge->setTools('tickets', [PrismToolFactory::string('list')]);

    config()->set('chatbot.mcp.servers', ['tickets' => ['enabled' => true]]);

    $registry = app(ToolRegistry::class);
    $counts   = $bridge->registerInto($registry);

    expect($counts)->toBe([])
        ->and($registry->all())->toBe([]);
});

it('registerInto() registers MCP tools with mcp.<server>.<tool> prefix', function () {
    config()->set('chatbot.mcp.servers', ['tickets' => ['enabled' => true]]);

    $bridge = makeFakeBridge();
    $bridge->setTools('tickets', [
        PrismToolFactory::string('list_open'),
        PrismToolFactory::string('search'),
    ]);

    $registry = app(ToolRegistry::class);
    $counts   = $bridge->registerInto($registry);

    expect($counts)->toBe(['tickets' => 2])
        ->and($registry->has('mcp.tickets.list_open'))->toBeTrue()
        ->and($registry->has('mcp.tickets.search'))->toBeTrue()
        ->and($registry->get('mcp.tickets.list_open'))->toBeInstanceOf(McpBackendTool::class);
});

it('registerInto() skips servers with enabled=false', function () {
    config()->set('chatbot.mcp.servers', [
        'tickets' => ['enabled' => true],
        'old'     => ['enabled' => false],
    ]);

    $bridge = makeFakeBridge();
    $bridge->setTools('tickets', [PrismToolFactory::string('list')]);
    $bridge->setTools('old', [PrismToolFactory::string('legacy')]);

    $registry = app(ToolRegistry::class);
    $counts   = $bridge->registerInto($registry);

    expect($counts)->toBe(['tickets' => 1])
        ->and($registry->has('mcp.tickets.list'))->toBeTrue()
        ->and($registry->has('mcp.old.legacy'))->toBeFalse();
});

it('registerInto() isolates a failing server and continues with the rest', function () {
    Log::spy();

    config()->set('chatbot.mcp.servers', [
        'broken' => ['enabled' => true],
        'good'   => ['enabled' => true],
    ]);

    $bridge = makeFakeBridge();
    $bridge->failServer('broken', new \RuntimeException('connection refused'));
    $bridge->setTools('good', [PrismToolFactory::string('ping')]);

    $registry = app(ToolRegistry::class);
    $counts   = $bridge->registerInto($registry);

    expect($counts)->toBe(['good' => 1])
        ->and($registry->has('mcp.good.ping'))->toBeTrue()
        ->and($registry->has('mcp.broken.anything'))->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $msg) => str_contains($msg, 'broken') && str_contains($msg, 'connection refused'))
        ->once();
});

it('MCP tools register without requiring a TenantResolver', function () {
    // Si el adapter declarara tenantScope=true, el ToolRegistry exigiría
    // un TenantResolver bind y lanzaría MissingTenantResolverException al
    // registrar — lo cual rompería el boot del paquete por el sólo hecho
    // de declarar un server MCP. El adapter debe declarar tenantScope=false.
    config()->set('chatbot.mcp.servers', ['tickets' => ['enabled' => true]]);

    $bridge = makeFakeBridge();
    $bridge->setTools('tickets', [PrismToolFactory::string('list')]);

    $registry = app(ToolRegistry::class);

    expect(fn () => $bridge->registerInto($registry))
        ->not->toThrow(\Rnkr69\LaraChatbot\Tools\Exceptions\MissingTenantResolverException::class);
});

it('caches the tool list per server when cache_ttl > 0', function () {
    config()->set('chatbot.mcp.servers', [
        'tickets' => ['enabled' => true, 'cache_ttl' => 60],
    ]);

    $bridge = makeFakeBridge();
    $bridge->setTools('tickets', [PrismToolFactory::string('list')]);

    $registry = app(ToolRegistry::class);
    $bridge->registerInto($registry);

    // Re-clear y registrar de nuevo: la cache debe servir el segundo hit.
    $registry->clear();
    $bridge->registerInto($registry);

    expect($bridge->callsByServer['tickets'] ?? 0)->toBe(1)
        ->and($registry->has('mcp.tickets.list'))->toBeTrue();
});

it('does not cache when cache_ttl is 0 or absent', function () {
    config()->set('chatbot.mcp.servers', [
        'tickets' => ['enabled' => true, 'cache_ttl' => 0],
    ]);

    $bridge = makeFakeBridge();
    $bridge->setTools('tickets', [PrismToolFactory::string('list')]);

    $registry = app(ToolRegistry::class);
    $bridge->registerInto($registry);
    $registry->clear();
    $bridge->registerInto($registry);

    expect($bridge->callsByServer['tickets'] ?? 0)->toBe(2);
});

it('serverConfigs() ignores non-array entries and empty keys', function () {
    config()->set('chatbot.mcp.servers', [
        'tickets' => ['enabled' => true],
        ''        => ['enabled' => true], // ignorado por nombre vacío
        42        => ['enabled' => true], // ignorado por nombre no-string
        'broken'  => 'not an array',      // normalizado a []
    ]);

    $bridge = app(McpToolBridge::class);
    $cfg    = $bridge->serverConfigs();

    expect($cfg)->toHaveKey('tickets')
        ->and($cfg)->toHaveKey('broken')
        ->and($cfg['broken'])->toBe([])
        ->and($cfg)->not->toHaveKey('')
        ->and(array_keys($cfg))->not->toContain(42);
});
