<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Models\Message;
use Rnkr69\LaraChatbot\Models\MessageRole;

/**
 * `php artisan chatbot:cost-report --since=YYYY-MM-DD [--until=YYYY-MM-DD]
 *                                  [--user=ID] [--format=table|json|csv]`
 *
 * Aggregates `tokens_in` / `tokens_out` per user (or globally) over a time
 * range and multiplies them by the rates declared in
 * `chatbot.telemetry.prices.{provider}.{model}.{input|output}` (USD per
 * 1M tokens).
 *
 * Without rates for a (provider, model) the row shows cost `n/a`;
 * the tokens are reported regardless. This is deliberate — we prefer
 * a visible partial report over pretending a zero cost.
 *
 * Output (default `table`):
 *
 *     +---------+----------+-------------+---------------+--------------+
 *     | user_id | tokens_in| tokens_out  | cost_input    | cost_output  |
 *     +---------+----------+-------------+---------------+--------------+
 *     | 1       | 12,300   | 4,500       | $0.04         | $0.07        |
 *     | 2       | 8,000    | 2,100       | $0.02         | $0.03        |
 *     +---------+----------+-------------+---------------+--------------+
 *     Total:    20,300     6,600          $0.06           $0.10
 *
 * `--format=json` / `--format=csv` emit the same dataset, machine-readable.
 */
class CostReportCommand extends Command
{
    protected $signature = 'chatbot:cost-report
                            {--since= : Start date (YYYY-MM-DD). Default: first day of the current month.}
                            {--until= : End date (YYYY-MM-DD), exclusive. Default: now.}
                            {--user= : Filter by a specific user_id.}
                            {--format=table : Output format: table | json | csv.}';

    protected $description = 'Aggregate tokens_in/out and cost per user over a time range.';

