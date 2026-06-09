<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Rnkr69\LaraChatbot\Authorization\Contracts\ScopeResolver;
use Rnkr69\LaraChatbot\Authorization\Contracts\TenantResolver;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\FakeUser;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\FixedScopeResolver;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\FixedTenantResolver;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\PermissionedTool;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\PublicTool;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\StrictArgsTool;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\TeamScopedTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;

it('returns ToolResult::error(validation) without invoking handle when args are invalid', function () {
    $tool = new StrictArgsTool;
    $ctx  = new ToolContext(user: new FakeUser);

    $result = $tool->execute([], $ctx); // missing target_id

    expect($result->isError())->toBeTrue()
        ->and($result->errorCategory)->toBe('validation')
        ->and($tool->handleCalled)->toBeFalse();
});

it('returns ToolResult::error(validation) when arg type does not match the schema', function () {
    $tool = new StrictArgsTool;
    $ctx  = new ToolContext(user: new FakeUser);

    $result = $tool->execute(['target_id' => 'not-an-int'], $ctx);

    expect($result->isError())->toBeTrue()
        ->and($result->errorCategory)->toBe('validation')
        ->and($tool->handleCalled)->toBeFalse();
});

it('invokes handle when args are valid', function () {
    $tool = new StrictArgsTool;
    $ctx  = new ToolContext(user: new FakeUser);

    $result = $tool->execute(['target_id' => 7, 'status' => 'paid'], $ctx);

    expect($result->isOk())->toBeTrue()
        ->and($tool->handleCalled)->toBeTrue();
});

it('rejects values outside the declared enum', function () {
    $tool = new StrictArgsTool;
    $ctx  = new ToolContext(user: new FakeUser);

    $result = $tool->execute(['target_id' => 1, 'status' => 'bogus'], $ctx);

    expect($result->isError())->toBeTrue()
        ->and($result->errorCategory)->toBe('validation');
});

it('returns unauthorized when the Authorizer denies', function () {
    Gate::define('orders.read', fn () => false);

    $tool = new PermissionedTool;
    $ctx  = new ToolContext(user: new FakeUser);

    $result = $tool->execute([], $ctx);

    expect($result->isError())->toBeTrue()
        ->and($result->errorCategory)->toBe('unauthorized');
});

it('runs handle when the public tool has empty permissions list', function () {
    $tool = new PublicTool;
    $ctx  = new ToolContext(user: new FakeUser);

    $result = $tool->execute([], $ctx);

    expect($result->isOk())->toBeTrue()
        ->and($tool->handleCalled)->toBeTrue();
});

it('exposes the team accessibleUserIds resolved by the registered ScopeResolver', function () {
    app()->singleton(ScopeResolver::class, fn () => new FixedScopeResolver([
        'self' => [42],
        'team' => [42, 99, 100],
        'all'  => [],
    ]));

    $tool = new TeamScopedTool;
    $ctx  = new ToolContext(user: new FakeUser);

    $result = $tool->execute([], $ctx);

    expect($result->isOk())->toBeTrue()
        ->and($tool->lastAccessibleUserIds)->toBe([42, 99, 100]);
});

it('applies whereIn(user_id) on the query builder using the team scope', function () {
    app()->singleton(ScopeResolver::class, fn () => new FixedScopeResolver([
        'self' => [42],
        'team' => [42, 99],
        'all'  => [],
    ]));

    $tool = new class extends \Rnkr69\LaraChatbot\Tools\BaseBackendTool {
        public ?string $sql = null;
        public array $bindings = [];

        public function name(): string { return 'sql_check_tool'; }
        public function description(): string { return 'inspector'; }
        public function parameters(): array { return ['type' => 'object', 'properties' => [], 'required' => []]; }
        public function defaultScope(): \Rnkr69\LaraChatbot\Authorization\AccessScope
        {
            return \Rnkr69\LaraChatbot\Authorization\AccessScope::Team;
        }

        public function handle(array $args, \Rnkr69\LaraChatbot\Tools\ToolContext $ctx): \Rnkr69\LaraChatbot\Tools\ToolResult
        {
            $query = $this->accessibleQuery(DB::table('orders'), $ctx);
            $this->sql      = $query->toSql();
            $this->bindings = $query->getBindings();

            return \Rnkr69\LaraChatbot\Tools\ToolResult::success();
        }
    };

    $tool->execute([], new ToolContext(user: new FakeUser));

    expect($tool->sql)->toContain('"user_id" in (?, ?)')
        ->and($tool->bindings)->toBe([42, 99]);
});

