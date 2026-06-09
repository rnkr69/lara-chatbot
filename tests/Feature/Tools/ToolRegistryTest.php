<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Rnkr69\LaraChatbot\Authorization\Contracts\TenantResolver;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\FakeUser;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\FixedTenantResolver;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\PermissionedTool;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\PublicTool;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\TenantScopedTool;
use Rnkr69\LaraChatbot\Tools\Exceptions\MissingTenantResolverException;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;

it('registers and retrieves a tool by name', function () {
    $registry = app(ToolRegistry::class)->clear();
    $registry->register(PublicTool::class);

    expect($registry->has('public_tool'))->toBeTrue()
        ->and($registry->get('public_tool'))->toBeInstanceOf(PublicTool::class)
        ->and($registry->all())->toHaveCount(1);
});

it('forUser includes a public tool for any user', function () {
    $registry = app(ToolRegistry::class)->clear();
    $registry->register(PublicTool::class);

    $tools = $registry->forUser(new FakeUser);

    expect($tools)->toHaveKey('public_tool');
});

it('forUser hides a permissioned tool when Gate denies', function () {
    Gate::define('orders.read', fn () => false);

    $registry = app(ToolRegistry::class)->clear();
    $registry->register(PermissionedTool::class);

    $tools = $registry->forUser(new FakeUser);

    expect($tools)->not->toHaveKey('permissioned_tool');
});

it('forUser includes a permissioned tool when Gate grants', function () {
    Gate::define('orders.read', fn () => true);

    $registry = app(ToolRegistry::class)->clear();
    $registry->register(PermissionedTool::class);

    $tools = $registry->forUser(new FakeUser);

    expect($tools)->toHaveKey('permissioned_tool');
});

it('throws MissingTenantResolverException when registering a tenantScope tool without a resolver bound', function () {
    $registry = app(ToolRegistry::class)->clear();

    expect(fn () => $registry->register(TenantScopedTool::class))
        ->toThrow(MissingTenantResolverException::class);
});

it('accepts a tenantScope tool when the host has bound a TenantResolver', function () {
    app()->singleton(TenantResolver::class, fn () => new FixedTenantResolver([10, 20]));

    $registry = app(ToolRegistry::class)->clear();
    $registry->register(TenantScopedTool::class);

    expect($registry->has('tenant_scoped_tool'))->toBeTrue();
});

it('registerMany accepts a list of class strings', function () {
    Gate::define('orders.read', fn () => true);

    $registry = app(ToolRegistry::class)->clear();
    $registry->registerMany([PublicTool::class, PermissionedTool::class]);

    expect($registry->all())->toHaveCount(2)
        ->and($registry->has('public_tool'))->toBeTrue()
        ->and($registry->has('permissioned_tool'))->toBeTrue();
});

it('discover scans a directory and registers concrete tool classes', function () {
    // El directorio de stubs incluye TenantScopedTool, que exige bind. Lo
    // hacemos antes del scan para que `discover` no lance al recorrerlas.
    app()->singleton(TenantResolver::class, fn () => new FixedTenantResolver);

    $registry = app(ToolRegistry::class)->clear();
    $registry->discover(['Stubs/Tools'], basePath: dirname(__DIR__, 2));

    expect($registry->has('public_tool'))->toBeTrue()
        ->and($registry->has('permissioned_tool'))->toBeTrue()
        ->and($registry->has('strict_args_tool'))->toBeTrue()
        ->and($registry->has('team_scoped_tool'))->toBeTrue()
        ->and($registry->has('tenant_scoped_tool'))->toBeTrue();
});

it('discover does not register the abstract BaseBackendTool', function () {
    app()->singleton(TenantResolver::class, fn () => new FixedTenantResolver);

    $registry = app(ToolRegistry::class)->clear();
    $registry->discover(['Stubs/Tools'], basePath: dirname(__DIR__, 2));

    foreach ($registry->all() as $tool) {
        $reflection = new \ReflectionClass($tool);
        expect($reflection->isAbstract())->toBeFalse();
    }
});
