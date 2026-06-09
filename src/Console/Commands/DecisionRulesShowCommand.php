<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Rnkr69\LaraChatbot\Llm\SystemPromptBuilder;

/**
 * `php artisan chatbot:decision-rules:show` (v1.1.1, finding #12.d).
 *
 * Imprime las reglas de "Page context — decision strategy" tal como las
 * verá el LLM, según la configuración actual:
 *
 *   - `chatbot.system_prompt.decision_strategy=true`   → default del package.
 *   - `chatbot.system_prompt.decision_strategy='view'` → renderiza la vista.
 *   - `chatbot.system_prompt.decision_strategy=false`  → sección desactivada.
 *
 * También imprime el addendum del system prompt si está configurado, para
 * que el dev vea el bloque completo de "instrucciones meta" que el LLM lee
 * en cada turn.
 */
class DecisionRulesShowCommand extends Command
{
    /** @var string */
    protected $signature = 'chatbot:decision-rules:show';

    /** @var string */
    protected $description = 'Imprime las reglas de decisión que el system prompt añade al LLM.';

    public function handle(ViewFactory $views): int
    {
        $this->newLine();

        $setting = config('chatbot.system_prompt.decision_strategy', true);

        if ($setting === false || $setting === null) {
            $this->components->warn('Decision strategy desactivada (chatbot.system_prompt.decision_strategy = false).');
            $this->newLine();
            $this->renderAddendum($views);
            return self::SUCCESS;
        }

        if (is_string($setting) && $setting !== '') {
            if (! $views->exists($setting)) {
                $this->components->error("La vista `{$setting}` no existe; el builder caerá al default.");
                $this->newLine();
                $this->printSection('package default (fallback)', SystemPromptBuilder::DEFAULT_DECISION_STRATEGY);
                $this->renderAddendum($views);
                return self::FAILURE;
            }

            $rendered = trim($views->make($setting)->render());
            $this->printSection("custom view ({$setting})", $rendered);
            $this->renderAddendum($views);
            return self::SUCCESS;
        }

        $this->printSection('package default', SystemPromptBuilder::DEFAULT_DECISION_STRATEGY);
        $this->renderAddendum($views);
        return self::SUCCESS;
    }

    private function printSection(string $source, string $body): void
    {
        $bytes = strlen($body);
        $kb    = number_format($bytes / 1024, 2);

        $this->line("  Source: <info>{$source}</info>");
        $this->line("  Length: <info>{$kb} KB</info> ({$bytes} bytes)");
        $this->newLine();
        $this->line('  ' . str_repeat('-', 70));
        foreach (preg_split("/\r?\n/", $body) as $line) {
            $this->line('  ' . $line);
        }
        $this->line('  ' . str_repeat('-', 70));
        $this->newLine();
    }

    private function renderAddendum(ViewFactory $views): void
    {
        $addView = config('chatbot.system_prompt.addendum_view');

        if (! is_string($addView) || $addView === '' || ! $views->exists($addView)) {
            $this->line('  Addendum: <comment>(none)</comment>');
            return;
        }

        $rendered = trim($views->make($addView)->render());
        if ($rendered === '') {
            $this->line('  Addendum: <comment>(empty)</comment>');
            return;
        }

        $this->line("  Addendum: <info>{$addView}</info>");
        $this->newLine();
        $this->line('  ' . str_repeat('-', 70));
        foreach (preg_split("/\r?\n/", $rendered) as $line) {
            $this->line('  ' . $line);
        }
        $this->line('  ' . str_repeat('-', 70));
    }
}
