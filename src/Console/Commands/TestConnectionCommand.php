<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Illuminate\Console\Command;
use Rnkr69\LaraChatbot\Llm\Exceptions\LlmException;
use Rnkr69\LaraChatbot\Llm\LlmGateway;

/**
 * `php artisan chatbot:test-connection [--provider=] [--model=]`
 *
 * Makes a "ping" call to the LLM and reports success/failure. Intended for
 * the host to validate its configuration after `chatbot:install` (E18) or
 * after a change of credentials/provider.
 *
 * Persists nothing — it does not touch conversations, tools, or page context.
 */
class TestConnectionCommand extends Command
{
    /** @var string */
    protected $signature = 'chatbot:test-connection
                            {--provider= : Override `chatbot.provider` for this call.}
                            {--model= : Override `chatbot.model` for this call.}';

    /** @var string */
    protected $description = 'Verify that the chatbot can talk to the configured LLM.';

    public function handle(LlmGateway $gateway): int
    {
        $provider = (string) ($this->option('provider') ?: config('chatbot.provider'));
        $model    = (string) ($this->option('model')    ?: config('chatbot.model'));

        $this->info("Pinging {$provider} / {$model}...");

        try {
            $reply = $gateway->ping(
                provider: $this->option('provider') ?: null,
                model:    $this->option('model')    ?: null,
            );
        } catch (LlmException $e) {
            $this->error('LLM connection failed.');
            $this->line("  reason:  <fg=yellow>{$e->reason}</>");
            $this->line("  message: {$e->getMessage()}");

            $this->printDiagnosticHint($e->getMessage());

            return self::FAILURE;
        }

        $this->info('LLM connection OK.');
        $this->line("  reply: {$reply}");

        return self::SUCCESS;
    }

    /**
     * v1.1 (findings #1): hosts behind a corporate LiteLLM proxy with a
     * self-signed CA get "cURL error 60" on the first ping and waste time
     * looking for nonexistent flags in Prism. When we detect that message,
     * we show an actionable hint with the 3 typical paths.
     */
    protected function printDiagnosticHint(string $message): void
    {
        if (stripos($message, 'cURL error 60') !== false || stripos($message, 'self-signed certificate') !== false) {
            $this->newLine();
            $this->line('<fg=yellow>Hint — SSL certificate verification failed.</>');
            $this->line('  Common fixes (pick one):');
            $this->line('   1. Trust the corporate CA system-wide (preferred): set <fg=cyan>CURL_CA_BUNDLE=/path/to/ca.pem</> in your env.');
            $this->line('   2. Set <fg=cyan>CHATBOT_HTTP_VERIFY=false</> in .env (dev/staging only — disables verification globally).');
            $this->line('   3. From your AppServiceProvider: <fg=cyan>Http::globalOptions(["verify" => false]);</> (manual equivalent of #2).');

            return;
        }

        if (stripos($message, 'cURL error 6') !== false || stripos($message, 'Could not resolve host') !== false) {
            $this->newLine();
            $this->line('<fg=yellow>Hint — DNS resolution failed.</>');
            $this->line('  Check that your proxy / model URL is reachable from this host (try `curl -v <url>`).');

            return;
        }

        if (stripos($message, '401') !== false || stripos($message, 'Unauthorized') !== false || stripos($message, 'authentication') !== false) {
            $this->newLine();
            $this->line('<fg=yellow>Hint — authentication rejected.</>');
            $this->line('  Verify the API key/token for your provider (e.g. <fg=cyan>ANTHROPIC_API_KEY</>) is set and valid.');
        }
    }
}
