<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;

/**
 * E18 — `php artisan chatbot:install`.
 *
 * El comando muta filesystem (publish + .env + stubs + layout). Cada test
 * limpia las rutas que va a tocar ANTES y DESPUÉS de ejecutarse: el
 * `beforeEach` garantiza un estado limpio de entrada; el `afterEach` evita
 * que las vistas publicadas en `resources/views/vendor/chatbot` queden
 * residuales tras el último test y eclipsen las vistas del paquete en
 * suites posteriores (las publicadas tienen prioridad de resolución).
 */
function installCommandCleanup(): void
{
    $files = new Filesystem;

    // Stubs generados por runs previos.
    foreach ([app_path('Chatbot')] as $dir) {
        if ($files->isDirectory($dir)) {
            $files->deleteDirectory($dir);
        }
    }

    // Config/lang/views publicados (los publish sobrescribirían pero
    // queremos verificar si los crea desde cero también).
    foreach ([config_path('chatbot.php'), resource_path('views/vendor/chatbot')] as $target) {
        if ($files->isDirectory($target)) {
            $files->deleteDirectory($target);
        } elseif ($files->isFile($target)) {
            $files->delete($target);
        }
    }

    // .env y .env.example creados por tests anteriores.
    foreach ([base_path('.env'), base_path('.env.example')] as $env) {
        if ($files->isFile($env)) {
            $files->delete($env);
        }
    }

    // Layouts de prueba.
    $layoutDir = resource_path('views/layouts');
    if ($files->isDirectory($layoutDir)) {
        $files->deleteDirectory($layoutDir);
    }
}

beforeEach(function () {
    installCommandCleanup();
});

afterEach(function () {
    installCommandCleanup();
});

it('runs end-to-end in --no-interaction mode and is idempotent', function () {
    File::put(base_path('.env'), "APP_NAME=Test\nAPP_ENV=testing\n");
    File::put(base_path('.env.example'), "APP_NAME=Test\nAPP_ENV=testing\n");

    $exit = $this->artisan('chatbot:install', ['--no-interaction' => true])->run();

    expect($exit)->toBe(0)
        ->and(file_exists(config_path('chatbot.php')))->toBeTrue()
        ->and(file_exists(app_path('Chatbot/ChatbotScopeResolver.php')))->toBeTrue();

    $env = File::get(base_path('.env'));
    expect($env)
        ->toContain('CHATBOT_PROVIDER=anthropic')
        ->and($env)->toContain('CHATBOT_MODEL=claude-sonnet-4-6')
        ->and($env)->toContain('ANTHROPIC_API_KEY=')
        ->and($env)->toContain('CHATBOT_AUTH_RESOLVER=');

    // No-interactive NO genera tool de ejemplo ni inyecta layout.
    expect(file_exists(app_path('Chatbot/Tools/ListMyInvoicesTool.php')))->toBeFalse();

    // Segunda ejecución: las claves no deben aparecer dos veces.
    $exit2 = $this->artisan('chatbot:install', ['--no-interaction' => true])->run();
    expect($exit2)->toBe(0);

    $env2 = File::get(base_path('.env'));
    expect(substr_count($env2, 'CHATBOT_PROVIDER='))->toBe(1)
        ->and(substr_count($env2, 'CHATBOT_MODEL='))->toBe(1)
        ->and(substr_count($env2, 'ANTHROPIC_API_KEY='))->toBe(1);
});

it('preserves an existing API key value on re-run (overwrite=false)', function () {
    File::put(base_path('.env'), "ANTHROPIC_API_KEY=sk-real-secret\n");

    $this->artisan('chatbot:install', ['--no-interaction' => true])->run();

    $env = File::get(base_path('.env'));
    expect($env)->toContain('ANTHROPIC_API_KEY=sk-real-secret');
});

