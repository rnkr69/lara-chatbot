<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * `php artisan chatbot:install` — interactive package setup (E18).
 *
 * Steps (all idempotent; the command can be re-run):
 *   1. Publishes config, migrations, views, prompts, assets and lang.
 *   2. Asks for provider/model and persists them in `.env` and `.env.example`
 *      (along with the provider API key, empty if it does not exist).
 *   3. Detects Spatie and proposes the authorization resolver.
 *   4. Generates a `ScopeResolver` stub (`chatbot:make:scope-resolver`).
 *   5. If the host needs tenant scope, generates a `TenantResolver` stub.
 *   6. If the host wants `system_prompt_addendum`, publishes the base view.
 *   7. Generates an example tool (`ListMyInvoicesTool`) from its own stub.
 *   8. Injects `<script>` + `<chatbot-widget>` into a host Blade layout
 *      (auto-detect + confirmable prompt + skip → manual instructions).
 *   9. Prints final instructions (routes, registering tools, commands).
 *
 * Supports `--no-interaction`:
 *   - Provider/model = config defaults (anthropic / claude-sonnet-4-6).
 *   - Resolver = `spatie` if the class is present, `gate` otherwise.
 *   - Always generates the ScopeResolver stub (fast win).
 *   - Does NOT generate a tenant resolver, does NOT generate an example tool,
 *     does NOT inject the layout (those steps touch host code and require
 *     explicit consent).
 *
 * Supports `--force` to overwrite already published publishables.
 */
