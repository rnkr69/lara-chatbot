<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * `php artisan chatbot:install` — setup interactivo del paquete (E18).
 *
 * Pasos (todos idempotentes; el comando se puede re-ejecutar):
 *   1. Publica config, migraciones, vistas, prompts, assets y lang.
 *   2. Pregunta provider/model y los persiste en `.env` y `.env.example`
 *      (junto con la API key del provider, vacía si no existe).
 *   3. Detecta Spatie y propone el resolver de autorización.
 *   4. Genera stub de `ScopeResolver` (`chatbot:make:scope-resolver`).
 *   5. Si el host necesita tenant scope, genera stub de `TenantResolver`.
 *   6. Si el host quiere `system_prompt_addendum`, publica la vista base.
 *   7. Genera tool de ejemplo (`ListMyInvoicesTool`) desde stub propio.
 *   8. Inyecta `<script>` + `<chatbot-widget>` en un layout Blade del host
 *      (auto-detect + prompt confirmable + skip → instrucciones manuales).
 *   9. Imprime instrucciones finales (rutas, registrar tools, comandos).
 *
 * Soporta `--no-interaction`:
 *   - Provider/model = defaults del config (anthropic / claude-sonnet-4-6).
 *   - Resolver = `spatie` si la clase está, `gate` si no.
 *   - Genera stub de ScopeResolver siempre (fast win).
 *   - NO genera tenant resolver, NO genera tool ejemplo, NO inyecta layout
 *     (esos pasos tocan código del host y exigen consentimiento explícito).
 *
 * Soporta `--force` para sobrescribir publishables ya publicados.
 */