it('overwrites CHATBOT_PROVIDER with the latest choice (overwrite=true)', function () {
    File::put(base_path('.env'), "CHATBOT_PROVIDER=openai\n");

    $this->artisan('chatbot:install', ['--no-interaction' => true])->run();

    $env = File::get(base_path('.env'));
    expect($env)->toContain('CHATBOT_PROVIDER=anthropic')
        ->and($env)->not->toContain('CHATBOT_PROVIDER=openai');
});

it('skips .env writes silently when no .env file exists', function () {
    expect(file_exists(base_path('.env')))->toBeFalse();

    $exit = $this->artisan('chatbot:install', ['--no-interaction' => true])->run();

    expect($exit)->toBe(0)
        ->and(file_exists(base_path('.env')))->toBeFalse();
});

it('runs interactive happy path: provider/model/scope yes, tenant no, addendum no, example tool yes, layout no', function () {
    File::put(base_path('.env'), "");

    $this->artisan('chatbot:install')
        ->expectsQuestion('Provider del LLM (anthropic, openai, groq, gemini, mistral, ollama, …)', 'openai')
        ->expectsQuestion('Modelo a usar', 'gpt-4o')
        ->expectsConfirmation('¿Generar stub de ScopeResolver en `app/Chatbot/`?', 'yes')
        ->expectsQuestion('Nombre de la clase ScopeResolver', 'MyResolver')
        ->expectsConfirmation('¿Tu host necesita tenant scope (multi-corporación, multi-evento, etc.)?', 'no')
        ->expectsConfirmation('¿Publicar la vista de "addendum" del system prompt? (instrucciones de dominio adicionales)', 'no')
        ->expectsConfirmation('¿Generar un tool de ejemplo (ListMyInvoicesTool) en app/Chatbot/Tools/?', 'yes')
        ->expectsQuestion('Ruta absoluta o relativa del layout donde inyectar el widget (vacío = skip)', '')
        ->assertExitCode(0);

    $env = File::get(base_path('.env'));
    expect($env)->toContain('CHATBOT_PROVIDER=openai')
        ->and($env)->toContain('CHATBOT_MODEL=gpt-4o')
        ->and($env)->toContain('OPENAI_API_KEY=')
        ->and(file_exists(app_path('Chatbot/MyResolver.php')))->toBeTrue()
        ->and(file_exists(app_path('Chatbot/Tools/ListMyInvoicesTool.php')))->toBeTrue();

    $tool = File::get(app_path('Chatbot/Tools/ListMyInvoicesTool.php'));
    expect($tool)
        ->toContain('class ListMyInvoicesTool extends BaseBackendTool')
        ->and($tool)->toContain("return 'list_my_invoices';");
});

it('generates a tenant resolver stub when the user opts in', function () {
    File::put(base_path('.env'), "");

    $this->artisan('chatbot:install')
        ->expectsQuestion('Provider del LLM (anthropic, openai, groq, gemini, mistral, ollama, …)', 'anthropic')
        ->expectsQuestion('Modelo a usar', 'claude-sonnet-4-6')
        ->expectsConfirmation('¿Generar stub de ScopeResolver en `app/Chatbot/`?', 'no')
        ->expectsConfirmation('¿Tu host necesita tenant scope (multi-corporación, multi-evento, etc.)?', 'yes')
        ->expectsQuestion('Nombre de la clase TenantResolver', 'MyTenantResolver')
        ->expectsConfirmation('¿Publicar la vista de "addendum" del system prompt? (instrucciones de dominio adicionales)', 'no')
        ->expectsConfirmation('¿Generar un tool de ejemplo (ListMyInvoicesTool) en app/Chatbot/Tools/?', 'no')
        ->expectsQuestion('Ruta absoluta o relativa del layout donde inyectar el widget (vacío = skip)', '')
        ->assertExitCode(0);

    $path = app_path('Chatbot/MyTenantResolver.php');
    expect(file_exists($path))->toBeTrue();

    $contents = File::get($path);
    expect($contents)
        ->toContain('class MyTenantResolver implements TenantResolver')
        ->and($contents)->toContain('resolveAccessibleTenantIds');
});

