<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Tests\Stubs\Tools\FakeUser;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\Contracts\FrontendTool;
use Rnkr69\LaraChatbot\Tools\Frontend\FillFormTool;
use Rnkr69\LaraChatbot\Tools\Frontend\InvokeHostActionTool;
use Rnkr69\LaraChatbot\Tools\Frontend\NavigateTool;
use Rnkr69\LaraChatbot\Tools\Frontend\OpenModalTool;
use Rnkr69\LaraChatbot\Tools\Frontend\RenderBlockTool;
use Rnkr69\LaraChatbot\Tools\Frontend\ShowToastTool;
use Rnkr69\LaraChatbot\Tools\Frontend\ToggleVisibilityTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;

/*
|--------------------------------------------------------------------------
| Primitivas FE — DoD ROADMAP §5/E11: test unitario por primitiva (validación
| de args), confirmation por defecto, y "shim queued" sin efectos secundarios.
|--------------------------------------------------------------------------
|
| `DownloadFileTool` tiene su propio archivo (DownloadFileToolTest.php) por
| volumen y por exigir Storage::fake().
*/

it('all primitives implement FrontendTool', function () {
    $primitives = [
        NavigateTool::class,
        ToggleVisibilityTool::class,
        FillFormTool::class,
        ShowToastTool::class,
        OpenModalTool::class,
        RenderBlockTool::class,
        InvokeHostActionTool::class,
    ];

    foreach ($primitives as $class) {
        $instance = new $class;
        expect($instance)->toBeInstanceOf(FrontendTool::class);
    }
});

it('all primitives expose unique snake_case names', function () {
    $names = [
        (new NavigateTool)->name(),
        (new ToggleVisibilityTool)->name(),
        (new FillFormTool)->name(),
        (new ShowToastTool)->name(),
        (new OpenModalTool)->name(),
        (new RenderBlockTool)->name(),
        (new InvokeHostActionTool)->name(),
    ];

    expect($names)->toBe([
        'navigate',
        'toggle_visibility',
        'fill_form',
        'show_toast',
        'open_modal',
        'render_block',
        'invoke_host_action',
    ]);

    expect($names)->toBe(array_unique($names));
});

it('all primitives expose a non-empty description and an object schema', function () {
    $primitives = [
        new NavigateTool,
        new ToggleVisibilityTool,
        new FillFormTool,
        new ShowToastTool,
        new OpenModalTool,
        new RenderBlockTool,
        new InvokeHostActionTool,
    ];

    foreach ($primitives as $tool) {
        expect($tool->description())->toBeString()->not->toBe('');
        $schema = $tool->parameters();
        expect($schema)->toHaveKey('type', 'object')
            ->and($schema)->toHaveKey('properties');
    }
});

it('confirmation defaults: navigate / toggle / toast / render_block / open_modal => auto', function () {
    expect((new NavigateTool)->confirmation())->toBe(ConfirmationLevel::Auto)
        ->and((new ToggleVisibilityTool)->confirmation())->toBe(ConfirmationLevel::Auto)
        ->and((new ShowToastTool)->confirmation())->toBe(ConfirmationLevel::Auto)
        ->and((new RenderBlockTool)->confirmation())->toBe(ConfirmationLevel::Auto)
        ->and((new OpenModalTool)->confirmation())->toBe(ConfirmationLevel::Auto);
});

it('confirmation default: fill_form => confirm (covers submit=true case)', function () {
    expect((new FillFormTool)->confirmation())->toBe(ConfirmationLevel::Confirm);
});

it('confirmation default: invoke_host_action => auto (v1.1.3, finding #19)', function () {
    expect((new InvokeHostActionTool)->confirmation())->toBe(ConfirmationLevel::Auto);
});

it('NavigateTool accepts url-only args', function () {
    $tool   = new NavigateTool;
    $ctx    = new ToolContext(user: new FakeUser);
    $result = $tool->execute(['url' => '/orders'], $ctx);

    expect($result->isOk())->toBeTrue()
        ->and($result->data)->toBe([]);
});