class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'chatbot:install
                            {--force : Overwrite already published publishables.}
                            {--backpack-forms : Publish the form_page.blade.php override to tag Backpack forms with data-chatbot-form (v1.1.1).}
                            {--backpack-admin : Publish 3 read-only CrudControllers to inspect conversations/messages/pending_actions from the Backpack admin (v1.1.1).}';

    /** @var string */
    protected $description = 'Interactive installation of the chatbot package (publishes assets, configures .env, generates stubs).';

    /**
     * HTML marker that `injectWidgetIntoLayout()` looks for so it does not
     * duplicate the injection if the command is re-run. It is an inert comment.
     */
    private const WIDGET_MARKER = '<!-- chatbot:widget -->';

    /**
     * List of known Prism providers with their API key env-var and a
     * reasonable default model. The host can choose any; the ones not
     * listed here are valid but do not auto-complete the API key.
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
        $this->components->info('Installing rnkr69/lara-chatbot…');

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
     * Step 1 — Publishes the package's six tags. With `--force`, overwrites.
     */
    private function publishAssets(): void
    {
        $this->components->task('Publishing configuration and assets', function (): bool {
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
     * Step 2 — Asks for provider+model and persists to .env / .env.example.
     */
    private function configureProviderAndModel(): void
    {
        $defaultProvider = (string) config('chatbot.provider', 'anthropic');
        $defaultModel    = (string) config('chatbot.model', 'claude-sonnet-4-6');

        if ($this->isInteractive()) {
            $provider = strtolower(trim((string) $this->components->ask(
                'LLM provider (anthropic, openai, groq, gemini, mistral, ollama, …)',
                $defaultProvider,
            )));

            $suggestedModel = self::PROVIDERS[$provider]['model'] ?? $defaultModel;
            $model = trim((string) $this->components->ask('Model to use', $suggestedModel));
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
                $this->components->info("Added the empty `{$apiKeyEnv}` key to your `.env`. Paste your API key before testing the chatbot.");
            }
        }
    }

    /**
     * Step 3 — Detects Spatie and proposes the resolver. Persists to `.env`.
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
                'spatie/laravel-permission detected. Use it as the authorizer (recommended)?',
                true,
            );
            $resolver = $useSpatie ? 'spatie' : 'gate';
        } else {
            $this->components->warn('spatie/laravel-permission not detected. Gate will be used by default.');
            $this->components->info('To enable Spatie later: `composer require spatie/laravel-permission` and change `CHATBOT_AUTH_RESOLVER` to `spatie`.');
            $resolver = 'gate';
        }

        $this->writeEnvKey('CHATBOT_AUTH_RESOLVER', $resolver);
    }

    /**
     * Step 4 — ScopeResolver stub. Default: yes (fast win) unless the
     * user explicitly declines. In `--no-interaction` always yes.
     */
    private function generateScopeResolverStub(): void
    {
        $shouldGenerate = $this->isInteractive()
            ? $this->components->confirm('Generate a ScopeResolver stub in `app/Chatbot/`?', true)
            : true;

        if (! $shouldGenerate) {
            return;
        }

        $name = $this->isInteractive()
            ? trim((string) $this->components->ask('ScopeResolver class name', 'ChatbotScopeResolver'))
            : 'ChatbotScopeResolver';

        $exit = $this->call('chatbot:make:scope-resolver', ['name' => $name]);

        if ($exit !== self::SUCCESS) {
            $this->components->warn("Could not generate the ScopeResolver ({$name}). Continuing.");

            return;
        }

        $this->components->info("Remember to point `chatbot.authorization.scope_resolver` to `App\\Chatbot\\{$name}::class` in `config/chatbot.php`.");
    }

    /**
     * Step 5 — TenantResolver opt-in. Only if the host indicates it needs
     * the 4th dimension (multi-tenant / entity-scoped). In no-interactive: no.
     */
    private function maybeGenerateTenantResolverStub(): void
    {
        if (! $this->isInteractive()) {
            return;
        }

        $needsTenantScope = $this->components->confirm(
            'Does your host need tenant scope (multi-corporation, multi-event, etc.)?',
            false,
        );

        if (! $needsTenantScope) {
            return;
        }

        $name = trim((string) $this->components->ask('TenantResolver class name', 'ChatbotTenantResolver'));

        $exit = $this->call('chatbot:make:tenant-resolver', ['name' => $name]);

        if ($exit !== self::SUCCESS) {
            $this->components->warn("Could not generate the TenantResolver ({$name}). Continuing.");

            return;
        }

        $this->components->info("Remember to point `chatbot.authorization.tenant_resolver` to `App\\Chatbot\\{$name}::class` in `config/chatbot.php`.");
    }

    /**
     * Step 6 — System prompt addendum view (gap E05). Asks whether the host
     * wants to customize domain-specific instructions.
     */
    private function maybePublishSystemPromptAddendum(): void
    {
        if (! $this->isInteractive()) {
            return;
        }

        $wants = $this->components->confirm(
            'Publish the system prompt "addendum" view? (additional domain-specific instructions)',
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
            $this->components->info('The view already exists; use `--force` to overwrite.');

            return;
        }

        $this->files->put($target, $this->systemPromptAddendumStub());

        $this->components->info("Created `resources/views/vendor/chatbot/system_prompt_addendum.blade.php`. Point to it from `config/chatbot.php` in `system_prompt.addendum_view` (or via the CHATBOT_SYSTEM_PROMPT_ADDENDUM env var).");
    }

    /**
     * Step 7 — Example tool. Interactive opt-in only (we do not want to
     * pollute the host's `app/` without consent).
     */
    private function maybeGenerateExampleTool(): void
    {
        if (! $this->isInteractive()) {
            return;
        }

        $wants = $this->components->confirm(
            'Generate an example tool (ListMyInvoicesTool) in app/Chatbot/Tools/?',
            true,
        );

        if (! $wants) {
            return;
        }

        $stubPath = __DIR__ . '/stubs/tool-example.stub';
        $target   = app_path('Chatbot/Tools/ListMyInvoicesTool.php');

        if ($this->files->exists($target) && ! $this->option('force')) {
            $this->components->info('app/Chatbot/Tools/ListMyInvoicesTool.php already exists; use `--force` to overwrite.');

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

        $this->components->info('Created `app/Chatbot/Tools/ListMyInvoicesTool.php`. Auto-discovery will register it at boot.');
    }

    /**
     * Step 7.5 — Override of `vendor/backpack/crud/inc/form_page.blade.php`
     * with `data-chatbot-form` so that `fill_form` has deterministic
     * targeting (findings #9.e). Only runs with the CLI flag
     * `--backpack-forms` so as not to add more prompts to the interactive
     * wizard; the step is documented in `docs/integrations/backpack.md §5.5`
     * so the dev can invoke it when needed.
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
            $this->components->warn("Stub not found at {$stubPath}; skipping.");
            return;
        }

        if (! $this->files->isDirectory(dirname($target))) {
            $this->files->makeDirectory(dirname($target), 0755, true);
        }

        if ($this->files->exists($target) && ! $this->option('force')) {
            $this->components->info(
                "The override `{$this->relativePath($target)}` already exists; use `--force` to overwrite."
            );
            return;
        }

        $this->files->put($target, $this->files->get($stubPath));

        $this->components->info("Published `{$this->relativePath($target)}`. Backpack `<form>`s will include `data-chatbot-form='<entity>-<operation>'`.");
    }

    /**
     * Step 7.6 — Auto-generated admin panel (findings #14.f). Only runs
     * with the CLI flag `--backpack-admin`. Same reasoning as 7.5: avoid
     * adding prompts to the interactive wizard. Documented in the
     * `chatbot:install --help` command and in the "Additional recipes"
     * section of backpack.md.
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
                $this->components->warn("Stub not found: {$stubPath}; skipping.");
                continue;
            }

            if ($this->files->exists($target) && ! $this->option('force')) {
                $this->components->info("`{$this->relativePath($target)}` already exists; use `--force` to overwrite.");
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
            $this->components->info('Backpack admin CRUDs published: ' . implode(', ', $created));
            $this->components->info("Add to `routes/backpack/custom.php`:");
            foreach ($created as $filename) {
                $class = pathinfo($filename, PATHINFO_FILENAME);
                $slug  = \Illuminate\Support\Str::kebab(str_replace('CrudController', '', $class));
                $this->line("  Route::crud('{$slug}', '\\\\{$namespace}\\\\{$class}');");
            }
        }
    }

    /**
     * Step 8 — Injection of the widget into a host Blade layout.
     *
     * Algorithm:
     *   1. Look for candidates in `resources/views/layouts/{app,main,master,base}.blade.php`.
     *   2. If there is >= 1, offer the user the first one (or a list in multi).
     *   3. If there are none or the user declines, prompt with a free path.
     *   4. If the final path is empty → skip (manual instructions in step 9).
     *   5. Idempotent: if the marker is already in the file, do not re-inject.
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
                "Detected Blade layout(s): {$list}. Inject the widget into the first one?",
                true,
            );

            if ($useDetected) {
                $chosen = $candidates[0];
            }
        }

        if ($chosen === null) {
            $manual = trim((string) $this->components->ask(
                'Absolute or relative path of the layout to inject the widget into (empty = skip)',
                '',
            ));

            if ($manual === '') {
                $this->components->warn('Widget injection skipped. Manual instructions are at the end.');

                return;
            }

            $chosen = $this->resolveAbsolutePath($manual);
        }

        if (! $this->files->exists($chosen)) {
            $this->components->error("The file `{$this->relativePath($chosen)}` does not exist. Injection skipped.");

            return;
        }

        $contents = $this->files->get($chosen);

        if (str_contains($contents, self::WIDGET_MARKER)) {
            $this->components->info('The widget is already injected into that layout (marker detected). Nothing to do.');

            return;
        }

        $injected = $this->injectWidgetSnippet($contents);

        if ($injected === null) {
            $this->components->warn('Could not find `</body>` in the layout — paste the snippet manually (see end).');

            return;
        }

        $this->files->put($chosen, $injected);
        $this->components->info("Widget injected into `{$this->relativePath($chosen)}` right before `</body>`.");
    }

    /**
     * Step 9 — Final summary with next steps.
     */
    private function printFinalInstructions(): void
    {
        $this->newLine();
        $this->components->info('Installation complete. Next steps:');

        $this->components->bulletList([
            'Run `php artisan migrate` to create `chatbot_conversations` and `chatbot_messages`.',
            'Verify the connection to the LLM: `php artisan chatbot:test-connection`.',
            'List the registered tools: `php artisan chatbot:tools:list`.',
            'If the widget was not injected automatically, add this to your main layout before `</body>`:',
            $this->widgetSnippet(),
        ]);
    }

    // ----- helpers -----

    private function isInteractive(): bool
    {
        return ! $this->option('no-interaction');
    }

    /**
     * Writes (or keeps if `overwrite=false`) a key in `.env` and `.env.example`.
     * If the file does not exist, it does not create it (we do not invent `.env` in empty repos).
     *
     * @return bool true if the key was written or existed empty, false if a previous value was preserved.
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
     * @return array<int, string>  Absolute paths to existing candidates.
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
{{-- System prompt addendum --}}
{{-- This text is concatenated to the end of the base system prompt. Use it for
     domain-specific instructions: jargon, formats, glossary, strict rules
     (e.g. "always respond in English", "never mention X"). The chat context
     (page, user, tools) is already injected in the base view. --}}

# Additional domain rules

- TODO: add your instructions here.
BLADE;
    }
}
