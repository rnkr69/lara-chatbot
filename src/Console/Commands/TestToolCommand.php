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
 * Invoca una tool desde CLI sin pasar por el LLM ni el browser. Útil
 * para iterar `handle()` en TDD rápido (cambias el código, lanzas el
 * comando, miras el resultado).
 *
 * Uso típico:
 *   php artisan chatbot:tools:test list_my_missions \
 *       --user=pilot1@andromeda.test \
 *       --args='{"status":"draft","limit":5}'
 *
 * Cómo funciona:
 *   1. Resuelve la tool por nombre desde el ToolRegistry.
 *   2. Resuelve el usuario invocante (--user= email|id, default = primer User).
 *   3. Construye un ToolContext sin conversation ni page context.
 *   4. Llama `execute(args, ctx)` → la cascada de validación + autorización
 *      del BaseBackendTool se aplica igual que en runtime.
 *   5. Imprime el ToolResult JSON-formatted.
 *
 * NO simula confirmations ni pending actions; útil sólo para tools backend
 * (Auto). Para frontend tools con confirm/manual, este comando devuelve
 * `awaiting_user` directamente.
 */
class TestToolCommand extends Command
{
    /** @var string */
    protected $signature = 'chatbot:tools:test
                            {name : Nombre de la tool (snake_case, e.g. list_my_missions).}
                            {--user= : Email o ID del usuario a usar como ToolContext->user (default: primero).}
                            {--args= : Args JSON (e.g. \'{"status":"draft"}\').}
                            {--page-context= : Page context JSON.}';

    /** @var string */
    protected $description = 'Invoca una tool por nombre desde CLI sin LLM ni browser. Útil para TDD rápido.';

    public function handle(ToolRegistry $registry): int
    {
        $name = (string) $this->argument('name');

        $tool = $registry->get($name);
        if ($tool === null) {
            $this->components->error("Tool `{$name}` no registrada. Lista las disponibles con `chatbot:tools:list`.");
            return self::FAILURE;
        }

        $user = $this->resolveUser();
        if ($user === null) {
            $this->components->error('No pude resolver un usuario invocante. Pasa `--user=<email|id>` o asegúrate de tener al menos un User en la BD.');
            return self::FAILURE;
        }

        // Bind auth manager para que policies/can/$user->can(...) funcionen
        // exactamente como en runtime real.
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
            $this->components->error('Excepción: ' . $e::class . ' — ' . $e->getMessage());
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
            $this->components->error("--{$label} no es JSON válido: " . $e->getMessage());
            return false;
        }
        return is_array($decoded) ? $decoded : [];
    }
}
