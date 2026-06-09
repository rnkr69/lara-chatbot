<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Rnkr69\LaraChatbot\Authorization\Contracts\Authorizer;
use Rnkr69\LaraChatbot\Authorization\Contracts\ScopeResolver;
use Rnkr69\LaraChatbot\Authorization\Contracts\TenantResolver;
use Rnkr69\LaraChatbot\Authorization\GateAuthorizer;
use Rnkr69\LaraChatbot\Authorization\NullScopeResolver;
use Rnkr69\LaraChatbot\Authorization\SpatieAuthorizer;
use Rnkr69\LaraChatbot\Console\Commands\CleanupActionsCommand;
use Rnkr69\LaraChatbot\Console\Commands\CostReportCommand;
use Rnkr69\LaraChatbot\Console\Commands\DashboardsPruneCommand;
use Rnkr69\LaraChatbot\Console\Commands\DecisionRulesShowCommand;
use Rnkr69\LaraChatbot\Console\Commands\DoctorCommand;
use Rnkr69\LaraChatbot\Console\Commands\InstallCommand;
use Rnkr69\LaraChatbot\Console\Commands\IntegrateFormCommand;
use Rnkr69\LaraChatbot\Console\Commands\MakeScopeResolverCommand;
use Rnkr69\LaraChatbot\Console\Commands\MakeTenantResolverCommand;
use Rnkr69\LaraChatbot\Console\Commands\MakeToolCommand;
use Rnkr69\LaraChatbot\Console\Commands\ScanFormsCommand;
use Rnkr69\LaraChatbot\Console\Commands\TestConnectionCommand;
use Rnkr69\LaraChatbot\Console\Commands\TestToolCommand;
use Rnkr69\LaraChatbot\Console\Commands\ToolsListCommand;
use Rnkr69\LaraChatbot\Dashboard\DashboardCrudService;
use Rnkr69\LaraChatbot\Dashboard\PinService;
use Rnkr69\LaraChatbot\Dashboard\ReplayService;
use Rnkr69\LaraChatbot\Dashboard\WidgetCrudService;
use Rnkr69\LaraChatbot\Integrations\Backpack\BackpackPageContextProvider;
use Rnkr69\LaraChatbot\Integrations\Backpack\BladeHelpers as BackpackBladeHelpers;
use Rnkr69\LaraChatbot\Llm\LlmGateway;
use Rnkr69\LaraChatbot\Llm\SystemPromptBuilder;
use Rnkr69\LaraChatbot\Mcp\McpToolBridge;
use Rnkr69\LaraChatbot\Services\ChatService;
use Rnkr69\LaraChatbot\Services\PageContextSanitizer;
use Rnkr69\LaraChatbot\Services\PendingActionStore;
use Rnkr69\LaraChatbot\Tools\Support\PrismToolFactory;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;
use Rnkr69\LaraChatbot\View\Directives\ChatbotFormDirective;
use RuntimeException;
use Throwable;

class ChatbotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // v2.1.2 (#28) — `replaceConfigRecursivelyFrom`, NO `mergeConfigFrom`.
        // `mergeConfigFrom` hace un `array_merge` PLANO: si el host publicó
        // `config/chatbot.php` en una versión anterior, su array `dashboard`
        // (o `page`, `tools`…) reemplaza por completo al del paquete, y las
        // claves anidadas nuevas de cada release (`dashboard.back_url`,
        // `dashboard.mount_widget`, …) simplemente no existen → el código que
        // las lee SIN default explícito recibe `null` en silencio. El recursivo
        // (`array_replace_recursive`) rellena toda clave anidada que falte
        // dejando ganar los valores del host. Caveat conocido: para claves de
        // tipo LISTA con default no vacío en el paquete (`tools.paths`,
        // `tools.frontend_primitives`, `route.middleware`) un host que las
        // RECORTÓ recupera los índices sobrantes del paquete — esos hosts deben
        // re-publicar el config al actualizar (documentado en CHANGELOG).
        $this->replaceConfigRecursivelyFrom(__DIR__ . '/../config/chatbot.php', 'chatbot');

        $this->registerAuthorizer();
        $this->registerScopeResolver();
        $this->registerTenantResolver();
        $this->registerLlm();
        $this->registerToolRegistry();
        $this->registerMcpBridge();
        $this->registerChatService();
        $this->registerReplayService();
        $this->registerPinService();
    }

    /**
     * Bind del orquestador de pin (v2.2). El controller HTTP y la nueva
     * `AddToDashboardTool` lo comparten para no duplicar la lógica de
     * cap + snapshot + page_context filtering + persist.
     */
    protected function registerPinService(): void
    {
        $this->app->singleton(PinService::class);
        $this->app->singleton(WidgetCrudService::class);
        $this->app->singleton(DashboardCrudService::class);
    }

    /**
     * Bind del orquestador (E08) y de su factory de tools Prism. Singleton
     * para que el host pueda inyectarlo en su `ChatController` (E09).
     *
     * Bind del detector de cierre del cliente usado por `ChatController`
     * (E09): un Closure que devuelve `true` cuando el cliente HTTP cerró la
     * conexión. Default = wrap de `connection_aborted()`. Los tests pueden
     * overridearlo via `app()->instance('chatbot.connection_aborted', $fakeClosure)`
     * para verificar que el loop de SSE rompe correctamente sin necesidad
     * de una conexión TCP real.
     */
    protected function registerChatService(): void
    {
        $this->app->singleton(PrismToolFactory::class);
        $this->app->singleton(ChatService::class);
        $this->app->singleton(PageContextSanitizer::class);
        $this->app->singleton(PendingActionStore::class);

        $this->app->bind(
            'chatbot.connection_aborted',
            static fn (): \Closure => static fn (): bool => connection_aborted() === 1,
        );
    }

    /**
     * Bind del bridge MCP (E07) como singleton. El bridge sólo "hace algo"
     * en `boot()` — y sólo si `prism-php/relay` está instalado. El binding
     * existe siempre para que `chatbot:tools:list` pueda inspeccionarlo.
     */
    protected function registerMcpBridge(): void
    {
        $this->app->singleton(McpToolBridge::class);
    }

    /**
     * Bind del registro central de backend tools como singleton para que
     * los hosts puedan inyectarlo en su AppServiceProvider y registrar
     * tools manualmente (`->register(MyTool::class)`).
     */
    protected function registerToolRegistry(): void
    {
        $this->app->singleton(ToolRegistry::class);
    }

    /**
     * Bind del replay engine del Personal Dashboard (v2.0 / E3) como
     * singleton. El controller del E4 lo inyectará en su constructor para
     * servir `POST /chatbot/dashboards/{slug}/widgets/{id}/refresh` y el
     * SSE de bulk refresh. Re-resuelve `ToolRegistry`/`Dispatcher` cada
     * invocación para que tests y hosts puedan rebindear.
     */
    protected function registerReplayService(): void
    {
        $this->app->singleton(ReplayService::class);
    }

    /**
     * Bind del system prompt builder y del gateway de LLM.
     *
     * Los hosts pueden sobreescribir cualquiera de los dos vía `extend()`
     * o `singleton()` desde su propio AppServiceProvider antes de boot().
     */
    protected function registerLlm(): void
    {
        $this->app->singleton(SystemPromptBuilder::class);
        $this->app->singleton(LlmGateway::class);
    }

    public function boot(): void
    {
        $this->registerRoutes();

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'chatbot');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'chatbot');

        $this->applyHttpOptions();
        $this->verifyAuthorizationConfig();
        $this->discoverTools();
        $this->registerFrontendPrimitives();
        $this->registerBackendPrimitives();
        $this->registerMcpTools();
        $this->registerBackpackIntegration();
        $this->registerBladeDirectives();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
            $this->registerCommands();
        }
    }

    /**
     * Carga las tools de los servers MCP (E07) en el `ToolRegistry`.
     *
     * Comportamiento:
     *   - Si no hay servers configurados en `chatbot.mcp.servers`: no-op.
     *   - Si hay servers pero `prism-php/relay` no está instalado: log
     *     warning UNA vez con instrucción accionable y sigue. El comando
     *     `chatbot:tools:list` también lo señala.
     *   - Cualquier excepción del bridge (server caído, config inválida)
     *     se atrapa: el bridge interno ya loguea por server. Aquí cubrimos
     *     el caso "fallo total" para no abortar el boot.
     */
    protected function registerMcpTools(): void
    {
        /** @var McpToolBridge $bridge */
        $bridge = $this->app->make(McpToolBridge::class);

        if ($bridge->configuredServerNames() === []) {
            return;
        }

        if (! $bridge->isAvailable()) {
            Log::warning(
                '[chatbot] chatbot.mcp.servers tiene entradas pero '
                . 'prism-php/relay no está instalado. Las tools MCP no se '
                . 'cargarán. Ejecuta `composer require prism-php/relay` '
                . 'para activarlas o vacía la sección para silenciar este '
                . 'warning.'
            );

            return;
        }

        try {
            $bridge->registerInto($this->app->make(ToolRegistry::class));
        } catch (Throwable $e) {
            Log::warning(sprintf(
                '[chatbot] Bridge MCP falló al boot: %s. El paquete sigue '
                . 'operativo sin tools MCP.',
                $e->getMessage(),
            ));
        }
    }

    /**
     * Registra las primitivas frontend del paquete (E11). El host puede
     * editar `chatbot.tools.frontend_primitives` para deshabilitar
     * primitivas individuales o añadir las suyas (ej. una subclase de
     * `DownloadFileTool` con ownership de dominio).
     *
     * Las primitivas viven en `src/Tools/Frontend/` del paquete y NO están
     * cubiertas por `chatbot.tools.paths` (que apunta a `app/Chatbot/Tools`
     * del host) — por eso se registran explícitamente aquí.
     */
    protected function registerFrontendPrimitives(): void
    {
        $primitives = config('chatbot.tools.frontend_primitives', []);

        if (! is_array($primitives) || $primitives === []) {
            return;
        }

        $registry = $this->app->make(ToolRegistry::class);

        foreach ($primitives as $class) {
            if (! is_string($class) || $class === '' || ! class_exists($class)) {
                continue;
            }

            $registry->register($class);
        }
    }

    /**
     * Registra las primitivas backend del paquete (v2.2 — `add_to_dashboard`
     * y los 4 tools de edición conversacional). Mismo patrón que
     * `registerFrontendPrimitives()`: el host edita
     * `chatbot.tools.backend_primitives` para quitar líneas, o pone
     * `chatbot.tools.{name}.enabled = false` para desactivar una sola sin
     * tocar la lista.
     *
     * Las primitives viven en `src/Tools/Backend/` del paquete y NO están
     * cubiertas por `chatbot.tools.paths` (que apunta a `app/Chatbot/Tools`
     * del host) — por eso se registran aquí explícitamente.
     */
    protected function registerBackendPrimitives(): void
    {
        $primitives = config('chatbot.tools.backend_primitives', []);

        if (! is_array($primitives) || $primitives === []) {
            return;
        }

        $registry = $this->app->make(ToolRegistry::class);

        foreach ($primitives as $class) {
            if (! is_string($class) || $class === '' || ! class_exists($class)) {
                continue;
            }

            // Resolvemos antes de consultar el flag para usar la clave real
            // que la tool declara en `name()` (no asumir que la última parte
            // del FQCN coincide). Coste despreciable: una instancia por tool
            // al boot. Si está desactivada, descartamos sin registrar.
            $tool = $this->app->make($class);

            if (config("chatbot.tools.{$tool->name()}.enabled", true) === false) {
                continue;
            }

            $registry->register($tool);
        }
    }

    /**
     * Auto-discovery de backend tools (E06). Si `chatbot.tools.auto_discover`
     * es false, el host es responsable de llamar
     * `app(ToolRegistry::class)->register(...)` desde su AppServiceProvider.
     *
     * El registro de cada tool puede lanzar `MissingTenantResolverException`
     * si declara `tenantScope=true` y el host no ha bind un `TenantResolver`
     * — esto fail-fast en boot, no en la primera invocación.
     */
    protected function discoverTools(): void
    {
        if (! config('chatbot.tools.auto_discover', true)) {
            return;
        }

        $paths = config('chatbot.tools.paths', []);

        if (! is_array($paths) || $paths === []) {
            return;
        }

        $this->app->make(ToolRegistry::class)->discover($paths);
    }

    /**
     * Integración opt-in con Backpack CRUD (gap cross-host E14 parte 1, D15).
     *
     * Detección por presencia en runtime: si la clase `CrudPanel` de
     * Backpack existe, registramos el `BackpackPageContextProvider` como
     * singleton y la directive Blade `@chatbotBackpackContext`. Cuando
     * Backpack NO está instalado, este método es no-op silencioso — el
     * host no necesita declarar nada para que el resto del paquete
     * funcione (mismo patrón que el bridge MCP en E07).
     *
     * Cualquier excepción durante el registro se atrapa con un warning
     * accionable: el paquete sigue boot OK aún si Backpack está roto.
     */
    protected function registerBackpackIntegration(): void
    {
        if (! class_exists('Backpack\\CRUD\\app\\Library\\CrudPanel\\CrudPanel')) {
            return;
        }

        try {
            $this->app->singleton(BackpackPageContextProvider::class);

            $blade = $this->app->make('blade.compiler');
            BackpackBladeHelpers::register($blade);
        } catch (Throwable $e) {
            Log::warning(sprintf(
                '[chatbot] Integración Backpack falló al boot: %s. El paquete '
                . 'sigue operativo sin la directive @chatbotBackpackContext.',
                $e->getMessage(),
            ));
        }
    }

    /**
     * Registra las directivas Blade generales del paquete (v1.1.1).
     *
     * `@chatbotForm($id, $schemaSource?)` — finding #13.a, marca un form
     * custom (no-Backpack) con `data-chatbot-form` y opcionalmente publica
     * su schema al page context.
     *
     * La integración Backpack (`@chatbotBackpackContext`) vive en su
     * propio path en `registerBackpackIntegration()` porque depende de la
     * presencia del package.
     */
    protected function registerBladeDirectives(): void
    {
        try {
            ChatbotFormDirective::register();
        } catch (Throwable $e) {
            Log::warning(sprintf(
                '[chatbot] Registro de directivas Blade falló: %s. El paquete '
                . 'sigue operativo sin la directive @chatbotForm.',
                $e->getMessage(),
            ));
        }
    }

    protected function registerRoutes(): void
    {
        Route::group([
            'prefix'     => config('chatbot.route.prefix', 'chatbot'),
            'middleware' => config('chatbot.route.middleware', ['web']),
            'domain'     => config('chatbot.route.domain'),
            'as'         => 'chatbot.',
        ], function (): void {
            $this->loadRoutesFrom(__DIR__ . '/../routes/chatbot.php');
        });
    }

    /**
     * Bind del `Authorizer` según `chatbot.authorization.resolver`.
     *
     *  - 'gate'   → GateAuthorizer (default safe).
     *  - 'spatie' → SpatieAuthorizer; el constructor verificará Spatie y
     *               lanzará si no está instalado (mensaje claro).
     *               `verifyAuthorizationConfig()` adelanta esa comprobación
     *               al boot para fallar pronto y no en el primer call.
     *  - 'custom' → la clase declarada en `chatbot.authorization.authorizer`.
     */
    protected function registerAuthorizer(): void
    {
        $this->app->singleton(Authorizer::class, function ($app) {
            $resolver = config('chatbot.authorization.resolver', 'spatie');

            return match ($resolver) {
                'spatie' => $app->make(SpatieAuthorizer::class),
                'custom' => $app->make($this->customAuthorizerClass()),
                default  => $app->make(GateAuthorizer::class),
            };
        });
    }

    protected function customAuthorizerClass(): string
    {
        $class = config('chatbot.authorization.authorizer');

        if (! is_string($class) || $class === '' || ! class_exists($class)) {
            throw new RuntimeException(
                'chatbot.authorization.resolver=custom requiere que '
                . 'chatbot.authorization.authorizer apunte a una clase '
                . 'existente que implemente Rnkr69\\LaraChatbot\\Authorization\\Contracts\\Authorizer.'
            );
        }

        return $class;
    }

    /**
     * Bind del `ScopeResolver`. Si el host no declara una clase, usa
     * `NullScopeResolver` (sólo sabe responder `Self`).
     */
    protected function registerScopeResolver(): void
    {
        $this->app->singleton(ScopeResolver::class, function ($app) {
            $class = config('chatbot.authorization.scope_resolver');

            if (is_string($class) && $class !== '' && class_exists($class)) {
                return $app->make($class);
            }

            return $app->make(NullScopeResolver::class);
        });
    }

    /**
     * Bind del `TenantResolver` (gap cross-host) **sólo** si el host declara
     * una clase. Sin clase declarada, el contenedor no tiene el binding —
     * el trait `AuthorizesToolAccess::accessibleTenantIds()` interpreta esto
     * como "sin restricción tenant". Tools que requieran tenant scope
     * (`tenantScope=true` en E06) deben hacer fallar el boot del registro
     * de tools si el binding falta.
     */
    protected function registerTenantResolver(): void
    {
        $class = config('chatbot.authorization.tenant_resolver');

        if (is_string($class) && $class !== '' && class_exists($class)) {
            $this->app->singleton(TenantResolver::class, $class);
        }
    }

    /**
     * v1.1 (findings #1): aplica `chatbot.http.verify=false` al cliente HTTP
     * global de Laravel cuando el host lo pide explícitamente. Esto cubre el
     * caso "proxy LiteLLM corporativo con CA self-signed" sin que el host
     * tenga que añadir la línea en su AppServiceProvider.
     *
     * Sólo actúa cuando verify=false; el default `true` no toca nada y deja
     * que Guzzle/cURL usen el trust store del sistema. Loguea un warning para
     * que la decisión quede rastreada.
     */
    protected function applyHttpOptions(): void
    {
        $verify = config('chatbot.http.verify', true);

        if ($verify === false) {
            Http::globalOptions(['verify' => false]);
            Log::warning(
                '[chatbot] chatbot.http.verify=false — SSL verification disabled '
                . 'globally on the Laravel HTTP client. Only acceptable in dev/staging '
                . 'behind a corporate proxy with a self-signed CA. Set CHATBOT_HTTP_VERIFY=true '
                . '(or remove it) in production.'
            );
        }
    }

    /**
     * Si el host pidió `resolver=spatie` y Spatie no está instalado, falla
     * al boot con un mensaje accionable (ROADMAP §5/E04 DoD).
     */
    protected function verifyAuthorizationConfig(): void
    {
        $resolver = config('chatbot.authorization.resolver', 'spatie');

        if ($resolver === 'spatie' && ! class_exists(\Spatie\Permission\PermissionServiceProvider::class)) {
            throw new RuntimeException(
                'chatbot.authorization.resolver=spatie pero el paquete '
                . 'spatie/laravel-permission no está instalado. Ejecuta '
                . '`composer require spatie/laravel-permission` o cambia '
                . 'a `resolver=gate` en config/chatbot.php.'
            );
        }
    }

    protected function registerPublishing(): void
    {
        // Config raíz.
        $this->publishes([
            __DIR__ . '/../config/chatbot.php' => config_path('chatbot.php'),
        ], 'chatbot-config');

        // Migraciones del paquete (E03 añade los archivos reales).
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'chatbot-migrations');

        // Todas las vistas Blade publishables.
        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/chatbot'),
        ], 'chatbot-views');

        // Sólo la vista del system prompt (subset de chatbot-views, útil para
        // el host que sólo quiere personalizar el prompt).
        $this->publishes([
            __DIR__ . '/../resources/views/system_prompt.blade.php'
                => resource_path('views/vendor/chatbot/system_prompt.blade.php'),
        ], 'chatbot-prompts');

        // Asset compilado del Web Component (E12 lo construye en public-build/).
        $this->publishes([
            __DIR__ . '/../public-build' => public_path('vendor/chatbot'),
        ], 'chatbot-assets');

        // Traducciones base (resources/lang/{en,es}/chatbot.php).
        $this->publishes([
            __DIR__ . '/../resources/lang' => $this->app->langPath('vendor/chatbot'),
        ], 'chatbot-lang');
    }

    protected function registerCommands(): void
    {
        $this->commands([
            CleanupActionsCommand::class,
            CostReportCommand::class,
            DashboardsPruneCommand::class,
            DecisionRulesShowCommand::class,
            DoctorCommand::class,
            InstallCommand::class,
            IntegrateFormCommand::class,
            MakeScopeResolverCommand::class,
            MakeTenantResolverCommand::class,
            MakeToolCommand::class,
            ScanFormsCommand::class,
            TestConnectionCommand::class,
            TestToolCommand::class,
            ToolsListCommand::class,
        ]);
    }
}