it('NavigateTool accepts route-only args and resolves them server-side (v1.1)', function () {
    app('router')->get('/orders/{id}', fn () => null)->name('orders.show');
    app('router')->getRoutes()->refreshNameLookups();

    $tool   = new NavigateTool;
    $ctx    = new ToolContext(user: new FakeUser);
    $result = $tool->execute(['route' => 'orders.show', 'params' => ['id' => 1]], $ctx);

    // v1.1 (findings #3): NavigateTool::handle() now resolves the named route
    // server-side and merges the URL into result.data so the frontend primitive
    // (which only knows `url`) can consume it. ChatService::onToolCall does the
    // args + result.data merge before emitting the SSE frame.
    expect($result->isOk())->toBeTrue()
        ->and($result->data)->toBe(['url' => '/orders/1']);
});

it('NavigateTool errors when route does not exist (v1.1)', function () {
    $tool   = new NavigateTool;
    $ctx    = new ToolContext(user: new FakeUser);
    $result = $tool->execute(['route' => 'definitely-not-a-real-route'], $ctx);

    expect($result->isError())->toBeTrue()
        ->and($result->errorCategory)->toBe('runtime');
});

it('NavigateTool errors when params are missing for a required route segment (v1.1)', function () {
    app('router')->get('/invoices/{invoice}', fn () => null)->name('invoices.show');
    app('router')->getRoutes()->refreshNameLookups();

    $tool   = new NavigateTool;
    $ctx    = new ToolContext(user: new FakeUser);
    $result = $tool->execute(['route' => 'invoices.show'], $ctx);

    expect($result->isError())->toBeTrue()
        // UrlGenerationException → 'validation' (params del LLM están mal).
        ->and($result->errorCategory)->toBe('validation');
});

it('NavigateTool errors when neither url nor route is provided (v1.1)', function () {
    $tool   = new NavigateTool;
    $ctx    = new ToolContext(user: new FakeUser);
    $result = $tool->execute([], $ctx);

    expect($result->isError())->toBeTrue()
        ->and($result->errorCategory)->toBe('validation');
});

it('ToggleVisibilityTool requires both selector and action', function () {
    $tool   = new ToggleVisibilityTool;
    $ctx    = new ToolContext(user: new FakeUser);

    expect($tool->execute([], $ctx)->isError())->toBeTrue()
        ->and($tool->execute(['selector' => '#x'], $ctx)->isError())->toBeTrue()
        ->and($tool->execute(['action' => 'show'], $ctx)->isError())->toBeTrue()
        ->and($tool->execute(['selector' => '#x', 'action' => 'show'], $ctx)->isOk())->toBeTrue();
});

it('ToggleVisibilityTool rejects unknown action', function () {
    $tool   = new ToggleVisibilityTool;
    $ctx    = new ToolContext(user: new FakeUser);
    $result = $tool->execute(['selector' => '#x', 'action' => 'collapse'], $ctx);

    expect($result->isError())->toBeTrue()
        ->and($result->errorCategory)->toBe('validation');
});

it('FillFormTool requires fields[] only — selector / form_id are optional (v1.1.1 #9.a + v1.1.2 #9.f)', function () {
    $tool   = new FillFormTool;
    $ctx    = new ToolContext(user: new FakeUser);

    // Laravel's `required` on array fields demands at least one entry; we
    // accept that semantic for fill_form (an empty `fields[]` is meaningless).
    // Since v1.1.1, calls without `form_id` are valid: the JS primitive
    // auto-discovers the first plausible form on the page. Since v1.1.2
    // (finding #9.f) `selector` is the preferred targeting field, but it
    // is also optional — and either path is fine.
    expect($tool->execute([], $ctx)->isError())->toBeTrue()
        ->and($tool->execute(['form_id' => 'f'], $ctx)->isError())->toBeTrue()
        ->and($tool->execute(['selector' => '[bp-section="crud-operation-create"] form'], $ctx)->isError())->toBeTrue()
        ->and($tool->execute(['fields' => [['name' => 'x', 'value' => 'y']]], $ctx)->isOk())->toBeTrue()
        ->and($tool->execute(['form_id' => 'f', 'fields' => [['name' => 'x', 'value' => 'y']]], $ctx)->isOk())->toBeTrue()
        ->and($tool->execute(['selector' => '[bp-section="crud-operation-create"] form', 'fields' => [['name' => 'x', 'value' => 'y']]], $ctx)->isOk())->toBeTrue();
});

