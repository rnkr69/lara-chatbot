<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Rnkr69\LaraChatbot\Llm\SystemPromptBuilder;

/**
 * `php artisan chatbot:decision-rules:show` (v1.1.1, finding #12.d).
 *
 * Prints the "Page context — decision strategy" rules exactly as the LLM
 * will see them, according to the current configuration:
 *
 *   - `chatbot.system_prompt.decision_strategy=true`   → package default.
 *   - `chatbot.system_prompt.decision_strategy='view'` → renders the view.
 *   - `chatbot.system_prompt.decision_strategy=false`  → section disabled.
 *
 * Also prints the system prompt addendum if it is configured, so that the
 * dev can see the full "meta instructions" block that the LLM reads on
 * each turn.
 */
class DecisionRulesShowCommand extends Command
{
    /** @var string */
    protected $signature = 'chatbot:decision-rules:show';

    /** @var string */
    protected $description = 'Print the decision rules that the system prompt adds to the LLM.';

    public function handle(ViewFactory $views): int
    {
        $this->newLine();

        $setting = config('chatbot.system_prompt.decision_strategy', true);

        if ($setting === false || $setting === null) {
            $this->components->warn('Decision strategy disabled (chatbot.system_prompt.decision_strategy = false).');
            $this->newLine();
            $this->renderAddendum($views);
            return self::SUCCESS;
        }

        if (is_string($setting) && $setting !== '') {
            if (! $views->exists($setting)) {
                $this->components->error("The view `{$setting}` does not exist; the builder will fall back to the default.");
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
