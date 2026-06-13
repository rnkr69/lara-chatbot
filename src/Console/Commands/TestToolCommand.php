<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\Contracts\BackendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;
use Throwable;

/**
 * `php artisan chatbot:tools:test <name>` (v1.1.1, finding #14.b).
 *
 * Invokes a tool from the CLI without going through the LLM or the browser.
 * Useful for iterating on `handle()` in fast TDD (you change the code, run
 * the command, look at the result).
 *
 * Typical usage:
 *   php artisan chatbot:tools:test list_my_missions \
 *       --user=pilot1@andromeda.test \
 *       --args='{"status":"draft","limit":5}'
 *
 * How it works:
 *   1. Resolves the tool by name from the ToolRegistry.
 *   2. Resolves the invoking user (--user= email|id, default = first User).
 *   3. Builds a ToolContext without conversation or page context.
 *   4. Calls `execute(args, ctx)` → the BaseBackendTool's validation +
 *      authorization cascade is applied just as at runtime.
 *   5. Prints the ToolResult JSON-formatted.
 *
 * It does NOT simulate confirmations or pending actions; useful only for
 * backend (Auto) tools. For frontend tools with confirm/manual, this command
 * returns `awaiting_user` directly.
 */
class TestToolCommand extends Command
{
    /** @var string */
    protected $signature = 'chatbot:tools:test
                            {name : Tool name (snake_case, e.g. list_my_missions).}
                            {--user= : Email or ID of the user to use as ToolContext->user (default: first).}
                            {--args= : JSON args (e.g. \'{"status":"draft"}\').}
                            {--page-context= : Page context JSON.}';

    /** @var string */
    protected $description = 'Invoke a tool by name from the CLI without the LLM or a browser. Useful for fast TDD.';

    public function handle(ToolRegistry $registry): int
    {
        $name = (string) $this->argument('name');

        $tool = $registry->get($name);
        if ($tool === null) {
            $this->components->error("Tool `{$name}` not registered. List the available ones with `chatbot:tools:list`.");
            return self::FAILURE;
        }

        $user = $this->resolveUser();
        if ($user === null) {
            $this->components->error('Could not resolve an invoking user. Pass `--user=<email|id>` or make sure there is at least one User in the database.');
            return self::FAILURE;
        }

        // Bind the auth manager so that policies/can/$user->can(...) work
        // exactly as in real runtime.
        try {
            Auth::setUser($user);
        } catch (Throwable) { /* fall through — auth driver may not support setUser in CLI */ }

        $args = $this->parseJson($this->option('args'), 'args');
        if ($args === false) return self::FAILURE;

        $pageContext = $this->parseJson($this->option('page-context'), 'page-context') ?: [];
        if ($pageContext === false) return self::FAILURE;

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>Calling</> ' . $tool->name());
        $this->line('  <info>Acting as:</info> ' . $this->describeUser($user));
        $this->line('  <info>Permissions:</info> ' . (empty($tool->permissions()) ? '(none)' : implode(', ', $tool->permissions())));
        $this->line('  <info>Default scope:</info> ' . $tool->defaultScope()->value);
        $this->newLine();

        $ctx = new ToolContext(user: $user, pageContext: is_array($pageContext) ? $pageContext : []);

        $started = microtime(true);
        try {
            $result = $tool instanceof BaseBackendTool
                ? $tool->execute(is_array($args) ? $args : [], $ctx)
                : $tool->handle(is_array($args) ? $args : [], $ctx);
        } catch (Throwable $e) {
            $this->components->error('Exception: ' . $e::class . ' — ' . $e->getMessage());
            $this->line($e->getTraceAsString());
            return self::FAILURE;
        }
        $ms = round((microtime(true) - $started) * 1000);

        $payload = method_exists($result, 'toArray') ? $result->toArray() : ['result' => $result];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->line('  <info>Result</info> (' . $ms . ' ms):');
        $this->newLine();
        foreach (preg_split("/\r?\n/", (string) $json) as $line) {
            $this->line('    ' . $line);
        }
        $this->newLine();

        // v2.1 (#5) — a tool that declares `pinnable()` but returns no
        // `blocks` can never produce the 📌 pin button: the orchestrator
        // emits the pin affordance from `ToolResult::blocks`, not from
        // `data`. This is silent in production (the tool "works", it just
        // can't be pinned) — surface it here, where the developer explicitly
        // asked to exercise this tool.
        if ($tool->pinnable() && $result->isOk() && $result->blocks === []) {
            $this->components->warn(
                "`{$tool->name()}` declares pinnable() === true but handle() returned 0 blocks. "
                . 'The 📌 pin button is server-emitted from ToolResult::blocks — a pinnable tool '
                . 'that returns only `data` (no `blocks: [...]`) can never be pinned. '
                . 'See docs/backend-tools.md §9.'
            );
            $this->newLine();
        }

        return $result->isOk() ? self::SUCCESS : self::FAILURE;
    }

    private function resolveUser(): ?Authenticatable
    {
        $needle = (string) $this->option('user');

        $userClass = $this->guessUserModel();
        if ($userClass === null || ! class_exists($userClass)) {
            return null;
        }

        try {
            if ($needle === '') {
                /** @var Model|null $u */
                $u = $userClass::query()->first();
                return $u instanceof Authenticatable ? $u : null;
            }

            // Try id (numeric or uuid-ish) first, then common email column.
            /** @var Model|null $u */
            $u = $userClass::query()
                ->where(function ($q) use ($needle): void {
                    $q->where('id', $needle)
                      ->orWhere('email', $needle);
                })
                ->first();

            return $u instanceof Authenticatable ? $u : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function guessUserModel(): ?string
    {
        $cfg = config('auth.providers.users.model');
        if (is_string($cfg) && $cfg !== '' && class_exists($cfg)) {
            return $cfg;
        }
        return class_exists('App\\Models\\User') ? 'App\\Models\\User' : null;
    }

    private function describeUser(Authenticatable $user): string
    {
        $id = method_exists($user, 'getAuthIdentifier') ? $user->getAuthIdentifier() : '?';
        $email = is_object($user) && property_exists($user, 'email') ? (string) $user->email : '';
        $name  = is_object($user) && property_exists($user, 'name')  ? (string) $user->name  : '';
        return "User #{$id}" . ($email !== '' ? " ({$email})" : '') . ($name !== '' ? " - {$name}" : '');
    }

    /**
     * @return array<int|string, mixed>|false
     */
    private function parseJson(mixed $raw, string $label): array|false
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (! is_string($raw)) {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->components->error("--{$label} is not valid JSON: " . $e->getMessage());
            return false;
        }
        return is_array($decoded) ? $decoded : [];
    }
}
