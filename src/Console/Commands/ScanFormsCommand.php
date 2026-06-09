<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * `php artisan chatbot:scan-forms` (v1.1.1, finding #13.b).
 *
 * Escanea Blade views del host buscando `<form>` y reporta cuáles están
 * tagueados (via `@chatbotForm`, `data-chatbot-form`, o `id="..."`) y
 * cuáles no. Es read-only — no modifica nada. Útil como auditoría inicial
 * cuando integras el package en una app no-Backpack.
 *
 * Heurística:
 *   - Glob de `resources/views/&star;&star;/&star;.blade.php` (path configurable).
 *   - Regex sobre cada `<form` para extraer atributos.
 *   - Si encuentra `@chatbotForm(`, `data-chatbot-form=`, o `id=`,
 *     considera el form "tagged" — el LLM tendrá un id determinístico.
 *   - Si la línea contiene `action="{{ route('X.store') }}"` o similar,
 *     intenta extraer el nombre de la ruta para la columna "Route hint".
 */
class ScanFormsCommand extends Command
{
    /** @var string */
    protected $signature = 'chatbot:scan-forms
                            {--path= : Path absoluto o relativo donde escanear (default: resources/views).}
                            {--json : Devuelve JSON estructurado en lugar de tabla legible.}';

    /** @var string */
    protected $description = 'Escanea views Blade buscando <form> y reporta cuáles están tagueados para el LLM.';

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
            $this->components->error("Path `{$path}` no es un directorio existente.");
            return self::FAILURE;
        }

        $rows = [];
        $files = $this->collectBladeFiles($path);

        foreach ($files as $file) {
            try {
                $content = $this->files->get($file);
            } catch (\Throwable $e) {
                $this->components->warn("No pude leer `{$file}`: {$e->getMessage()}");
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
            $this->components->info("No se encontraron `<form>` en `{$path}`.");
            return self::SUCCESS;
        }

        $this->components->info("Escaneadas " . count($files) . " views. Encontrados " . count($rows) . " forms:");
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
            $this->line('Next: <info>php artisan chatbot:integrate-form &lt;view&gt;</info> para añadir `@chatbotForm` a un form concreto.');
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
     * Parsea atributos del `<form ...>` con regex (heurístico, suficiente
     * para 95% de los casos). No maneja `<form` partido en varias líneas
     * con condicionales Blade complejos, pero sí maneja multi-line plano.
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