it('also applies whereIn(tenant_column) when tenantScope is true and the resolver returns a list', function () {
    app()->singleton(ScopeResolver::class, fn () => new FixedScopeResolver([
        'self' => [42], 'team' => [42], 'all' => [],
    ]));
    app()->singleton(TenantResolver::class, fn () => new FixedTenantResolver([10, 20]));

    $tool = new class extends \Rnkr69\LaraChatbot\Tools\BaseBackendTool {
        public array $bindings = [];

        public function name(): string { return 'tenant_sql_tool'; }
        public function description(): string { return 'inspector'; }
        public function parameters(): array { return ['type' => 'object', 'properties' => [], 'required' => []]; }
        public function tenantScope(): bool { return true; }

        public function handle(array $args, \Rnkr69\LaraChatbot\Tools\ToolContext $ctx): \Rnkr69\LaraChatbot\Tools\ToolResult
        {
            $q = $this->accessibleQuery(DB::table('orders'), $ctx, tenantColumn: 'corporation_id');
            $this->bindings = $q->getBindings();

            return \Rnkr69\LaraChatbot\Tools\ToolResult::success();
        }
    };

    $tool->execute([], new ToolContext(user: new FakeUser));

    // Bindings: primero los user_ids ([42]), luego los tenant_ids ([10, 20]).
    expect($tool->bindings)->toBe([42, 10, 20]);
});

it('honors defaultScopeFor($user) when overridden and falls back to defaultScope() otherwise (v1.1 findings #7)', function () {
    app()->singleton(ScopeResolver::class, fn () => new FixedScopeResolver([
        'self' => [42],
        'team' => [42, 99],
        'all'  => [1, 2, 3],
    ]));

    // Tool with a user-aware scope: id=99 → All (admiral); everyone else → null
    // (falls back to the static defaultScope, here Self).
    $tool = new class extends \Rnkr69\LaraChatbot\Tools\BaseBackendTool {
        public ?string $sql = null;
        public array $bindings = [];

        public function name(): string { return 'role_aware_tool'; }
        public function description(): string { return 'admiral sees all, everyone else sees self'; }
        public function parameters(): array { return ['type' => 'object', 'properties' => [], 'required' => []]; }

        public function defaultScopeFor(\Illuminate\Contracts\Auth\Authenticatable $user): ?\Rnkr69\LaraChatbot\Authorization\AccessScope
        {
            return $user->getAuthIdentifier() === 99
                ? \Rnkr69\LaraChatbot\Authorization\AccessScope::All
                : null; // fall back to defaultScope()
        }

        public function handle(array $args, \Rnkr69\LaraChatbot\Tools\ToolContext $ctx): \Rnkr69\LaraChatbot\Tools\ToolResult
        {
            $q = $this->accessibleQuery(DB::table('orders'), $ctx);
            $this->sql      = $q->toSql();
            $this->bindings = $q->getBindings();

            return \Rnkr69\LaraChatbot\Tools\ToolResult::success();
        }
    };

    // admiral (id=99) → defaultScopeFor returns All → bindings = [1, 2, 3].
    $tool->execute([], new ToolContext(user: new FakeUser(99)));
    expect($tool->bindings)->toBe([1, 2, 3]);

    // pilot (id=42) → defaultScopeFor returns null → falls back to Self → [42].
    $tool->execute([], new ToolContext(user: new FakeUser(42)));
    expect($tool->bindings)->toBe([42]);
});

it('returns out_of_scope when tenantScope is true and the resolver returns an empty list', function () {
    app()->singleton(TenantResolver::class, fn () => new FixedTenantResolver([])); // sin acceso

    $tool = new class extends \Rnkr69\LaraChatbot\Tools\BaseBackendTool {
        public bool $handleCalled = false;

        public function name(): string { return 'no_access_tool'; }
        public function description(): string { return 'should not run'; }
        public function parameters(): array { return ['type' => 'object', 'properties' => [], 'required' => []]; }
        public function tenantScope(): bool { return true; }

        public function handle(array $args, \Rnkr69\LaraChatbot\Tools\ToolContext $ctx): \Rnkr69\LaraChatbot\Tools\ToolResult
        {
            $this->handleCalled = true;

            return \Rnkr69\LaraChatbot\Tools\ToolResult::success();
        }
    };

    $result = $tool->execute([], new ToolContext(user: new FakeUser));

    expect($result->isError())->toBeTrue()
        ->and($result->errorCategory)->toBe('out_of_scope')
        ->and($tool->handleCalled)->toBeFalse();
});

it('returns false from pinnable() by default — every v1.x tool stays non-pinnable', function () {
    // E1 — the most important back-compat invariant of the v2.0 contract:
    // adding `pinnable()` to the BackendTool interface MUST NOT silently
    // opt v1.x tools into the dashboard pin flow. Any tool that has not
    // explicitly overridden the method inherits `false` from BaseBackendTool.
    expect((new PublicTool)->pinnable())->toBeFalse()
        ->and((new PermissionedTool)->pinnable())->toBeFalse()
        ->and((new StrictArgsTool)->pinnable())->toBeFalse();
});

it('returns the override when a tool opts into pinnable()', function () {
    $tool = new class extends \Rnkr69\LaraChatbot\Tools\BaseBackendTool {
        public function name(): string { return 'pinnable_tool'; }
        public function description(): string { return 'opt-in to pinning'; }
        public function parameters(): array { return ['type' => 'object', 'properties' => [], 'required' => []]; }
        public function pinnable(): bool { return true; }

        public function handle(array $args, \Rnkr69\LaraChatbot\Tools\ToolContext $ctx): \Rnkr69\LaraChatbot\Tools\ToolResult
        {
            return \Rnkr69\LaraChatbot\Tools\ToolResult::success();
        }
    };

    expect($tool->pinnable())->toBeTrue();
});