it('FillFormTool returns a "queued"-shaped success without side effects (shim)', function () {
    $tool   = new FillFormTool;
    $ctx    = new ToolContext(user: new FakeUser);
    $result = $tool->execute([
        'form_id' => 'order-form',
        'fields'  => [['name' => 'qty', 'value' => 3]],
        'submit'  => true,
    ], $ctx);

    expect($result->isOk())->toBeTrue()
        ->and($result->data)->toBe([])  // base shim — no extra payload
        ->and($result->blocks)->toBe([]);
});

it('ShowToastTool requires message', function () {
    $tool   = new ShowToastTool;
    $ctx    = new ToolContext(user: new FakeUser);

    expect($tool->execute([], $ctx)->isError())->toBeTrue()
        ->and($tool->execute(['message' => 'Hi'], $ctx)->isOk())->toBeTrue();
});

it('ShowToastTool rejects unknown level', function () {
    $tool   = new ShowToastTool;
    $ctx    = new ToolContext(user: new FakeUser);
    $result = $tool->execute(['message' => 'Hi', 'level' => 'fatal'], $ctx);

    expect($result->isError())->toBeTrue();
});

it('OpenModalTool requires title and block', function () {
    $tool   = new OpenModalTool;
    $ctx    = new ToolContext(user: new FakeUser);

    expect($tool->execute(['title' => 'X'], $ctx)->isError())->toBeTrue()
        ->and($tool->execute(['block' => ['type' => 'card']], $ctx)->isError())->toBeTrue()
        ->and($tool->execute(['title' => 'X', 'block' => ['type' => 'card', 'data' => []]], $ctx)->isOk())->toBeTrue();
});

it('RenderBlockTool requires type and data', function () {
    $tool   = new RenderBlockTool;
    $ctx    = new ToolContext(user: new FakeUser);

    expect($tool->execute([], $ctx)->isError())->toBeTrue()
        ->and($tool->execute(['type' => 'card'], $ctx)->isError())->toBeTrue()
        ->and($tool->execute(['type' => 'card', 'data' => ['title' => 'x']], $ctx)->isOk())->toBeTrue();
});

it('InvokeHostActionTool requires action_name', function () {
    $tool   = new InvokeHostActionTool;
    $ctx    = new ToolContext(user: new FakeUser);

    expect($tool->execute([], $ctx)->isError())->toBeTrue()
        ->and($tool->execute(['action_name' => 'refreshGrid'], $ctx)->isOk())->toBeTrue();
});

it('all primitives default to public permissions (empty list) and Self scope', function () {
    $primitives = [
        new NavigateTool,
        new ToggleVisibilityTool,
        new FillFormTool,
        new ShowToastTool,
        new OpenModalTool,
        new RenderBlockTool,
        new InvokeHostActionTool,
    ];

    foreach ($primitives as $tool) {
        expect($tool->permissions())->toBe([])
            ->and($tool->tenantScope())->toBeFalse();
    }
});

it('BaseFrontendTool default handle returns success([]) without invoking BD work', function () {
    // Regression guard: the shim must NOT throw / NOT touch DB / NOT do
    // anything beyond returning an empty success.
    $tool   = new NavigateTool;
    $ctx    = new ToolContext(user: new FakeUser);
    $result = $tool->handle(['url' => '/'], $ctx);

    expect($result->isOk())->toBeTrue()
        ->and($result->data)->toBe([]);
});
