<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Evals;

use DateTimeImmutable;
use RuntimeException;

/**
 * Stateful sink for live-mode eval traces. Each fixture records a row; the
 * collector flushes the running aggregate to `tests/Evals/last-live-run.json`
 * after every record so a partial / interrupted run still leaves a usable
 * file on disk.
 *
 * Lazy-initialised on first `record()` — Pest's `beforeAll` interacts
 * awkwardly with datasets, so we side-step it.
 */
final class EvalTraceCollector
{
    /** @var array<int, array<string, mixed>> */
    private static array $entries = [];
    private static ?string $runId = null;
    private static ?string $startedAt = null;
    private static string $outputPath = '';

    public static function record(array $entry): void
    {
        if (self::$runId === null) {
            self::$runId = uniqid('eval_', true);
            self::$startedAt = (new DateTimeImmutable)->format(DATE_ATOM);
            self::$outputPath = dirname(__DIR__) . '/Evals/last-live-run.json';
            self::$entries = [];
        }
        self::$entries[] = $entry;
        self::flush();
    }

    public static function reset(): void
    {
        self::$entries = [];
        self::$runId = null;
        self::$startedAt = null;
        self::$outputPath = '';
    }

    /**
     * @return array{total:int, passed:int, failed:int, errored:int, accuracy:?float}
     */
    public static function summary(): array
    {
        $total = count(self::$entries);
        $passed = count(array_filter(self::$entries, fn ($e) => ($e['pass'] ?? false) === true));
        $errored = count(array_filter(self::$entries, fn ($e) => isset($e['error'])));
        return [
            'total'    => $total,
            'passed'   => $passed,
            'failed'   => $total - $passed,
            'errored'  => $errored,
            'accuracy' => $total > 0 ? round($passed / $total, 3) : null,
        ];
    }

    public static function outputPath(): string
    {
        return self::$outputPath;
    }

    /** @return array<int, array<string, mixed>> */
    public static function entries(): array
    {
        return self::$entries;
    }

    private static function flush(): void
    {
        if (self::$outputPath === '') return;
        $payload = [
            'run_id'     => self::$runId,
            'started_at' => self::$startedAt,
            'summary'    => self::summary(),
            'entries'    => self::$entries,
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Failed to encode eval trace as JSON.');
        }
        file_put_contents(self::$outputPath, $json);
    }
}
