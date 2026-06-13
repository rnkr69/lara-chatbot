<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * `php artisan chatbot:scan-forms` (v1.1.1, finding #13.b).
 *
 * Scans the host's Blade views looking for `<form>` and reports which ones
 * are tagged (via `@chatbotForm`, `data-chatbot-form`, or `id="..."`) and
 * which are not. It is read-only — it modifies nothing. Useful as an initial
 * audit when integrating the package into a non-Backpack app.
 *
 * Heuristic:
 *   - Glob of `resources/views/&star;&star;/&star;.blade.php` (configurable path).
 *   - Regex over each `<form` to extract attributes.
 *   - If it finds `@chatbotForm(`, `data-chatbot-form=`, or `id=`,
 *     it considers the form "tagged" — the LLM will have a deterministic id.
 *   - If the line contains `action="{{ route('X.store') }}"` or similar,
 *     it tries to extract the route name for the "Route hint" column.
 */
class ScanFormsCommand extends Command
{
    /** @var string */
    protected $signature = 'chatbot:scan-forms
                            {--path= : Absolute or relative path to scan (default: resources/views).}
                            {--json : Return structured JSON instead of a human-readable table.}';

    /** @var string */
    protected $description = 'Scan Blade views for <form> elements and report which are tagged for the LLM.';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = $this->option('path');
        if (! is_string($path) || $path === '') {
            $path = function_exists('resource_path') ? resource_path('views') : 'resources/views';
        }
        if (function_exists('base_path') && ! str_starts_with($path, DIRECTORY_SEPARATOR) && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            $path = base_path($path);
        }

        if (! $this->files->isDirectory($path)) {
            $this->components->error("Path `{$path}` is not an existing directory.");
            return self::FAILURE;
        }

        $rows = [];
        $files = $this->collectBladeFiles($path);

        foreach ($files as $file) {
            try {
                $content = $this->files->get($file);
            } catch (\Throwable $e) {
                $this->components->warn("Could not read `{$file}`: {$e->getMessage()}");
                continue;
            }

            $forms = $this->findForms($content);
            $relative = $this->relativize($file, $path);

            foreach ($forms as $form) {
                $rows[] = array_merge(['view' => $relative], $form);
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['path' => $path, 'forms' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->components->info("No `<form>` elements found in `{$path}`.");
            return self::SUCCESS;
        }

        $this->components->info("Scanned " . count($files) . " views. Found " . count($rows) . " forms:");
        $this->newLine();

        $tableRows = array_map(static fn ($r) => [
            $r['view'],
            $r['route_hint'] ?? '—',
            $r['tag'] ?? '—',
            $r['status'],
        ], $rows);

        $this->table(['View', 'Route hint', 'data-chatbot-form', 'Status'], $tableRows);

        $tagged = count(array_filter($rows, static fn ($r) => $r['status'] === 'Tagged'));
        $untagged = count($rows) - $tagged;

        $this->newLine();
        $this->line("Summary: <info>{$tagged}</info> tagged, <comment>{$untagged}</comment> untagged.");

        if ($untagged > 0) {
            $this->newLine();
            $this->line('Untagged forms are invisible to the LLM via `fill_form` unless auto-discovery applies (one form per page).');
            $this->line('Next: <info>php artisan chatbot:integrate-form &lt;view&gt;</info> to add `@chatbotForm` to a specific form.');
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    protected function collectBladeFiles(string $dir): array
    {
        $out = [];
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile() && str_ends_with(strtolower($file->getFilename()), '.blade.php')) {
                    $out[] = $file->getPathname();
                }
            }
        } catch (\Throwable) { /* fall through with whatever we collected */ }
        sort($out);
        return $out;
    }

    /**
     * Parses `<form ...>` attributes with regex (heuristic, sufficient
     * for 95% of cases). It does not handle a `<form` split across several
     * lines with complex Blade conditionals, but it does handle plain multi-line.
     *
     * @return list<array<string, mixed>>
     */
    protected function findForms(string $content): array
    {
        // Match each <form ... > (greedy until first '>' but allowing newlines).
        preg_match_all('/<form\b([^>]*)>/is', $content, $matches, PREG_OFFSET_CAPTURE);

        $forms = [];
        foreach ($matches[1] ?? [] as $i => $attrsMatch) {
            $attrs = $attrsMatch[0];
            $offset = (int) $attrsMatch[1];

            $line = substr_count(substr($content, 0, $offset), "\n") + 1;

            $tag    = $this->extractDataChatbotForm($attrs);
            $hasDir = preg_match('/@chatbotForm\s*\(/i', $attrs) === 1;
            $id     = $this->extractAttr($attrs, 'id');

            $status = ($tag !== null || $hasDir || ($id !== null && $id !== ''))
                ? 'Tagged'
                : 'Untagged';

            $forms[] = [
                'line'       => $line,
                'tag'        => $tag ?? ($hasDir ? '(via @chatbotForm)' : ($id !== null && $id !== '' ? "id=\"{$id}\"" : null)),
                'route_hint' => $this->extractRouteHint($attrs),
                'status'     => $status,
            ];
        }

        return $forms;
    }

    protected function extractDataChatbotForm(string $attrs): ?string
    {
        if (preg_match('/data-chatbot-form\s*=\s*"([^"]*)"/i', $attrs, $m) === 1) {
            return $m[1];
        }
        if (preg_match("/data-chatbot-form\s*=\s*'([^']*)'/i", $attrs, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    protected function extractAttr(string $attrs, string $name): ?string
    {
        if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*"([^"]*)"/i', $attrs, $m) === 1) {
            return $m[1];
        }
        if (preg_match("/\b" . preg_quote($name, '/') . "\s*=\s*'([^']*)'/i", $attrs, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    protected function extractRouteHint(string $attrs): ?string
    {
        if (preg_match("/route\s*\(\s*['\"]([^'\"]+)['\"]/i", $attrs, $m) === 1) {
            return $m[1];
        }
        $action = $this->extractAttr($attrs, 'action');
        if ($action !== null && $action !== '' && ! str_contains($action, '{{')) {
            return $action;
        }
        return null;
    }

    protected function relativize(string $file, string $base): string
    {
        $sep = DIRECTORY_SEPARATOR;
        $base = rtrim($base, $sep) . $sep;
        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }
}