it('publishes the system_prompt_addendum view when requested', function () {
    File::put(base_path('.env'), "");

    $this->artisan('chatbot:install')
        ->expectsQuestion('Provider del LLM (anthropic, openai, groq, gemini, mistral, ollama, …)', 'anthropic')
        ->expectsQuestion('Modelo a usar', 'claude-sonnet-4-6')
        ->expectsConfirmation('¿Generar stub de ScopeResolver en `app/Chatbot/`?', 'no')
        ->expectsConfirmation('¿Tu host necesita tenant scope (multi-corporación, multi-evento, etc.)?', 'no')
        ->expectsConfirmation('¿Publicar la vista de "addendum" del system prompt? (instrucciones de dominio adicionales)', 'yes')
        ->expectsConfirmation('¿Generar un tool de ejemplo (ListMyInvoicesTool) en app/Chatbot/Tools/?', 'no')
        ->expectsQuestion('Ruta absoluta o relativa del layout donde inyectar el widget (vacío = skip)', '')
        ->assertExitCode(0);

    $path = resource_path('views/vendor/chatbot/system_prompt_addendum.blade.php');
    expect(file_exists($path))->toBeTrue();
    expect(File::get($path))->toContain('Reglas adicionales de dominio');
});

it('detects an existing layouts/app.blade.php and injects the widget once', function () {
    File::put(base_path('.env'), "");

    $layoutDir = resource_path('views/layouts');
    File::ensureDirectoryExists($layoutDir);
    $layoutPath = $layoutDir . '/app.blade.php';
    File::put($layoutPath, "<html>\n<body>\n  @yield('content')\n</body>\n</html>\n");

    $this->artisan('chatbot:install')
        ->expectsQuestion('Provider del LLM (anthropic, openai, groq, gemini, mistral, ollama, …)', 'anthropic')
        ->expectsQuestion('Modelo a usar', 'claude-sonnet-4-6')
        ->expectsConfirmation('¿Generar stub de ScopeResolver en `app/Chatbot/`?', 'no')
        ->expectsConfirmation('¿Tu host necesita tenant scope (multi-corporación, multi-evento, etc.)?', 'no')
        ->expectsConfirmation('¿Publicar la vista de "addendum" del system prompt? (instrucciones de dominio adicionales)', 'no')
        ->expectsConfirmation('¿Generar un tool de ejemplo (ListMyInvoicesTool) en app/Chatbot/Tools/?', 'no')
        // El prompt incluye el path del layout detectado entre comillas dinámicamente;
        // expectsConfirmation matchea por substring si la pregunta es estable.
        ->expectsConfirmation('Detectado(s) layout(s) Blade: resources/views/layouts/app.blade.php. ¿Inyectar el widget en el primero?', 'yes')
        ->assertExitCode(0);

    $contents = File::get($layoutPath);
    expect($contents)
        ->toContain('<!-- chatbot:widget -->')
        ->and($contents)->toContain('<chatbot-widget></chatbot-widget>')
        ->and(substr_count($contents, '<!-- chatbot:widget -->'))->toBe(1);

    // Segunda ejecución: el marker está, no se vuelve a inyectar.
    $this->artisan('chatbot:install', ['--no-interaction' => true])->run();
    // No-interactive no toca el layout, pero verifiquemos que el marker sigue 1.
    expect(substr_count(File::get($layoutPath), '<!-- chatbot:widget -->'))->toBe(1);
});

it('sets CHATBOT_AUTH_RESOLVER=gate when Spatie is not installed (no-interaction)', function () {
    // Spatie no está instalado en la matriz de tests por defecto; verificamos.
    expect(class_exists(\Spatie\Permission\PermissionServiceProvider::class))->toBeFalse();

    File::put(base_path('.env'), "");

    $this->artisan('chatbot:install', ['--no-interaction' => true])->run();

    expect(File::get(base_path('.env')))->toContain('CHATBOT_AUTH_RESOLVER=gate');
});
