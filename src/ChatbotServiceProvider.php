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
        // v2.1.2 (#28) — `replaceConfigRecursivelyFrom`, NOT `mergeConfigFrom`.
        // `mergeConfigFrom` does a FLAT `array_merge`: if the host published
        // `config/chatbot.php` on an earlier version, its `dashboard` array
        // (or `page`, `tools`…) completely replaces the package's, and the
        // new nested keys added in each release (`dashboard.back_url`,
        // `dashboard.mount_widget`, …) simply do not exist → code that reads
        // them WITHOUT an explicit default silently gets `null`. The recursive
        // variant (`array_replace_recursive`) fills in every missing nested
        // key while letting the host's values win. Known caveat: for LIST-type
        // keys with a non-empty package default (`tools.paths`,
        // `tools.frontend_primitives`, `route.middleware`) a host that
        // TRIMMED them gets the package's leftover indices back — those hosts
        // must re-publish the config when upgrading (documented in CHANGELOG).
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
     * Binds the pin orchestrator (v2.2). The HTTP controller and the new
     * `AddToDashboardTool` share it to avoid duplicating the
     * cap + snapshot + page_context filtering + persist logic.
     */
    protected function registerPinService(): void
    {
        $this->app->singleton(PinService::class);
        $this->app->singleton(WidgetCrudService::class);
        $this->app->singleton(DashboardCrudService::class);
    }

    /**
     * Binds the orchestrator (E08) and its Prism tool factory. Singleton so
     * the host can inject it into its `ChatController` (E09).
     *
     * Binds the client-disconnect detector used by `ChatController` (E09):
     * a Closure that returns `true` when the HTTP client closed the
     * connection. Default = a wrapper around `connection_aborted()`. Tests
     * can override it via `app()->instance('chatbot.connection_aborted', $fakeClosure)`
     * to verify that the SSE loop breaks correctly without needing a real
     * TCP connection.
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
     * Binds the MCP bridge (E07) as a singleton. The bridge only "does
     * something" in `boot()` — and only if `prism-php/relay` is installed.
     * The binding always exists so `chatbot:tools:list` can inspect it.
     */
    protected function registerMcpBridge(): void
    {
        $this->app->singleton(McpToolBridge::class);
    }

    /**
     * Binds the central backend tool registry as a singleton so hosts can
     * inject it into their AppServiceProvider and register tools manually
     * (`->register(MyTool::class)`).
     */
    protected function registerToolRegistry(): void
    {
        $this->app->singleton(ToolRegistry::class);
    }

    /**
     * Binds the Personal Dashboard replay engine (v2.0 / E3) as a
     * singleton. The E4 controller injects it into its constructor to serve
     * `POST /chatbot/dashboards/{slug}/widgets/{id}/refresh` and the bulk
     * refresh SSE. Re-resolves `ToolRegistry`/`Dispatcher` on every
     * invocation so tests and hosts can rebind.
     */
    protected function registerReplayService(): void
    {
        $this->app->singleton(ReplayService::class);
    }

    /**
     * Binds the system prompt builder and the LLM gateway.
     *
     * Hosts can override either one via `extend()` or `singleton()` from
     * their own AppServiceProvider before boot().
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
     * Loads the MCP servers' tools (E07) into the `ToolRegistry`.
     *
     * Behavior:
     *   - If no servers are configured in `chatbot.mcp.servers`: no-op.
     *   - If there are servers but `prism-php/relay` is not installed: log
     *     a warning ONCE with an actionable instruction and continue. The
     *     `chatbot:tools:list` command flags it too.
     *   - Any exception from the bridge (server down, invalid config) is
     *     caught: the inner bridge already logs per server. Here we cover
     *     the "total failure" case so we don't abort boot.
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
                '[chatbot] chatbot.mcp.servers has entries but '
                . 'prism-php/relay is not installed. MCP tools will not be '
                . 'loaded. Run `composer require prism-php/relay` to '
                . 'enable them, or empty the section to silence this '
                . 'warning.'
            );

            return;
        }

        try {
            $bridge->registerInto($this->app->make(ToolRegistry::class));
        } catch (Throwable $e) {
            Log::warning(sprintf(
                '[chatbot] MCP bridge failed at boot: %s. The package remains '
                . 'operational without MCP tools.',
                $e->getMessage(),
            ));
        }
    }

    /**
     * Registers the package's frontend primitives (E11). The host can edit
     * `chatbot.tools.frontend_primitives` to disable individual primitives
     * or add its own (e.g. a `DownloadFileTool` subclass with domain
     * ownership).
     *
     * The primitives live in the package's `src/Tools/Frontend/` and are
     * NOT covered by `chatbot.tools.paths` (which points to the host's
     * `app/Chatbot/Tools`) — that's why they are registered explicitly here.
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
     * Registers the package's backend primitives (v2.2 — `add_to_dashboard`
     * and the 4 conversational editing tools). Same pattern as
     * `registerFrontendPrimitives()`: the host edits
     * `chatbot.tools.backend_primitives` to remove lines, or sets
     * `chatbot.tools.{name}.enabled = false` to disable a single one
     * without touching the list.
     *
     * The primitives live in the package's `src/Tools/Backend/` and are NOT
     * covered by `chatbot.tools.paths` (which points to the host's
     * `app/Chatbot/Tools`) — that's why they are registered explicitly here.
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

            // Resolve before checking the flag so we use the real key the
            // tool declares in `name()` (don't assume the last part of the
            // FQCN matches). Negligible cost: one instance per tool at boot.
            // If it's disabled, we discard it without registering.
            $tool = $this->app->make($class);

            if (config("chatbot.tools.{$tool->name()}.enabled", true) === false) {
                continue;
            }

            $registry->register($tool);
        }
    }

    /**
     * Auto-discovery of backend tools (E06). If `chatbot.tools.auto_discover`
     * is false, the host is responsible for calling
     * `app(ToolRegistry::class)->register(...)` from its AppServiceProvider.
     *
     * Registering each tool can throw `MissingTenantResolverException` if it
     * declares `tenantScope=true` and the host has not bound a
     * `TenantResolver` — this fails fast at boot, not on the first
     * invocation.
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
     * Opt-in integration with Backpack CRUD (cross-host gap E14 part 1, D15).
     *
     * Detection by runtime presence: if Backpack's `CrudPanel` class exists,
     * we register the `BackpackPageContextProvider` as a singleton and the
     * `@chatbotBackpackContext` Blade directive. When Backpack is NOT
     * installed, this method is a silent no-op — the host doesn't need to
     * declare anything for the rest of the package to work (same pattern as
     * the MCP bridge in E07).
     *
     * Any exception during registration is caught with an actionable
     * warning: the package still boots OK even if Backpack is broken.
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
                '[chatbot] Backpack integration failed at boot: %s. The package '
                . 'remains operational without the @chatbotBackpackContext directive.',
                $e->getMessage(),
            ));
        }
    }

    /**
     * Registers the package's general Blade directives (v1.1.1).
     *
     * `@chatbotForm($id, $schemaSource?)` — finding #13.a, marks a custom
     * (non-Backpack) form with `data-chatbot-form` and optionally publishes
     * its schema to the page context.
     *
     * The Backpack integration (`@chatbotBackpackContext`) lives on its own
     * path in `registerBackpackIntegration()` because it depends on the
     * package's presence.
     */
    protected function registerBladeDirectives(): void
    {
        try {
            ChatbotFormDirective::register();
        } catch (Throwable $e) {
            Log::warning(sprintf(
                '[chatbot] Blade directive registration failed: %s. The package '
                . 'remains operational without the @chatbotForm directive.',
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
     * Binds the `Authorizer` based on `chatbot.authorization.resolver`.
     *
     *  - 'gate'   → GateAuthorizer (safe default).
     *  - 'spatie' → SpatieAuthorizer; the constructor verifies Spatie and
     *               throws if it's not installed (clear message).
     *               `verifyAuthorizationConfig()` brings that check forward
     *               to boot to fail early rather than on the first call.
     *  - 'custom' → the class declared in `chatbot.authorization.authorizer`.
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
                'chatbot.authorization.resolver=custom requires '
                . 'chatbot.authorization.authorizer to point to an '
                . 'existing class that implements Rnkr69\\LaraChatbot\\Authorization\\Contracts\\Authorizer.'
            );
        }

        return $class;
    }

    /**
     * Binds the `ScopeResolver`. If the host doesn't declare a class, uses
     * `NullScopeResolver` (which only knows how to answer `Self`).
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
     * Binds the `TenantResolver` (cross-host gap) **only** if the host
     * declares a class. With no class declared, the container has no
     * binding — the `AuthorizesToolAccess::accessibleTenantIds()` trait
     * interprets this as "no tenant restriction". Tools that require tenant
     * scope (`tenantScope=true` in E06) must fail the tool registry boot if
     * the binding is missing.
     */
    protected function registerTenantResolver(): void
    {
        $class = config('chatbot.authorization.tenant_resolver');

        if (is_string($class) && $class !== '' && class_exists($class)) {
            $this->app->singleton(TenantResolver::class, $class);
        }
    }

    /**
     * v1.1 (findings #1): applies `chatbot.http.verify=false` to Laravel's
     * global HTTP client when the host explicitly asks for it. This covers
     * the "corporate LiteLLM proxy with a self-signed CA" case without the
     * host having to add the line in its AppServiceProvider.
     *
     * Only acts when verify=false; the `true` default touches nothing and
     * lets Guzzle/cURL use the system trust store. Logs a warning so the
     * decision is traceable.
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
     * If the host requested `resolver=spatie` and Spatie is not installed,
     * fails at boot with an actionable message (ROADMAP §5/E04 DoD).
     */
    protected function verifyAuthorizationConfig(): void
    {
        $resolver = config('chatbot.authorization.resolver', 'spatie');

        if ($resolver === 'spatie' && ! class_exists(\Spatie\Permission\PermissionServiceProvider::class)) {
            throw new RuntimeException(
                'chatbot.authorization.resolver=spatie but the '
                . 'spatie/laravel-permission package is not installed. Run '
                . '`composer require spatie/laravel-permission` or switch '
                . 'to `resolver=gate` in config/chatbot.php.'
            );
        }
    }

    protected function registerPublishing(): void
    {
        // Root config.
        $this->publishes([
            __DIR__ . '/../config/chatbot.php' => config_path('chatbot.php'),
        ], 'chatbot-config');

        // Package migrations (E03 adds the real files).
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'chatbot-migrations');

        // All publishable Blade views.
        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/chatbot'),
        ], 'chatbot-views');

        // Just the system prompt view (a subset of chatbot-views, handy for
        // the host that only wants to customize the prompt).
        $this->publishes([
            __DIR__ . '/../resources/views/system_prompt.blade.php'
                => resource_path('views/vendor/chatbot/system_prompt.blade.php'),
        ], 'chatbot-prompts');

        // Compiled Web Component asset (E12 builds it into public-build/).
        $this->publishes([
            __DIR__ . '/../public-build' => public_path('vendor/chatbot'),
        ], 'chatbot-assets');

        // Base translations (resources/lang/{en,es}/chatbot.php).
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