class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'chatbot:install
                            {--force : Sobrescribe publishables ya publicados.}
                            {--backpack-forms : Publica el override de form_page.blade.php para tagear forms Backpack con data-chatbot-form (v1.1.1).}
                            {--backpack-admin : Publica 3 CrudControllers read-only para inspeccionar conversations/messages/pending_actions desde el admin Backpack (v1.1.1).}';

    /** @var string */
    protected $description = 'Instalación interactiva del paquete chatbot (publica assets, configura .env, genera stubs).';

    /**
     * Marcador HTML que `injectWidgetIntoLayout()` busca para no duplicar
     * la inyección si el comando se re-ejecuta. Es un comentario inerte.
     */
    private const WIDGET_MARKER = '<!-- chatbot:widget -->';

    /**
     * Lista de provider Prism conocidos con la env-var de su API key y un
     * modelo razonable por defecto. El host puede elegir cualquiera; los
     * que no aparezcan aquí son válidos pero no auto-completan API key.
     *
     * @var array<string, array{env: ?string, model: string}>
     */
    private const PROVIDERS = [
        'anthropic' => ['env' => 'ANTHROPIC_API_KEY', 'model' => 'claude-sonnet-4-6'],
        'openai'    => ['env' => 'OPENAI_API_KEY',    'model' => 'gpt-4o'],
        'groq'      => ['env' => 'GROQ_API_KEY',      'model' => 'llama-3.3-70b-versatile'],
        'gemini'    => ['env' => 'GEMINI_API_KEY',    'model' => 'gemini-2.0-flash'],
        'mistral'   => ['env' => 'MISTRAL_API_KEY',   'model' => 'mistral-large-latest'],
        'ollama'    => ['env' => null,                'model' => 'llama3.2'],
    ];

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->components->info('Instalando rnkr69/lara-chatbot…');

        $this->publishAssets();
        $this->configureProviderAndModel();
        $this->configureAuthorization();
        $this->generateScopeResolverStub();
        $this->maybeGenerateTenantResolverStub();
        $this->maybePublishSystemPromptAddendum();
        $this->maybeGenerateExampleTool();
        $this->maybePublishBackpackFormPage();
        $this->maybePublishBackpackAdmin();
        $this->maybeInjectWidgetIntoLayout();
        $this->printFinalInstructions();

        return self::SUCCESS;
    }

    /**
     * Paso 1 — Publica los seis tags del paquete. Si `--force`, sobrescribe.
     */
    private function publishAssets(): void
    {
        $this->components->task('Publicando configuración y assets', function (): bool {
            foreach (['chatbot-config', 'chatbot-migrations', 'chatbot-views', 'chatbot-prompts', 'chatbot-assets', 'chatbot-lang'] as $tag) {
                $this->callSilent('vendor:publish', array_filter([
                    '--tag'   => $tag,
                    '--force' => $this->option('force') ? true : null,
                ], fn ($v) => $v !== null));
            }

            return true;
        });
    }

    /**
     * Paso 2 — Pregunta provider+model y persiste a .env / .env.example.
     */
    private function configureProviderAndModel(): void
    {
        $defaultProvider = (string) config('chatbot.provider', 'anthropic');
        $defaultModel    = (string) config('chatbot.model', 'claude-sonnet-4-6');

        if ($this->isInteractive()) {
            $provider = strtolower(trim((string) $this->components->ask(
                'Provider del LLM (anthropic, openai, groq, gemini, mistral, ollama, …)',
                $defaultProvider,
            )));

            $suggestedModel = self::PROVIDERS[$provider]['model'] ?? $defaultModel;
            $model = trim((string) $this->components->ask('Modelo a usar', $suggestedModel));
        } else {
            $provider = $defaultProvider;
            $model    = $defaultModel;
        }

        $this->writeEnvKey('CHATBOT_PROVIDER', $provider);
        $this->writeEnvKey('CHATBOT_MODEL', $model);

        $apiKeyEnv = self::PROVIDERS[$provider]['env'] ?? null;

        if ($apiKeyEnv !== null) {
            $created = $this->writeEnvKey($apiKeyEnv, '', overwrite: false);

            if ($created) {
                $this->components->info("Añadida la clave `{$apiKeyEnv}` vacía a tu `.env`. Pega tu API key antes de probar el chatbot.");
            }
        }
    }

    /**
     * Paso 3 — Detecta Spatie y propone el resolver. Persiste a `.env`.
     */
    private function configureAuthorization(): void
    {
        $spatieAvailable = class_exists(\Spatie\Permission\PermissionServiceProvider::class);

        if (! $this->isInteractive()) {
            $this->writeEnvKey('CHATBOT_AUTH_RESOLVER', $spatieAvailable ? 'spatie' : 'gate');

            return;
        }

        if ($spatieAvailable) {
            $useSpatie = $this->components->confirm(
                'Detectado spatie/laravel-permission. ¿Usar como authorizer (recomendado)?',
                true,
            );
            $resolver = $useSpatie ? 'spatie' : 'gate';
        } else {
            $this->components->warn('spatie/laravel-permission no detectado. Se usará Gate por defecto.');
            $this->components->info('Para activar Spatie luego: `composer require spatie/laravel-permission` y cambia `CHATBOT_AUTH_RESOLVER` a `spatie`.');
            $resolver = 'gate';
        }

        $this->writeEnvKey('CHATBOT_AUTH_RESOLVER', $resolver);
    }

    /**
     * Paso 4 — Stub de ScopeResolver. Default: sí (fast win) salvo que el
     * usuario lo rechace explícitamente. En `--no-interaction` siempre sí.
     */
    private function generateScopeResolverStub(): void
    {
        $shouldGenerate = $this->isInteractive()
            ? $this->components->confirm('¿Generar stub de ScopeResolver en `app/Chatbot/`?', true)
            : true;

        if (! $shouldGenerate) {
            return;
        }

        $name = $this->isInteractive()
            ? trim((string) $this->components->ask('Nombre de la clase ScopeResolver', 'ChatbotScopeResolver'))
            : 'ChatbotScopeResolver';

        $exit = $this->call('chatbot:make:scope-resolver', ['name' => $name]);

        if ($exit !== self::SUCCESS) {
            $this->components->warn("No se pudo generar el ScopeResolver ({$name}). Continuando.");

            return;
        }

        $this->components->info("Recuerda apuntar `chatbot.authorization.scope_resolver` a `App\\Chatbot\\{$name}::class` en `config/chatbot.php`.");
    }

    /**
     * Paso 5 — TenantResolver opt-in. Sólo si el host indica que necesita
     * la 4ª dimensión (multi-tenant / entity-scoped). En no-interactive: no.
     */
    private function maybeGenerateTenantResolverStub(): void
    {
        if (! $this->isInteractive()) {
            return;
        }

        $needsTenantScope = $this->components->confirm(
            '¿Tu host necesita tenant scope (multi-corporación, multi-evento, etc.)?',
            false,
        );

        if (! $needsTenantScope) {
            return;
        }

        $name = trim((string) $this->components->ask('Nombre de la clase TenantResolver', 'ChatbotTenantResolver'));

        $exit = $this->call('chatbot:make:tenant-resolver', ['name' => $name]);

        if ($exit !== self::SUCCESS) {
            $this->components->warn("No se pudo generar el TenantResolver ({$name}). Continuando.");

            return;
        }

        $this->components->info("Recuerda apuntar `chatbot.authorization.tenant_resolver` a `App\\Chatbot\\{$name}::class` en `config/chatbot.php`.");
    }

    /**
     * Paso 6 — Vista de addendum del system prompt (gap E05). Pregunta si
     * el host quiere personalizar instrucciones específicas de dominio.
     */
    private function maybePublishSystemPromptAddendum(): void
    {
        if (! $this->isInteractive()) {
            return;
        }

        $wants = $this->components->confirm(
            '¿Publicar la vista de "addendum" del system prompt? (instrucciones de dominio adicionales)',
            false,
        );

        if (! $wants) {
            return;
        }

        $target = resource_path('views/vendor/chatbot/system_prompt_addendum.blade.php');

        if (! $this->files->isDirectory(dirname($target))) {
            $this->files->makeDirectory(dirname($target), 0755, true);
        }

        if ($this->files->exists($target) && ! $this->option('force')) {
            $this->components->info('La vista ya existe; usa `--force` para sobrescribir.');

            return;
        }

        $this->files->put($target, $this->systemPromptAddendumStub());

        $this->components->info("Creada `resources/views/vendor/chatbot/system_prompt_addendum.blade.php`. Apúntala desde `config/chatbot.php` en `system_prompt.addendum_view` (o vía la env-var CHATBOT_SYSTEM_PROMPT_ADDENDUM).");
    }

    /**
     * Paso 7 — Tool de ejemplo. Sólo opt-in interactivo (no queremos
     * polucionar el `app/` del host sin consentimiento).
     */
    private function maybeGenerateExampleTool(): void
    {
        if (! $this->isInteractive()) {
            return;
        }

        $wants = $this->components->confirm(
            '¿Generar un tool de ejemplo (ListMyInvoicesTool) en app/Chatbot/Tools/?',
            true,
        );

        if (! $wants) {
            return;
        }

        $stubPath = __DIR__ . '/stubs/tool-example.stub';
        $target   = app_path('Chatbot/Tools/ListMyInvoicesTool.php');

        if ($this->files->exists($target) && ! $this->option('force')) {
            $this->components->info('app/Chatbot/Tools/ListMyInvoicesTool.php ya existe; usa `--force` para sobrescribir.');

            return;
        }

        if (! $this->files->isDirectory(dirname($target))) {
            $this->files->makeDirectory(dirname($target), 0755, true);
        }

        $rendered = strtr($this->files->get($stubPath), [
            '{{ namespace }}' => $this->rootNamespace() . 'Chatbot\\Tools',
            '{{ class }}'     => 'ListMyInvoicesTool',
            '{{ tool_name }}' => 'list_my_invoices',
        ]);

        $this->files->put($target, $rendered);

        $this->components->info('Creado `app/Chatbot/Tools/ListMyInvoicesTool.php`. Auto-discovery lo registrará en boot.');
    }

    /**
     * Paso 7.5 — Override de `vendor/backpack/crud/inc/form_page.blade.php`
     * con `data-chatbot-form` para que `fill_form` tenga targeting
     * determinístico (findings #9.e). Solo se ejecuta con el flag CLI
     * `--backpack-forms` para no añadir más prompts al wizard interactivo;
     * el step se documenta en `docs/integrations/backpack.md §5.5` para
     * que el dev lo invoque cuando lo necesite.
     */
    private function maybePublishBackpackFormPage(): void
    {
        $backpackPresent = class_exists('Backpack\\CRUD\\app\\Library\\CrudPanel\\CrudPanel');

        if (! $backpackPresent) {
            return;
        }

        $flag = $this->option('backpack-forms');
        $wants = $flag === true || $flag === '1' || (is_string($flag) && in_array(strtolower($flag), ['1', 'true', 'yes'], true));

        if (! $wants) {
            return;
        }

        $stubPath = __DIR__ . '/stubs/backpack-form-page.stub';
        $target   = resource_path('views/vendor/backpack/crud/inc/form_page.blade.php');

        if (! $this->files->exists($stubPath)) {
            $this->components->warn("Stub no encontrado en {$stubPath}; omitiendo.");
            return;
        }

        if (! $this->files->isDirectory(dirname($target))) {
            $this->files->makeDirectory(dirname($target), 0755, true);
        }

        if ($this->files->exists($target) && ! $this->option('force')) {
            $this->components->info(
                "El override `{$this->relativePath($target)}` ya existe; usa `--force` para sobrescribir."
            );
            return;
        }

        $this->files->put($target, $this->files->get($stubPath));

        $this->components->info("Publicado `{$this->relativePath($target)}`. Los `<form>` Backpack incluirán `data-chatbot-form='<entity>-<operation>'`.");
    }

    /**
     * Paso 7.6 — Admin panel auto-generado (findings #14.f). Solo se
     * ejecuta con el flag CLI `--backpack-admin`. Mismo razonamiento que
     * 7.5: evitar añadir prompts al wizard interactivo. Documentado en el
     * comando `chatbot:install --help` y en la sección "Recetas
     * adicionales" de backpack.md.
     */
    private function maybePublishBackpackAdmin(): void
    {
        $backpackPresent = class_exists('Backpack\\CRUD\\app\\Library\\CrudPanel\\CrudPanel');

        if (! $backpackPresent) {
            return;
        }

        $flag = $this->option('backpack-admin');
        $wants = $flag === true || $flag === '1' || (is_string($flag) && in_array(strtolower($flag), ['1', 'true', 'yes'], true));

        if (! $wants) {
            return;
        }

        $stubs = [
            'ChatbotConversationCrudController.php'   => __DIR__ . '/stubs/backpack-conversation-crud.stub',
            'ChatbotMessageCrudController.php'        => __DIR__ . '/stubs/backpack-message-crud.stub',
            'ChatbotPendingActionCrudController.php'  => __DIR__ . '/stubs/backpack-pending-action-crud.stub',
        ];

        $targetDir = app_path('Http/Controllers/Admin');
        if (! $this->files->isDirectory($targetDir)) {
            $this->files->makeDirectory($targetDir, 0755, true);
        }

        $namespace = $this->rootNamespace() . 'Http\\Controllers\\Admin';
        $created   = [];

        foreach ($stubs as $filename => $stubPath) {
            $target = $targetDir . DIRECTORY_SEPARATOR . $filename;

            if (! $this->files->exists($stubPath)) {
                $this->components->warn("Stub no encontrado: {$stubPath}; saltando.");
                continue;
            }

            if ($this->files->exists($target) && ! $this->option('force')) {
                $this->components->info("`{$this->relativePath($target)}` ya existe; usa `--force` para sobrescribir.");
                continue;
            }

            $class = pathinfo($filename, PATHINFO_FILENAME);
            $rendered = strtr($this->files->get($stubPath), [
                '{{ namespace }}' => $namespace,
                '{{ class }}'     => $class,
            ]);

            $this->files->put($target, $rendered);
            $created[] = $filename;
        }

        if ($created !== []) {
            $this->components->info('Backpack admin CRUDs publicados: ' . implode(', ', $created));
            $this->components->info("Añade en `routes/backpack/custom.php`:");
            foreach ($created as $filename) {
                $class = pathinfo($filename, PATHINFO_FILENAME);
                $slug  = \Illuminate\Support\Str::kebab(str_replace('CrudController', '', $class));
                $this->line("  Route::crud('{$slug}', '\\\\{$namespace}\\\\{$class}');");
            }
        }
    }

    /**
     * Paso 8 — Inyección del widget en un layout Blade del host.
     *
     * Algoritmo:
     *   1. Buscar candidatos en `resources/views/layouts/{app,main,master,base}.blade.php`.
     *   2. Si hay >= 1, ofrecer al usuario el primero (o lista en multi).
     *   3. Si no hay o el usuario rechaza, prompt con path libre.
     *   4. Si el path final está vacío → skip (instrucciones manuales en el paso 9).
     *   5. Idempotente: si el marker ya está en el archivo, no re-inyectar.
     */
    private function maybeInjectWidgetIntoLayout(): void
    {
        if (! $this->isInteractive()) {
            return;
        }

        $candidates = $this->detectLayoutCandidates();

        $chosen = null;

        if ($candidates !== []) {
            $list = implode(', ', array_map(fn ($p) => $this->relativePath($p), $candidates));
            $useDetected = $this->components->confirm(
                "Detectado(s) layout(s) Blade: {$list}. ¿Inyectar el widget en el primero?",
                true,
            );

            if ($useDetected) {
                $chosen = $candidates[0];
            }
        }

        if ($chosen === null) {
            $manual = trim((string) $this->components->ask(
                'Ruta absoluta o relativa del layout donde inyectar el widget (vacío = skip)',
                '',
            ));

            if ($manual === '') {
                $this->components->warn('Inyección de widget omitida. Las instrucciones manuales están al final.');

                return;
            }

            $chosen = $this->resolveAbsolutePath($manual);
        }

        if (! $this->files->exists($chosen)) {
            $this->components->error("El archivo `{$this->relativePath($chosen)}` no existe. Inyección omitida.");

            return;
        }

        $contents = $this->files->get($chosen);

        if (str_contains($contents, self::WIDGET_MARKER)) {
            $this->components->info('El widget ya está inyectado en ese layout (marker detectado). Nada que hacer.');

            return;
        }

        $injected = $this->injectWidgetSnippet($contents);

        if ($injected === null) {
            $this->components->warn('No encontré `</body>` en el layout — pega el snippet manualmente (ver final).');

            return;
        }

        $this->files->put($chosen, $injected);
        $this->components->info("Widget inyectado en `{$this->relativePath($chosen)}` justo antes de `</body>`.");
    }

    /**
     * Paso 9 — Resumen final con next steps.
     */
    private function printFinalInstructions(): void
    {
        $this->newLine();
        $this->components->info('Instalación completa. Próximos pasos:');

        $this->components->bulletList([
            'Ejecuta `php artisan migrate` para crear `chatbot_conversations` y `chatbot_messages`.',
            'Verifica la conexión con el LLM: `php artisan chatbot:test-connection`.',
            'Lista las tools registradas: `php artisan chatbot:tools:list`.',
            'Si no se inyectó el widget automáticamente, añade en tu layout principal antes de `</body>`:',
            $this->widgetSnippet(),
        ]);
    }

    // ----- helpers -----

    private function isInteractive(): bool
    {
        return ! $this->option('no-interaction');
    }

    /**
     * Escribe (o conserva si `overwrite=false`) una clave en `.env` y `.env.example`.
     * Si el archivo no existe, no lo crea (no inventamos `.env` en repos vacíos).
     *
     * @return bool true si la clave se escribió o existía vacía, false si se preservó un valor previo.
     */
    private function writeEnvKey(string $key, string $value, bool $overwrite = true): bool
    {
        $any = false;

        foreach ([base_path('.env'), base_path('.env.example')] as $envFile) {
            if (! $this->files->exists($envFile)) {
                continue;
            }

            $contents = $this->files->get($envFile);

            if (preg_match("/^{$key}=.*$/m", $contents) === 1) {
                if (! $overwrite) {
                    continue;
                }

                $contents = preg_replace(
                    "/^{$key}=.*$/m",
                    "{$key}={$this->escapeEnvValue($value)}",
                    $contents,
                );
            } else {
                $contents = rtrim($contents, "\n") . "\n{$key}={$this->escapeEnvValue($value)}\n";
            }

            $this->files->put($envFile, $contents);
            $any = true;
        }

        return $any;
    }

    private function escapeEnvValue(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_\-\.\/:]+$/', $value) === 1) {
            return $value;
        }

        return '"' . str_replace('"', '\\"', $value) . '"';
    }

    /**
     * @return array<int, string>  Paths absolutos a candidatos existentes.
     */
    private function detectLayoutCandidates(): array
    {
        $base = resource_path('views/layouts');

        if (! $this->files->isDirectory($base)) {
            return [];
        }

        $found = [];
        foreach (['app', 'main', 'master', 'base'] as $name) {
            $path = $base . DIRECTORY_SEPARATOR . $name . '.blade.php';
            if ($this->files->exists($path)) {
                $found[] = $path;
            }
        }

        return $found;
    }

    private function injectWidgetSnippet(string $contents): ?string
    {
        if (! str_contains($contents, '</body>')) {
            return null;
        }

        $snippet = $this->widgetSnippet() . "\n";

        return Str::replaceLast('</body>', $snippet . '</body>', $contents);
    }

    private function widgetSnippet(): string
    {
        $script  = "<script src=\"{{ asset(config('chatbot.widget.asset_path')) }}\" defer></script>";
        $element = '<chatbot-widget></chatbot-widget>';

        return self::WIDGET_MARKER . "\n{$script}\n{$element}";
    }

    private function relativePath(string $absolute): string
    {
        $base = base_path() . DIRECTORY_SEPARATOR;

        $relative = str_starts_with($absolute, $base)
            ? substr($absolute, strlen($base))
            : $absolute;

        // Display-only: normalise to forward slashes so console output is
        // consistent across platforms. On Windows the candidate paths mix
        // separators (`resource_path('views/layouts')` joins the `\`-based
        // base with the `/`-based literal arg), which would otherwise leak
        // `resources\views/layouts\app.blade.php` into messages.
        return str_replace('\\', '/', $relative);
    }

    private function resolveAbsolutePath(string $path): string
    {
        if ($this->files->isFile($path)) {
            return $path;
        }

        return base_path($path);
    }

    private function rootNamespace(): string
    {
        $namespace = $this->laravel->getNamespace();

        return rtrim((string) $namespace, '\\') . '\\';
    }

    private function systemPromptAddendumStub(): string
    {
        return <<<'BLADE'
{{-- Addendum del system prompt --}}
{{-- Este texto se concatena al final del system prompt base. Úsalo para
     instrucciones específicas de tu dominio: jerga, formatos, glosario,
     reglas estrictas (eg. "responde siempre en español", "nunca menciones
     X"). El contexto del chat (page, user, tools) ya está inyectado en
     la vista base. --}}

# Reglas adicionales de dominio

- TODO: añade tus instrucciones aquí.
BLADE;
    }
}
