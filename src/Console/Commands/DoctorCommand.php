<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Rnkr69\LaraChatbot\Authorization\Contracts\ScopeResolver;
use Rnkr69\LaraChatbot\Authorization\Contracts\TenantResolver;
use Rnkr69\LaraChatbot\Llm\LlmGateway;
use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Models\Message;
use Rnkr69\LaraChatbot\Models\PendingAction;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;
use Throwable;

/**
 * `php artisan chatbot:doctor` (v1.1.1, finding #14.a).
 *
 * Health check of the package setup. Reports a checklist with
 * ✓ / ✗ / ⚠ per category. Low cost, very high onboarding value —
 * it is the first command any new dev will run when integrating.
 *
 * Categories:
 *   - Configuration (provider, model, scope_resolver, basic env vars).
 *   - Authorization (resolver / scope / tenant).
 *   - Database (tables, connection).
 *   - Assets (published JS bundle + sourcemap).
 *   - LLM (API key env var, optionally ping).
 *   - Tools (registered count, mcp warnings, tenant scope without resolver).
 *
 * Exit code 0 if there are no errors. 1 if at least one error (not warnings).
 */
class DoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'chatbot:doctor
                            {--no-ping : Skip the LLM ping (faster, consumes no tokens).}';

    /** @var string */
    protected $description = 'Health check of the chatbot package setup (config, auth, DB, assets, LLM, tools).';

    private int $errors = 0;
    private int $warnings = 0;

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>rnkr69/lara-chatbot</> — health check');
        $this->newLine();

        $this->checkConfiguration();
        $this->checkAuthorization();
        $this->checkDatabase();
        $this->checkAssets();
        $this->checkLlm();
        $this->checkTools();

        $this->newLine();
        $this->summary();

        return $this->errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function summary(): void
    {
        if ($this->errors === 0 && $this->warnings === 0) {
            $this->components->info('All checks passed. Ready to use.');
            return;
        }

        $parts = [];
        if ($this->errors > 0)   $parts[] = "{$this->errors} error" . ($this->errors === 1 ? '' : 's');
        if ($this->warnings > 0) $parts[] = "{$this->warnings} warning" . ($this->warnings === 1 ? '' : 's');

        $msg = 'Result: ' . implode(', ', $parts) . '.';
        if ($this->errors > 0) {
            $this->components->error($msg);
        } else {
            $this->components->warn($msg);
        }
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("  <fg=yellow;options=bold>{$title}</>");
    }

    private function ok(string $msg): void
    {
        $this->line("    <fg=green>✓</> {$msg}");
    }

    private function checkWarn(string $msg): void
    {
        $this->warnings++;
        $this->line("    <fg=yellow>⚠</> {$msg}");
    }

    private function err(string $msg): void
    {
        $this->errors++;
        $this->line("    <fg=red>✗</> {$msg}");
    }

    // ----- Sections -----

    private function checkConfiguration(): void
    {
        $this->section('Configuration');

        $configPath = function_exists('config_path') ? config_path('chatbot.php') : '';
        if ($configPath !== '' && $this->files->exists($configPath)) {
            $this->ok('config/chatbot.php published');
        } else {
            $this->checkWarn('config/chatbot.php not published (running from package defaults). Run `chatbot:install`.');
        }

        $provider = (string) config('chatbot.provider', '');
        $model    = (string) config('chatbot.model', '');

        if ($provider !== '') {
            $this->ok("chatbot.provider = <info>{$provider}</info>");
        } else {
            $this->err('chatbot.provider is empty.');
        }

        if ($model !== '') {
            $this->ok("chatbot.model = <info>{$model}</info>");
        } else {
            $this->err('chatbot.model is empty.');
        }

        $scopeResolver = config('chatbot.authorization.scope_resolver');
        if (is_string($scopeResolver) && $scopeResolver !== '') {
            if (class_exists($scopeResolver)) {
                $this->ok("scope_resolver → <info>{$scopeResolver}</info>");
            } else {
                $this->err("chatbot.authorization.scope_resolver points to `{$scopeResolver}` but that class does not exist.");
            }
        } else {
            $this->checkWarn('chatbot.authorization.scope_resolver not configured (using NullScopeResolver — AccessScope::Self only).');
        }
    }

    private function checkAuthorization(): void
    {
        $this->section('Authorization');

        $resolver = (string) config('chatbot.authorization.resolver', 'spatie');
        $this->ok("resolver = <info>{$resolver}</info>");

        if ($resolver === 'spatie') {
            if (class_exists(\Spatie\Permission\PermissionServiceProvider::class)) {
                $this->ok('spatie/laravel-permission detected');
            } else {
                $this->err('resolver=spatie but spatie/laravel-permission is not installed.');
            }
        }

        try {
            $scope = function_exists('app') ? app(ScopeResolver::class) : null;
            if ($scope !== null) {
                $this->ok('ScopeResolver bind OK (' . $scope::class . ')');
            }
        } catch (Throwable $e) {
            $this->err('ScopeResolver not resolvable: ' . $e->getMessage());
        }

        try {
            if (function_exists('app') && app()->bound(TenantResolver::class)) {
                $tenant = app(TenantResolver::class);
                $this->ok('TenantResolver bind OK (' . $tenant::class . ')');
            } else {
                $this->checkWarn('TenantResolver not bound (OK if your app is single-tenant).');
            }
        } catch (Throwable $e) {
            $this->err('TenantResolver not resolvable: ' . $e->getMessage());
        }
    }

    private function checkDatabase(): void
    {
        $this->section('Database');

        try {
            $conv = Conversation::query()->count();
            $this->ok("chatbot_conversations: <info>{$conv}</info> rows");
        } catch (Throwable $e) {
            $this->err('chatbot_conversations not accessible — did you run `php artisan migrate`? ('. $e->getMessage() .')');
            return;
        }

        try {
            $msg = Message::query()->count();
            $this->ok("chatbot_messages: <info>{$msg}</info> rows");
        } catch (Throwable $e) {
            $this->err('chatbot_messages not accessible: ' . $e->getMessage());
        }

        try {
            $pa = PendingAction::query()->count();
            $this->ok("chatbot_pending_actions: <info>{$pa}</info> rows");
        } catch (Throwable $e) {
            $this->err('chatbot_pending_actions not accessible: ' . $e->getMessage());
        }
    }

    private function checkAssets(): void
    {
        $this->section('Assets');

        $path = function_exists('public_path') ? public_path('vendor/chatbot/chatbot-widget.js') : '';
        if ($path !== '' && $this->files->exists($path)) {
            $size = $this->files->size($path);
            $kb = number_format($size / 1024, 1);
            $this->ok("chatbot-widget.js published ({$kb} KB)");
        } else {
            $this->err('public/vendor/chatbot/chatbot-widget.js does not exist. Run `php artisan vendor:publish --tag=chatbot-assets --force`.');
        }

        $mapPath = function_exists('public_path') ? public_path('vendor/chatbot/chatbot-widget.js.map') : '';
        if ($mapPath !== '' && $this->files->exists($mapPath)) {
            $this->ok('chatbot-widget.js.map present');
        } else {
            $this->checkWarn('chatbot-widget.js.map missing (bundle debugging will be limited).');
        }
    }

    private function checkLlm(): void
    {
        $this->section('LLM');

        $provider = (string) config('chatbot.provider', '');
        $envMap = [
            'anthropic' => 'ANTHROPIC_API_KEY',
            'openai'    => 'OPENAI_API_KEY',
            'groq'      => 'GROQ_API_KEY',
            'gemini'    => 'GEMINI_API_KEY',
            'mistral'   => 'MISTRAL_API_KEY',
        ];

        $envKey = $envMap[$provider] ?? null;
        if ($envKey !== null) {
            $val = function_exists('env') ? env($envKey) : null;
            if (is_string($val) && $val !== '') {
                $this->ok("{$envKey} present");
            } else {
                $this->err("{$envKey} is not set in `.env` (provider={$provider}).");
            }
        }

        $verify = config('chatbot.http.verify', true);
        if ($verify === false) {
            $this->checkWarn('chatbot.http.verify = false (SSL disabled globally — only acceptable in dev/staging).');
        } else {
            $this->ok('chatbot.http.verify = true');
        }

        if ($this->option('no-ping')) {
            $this->line('    (skipping LLM ping — run without `--no-ping` to perform a real test)');
            return;
        }

        try {
            $gateway = function_exists('app') ? app(LlmGateway::class) : null;
            if ($gateway === null) {
                $this->checkWarn('LlmGateway not resolvable — ping skipped.');
                return;
            }
            $started = microtime(true);
            $reply = $gateway->ping();
            $ms = round((microtime(true) - $started) * 1000);
            if (stripos($reply, 'pong') !== false) {
                $this->ok("ping → <info>pong</info> ({$ms} ms)");
            } else {
                $this->checkWarn("ping replied: " . substr($reply, 0, 60) . " ({$ms} ms)");
            }
        } catch (Throwable $e) {
            $this->err('LLM ping failed: ' . $e->getMessage());
        }
    }

    private function checkTools(): void
    {
        $this->section('Tools');

        try {
            $registry = function_exists('app') ? app(ToolRegistry::class) : null;
            if ($registry === null) {
                $this->err('ToolRegistry not resolvable.');
                return;
            }
            $count = count($registry->all());
            $this->ok("{$count} tools registered");
        } catch (Throwable $e) {
            $this->err('Could not list tools: ' . $e->getMessage());
            return;
        }

        // Auto-discovery hint.
        $autoDiscover = (bool) config('chatbot.tools.auto_discover', true);
        $paths = (array) config('chatbot.tools.paths', []);
        if ($autoDiscover && $paths !== []) {
            $this->ok('auto_discover active in: ' . implode(', ', $paths));
        } elseif (! $autoDiscover) {
            $this->checkWarn('auto_discover disabled — register tools manually from AppServiceProvider.');
        }
    }
}