    public function handle(): int
    {
        $since = $this->option('since')
            ? Carbon::parse($this->option('since'))->startOfDay()
            : Carbon::now()->startOfMonth();
        $until = $this->option('until')
            ? Carbon::parse($this->option('until'))->startOfDay()
            : Carbon::now();
        $userFilter = $this->option('user');
        $format = $this->option('format');

        if (! in_array($format, ['table', 'json', 'csv'], true)) {
            $this->error("Invalid format: {$format}. Use table | json | csv.");
            return self::INVALID;
        }

        // Load the assistant messages in the range with conversation + meta
        // to resolve the effective provider/model (per-conversation override
        // or config default).
        $query = Message::query()
            ->where('role', MessageRole::Assistant->value)
            ->whereBetween('created_at', [$since, $until])
            ->with('conversation:id,user_id,user_type,metadata');

        if ($userFilter !== null) {
            $query->whereHas('conversation', function (Builder $q) use ($userFilter): void {
                $q->where('user_id', $userFilter);
            });
        }

        $defaultProvider = is_string(config('chatbot.provider')) ? config('chatbot.provider') : null;
        $defaultModel    = is_string(config('chatbot.model')) ? config('chatbot.model') : null;

        // Accumulate per (user_id, provider, model) — the rate may differ per
        // conversation if the host passes an override in `metadata`.
        $bucket = [];
        foreach ($query->cursor() as $msg) {
            /** @var Message $msg */
            $convo = $msg->conversation;
            if (! $convo instanceof Conversation) continue;
            $userId = $convo->user_id;
            $meta = is_array($convo->metadata) ? $convo->metadata : [];
            $provider = is_string($meta['provider'] ?? null) ? $meta['provider'] : $defaultProvider;
            $model    = is_string($meta['model'] ?? null) ? $meta['model'] : $defaultModel;

            $key = sprintf('%s|%s|%s', (string) $userId, (string) $provider, (string) $model);
            if (! isset($bucket[$key])) {
                $bucket[$key] = [
                    'user_id'    => $userId,
                    'provider'   => $provider,
                    'model'      => $model,
                    'tokens_in'  => 0,
                    'tokens_out' => 0,
                ];
            }
            $bucket[$key]['tokens_in']  += (int) ($msg->tokens_in ?? 0);
            $bucket[$key]['tokens_out'] += (int) ($msg->tokens_out ?? 0);
        }

        $rows = array_map(function (array $b): array {
            $price = $this->pricesFor($b['provider'], $b['model']);
            $b['cost_input']  = $price !== null ? round($b['tokens_in']  / 1_000_000 * $price['input'],  4) : null;
            $b['cost_output'] = $price !== null ? round($b['tokens_out'] / 1_000_000 * $price['output'], 4) : null;
            return $b;
        }, array_values($bucket));

        // Sort by user_id for stable output.
        usort($rows, fn ($a, $b) => $a['user_id'] <=> $b['user_id']);

        if ($format === 'json') {
            $this->line(json_encode([
                'since'  => $since->toIso8601String(),
                'until'  => $until->toIso8601String(),
                'rows'   => $rows,
                'totals' => $this->totals($rows),
            ], JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        if ($format === 'csv') {
            $this->line('user_id,provider,model,tokens_in,tokens_out,cost_input_usd,cost_output_usd');
            foreach ($rows as $r) {
                $this->line(sprintf(
                    '%s,%s,%s,%d,%d,%s,%s',
                    $r['user_id'], $r['provider'] ?? '', $r['model'] ?? '',
                    $r['tokens_in'], $r['tokens_out'],
                    $r['cost_input']  === null ? 'n/a' : number_format($r['cost_input'], 4, '.', ''),
                    $r['cost_output'] === null ? 'n/a' : number_format($r['cost_output'], 4, '.', ''),
                ));
            }
            return self::SUCCESS;
        }

        // table
        $this->info(sprintf('Cost report: %s → %s', $since->toDateString(), $until->toDateString()));
        if ($rows === []) {
            $this->line('  (no messages in range)');
            return self::SUCCESS;
        }
        $this->table(
            ['user_id', 'provider', 'model', 'tokens_in', 'tokens_out', 'cost_in_$', 'cost_out_$'],
            array_map(fn ($r) => [
                $r['user_id'],
                $r['provider'] ?? '—',
                $r['model'] ?? '—',
                number_format($r['tokens_in'], 0, '.', ','),
                number_format($r['tokens_out'], 0, '.', ','),
                $r['cost_input']  === null ? 'n/a' : '$' . number_format($r['cost_input'], 4),
                $r['cost_output'] === null ? 'n/a' : '$' . number_format($r['cost_output'], 4),
            ], $rows),
        );
        $totals = $this->totals($rows);
        $this->line(sprintf(
            'Totals: tokens_in=%s tokens_out=%s cost_in=%s cost_out=%s',
            number_format($totals['tokens_in'], 0, '.', ','),
            number_format($totals['tokens_out'], 0, '.', ','),
            $totals['cost_input']  === null ? 'n/a' : '$' . number_format($totals['cost_input'],  4),
            $totals['cost_output'] === null ? 'n/a' : '$' . number_format($totals['cost_output'], 4),
        ));
        return self::SUCCESS;
    }

    /**
     * @return ?array{input:float, output:float}
     */
    protected function pricesFor(?string $provider, ?string $model): ?array
    {
        if ($provider === null || $model === null) return null;
        $price = config(sprintf('chatbot.telemetry.prices.%s.%s', $provider, $model));
        if (! is_array($price)) return null;
        if (! isset($price['input'], $price['output'])) return null;
        return ['input' => (float) $price['input'], 'output' => (float) $price['output']];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{tokens_in:int, tokens_out:int, cost_input:?float, cost_output:?float}
     */
    protected function totals(array $rows): array
    {
        $tokensIn = $tokensOut = 0;
        $costIn = $costOut = 0.0;
        $costInKnown = $costOutKnown = false;
        foreach ($rows as $r) {
            $tokensIn  += $r['tokens_in'];
            $tokensOut += $r['tokens_out'];
            if ($r['cost_input']  !== null) { $costIn  += $r['cost_input'];  $costInKnown  = true; }
            if ($r['cost_output'] !== null) { $costOut += $r['cost_output']; $costOutKnown = true; }
        }
        return [
            'tokens_in'   => $tokensIn,
            'tokens_out'  => $tokensOut,
            'cost_input'  => $costInKnown  ? round($costIn,  4) : null,
            'cost_output' => $costOutKnown ? round($costOut, 4) : null,
        ];
    }
}
