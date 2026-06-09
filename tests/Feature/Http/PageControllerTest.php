<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;

/**
 * E17 — `GET /chatbot` (página dedicada de chat).
 *
 * Cubre el shape público:
 *   - autenticación heredada del middleware del grupo
 *   - render standalone vs `@extends($layout)`
 *   - fallback con log warning si el layout configurado no existe
 *   - desactivación vía `chatbot.page.enabled = false`
 */

beforeEach(function () {
    $this->artisan('migrate')->run();
});

function makePageUser(int $id = 1): TestUser
{
    $user = new TestUser(['id' => $id, 'name' => "User-{$id}"]);
    $user->setRawAttributes(['id' => $id, 'name' => "User-{$id}"], sync: true);

    return $user;
}

it('renders the page with status 200 for authenticated users', function () {
    $user = makePageUser();

    $response = $this->actingAs($user, 'web')->get('/chatbot');

    $response->assertOk();
});

it('mounts the chatbot widget with mode="page" attribute', function () {
    $user = makePageUser();

    $response = $this->actingAs($user, 'web')->get('/chatbot');

    $response->assertOk();
    $body = $response->getContent();
    expect($body)->toContain('<chatbot-widget');
    expect($body)->toContain('mode="page"');
});

it('exposes the SSE and conversations endpoints to the widget', function () {
    $user = makePageUser();

    $response = $this->actingAs($user, 'web')->get('/chatbot');

    $body = $response->getContent();
    expect($body)->toContain('data-endpoint="' . url('/chatbot/stream') . '"');
    expect($body)->toContain('data-conversations-endpoint="' . url('/chatbot/conversations') . '"');
});

it('renders standalone HTML when chatbot.page.layout is null', function () {
    config()->set('chatbot.page.layout', null);
    $user = makePageUser();

    $response = $this->actingAs($user, 'web')->get('/chatbot');

    $body = $response->getContent();
    // Standalone: includes <!DOCTYPE html> and <head>; no @extends boilerplate.
    expect($body)->toContain('<!DOCTYPE html>');
    expect($body)->toContain('<title>');
});

it('renders a "back to app" link in standalone mode when back_url is set (#26)', function () {
    config()->set('chatbot.page.layout', null);
    config()->set('chatbot.page.back_url', '/admin');
    $user = makePageUser();

    $response = $this->actingAs($user, 'web')->get('/chatbot');

    $body = $response->getContent();
    expect($body)->toContain('<nav class="cb-standalone-bar">');
    expect($body)->toContain('href="/admin"');
    expect($body)->toContain('Back to app');
});

it('omits the "back to app" link in standalone mode when back_url is null (#26)', function () {
    config()->set('chatbot.page.layout', null);
    config()->set('chatbot.page.back_url', null);
    $user = makePageUser();

    $response = $this->actingAs($user, 'web')->get('/chatbot');

    // The `.cb-standalone-bar` CSS rule is always in the <style> block; assert
    // on the <nav> element, which only renders when back_url is set.
    expect($response->getContent())->not->toContain('<nav class="cb-standalone-bar">');
});

it('extends the host layout when chatbot.page.layout points to an existing view', function () {
    // Create a fake layout in a temp namespace so View::exists picks it up.
    View::addNamespace('chatbottest', __DIR__ . '/../../Stubs/views');
    @mkdir(__DIR__ . '/../../Stubs/views', 0775, recursive: true);
    file_put_contents(
        __DIR__ . '/../../Stubs/views/host_layout.blade.php',
        '<html><head><title>HOST LAYOUT</title>@stack(\'head\')</head><body>@yield(\'content\')</body></html>',
    );

    config()->set('chatbot.page.layout', 'chatbottest::host_layout');
    config()->set('chatbot.page.section', 'content');

    $user = makePageUser();
    $response = $this->actingAs($user, 'web')->get('/chatbot');

    $body = $response->getContent();
    expect($body)->toContain('HOST LAYOUT');
    expect($body)->toContain('<chatbot-widget');
    expect($body)->toContain('mode="page"');

    @unlink(__DIR__ . '/../../Stubs/views/host_layout.blade.php');
});

it('falls back to standalone with a log warning when the layout view does not exist', function () {
    config()->set('chatbot.page.layout', 'non.existent.layout');

    Log::spy();

    $user = makePageUser();
    $response = $this->actingAs($user, 'web')->get('/chatbot');

    $response->assertOk();
    $body = $response->getContent();
    expect($body)->toContain('<!DOCTYPE html>'); // standalone fallback
    expect($body)->toContain('<chatbot-widget');

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($msg) => str_contains((string) $msg, 'non.existent.layout'));
});

it('emits a data-i18n payload on the <chatbot-widget> element', function () {
    $user = makePageUser();
    $response = $this->actingAs($user, 'web')->get('/chatbot');

    $body = $response->getContent();
    expect($body)->toContain('data-i18n=');

    preg_match('/<chatbot-widget[^>]*data-i18n="([^"]*)"/s', $body, $m);
    expect($m)->toHaveCount(2);
    $payload = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);
    expect($payload)->toBeArray();
    expect($payload['title'] ?? null)->toBe('Chatbot');
    expect($payload['new_conversation'] ?? null)->toBeString();
});

it('registers the route under the chatbot.page name', function () {
    $route = app('router')->getRoutes()->getByName('chatbot.page');

    expect($route)->not->toBeNull();
    expect($route->uri())->toBe('chatbot');
    expect($route->methods())->toContain('GET');
});

it('uses the configured section name when extending a host layout', function () {
    @mkdir(__DIR__ . '/../../Stubs/views', 0775, recursive: true);
    file_put_contents(
        __DIR__ . '/../../Stubs/views/host_layout_custom.blade.php',
        '<html><body>BEGIN @yield(\'cb_main\') END</body></html>',
    );

    View::addNamespace('chatbottest', __DIR__ . '/../../Stubs/views');
    config()->set('chatbot.page.layout', 'chatbottest::host_layout_custom');
    config()->set('chatbot.page.section', 'cb_main');

    $user = makePageUser();
    $response = $this->actingAs($user, 'web')->get('/chatbot');

    $body = $response->getContent();
    expect($body)->toContain('BEGIN');
    expect($body)->toContain('<chatbot-widget');
    expect($body)->toContain('END');

    @unlink(__DIR__ . '/../../Stubs/views/host_layout_custom.blade.php');
});
